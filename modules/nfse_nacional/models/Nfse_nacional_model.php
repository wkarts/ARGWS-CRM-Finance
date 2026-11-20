<?php
defined('BASEPATH') or exit('No direct script access allowed');

use NfseNacional\Config;
use NfseNacional\Crypto;
use NfseNacional\CrmData;
use NfseNacional\Decimal;
use NfseNacional\EmissionProfile;
use NfseNacional\ValidationException;
use NfseNacional\Validator;

class Nfse_nacional_model extends App_Model
{
    private string $prefix;

    public function __construct()
    {
        parent::__construct();
        nfse_nacional_scope();
        $this->prefix = db_prefix() . 'nfse_nacional_';
        // SQL statements can contain protected taxpayer data; never collect them for profiling.
        $this->db->save_queries = false;
        $this->db->db_debug = false;
    }

    public function table(string $suffix): string
    {
        if (!in_array($suffix, ['settings', 'profiles', 'recipients', 'invoice_preferences', 'documents', 'events', 'sequences', 'competence_rates', 'competence_iss_rates', 'automation_jobs', 'automation_targets'], true)) {
            throw new InvalidArgumentException('Tabela fiscal inválida.');
        }
        return $this->prefix . $suffix;
    }

    public function crypto(): Crypto
    {
        // A host/database migration must not invalidate encrypted fiscal archives.
        // APP_ENC_KEY + trusted company identity + prefix remain stable across backups.
        nfse_nacional_scope();
        $company = (defined('PME_CONTEXT_READY') && PME_CONTEXT_READY === true) ? (string) $GLOBALS['pme_context']->companyId : 'standalone';
        return new Crypto(defined('APP_ENC_KEY') ? (string) APP_ENC_KEY : '', 'nfse_nacional:' . $company . ':' . db_prefix());
    }

    public function settingsRow(): array
    {
        $query = $this->db->where('id', 1)->get($this->table('settings'));
        if (!$query || !($row = $query->row_array())) { throw new RuntimeException('Ative o módulo NFS-e Nacional nesta empresa.'); }
        return $row;
    }

    public function settings(): array
    {
        $row = $this->settingsRow();
        $stored = self::decode($row['settings_json']);
        // Preserve the explicit local certificate used by installations upgraded from 0.1.0.
        if (!array_key_exists('use_local_certificate', $stored)) { $stored['use_local_certificate'] = empty($row['certificate_enc']) ? '0' : '1'; }
        $settings = Config::merge($stored);
        if (function_exists('get_option')) { $settings['provider'] = CrmData::provider($settings['provider'], static fn($name) => get_option($name)); }
        return $settings;
    }

    public function competenceRate(string $month, string $type = 'simple'): ?array
    {
        $month = \NfseNacional\CompetenceRate::month($month);
        return $this->db->where('issuer', $this->settings()['provider']['tax_id'])->where('competence', $month)
            ->get($this->table(\NfseNacional\CompetenceRate::tableSuffix($type)))->row_array() ?: null;
    }

    public function applyCompetenceRate(array $draft, bool $required = false): array
    {
        return \NfseNacional\CompetenceRate::apply($draft, $this->settings(), fn($month, $type = 'simple') => $this->competenceRate($month, $type), $required);
    }

    public function settingsData(): array
    {
        if (!nfse_nacional_can('settings')) { throw new RuntimeException('Você não tem permissão para configurar a NFS-e.'); }
        $row = $this->settingsRow(); $settings = $this->settings();
        $rateMonth = (new DateTimeImmutable('now', new DateTimeZone('America/Bahia')))->format('Y-m');
        $monthlyRate = $this->competenceRate($rateMonth);
        $monthlyIssRate = $this->competenceRate($rateMonth, 'iss');
        $state = (new \NfseNacional\CertificateResolver($this))->inspect(null, (string) $settings['environment']);
        $authState = \NfseNacional\EnvironmentAuth::publicState($this->authMaterial());
        $diagnostics = [['label' => 'Versão do módulo', 'ok' => defined('NFSE_NACIONAL_VERSION'),
            'message' => defined('NFSE_NACIONAL_VERSION') ? NFSE_NACIONAL_VERSION : 'Não identificada']];
        foreach (['openssl', 'curl', 'dom', 'zlib', 'mbstring'] as $ext) {
            $diagnostics[] = ['label' => 'PHP ' . $ext, 'ok' => extension_loaded($ext), 'message' => extension_loaded($ext) ? 'Disponível' : 'Ausente'];
        }
        $ci = &get_instance();
        $diagnostics[] = ['label' => 'Proteção CSRF', 'ok' => (bool) $ci->config->item('csrf_protection'), 'message' => 'Proteção nativa das operações de alteração.'];
        $pdf = \NfseNacional\Danfe::diagnostics();
        $diagnostics[] = ['label' => 'DANFSe', 'ok' => !empty($pdf['available']), 'message' => $pdf['message']];
        foreach (['1' => 'Produção', '2' => 'Testes'] as $env => $label) {
            $path = (new \NfseNacional\DpsBuilder())->schemaPath((string) $env);
            $diagnostics[] = ['label' => 'Esquema XML — ' . $label, 'ok' => is_file($path), 'message' => is_file($path) ? 'Disponível' : 'Arquivo ausente'];
        }
        // Opening settings only inspects the local table. It must never download
        // a CSV or prevent editing an issuer whose address is still incomplete.
        $ibptTable = ['uf' => '', 'status' => 'invalid', 'status_label' => 'Indisponível',
            'can_refresh' => false, 'message' => '', 'refresh_state' => 'idle', 'refresh_message' => ''];
        try {
            $ibptTable['uf'] = \NfseNacional\DraftTax::state($settings['provider']);
            $ibptTable = \NfseNacional\IbptTable::current()->status($ibptTable['uf']);
        } catch (InvalidArgumentException $error) {
            $ibptTable['message'] = $error->getMessage();
        } catch (Throwable $error) {
            $ibptTable['message'] = 'Não foi possível consultar a tabela IBPT armazenada. Reabra a guia e tente novamente.';
        }
        return ['title' => 'NFS-e Nacional', 'settings' => $settings,
            'certificate' => $state['metadata'] ?? [], 'local_certificate' => self::decode($row['certificate_meta'] ?? '{}'),
            'certificate_state' => ['available' => (bool) ($state['available'] ?? false), 'message' => (string) ($state['message'] ?? '')],
            'auth_state' => $authState, 'rate_month' => $rateMonth, 'monthly_rate' => $monthlyRate, 'monthly_iss_rate' => $monthlyIssRate,
            'diagnostics' => $diagnostics, 'ibpt_table' => $ibptTable];
    }

    public function saveSettings(array $settings, ?string $certificate = null, ?array $metadata = null, ?array $authMaterial = null): void
    {
        $changes = ['settings_json' => self::json($settings), 'updated_at' => self::now()];
        if ($certificate !== null) {
            $changes['certificate_enc'] = $this->crypto()->encrypt($certificate);
            $changes['certificate_meta'] = self::json($metadata ?? []);
        }
        if ($authMaterial !== null) {
            $changes['auth_material_enc'] = $this->crypto()->encrypt(self::json($authMaterial));
        }
        $this->must($this->db->where('id', 1)->update($this->table('settings'), $changes));
    }

    /** Restricted to the authenticated company; never passed to views or logs. */
    public function authMaterial(): array
    {
        $ciphertext = $this->settingsRow()['auth_material_enc'] ?? null;
        if (!is_string($ciphertext) || $ciphertext === '') { return []; }
        $decoded = self::decode($this->crypto()->decrypt($ciphertext));
        if (!is_array($decoded)) { throw new RuntimeException('Credenciais protegidas inválidas.'); }
        return $decoded;
    }

    public function removeCertificate(): void
    {
        $this->must($this->db->where('id', 1)->update($this->table('settings'), ['certificate_enc' => null, 'certificate_meta' => null, 'updated_at' => self::now()]));
    }

    public function client(int $id): object
    {
        if (!nfse_nacional_client_allowed($id)) { throw new RuntimeException('Cliente indisponível para o seu usuário.'); }
        $query = $this->db->where('userid', $id)->get(db_prefix() . 'clients');
        $row = $query ? $query->row() : null;
        if (!$row) { throw new RuntimeException('Cliente não encontrado nesta empresa.'); }
        return $row;
    }

    public function invoice(int $id): object
    {
        if (!nfse_nacional_invoice_allowed($id)) { throw new RuntimeException('Fatura indisponível para o seu usuário.'); }
        $ci = &get_instance();
        $ci->load->model('invoices_model');
        $invoice = $ci->invoices_model->get($id);
        if (!$invoice) { throw new RuntimeException('Fatura não encontrada nesta empresa.'); }
        return $invoice;
    }

    public function profile(int $clientId): array
    {
        $row = $this->db->where('client_id', $clientId)->get($this->table('profiles'))->row_array();
        $profile = array_replace([
            'source' => 'client', 'recipient_id' => '', 'contact_tax_id' => '', 'municipality_code' => '', 'number' => '',
            'complement' => '', 'district' => '', 'service_code' => '', 'municipal_service_code' => '', 'nbs' => '', 'description' => '', 'send_after_authorized' => '0',
        ], self::decode($row['profile_json'] ?? '{}'));
        $client = $this->client($clientId);
        $contact = $this->db->where('userid', $clientId)->where('is_primary', 1)->where('active', 1)->get(db_prefix() . 'contacts')->row_array() ?: [];
        foreach (CrmData::profileDefaults((array) $client, $contact) as $key => $value) {
            if (($profile[$key] ?? '') === '' && $value !== '') { $profile[$key] = $value; }
        }
        $profile['emission_profile'] = EmissionProfile::fromRecord($profile);
        return $profile;
    }

    public function saveProfile(int $clientId, array $input): void
    {
        $this->client($clientId);
        $profile = $this->profile($clientId);
        $emissionProfile = EmissionProfile::fromInput($input, $profile);
        foreach ($profile as $key => $_) {
            if (in_array($key, ['emission_profile', 'service_code', 'municipal_service_code', 'nbs', 'description'], true)) { continue; }
            if (array_key_exists($key, $input)) { $profile[$key] = Config::text($input[$key], $key === 'description' ? 2000 : 200); }
        }
        if (!in_array($profile['source'], ['client', 'primary_contact', 'recipient'], true)) { throw new InvalidArgumentException('Origem do tomador inválida.'); }
        $profile['contact_tax_id'] = Config::taxId($profile['contact_tax_id']);
        if (array_key_exists('send_after_authorized', $input)) {
            if (!nfse_nacional_can('send')) { throw new RuntimeException('Você não tem permissão para alterar o envio ao destinatário.'); }
            $profile['send_after_authorized'] = $input['send_after_authorized'] === '1' ? '1' : '0';
        }
        if ($profile['source'] === 'recipient') { $this->recipient((int) $profile['recipient_id'], $clientId); }
        $profile = EmissionProfile::canonicalRecord($profile, $emissionProfile);
        $this->must($this->db->query('INSERT INTO `' . $this->table('profiles') . '` (client_id,profile_json,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE profile_json=VALUES(profile_json),updated_at=VALUES(updated_at)', [$clientId, self::json($profile), self::now()]));
    }

    public function recipients(int $clientId): array
    {
        $rows = $this->db->where('client_id', $clientId)->order_by('label', 'asc')->get($this->table('recipients'))->result_array();
        foreach ($rows as &$row) {
            $row['data'] = self::decode($row['data_json']);
            $row['data']['emission_profile'] = EmissionProfile::fromRecord($row['data'], true);
            unset($row['data_json']);
        }
        unset($row);
        return $rows;
    }

    public function recipient(int $id, int $clientId): array
    {
        $row = $this->db->where('id', $id)->where('client_id', $clientId)->get($this->table('recipients'))->row_array();
        if (!$row) { throw new InvalidArgumentException('O tomador selecionado não pertence a este cliente.'); }
        $data = self::decode($row['data_json']);
        $data['emission_profile'] = EmissionProfile::fromRecord($data, true);
        return $data;
    }

    public function saveRecipient(int $clientId, int $id, array $input): int
    {
        $this->client($clientId);
        $previous = $id > 0 ? $this->recipient($id, $clientId) : [];
        $data = self::sanitizeRecipient(array_replace($previous, $input));
        $data = EmissionProfile::canonicalRecord($data, EmissionProfile::fromInput($input, $previous, true), true);
        if ($data['name'] === '') { throw new InvalidArgumentException('Informe o nome do tomador.'); }
        if ($data['tax_id'] !== '' && !Validator::taxId($data['tax_id'])) { throw new InvalidArgumentException('CPF/CNPJ do tomador inválido.'); }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('E-mail do tomador inválido.'); }
        $label = $input['label'] ?? $data['name'];
        if ($id > 0 && !array_key_exists('label', $input)) {
            $previousRow = $this->db->select('label')->where('id', $id)->where('client_id', $clientId)->get($this->table('recipients'))->row_array();
            $label = $previousRow['label'] ?? $data['name'];
        }
        $row = ['label' => Config::text($label, 100), 'data_json' => self::json($data), 'updated_at' => self::now()];
        if ($id > 0) {
            $this->must($this->db->where('id', $id)->where('client_id', $clientId)->update($this->table('recipients'), $row));
            return $id;
        }
        $row += ['client_id' => $clientId, 'created_at' => self::now()];
        $this->must($this->db->insert($this->table('recipients'), $row));
        return (int) $this->db->insert_id();
    }

    public function deleteRecipient(int $clientId, int $id): void
    {
        $this->client($clientId); $this->recipient($id, $clientId);
        $p = $this->profile($clientId);
        if ($p['source'] === 'recipient' && (int) $p['recipient_id'] === $id) { throw new RuntimeException('Escolha outro tomador padrão antes de excluir este cadastro.'); }
        $preferences = $this->table('invoice_preferences');
        if ($this->db->table_exists($preferences)) {
            $links = $this->db->select('invoice_id')->where('client_id', $clientId)
                ->where('recipient_selection', 'recipient:' . $id)->get($preferences)->result_array();
            foreach ($links as $link) {
                $invoice = $this->db->select('id,clientid')->where('id', (int) $link['invoice_id'])->get(db_prefix() . 'invoices')->row_array();
                if ($invoice && (int) $invoice['clientid'] === $clientId) {
                    throw new RuntimeException('Este tomador está predefinido em uma fatura. Altere o tomador dessa fatura antes de excluir o cadastro.');
                }
                // Remove obsolete preferences only; historical documents retain their own snapshot.
                $this->must($this->db->where('invoice_id', (int) $link['invoice_id'])->delete($preferences));
            }
        }
        $this->must($this->db->where('id', $id)->where('client_id', $clientId)->delete($this->table('recipients')));
    }

    /** Validates a selection against the active company's customer records. */
    public function validateInvoiceRecipientSelection(int $clientId, string $selection): string
    {
        $this->client($clientId);
        $selection = trim($selection);
        if ($selection === '' || $selection === 'client') { return $selection; }
        if ($selection === 'primary_contact') {
            $contact = $this->db->select('id')->where('userid', $clientId)->where('is_primary', 1)->where('active', 1)
                ->get(db_prefix() . 'contacts')->row_array();
            if (!$contact) { throw new InvalidArgumentException('Cadastre um contato principal ativo para selecionar essa opção.'); }
            return $selection;
        }
        if (!preg_match('/\Arecipient:([1-9][0-9]{0,9})\z/', $selection, $match) || (int) $match[1] > 2147483647) {
            throw new InvalidArgumentException('Escolha um tomador válido para a NFS-e.');
        }
        $this->recipient((int) $match[1], $clientId);
        return 'recipient:' . (int) $match[1];
    }

    /** Missing preference means the customer's standard, evaluated when a new draft is prepared. */
    public function invoiceRecipientSelection(int $invoiceId, int $clientId): string
    {
        if (!$this->db->table_exists($this->table('invoice_preferences'))) { return ''; }
        $row = $this->db->where('invoice_id', $invoiceId)->where('client_id', $clientId)
            ->get($this->table('invoice_preferences'))->row_array();
        return (string) ($row['recipient_selection'] ?? '');
    }

    /** Form options never include recipients belonging to another customer. */
    public function invoiceRecipientContext(int $clientId, int $invoiceId = 0): array
    {
        if (!nfse_nacional_can('view')) { throw new RuntimeException('Você não tem permissão para consultar os tomadores.'); }
        $client = $this->client($clientId);
        // The edit form may be changing customers; check access to both records, but do not carry
        // the old customer's selection into the new customer's options.
        $invoice = $invoiceId > 0 ? $this->invoice($invoiceId) : null;
        $selection = $invoice && (int) $invoice->clientid === $clientId ? $this->invoiceRecipientSelection($invoiceId, $clientId) : '';
        $options = [
            ['value' => '', 'label' => 'Usar padrão do cliente'],
            ['value' => 'client', 'label' => 'Próprio cliente — ' . (string) $client->company],
        ];
        $contact = $this->db->where('userid', $clientId)->where('is_primary', 1)->where('active', 1)
            ->get(db_prefix() . 'contacts')->row_array();
        if ($contact) {
            $options[] = ['value' => 'primary_contact', 'label' => 'Contato principal — ' . trim(($contact['firstname'] ?? '') . ' ' . ($contact['lastname'] ?? ''))];
        }
        foreach ($this->recipients($clientId) as $recipient) {
            $name = (string) ($recipient['data']['name'] ?? '');
            $label = (string) ($recipient['label'] ?: $name);
            $options[] = ['value' => 'recipient:' . (int) $recipient['id'], 'label' => $label . ($name !== '' && $name !== $label ? ' — ' . $name : '')];
        }
        if ($selection !== '' && !in_array($selection, array_column($options, 'value'), true)) {
            // Do not silently replace an unavailable saved recipient with a different taxpayer.
            $options[] = ['value' => $selection, 'label' => 'Tomador salvo indisponível — escolha outra opção'];
        }
        return ['client_id' => $clientId, 'invoice_id' => $invoiceId, 'selection' => $selection,
            'options' => $options, 'company_scope' => nfse_nacional_scope()];
    }

    /** Called by native invoice hooks after the CRM has saved the actual invoice. */
    public function saveInvoiceRecipientSelection(int $invoiceId, int $expectedClientId, string $selection): void
    {
        if (!nfse_nacional_can('create') && !nfse_nacional_can('edit')) {
            throw new RuntimeException('Você não tem permissão para definir o tomador da NFS-e.');
        }
        $invoice = $this->invoice($invoiceId);
        if ((int) $invoice->clientid !== $expectedClientId) {
            throw new RuntimeException('O cliente da fatura mudou. Atualize a tela antes de escolher o tomador.');
        }
        $selection = $this->validateInvoiceRecipientSelection($expectedClientId, $selection);
        $table = $this->table('invoice_preferences');
        if (!$this->db->table_exists($table)) {
            throw new RuntimeException('Atualize o banco do módulo NFS-e Nacional para salvar o tomador da fatura.');
        }
        if ($selection === '') {
            $this->must($this->db->where('invoice_id', $invoiceId)->delete($table));
            return;
        }
        $this->must($this->db->query('INSERT INTO `' . $table . '` (invoice_id,client_id,recipient_selection,updated_by,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id),recipient_selection=VALUES(recipient_selection),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',
            [$invoiceId, $expectedClientId, $selection, (int) get_staff_user_id(), self::now()]));
    }

    /** Native invoice edits without the fiscal field must still invalidate a previous customer. */
    public function syncInvoiceRecipientClient(int $invoiceId): void
    {
        if ($invoiceId < 1) { return; }
        $table = $this->table('invoice_preferences');
        if (!$this->db->table_exists($table)) { return; }
        // Trusted lifecycle hook only. This cleanup needs no disclosure of fiscal records to
        // an invoice editor who may not have access to the NFS-e module.
        $invoice = $this->db->select('clientid')->where('id', $invoiceId)->get(db_prefix() . 'invoices')->row_array();
        if (!$invoice) { return; }
        $this->must($this->db->where('invoice_id', $invoiceId)->where('client_id !=', (int) $invoice['clientid'])->delete($table));
    }

    /** Resolves a selection without changing the shared customer profile. */
    public function recipientSelectionData(int $clientId, string $selection): array
    {
        $selection = $this->validateInvoiceRecipientSelection($clientId, $selection);
        $profile = $this->profile($clientId);
        if ($selection === 'client' || $selection === 'primary_contact') {
            $profile['source'] = $selection; $profile['recipient_id'] = '';
        } elseif (strncmp($selection, 'recipient:', 10) === 0) {
            $profile['source'] = 'recipient'; $profile['recipient_id'] = substr($selection, 10);
        }
        $effectiveSelection = $profile['source'] === 'recipient' ? 'recipient:' . (int) $profile['recipient_id'] : $profile['source'];
        $defaults = $profile['source'] === 'recipient' ? $this->recipient((int) $profile['recipient_id'], $clientId) : [];
        return ['selection' => $effectiveSelection, 'profile' => $profile, 'recipient_defaults' => $defaults,
            'recipient' => $this->resolveRecipient($clientId, $profile)];
    }

    public static function sanitizeRecipient(array $input): array
    {
        $result = [];
        foreach (['name' => 150, 'tax_id' => 32, 'email' => 80, 'phone' => 20, 'municipality_code' => 7, 'zip' => 10, 'street' => 255, 'number' => 60, 'complement' => 156, 'district' => 60] as $key => $limit) {
            $result[$key] = Config::text($input[$key] ?? '', $limit);
        }
        $result['tax_id'] = Config::taxId($result['tax_id']);
        $result['phone'] = preg_replace('/\D+/', '', $result['phone']);
        $result['zip'] = preg_replace('/\D+/', '', $result['zip']);
        return $result;
    }

    public function resolveRecipient(int $clientId, ?array $profile = null): array
    {
        $client = $this->client($clientId); $p = $profile ?? $this->profile($clientId);
        if ($p['source'] === 'recipient') { return self::sanitizeRecipient($this->recipient((int) $p['recipient_id'], $clientId)); }
        $contact = $this->db->where('userid', $clientId)->where('is_primary', 1)->where('active', 1)->get(db_prefix() . 'contacts')->row_array();
        $contact = $contact ?: [];
        if ($p['source'] === 'primary_contact' && !$contact) { throw new RuntimeException('Cadastre um contato principal ativo ou escolha outro tomador.'); }
        $address = CrmData::address((array) $client);
        return self::sanitizeRecipient([
            'name' => $p['source'] === 'primary_contact' ? trim(($contact['firstname'] ?? '') . ' ' . ($contact['lastname'] ?? '')) : (string) $client->company,
            'tax_id' => $p['source'] === 'primary_contact' ? $p['contact_tax_id'] : (string) ($client->vat ?? ''),
            'email' => (string) ($contact['email'] ?? ''), 'phone' => (string) ($contact['phonenumber'] ?? $client->phonenumber ?? ''),
            'municipality_code' => $p['municipality_code'], 'zip' => $address['zip'],
            'street' => $address['street'], 'number' => $p['number'], 'complement' => $p['complement'],
            // Perfex has no district by default. The fiscal tab stores it explicitly.
            'district' => $p['district'] ?? '',
        ]);
    }

    public function clientData(int $clientId): array
    {
        $client = $this->client($clientId);
        $invoices = $this->db->select('id,number,date,total,status')->where('clientid', $clientId)->where_not_in('status', [5, 6])->order_by('id', 'desc')->limit(100)->get(db_prefix() . 'invoices')->result_array();
        $invoices = array_values(array_filter($invoices, static fn($i) => nfse_nacional_invoice_allowed($i['id'])));
        $documents = $this->listDocuments(['client_id' => $clientId]);
        $settings = $this->settings(); $environment = $settings['environment'];
        $byInvoice = [];
        foreach ($documents as $doc) { if ($doc['environment'] === $environment && !isset($byInvoice[(int) $doc['invoice_id']])) { $byInvoice[(int) $doc['invoice_id']] = $doc; } }
        foreach ($invoices as &$invoice) {
            if (isset($byInvoice[(int) $invoice['id']])) {
                $doc = $byInvoice[(int) $invoice['id']];
                $invoice['document_id'] = $doc['id']; $invoice['document_status'] = $doc['status']; $invoice['document_number'] = $doc['nfse_number'];
            }
        }
        unset($invoice);
        $profile = $this->profile($clientId);
        return ['client' => $client, 'profile' => $profile, 'recipients' => $this->recipients($clientId),
            'contacts' => $this->db->where('userid', $clientId)->where('active', 1)->get(db_prefix() . 'contacts')->result_array(),
            'invoices' => $invoices, 'documents' => $documents, 'emission_environment' => $environment,
            'emission_defaults' => EmissionProfile::resolve($settings, []),
            'recipient_emission_defaults' => EmissionProfile::resolve($settings, $profile)];
    }

    public function invoiceContext(int $invoiceId): array
    {
        if (!nfse_nacional_can('view')) { throw new RuntimeException('Você não tem permissão para visualizar a NFS-e.'); }
        $invoice = $this->invoice($invoiceId);
        $this->client((int) $invoice->clientid);
        $environment = $this->settings()['environment'];
        $rows = $this->db->select('id,invoice_id,client_id,environment,status,nfse_number,access_key,last_error,email_status,created_at,draft_json')
            ->where('invoice_id', $invoiceId)->order_by('environment', 'asc')->order_by('id', 'asc')->get($this->table('documents'))->result_array();
        $historyConflict = false;
        foreach ($rows as $row) { if ((int) $row['client_id'] !== (int) $invoice->clientid) { $historyConflict = true; } }
        $rows = array_values(array_filter($rows, static fn($row) => (int) $row['client_id'] === (int) $invoice->clientid));
        foreach ($rows as &$row) {
            $snapshot = self::decode($row['draft_json'] ?? '{}');
            $row['recipient_name'] = (string) ($snapshot['recipient']['name'] ?? '');
            unset($row['draft_json']);
        }
        unset($row);
        $current = null;
        foreach ($rows as $row) { if ($row['environment'] === $environment) { $current = $row; } }
        $can = [];
        foreach (['view', 'create', 'edit', 'transmit', 'send', 'settings'] as $cap) { $can[$cap] = nfse_nacional_can($cap); }
        if ($historyConflict || in_array((int) $invoice->status, [5, 6], true)) { $can['create'] = $can['transmit'] = false; }
        return ['invoice' => $invoice, 'documents' => $rows, 'current_document' => $current, 'environment' => $environment,
            'can' => $can, 'history_conflict' => $historyConflict,
            'recipient_context' => !$current && !$historyConflict && $can['create'] ? $this->invoiceRecipientContext((int) $invoice->clientid, $invoiceId) : [],
            'client_url' => admin_url('clients/client/' . (int) $invoice->clientid . '?group=nfse_nacional')];
    }

    public function document(int $id): array
    {
        $row = $this->db->where('id', $id)->get($this->table('documents'))->row_array();
        if (!$row || !nfse_nacional_can('view')) { throw new RuntimeException('Documento indisponível para o seu usuário.'); }
        if (!is_admin() && (!nfse_nacional_client_allowed($row['client_id']) || !nfse_nacional_invoice_allowed($row['invoice_id']))) { throw new RuntimeException('Documento indisponível para o seu usuário.'); }
        return $row;
    }

    public function listDocuments(array $filters = []): array
    {
        $this->db->select('d.id,d.invoice_id,d.client_id,d.environment,d.status,d.service_amount,d.nfse_number,d.access_key,d.last_error,d.created_at,d.email_status,c.company AS client_name,c.userid AS origin_client_id,i.id AS origin_invoice_id')
            ->from($this->table('documents') . ' d')->join(db_prefix() . 'clients c', 'c.userid=d.client_id', 'left')
            ->join(db_prefix() . 'invoices i', 'i.id=d.invoice_id AND i.clientid=d.client_id', 'left');
        if (!is_admin()) {
            $staff = (int) get_staff_user_id();
            $this->db->where('i.id IS NOT NULL', null, false);
            if (!nfse_nacional_native_can('customers', 'view')) {
                $this->db->join(db_prefix() . 'customer_admins ca', 'ca.customer_id=d.client_id AND ca.staff_id=' . $staff);
            }
            if (!nfse_nacional_native_can('invoices', 'view')) {
                $own = nfse_nacional_native_can('invoices', 'view_own');
                $assigned = function_exists('get_option') && (string) get_option('allow_staff_view_invoices_assigned') === '1';
                if (!$own && !$assigned) { $this->db->where('1=0', null, false); }
                else {
                    $this->db->group_start();
                    if ($own) { $this->db->where('i.addedfrom', $staff); }
                    if ($assigned) { $own ? $this->db->or_where('i.sale_agent', $staff) : $this->db->where('i.sale_agent', $staff); }
                    $this->db->group_end();
                }
            }
        }
        if (!empty($filters['client_id'])) { $this->db->where('d.client_id', (int) $filters['client_id']); }
        if (in_array($filters['environment'] ?? '', ['1', '2'], true)) { $this->db->where('d.environment', $filters['environment']); }
        if (in_array($filters['status'] ?? '', ['draft', 'ready', 'processing', 'authorized', 'cancelled', 'rejected', 'uncertain'], true)) { $this->db->where('d.status', $filters['status']); }
        if (($filters['collection'] ?? '') === 'authorized') { $this->db->where_in('d.status', ['authorized', 'cancelled']); }
        elseif (($filters['collection'] ?? '') === 'pending') { $this->db->where_not_in('d.status', ['authorized', 'cancelled']); }
        if (!empty($filters['search'])) { $this->db->like('c.company', mb_substr((string) $filters['search'], 0, 100)); }
        $rows = $this->db->order_by('d.id', 'desc')->limit(200)->get()->result_array();
        foreach ($rows as &$row) { $row['orphan_document'] = empty($row['origin_invoice_id']) || empty($row['origin_client_id']); }
        unset($row);
        return array_values(array_filter($rows, static fn($r) => is_admin() || (nfse_nacional_client_allowed($r['client_id']) && nfse_nacional_invoice_allowed($r['invoice_id']))));
    }

    /** Close queue entries immediately after an official/manual authorization, in the native company database. */
    public function completeInvoiceAutomations(array $doc): void
    {
        if (!$this->db || !$this->db->table_exists($this->table('automation_jobs'))) { return; }
        $pending = ['pending', 'blocked', 'processing', 'checking', 'review'];
        $base = function () use ($doc, $pending) {
            return $this->db->where('scope', nfse_nacional_scope())->where('invoice_id', (int) $doc['invoice_id'])
                ->where('environment', $doc['environment'])->where_in('status', $pending);
        };
        $this->must($base()->where('document_id', (int) $doc['id'])->update($this->table('automation_jobs'),
            ['status' => 'issued', 'last_error' => null, 'updated_at' => self::now()]));
        $this->must($base()->update($this->table('automation_jobs'),
            ['status' => 'skipped', 'last_error' => 'NFS-e já emitida para esta fatura. Pendência automática encerrada.', 'updated_at' => self::now()]));
    }

    public function events(int $id): array
    {
        return $this->db->where('document_id', $id)->order_by('id', 'desc')->limit(100)->get($this->table('events'))->result_array();
    }

    public function event(int $id, string $type, string $message): void
    {
        $this->must($this->db->insert($this->table('events'), ['document_id' => $id, 'event_type' => $type,
            'message' => mb_substr($message, 0, 2000), 'staff_id' => (int) get_staff_user_id(), 'created_at' => self::now()]));
    }

    public function updateDocument(int $id, array $changes): void
    {
        $changes['updated_at'] = self::now();
        $this->must($this->db->where('id', $id)->update($this->table('documents'), $changes));
    }

    public function lock(string $name, callable $operation)
    {
        $key = 'nfse:' . substr(hash('sha256', nfse_nacional_scope() . ':' . $name), 0, 56);
        $query = $this->db->query('SELECT GET_LOCK(?, 0) AS acquired', [$key]);
        if (!$query || (int) ($query->row_array()['acquired'] ?? 0) !== 1) { throw new RuntimeException('Esta operação já está em andamento. Aguarde e consulte a nota.'); }
        try { return $operation(); }
        finally { $this->db->query('SELECT RELEASE_LOCK(?)', [$key]); }
    }

    public static function fingerprint(object $invoice): string
    {
        return hash('sha256', self::json([
            'id' => (string) $invoice->id, 'client' => (string) $invoice->clientid, 'date' => (string) ($invoice->date ?? ''),
            'currency' => (string) $invoice->currency, 'subtotal' => (string) $invoice->subtotal,
            'discount' => (string) ($invoice->discount_total ?? '0'), 'total' => (string) $invoice->total,
            'items' => $invoice->items ?? [],
        ]));
    }

    /** Recipient defaults override the client's defaults and the active company's defaults. */
    public static function mergeServiceDefaults(array $service, array $profile, array $recipient = [], array $items = []): array
    {
        return EmissionProfile::resolve(['service' => $service], $profile, $recipient, $items)['service'];
    }

    /** Only used in an authorized invoice/document context; historic snapshots are not modified. */
    public function recipientsForInvoice(int $clientId, object $invoice): array
    {
        $this->client($clientId);
        if ((int) ($invoice->clientid ?? 0) !== $clientId) { throw new RuntimeException('A fatura não pertence ao cliente do tomador.'); }
        $profile = $this->profile($clientId); $settings = $this->settings();
        $recipients = $this->recipients($clientId);
        foreach ($recipients as &$row) {
            $row['emission_defaults'] = EmissionProfile::resolve($settings, $profile, $row['data'], $invoice->items ?? []);
            $row['service_defaults'] = array_intersect_key($row['emission_defaults']['service'], array_flip(['national_code', 'description']));
            $row['selection'] = 'recipient:' . (int) $row['id'];
        }
        unset($row);
        $standard = [];
        foreach (['client' => 'Próprio cliente', 'primary_contact' => 'Contato principal'] as $source => $label) {
            try { $resolved = $this->recipientSelectionData($clientId, $source); }
            catch (InvalidArgumentException $error) { if ($source === 'primary_contact') { continue; } throw $error; }
            $emission = EmissionProfile::resolve($settings, $resolved['profile'], [], $invoice->items ?? []);
            $standard[] = ['id' => $source, 'selection' => $source, 'label' => $label . ' — ' . $resolved['recipient']['name'],
                'data' => $resolved['recipient'], 'emission_defaults' => $emission,
                'service_defaults' => array_intersect_key($emission['service'], array_flip(['national_code', 'description']))];
        }
        return array_merge($standard, $recipients);
    }

    public function prepare(int $invoiceId, ?string $recipientSelection = null): int
    {
        return $this->lock('invoice:' . $invoiceId, function () use ($invoiceId, $recipientSelection) {
            $invoice = $this->invoice($invoiceId); $settings = $this->settings(); $environment = $settings['environment'];
            $conflict = $this->db->select('id')->where('invoice_id', $invoiceId)->where('client_id !=', (int) $invoice->clientid)->limit(1)->get($this->table('documents'))->row_array();
            if ($conflict) { throw new RuntimeException('O cliente da fatura foi alterado após a criação de seu histórico fiscal. Revise esse vínculo antes de preparar outra emissão.'); }
            $old = $this->db->where('invoice_id', $invoiceId)->where('environment', $environment)->order_by('id', 'desc')->limit(1)->get($this->table('documents'))->row_array();
            if ($old) {
                if ($recipientSelection !== null) {
                    $requested = $this->recipientSelectionData((int) $invoice->clientid, $recipientSelection);
                    $snapshot = self::decode($old['draft_json']);
                    if (($snapshot['recipient_selection'] ?? '') !== $requested['selection']) {
                        throw new RuntimeException('Esta fatura já possui um documento fiscal. Abra o rascunho para revisar o tomador; a escolha da fatura não altera documentos preparados.');
                    }
                }
                return (int) $old['id'];
            }
            $this->assertInvoice($invoice);
            $selection = $recipientSelection ?? $this->invoiceRecipientSelection($invoiceId, (int) $invoice->clientid);
            $resolved = $this->recipientSelectionData((int) $invoice->clientid, $selection);
            $profile = $resolved['profile'];
            $emission = EmissionProfile::resolve($settings, $profile, $resolved['recipient_defaults'], $invoice->items ?? []);
            $draft = ['environment' => $environment, 'competence' => (string) $invoice->date, 'provider' => $settings['provider'],
                'recipient' => $resolved['recipient'], 'recipient_selection' => $resolved['selection'], 'service' => $emission['service'],
                'values' => $emission['tax'] + ['service_amount' => Decimal::normalize($invoice->subtotal), 'unconditional_discount' => Decimal::normalize($invoice->discount_total ?? '0'), 'conditional_discount' => '0.00'],
                'ibscbs' => $emission['ibscbs'], 'invoice_values_acknowledged' => '0'];
            $draft = $this->refreshDraftTaxes($draft);
            $asaas = null;
            if ($this->db->table_exists(db_prefix() . 'asaas_payments_map')) {
                $mapping = $this->db->select('asaas_payment_id')->where('invoice_id', $invoiceId)->get(db_prefix() . 'asaas_payments_map')->row_array();
                $asaas = $mapping['asaas_payment_id'] ?? null;
            }
            $json = self::json($draft);
            $this->must($this->db->insert($this->table('documents'), ['invoice_id' => $invoiceId, 'client_id' => (int) $invoice->clientid,
                'environment' => $environment, 'status' => 'draft', 'draft_json' => $json, 'draft_hash' => hash('sha256', $json),
                'invoice_fingerprint' => self::fingerprint($invoice), 'service_amount' => Decimal::subtract($draft['values']['service_amount'], $draft['values']['unconditional_discount']),
                'asaas_payment_id' => $asaas, 'send_after_authorized' => ($profile['send_after_authorized'] === '1' && nfse_nacional_can('send')) ? 1 : 0,
                'recipient_email' => $draft['recipient']['email'], 'created_by' => (int) (\NfseNacional\AutomationContext::current()['staff_id'] ?? get_staff_user_id()), 'created_at' => self::now(), 'updated_at' => self::now()]));
            $id = (int) $this->db->insert_id();
            $this->event($id, 'draft_created', 'Rascunho preparado a partir da fatura. Confira competência, serviço, valores e tomador.');
            return $id;
        });
    }

    public function refreshDraftTaxes(array $draft): array
    {
        return \NfseNacional\DraftTax::refresh($this->applyCompetenceRate($draft));
    }

    public function replacementFor(int $originalId): ?array
    {
        $this->document($originalId);
        $row = $this->db->where('replacement_of_id', $originalId)->limit(1)->get($this->table('documents'))->row_array();
        return $row ? $this->document((int) $row['id']) : null;
    }

    /** Caller holds the invoice lock. Both versions stay linked to the real invoice. */
    public function createSubstitution(array $original, array $draft, object $invoice): int
    {
        if (!nfse_nacional_can('substitute') || !nfse_nacional_can('create') || !nfse_nacional_can('edit')) { throw new RuntimeException('Você não tem permissão para preparar a substituição.'); }
        $original = $this->document((int) $original['id']);
        $existing = $this->replacementFor((int) $original['id']);
        if ($existing) { return (int) $existing['id']; }
        $this->assertInvoice($invoice);
        if ($original['status'] !== 'authorized' || (int) $invoice->id !== (int) $original['invoice_id']
            || (int) $invoice->clientid !== (int) $original['client_id']) { throw new RuntimeException('A nota original não está disponível para substituição.'); }
        $json = self::json($draft); $now = self::now();
        $this->must($this->db->trans_begin());
        try {
            $this->must($this->db->insert($this->table('documents'), ['invoice_id' => (int) $original['invoice_id'], 'client_id' => (int) $original['client_id'],
                'environment' => $original['environment'], 'revision' => (int) ($original['revision'] ?? 0) + 1, 'replacement_of_id' => (int) $original['id'],
                'status' => 'draft', 'draft_json' => $json, 'draft_hash' => hash('sha256', $json), 'invoice_fingerprint' => self::fingerprint($invoice),
                'service_amount' => $original['service_amount'], 'asaas_payment_id' => $original['asaas_payment_id'] ?? null,
                'send_after_authorized' => 0, 'recipient_email' => $draft['recipient']['email'] ?? '',
                'created_by' => (int) (\NfseNacional\AutomationContext::current()['staff_id'] ?? get_staff_user_id()), 'created_at' => $now, 'updated_at' => $now]));
            $id = (int) $this->db->insert_id();
            $this->event($id, 'substitution_prepared', 'Rascunho de substituição preparado a partir da NFS-e #' . $original['nfse_number'] . '. A nota original permanece no histórico.');
            $this->event((int) $original['id'], 'substitution_draft', 'Preparado rascunho de substituição #' . $id . '. O cancelamento depende da autorização no ambiente nacional.');
            if ($this->db->trans_status() === false) { throw new RuntimeException('A preparação da substituição não foi concluída.'); }
            $this->must($this->db->trans_commit());
            return $id;
        } catch (Throwable $error) { $this->db->trans_rollback(); throw $error; }
    }

    public function assertInvoice(object $invoice): void
    {
        if (in_array((int) $invoice->status, [5, 6], true)) { throw new RuntimeException('Conclua a fatura antes de emitir; faturas canceladas ou em rascunho não podem gerar NFS-e.'); }
        $currency = $this->db->where('id', (int) $invoice->currency)->get(db_prefix() . 'currencies')->row_array();
        if (!$currency || strtoupper((string) ($currency['name'] ?? '')) !== 'BRL') { throw new RuntimeException('Esta versão emite apenas faturas em BRL.'); }
    }

    public function deleteDraft(int $id): int
    {
        return $this->lock('document:' . $id, function () use ($id) {
            $doc = $this->document($id);
            if (!empty($doc['dps_id']) || (int) $doc['attempts'] > 0 || !in_array($doc['status'], ['draft', 'ready', 'rejected'], true)) {
                throw new RuntimeException('Uma DPS reservada ou transmitida precisa permanecer no histórico fiscal.');
            }
            $this->must($this->db->trans_begin());
            try {
                $this->must($this->db->where('document_id', $id)->delete($this->table('events')));
                $this->must($this->db->where('id', $id)->delete($this->table('documents')));
                if ($this->db->trans_status() === false) { throw new RuntimeException('Falha ao excluir o rascunho.'); }
                $this->must($this->db->trans_commit());
            } catch (Throwable $error) { $this->db->trans_rollback(); throw $error; }
            return (int) $doc['client_id'];
        });
    }

    /** Reservation and document identity commit atomically before the first network attempt. */
    public function reserve(array $document, array $draft, array $settings): array
    {
        if (!empty($document['dps_id'])) { return $document; }
        $series = (string) (int) $settings['series'];
        $key = hash('sha256', $document['environment'] . ':' . $draft['provider']['tax_id'] . ':' . $series);
        $this->must($this->db->trans_begin());
        try {
            $this->must($this->db->query('INSERT IGNORE INTO `' . $this->table('sequences') . '` (scope,last_number) VALUES (?,?)', [$key, (string) ((int) $settings['next_number'] - 1)]));
            $row = $this->db->query('SELECT last_number FROM `' . $this->table('sequences') . '` WHERE scope=? FOR UPDATE', [$key])->row_array();
            $number = (int) $row['last_number'] + 1;
            if ($number > 999999999999999) { throw new RuntimeException('A numeração desta série foi esgotada.'); }
            $this->must($this->db->where('scope', $key)->update($this->table('sequences'), ['last_number' => (string) $number]));
            $draft['issued_at'] = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:sP');
            $changes = ['dps_series' => $series, 'dps_number' => (string) $number,
                'dps_id' => \NfseNacional\DpsBuilder::identifier($draft['provider'], $series, (string) $number),
                'draft_json' => self::json($draft), 'draft_hash' => hash('sha256', self::json($draft))];
            $this->updateDocument((int) $document['id'], $changes);
            if ($this->db->trans_status() === false) { throw new RuntimeException('Não foi possível reservar a DPS.'); }
            $this->must($this->db->trans_commit());
            return array_replace($document, $changes);
        } catch (Throwable $error) { $this->db->trans_rollback(); throw $error; }
    }

    private function must($success): void
    {
        if ($success === false) { throw new RuntimeException('Não foi possível gravar os dados fiscais. Verifique o banco de dados e tente novamente.'); }
    }
    public static function now(): string { return date('Y-m-d H:i:s'); }
    public static function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    public static function decode(?string $json): array { return $json ? (json_decode($json, true, 64, JSON_THROW_ON_ERROR) ?: []) : []; }
}
