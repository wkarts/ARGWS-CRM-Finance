<?php
declare(strict_types=1);
namespace NfseNacional;

/** Durable queue. Uses the current company's database only; never changes tenant context. */
final class Automation
{
    private $db;
    public function __construct(private $model, private $service = null)
    {
        $this->db = $model->db;
        $this->service = $service ?? new Service($model, static fn($environment, $certificate) =>
            new ApiClient($environment, $certificate, null, static function (array $event) {
                try { OperationalLog::current()->record('info', (string) ($event['operation'] ?? 'api.request'), 'Resposta da emissão automática.', $event); }
                catch (\Throwable $ignored) { /* Optional logging must not change a fiscal result. */ }
            }));
    }
    public static function schema(): array
    {
        return [
            'competence_rates' => "issuer VARCHAR(14) NOT NULL, competence CHAR(7) NOT NULL, rate DECIMAL(4,2) NOT NULL,
                updated_by INT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(issuer,competence)",
            'competence_iss_rates' => "issuer VARCHAR(14) NOT NULL, competence CHAR(7) NOT NULL, rate DECIMAL(4,2) NOT NULL,
                updated_by INT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(issuer,competence)",
            'automation_targets' => "client_id INT NOT NULL, recipient_selection VARCHAR(64) NOT NULL,
                trigger_event VARCHAR(12) NOT NULL, PRIMARY KEY(client_id,recipient_selection,trigger_event)",
            'automation_jobs' => "id BIGINT NOT NULL AUTO_INCREMENT, scope CHAR(64) NOT NULL, issuer VARCHAR(14) NOT NULL,
                invoice_id INT NOT NULL, client_id INT NOT NULL, environment CHAR(1) NOT NULL, trigger_event VARCHAR(12) NOT NULL,
                recipient_selection VARCHAR(64) NOT NULL, explicit_selection TINYINT NOT NULL DEFAULT 0, invoice_fingerprint CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending', due_at DATETIME NOT NULL, document_id BIGINT NULL,
                last_error TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                PRIMARY KEY(id), UNIQUE KEY invoice_environment_trigger(invoice_id,environment,trigger_event), KEY queue(status,due_at)"
        ];
    }
    private function selected(int $clientId, string $selection, string $event): bool
    {
        $selections = ['*', $selection];
        if ($selection === '') {
            $row = $this->db->where('client_id', $clientId)->get($this->model->table('profiles'))->row_array();
            $profile = \Nfse_nacional_model::decode($row['profile_json'] ?? '{}');
            $source = $profile['source'] ?? 'client';
            $selections[] = $source === 'recipient' ? 'recipient:' . (int) ($profile['recipient_id'] ?? 0) : $source;
        }
        return (bool) $this->db->where('trigger_event', $event)->where('client_id', $clientId)->where_in('recipient_selection', $selections)
            ->limit(1)->get($this->model->table('automation_targets'))->row_array();
    }
    public function summary(int $clientId, string $selection): string
    {
        $settings = $this->model->settings(); $parts = [];
        foreach (['paid' => 'Após pagamento integral', 'created' => 'Após criação'] as $event => $label) {
            $config = AutomationPolicy::rule($settings, $event);
            if ($config['mode'] !== 'disabled' && ($config['mode'] === 'all' || $this->selected($clientId, $selection, $event))) {
                $parts[] = $label . ': ' . ((int) $config['delay_minutes'] === 0 ? 'imediatamente, pelo cron' : (int) $config['delay_minutes'] . ' minutos');
            }
        }
        return $parts ? implode(' · ', $parts) . '. Uma única NFS-e por fatura.' : 'Nenhuma automação aplicável ao cliente/tomador selecionado.';
    }
    private function rawInvoice(int $id): ?object
    {
        get_instance()->load->model('invoices_model');
        return get_instance()->invoices_model->get($id) ?: null;
    }
    private function finish(int $id, string $status, string $message = ''): void
    {
        if ($this->db->where('id', $id)->update($this->model->table('automation_jobs'),
            ['status' => $status, 'last_error' => mb_substr($message, 0, 2000), 'updated_at' => date('Y-m-d H:i:s')]) === false) {
            throw new \RuntimeException('Não foi possível atualizar a fila da NFS-e.');
        }
    }
    public function enqueue(int $invoiceId, string $event, bool $explicit = false): void
    {
        $settings = $this->model->settings(); $config = AutomationPolicy::rule($settings, $event);
        if ($config['mode'] === 'disabled') { return; }
        $invoice = $this->rawInvoice($invoiceId);
        if (!$invoice) { return; }
        $selection = $this->model->invoiceRecipientSelection($invoiceId, (int) $invoice->clientid);
        $effectiveConfig = $config;
        if ($explicit && $event === 'created') { $effectiveConfig['trigger'] = 'created'; }
        if (!AutomationPolicy::eligible($effectiveConfig, $invoice, ($explicit || $this->selected((int) $invoice->clientid, $selection, $event)), $event)) { return; }
        $now = date('Y-m-d H:i:s');
        // Created means the creation instant, even when another hook finishes a moment later.
        $start = $event === 'created' && !empty($invoice->datecreated) ? (string) $invoice->datecreated : $now;
        $queued = $this->db->query('INSERT IGNORE INTO `' . $this->model->table('automation_jobs') . '`
            (scope,issuer,invoice_id,client_id,environment,trigger_event,recipient_selection,explicit_selection,invoice_fingerprint,status,due_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [nfse_nacional_scope(), $settings['provider']['tax_id'], $invoiceId,
                (int) $invoice->clientid, $settings['environment'], $event, $selection, $explicit ? 1 : 0, \Nfse_nacional_model::fingerprint($invoice),
                'pending', AutomationPolicy::due($start, (int) $config['delay_minutes']), $now, $now]);
        if ($queued === false) { throw new \RuntimeException('Não foi possível agendar a emissão da NFS-e.'); }
        // All jobs, including zero delay, run through the native cron, never a screen request.
    }
    public function cancelInvoice(int $invoiceId): void
    {
        $this->db->where('invoice_id', $invoiceId)->where_in('status', ['pending', 'blocked', 'review', 'processing', 'checking'])
            ->update($this->model->table('automation_jobs'), ['status' => 'cancelled', 'last_error' => 'Fatura cancelada ou excluída.', 'updated_at' => date('Y-m-d H:i:s')]);
    }
    public function run(): void
    {
        $settings = $this->model->settings();
        $rows = $this->db->where('scope', nfse_nacional_scope())->where('issuer', $settings['provider']['tax_id'])
            ->where('environment', $settings['environment'])->where_in('status', ['pending', 'blocked', 'processing', 'checking'])
            ->where('due_at <=', date('Y-m-d H:i:s'))->order_by('due_at', 'asc')->limit(25)
            ->get($this->model->table('automation_jobs'))->result_array();
        foreach ($rows as $row) {
            try {
                $this->model->lock('automation:' . $row['id'], function () use ($row, $settings) {
                    $job = $this->db->where('id', $row['id'])->get($this->model->table('automation_jobs'))->row_array();
                    if (!$job || !in_array($job['status'], ['pending', 'blocked', 'processing', 'checking'], true)) { return; }
                    $rule = AutomationPolicy::rule($this->model->settings(), $job['trigger_event']);
                    if ($rule['mode'] === 'disabled') { $this->finish((int) $job['id'], 'cancelled', 'Esta automação foi desabilitada.'); return; }
                    // Authorization is renewed for each type; revoking one delegation does not stop the other.
                    $actor = $this->db->where('staffid', (int) $rule['staff_id'])->where('active', 1)->where('admin', 1)->get(db_prefix() . 'staff')->row_array();
                    if (!$actor) { $this->finish((int) $job['id'], 'blocked', 'O administrador que habilitou esta automação não está ativo. Salve novamente a configuração com um administrador ativo.'); return; }
                    $context = ['scope' => nfse_nacional_scope(), 'invoice_id' => (int) $job['invoice_id'], 'client_id' => (int) $job['client_id'],
                        'staff_id' => (int) $actor['staffid'], 'company_id' => (defined('PME_CONTEXT_READY') && PME_CONTEXT_READY === true) ? (string) $GLOBALS['pme_context']->companyId : 'standalone',
                        'organization_id' => (defined('PME_CONTEXT_READY') && PME_CONTEXT_READY === true) ? (string) ($GLOBALS['pme_context']->organizationId ?? '') : ''];
                    AutomationContext::run($context, fn() => $this->model->lock('invoice:' . $job['invoice_id'], fn() => $this->process($job, $settings)));
                });
            } catch (\Throwable $error) {
                // No raw network/credential details in PHP's global log. Other jobs can continue.
                if (function_exists('log_message')) { log_message('error', 'NFS-e: não foi possível processar um agendamento; consulte NFSE > Automação.'); }
            }
        }
    }
    private function postpone(int $id, int $minutes): void
    {
        $this->db->where('id', $id)->update($this->model->table('automation_jobs'), ['due_at' => AutomationPolicy::due(date('Y-m-d H:i:s'), $minutes)]);
    }
    private function closeOtherJobs(array $job): void
    {
        $this->db->where('invoice_id', $job['invoice_id'])->where('environment', $job['environment'])
            ->where_in('status', ['pending', 'blocked', 'review', 'processing', 'checking'])
            ->update($this->model->table('automation_jobs'), ['status' => 'skipped', 'last_error' => 'NFS-e já confirmada para esta fatura.', 'updated_at' => date('Y-m-d H:i:s')]);
    }
    private function process(array $job, array $settings): void
    {
        $id = (int) $job['id']; $invoiceId = (int) $job['invoice_id'];
        $settings = $this->model->settings();
        if ($settings['environment'] !== $job['environment'] || $settings['provider']['tax_id'] !== $job['issuer']) { $this->finish($id, 'cancelled', 'Ambiente ou emitente alterado.'); return; }
        $invoice = $this->rawInvoice($invoiceId);
        if (!$invoice || (int) $invoice->clientid !== (int) $job['client_id']) { $this->finish($id, 'cancelled', 'Fatura excluída ou cliente alterado.'); return; }
        $selection = $this->model->invoiceRecipientSelection($invoiceId, (int) $invoice->clientid);
        $event = $job['trigger_event'];
        $effectiveConfig = AutomationPolicy::rule($settings, $event);
        if (!empty($job['explicit_selection']) && $job['trigger_event'] === 'created') { $effectiveConfig['trigger'] = 'created'; }
        if (!AutomationPolicy::eligible($effectiveConfig, $invoice, (!empty($job['explicit_selection']) || $this->selected((int) $invoice->clientid, $selection, $event)), $job['trigger_event'])
            || $selection !== $job['recipient_selection'] || !hash_equals($job['invoice_fingerprint'], \Nfse_nacional_model::fingerprint($invoice))) {
            $this->finish($id, 'cancelled', 'Fatura, tomador ou opção da automação alterados.'); return;
        }
        $documents = $this->db->where('invoice_id', $invoiceId)->where('environment', $job['environment'])
            ->get($this->model->table('documents'))->result_array();
        foreach ($documents as $document) {
            $owned = !empty($job['document_id']) && (int) $job['document_id'] === (int) $document['id'];
            if ($document['status'] === 'authorized') {
                $this->finish($id, $owned ? 'issued' : 'skipped', $owned ? '' : 'NFS-e já emitida para esta fatura.');
                $this->closeOtherJobs($job); return;
            }
            if ($owned && (in_array($document['status'], ['processing', 'uncertain'], true) || (int) $document['attempts'] > 0)) {
                try {
                    $result = $this->service->reconcile((int) $document['id']);
                    if ($result['status'] === 'authorized') { $this->finish($id, 'issued'); $this->closeOtherJobs($job); }
                    else { $this->finish($id, 'checking', 'Aguardando confirmação da DPS anterior. Nenhum reenvio foi realizado.'); $this->postpone($id, 5); }
                } catch (\Throwable $error) { $this->finish($id, 'checking', 'A consulta da transmissão anterior falhou. Consulte os logs; nenhum reenvio foi realizado.'); $this->postpone($id, 5); }
                return;
            }
            if (!$owned || $document['status'] === 'cancelled') {
                $this->finish($id, 'skipped', 'Há documento manual, cancelado ou de outra automação. Nenhuma nova NFS-e será criada.'); return;
            }
        }
        try {
            // Missing competence keeps the job waiting, without creating any external request.
            if (empty($job['document_id']) && !$documents) { $this->model->applyCompetenceRate(['competence' => (string) $invoice->date, 'values' => []], true); }
            // prepare()/Service validate the document's fiscal competence, including reviewed drafts.
            $documentId = empty($job['document_id']) ? $this->model->prepare($invoiceId) : (int) $job['document_id'];
            if ($this->db->where('id', $id)->update($this->model->table('automation_jobs'), ['document_id' => $documentId]) === false) { throw new \RuntimeException('Não foi possível vincular o documento à fila.'); }
            // Reserve a terminal local queue state BEFORE remote I/O. A killed worker never retransmits blindly.
            $this->finish($id, 'processing', 'Emissão em processamento. Em caso de interrupção, consulte a DPS no documento.');
            $result = $this->service->transmit($documentId);
            $this->finish($id, $result['status'] === 'authorized' ? 'issued' : 'checking',
                $result['status'] === 'authorized' ? '' : 'Consultando a situação da DPS anterior; nenhum reenvio automático.');
            if ($result['status'] === 'authorized') { $this->closeOtherJobs($job); } else { $this->postpone($id, 5); }
        } catch (ValidationException $error) {
            $this->finish($id, 'blocked', $error->getMessage());
            $this->db->where('id', $id)->update($this->model->table('automation_jobs'), ['due_at' => AutomationPolicy::due(date('Y-m-d H:i:s'), 5)]);
        } catch (ApiException $error) {
            $this->finish($id, $error->isUncertain() ? 'checking' : 'review', 'API NFS-e (HTTP ' . $error->httpStatus() . '): ' . $error->getMessage());
            if ($error->isUncertain()) { $this->postpone($id, 5); }
        } catch (\Throwable $error) {
            $this->finish($id, 'review', 'A emissão exige revisão. Confira os dados, o certificado disponível e os logs do documento.');
        }
    }
}
