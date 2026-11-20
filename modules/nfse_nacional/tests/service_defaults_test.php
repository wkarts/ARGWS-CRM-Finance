<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/autoload.php';
class App_Model { public $db; }
require_once dirname(__DIR__) . '/models/Nfse_nacional_model.php';

use NfseNacional\Config;
use NfseNacional\EmissionProfile;
use NfseNacional\Service;

function db_prefix(): string { return 'defaults_test_'; }
function nfse_nacional_can(string $capability): bool { return $capability !== 'send'; }
function get_staff_user_id(): int { return 9; }
function is_admin(): bool { return true; }
$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$checks; echo 'OK: ' . $label . PHP_EOL;
}
function refuses(callable $operation, string $label): void {
    try { $operation(); } catch (InvalidArgumentException | RuntimeException $error) { check(true, $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}

final class DefaultsResult {
    public function __construct(private array $rows) {}
    public function row_array(): ?array { return $this->rows[0] ?? null; }
    public function result_array(): array { return $this->rows; }
}
/** A bounded in-memory query adapter; production defaults, model and service are not replaced. */
final class DefaultsDatabase {
    public array $tables = [];
    private array $where = [];
    private int $lastId = 0;
    private ?int $limit = null;
    public function where(string $column, $value): self {
        if (!preg_match('/^([a-z_]+)(?:\s+(!=|=))?$/D', $column, $match)) { throw new LogicException('Condição não suportada.'); }
        $this->where[] = [$match[1], $match[2] ?? '=', $value]; return $this;
    }
    public function select(string $columns): self { return $this; }
    public function order_by(string $column, string $order): self { return $this; }
    public function limit(int $limit): self { $this->limit = $limit; return $this; }
    private function matches(array $row): bool {
        foreach ($this->where as [$key, $operator, $value]) {
            $equal = (string) ($row[$key] ?? '') === (string) $value;
            if (($operator === '=' && !$equal) || ($operator === '!=' && $equal)) { return false; }
        }
        return true;
    }
    private function reset(): void { $this->where = []; $this->limit = null; }
    public function get(string $table): DefaultsResult {
        $rows = array_values(array_filter($this->tables[$table] ?? [], fn($row) => $this->matches($row)));
        if ($this->limit !== null) { $rows = array_slice($rows, 0, $this->limit); }
        $this->reset(); return new DefaultsResult($rows);
    }
    public function insert(string $table, array $row): bool {
        $this->lastId = max(array_column($this->tables[$table] ?? [], 'id') ?: [0]) + 1;
        $row['id'] = $this->lastId; $this->tables[$table][] = $row; return true;
    }
    public function insert_id(): int { return $this->lastId; }
    public function update(string $table, array $values): bool {
        foreach ($this->tables[$table] as &$row) { if ($this->matches($row)) { $row = array_replace($row, $values); } }
        unset($row); $this->reset(); return true;
    }
    public function table_exists(string $table): bool { return isset($this->tables[$table]); }
    public function query(string $sql, array $params): bool {
        if (!preg_match('/^INSERT INTO `([^`]+nfse_nacional_profiles)` /', $sql, $matches)) { throw new LogicException('SQL inesperado.'); }
        [$clientId, $json, $updated] = $params;
        $table = $matches[1]; $this->tables[$table] ??= [];
        foreach ($this->tables[$table] as &$row) {
            if ((int) $row['client_id'] === (int) $clientId) { $row['profile_json'] = $json; return true; }
        }
        unset($row);
        $this->tables[$table][] = ['client_id' => $clientId, 'profile_json' => $json, 'updated_at' => $updated]; return true;
    }
}

final class DefaultsModel extends Nfse_nacional_model {
    public array $configuration;
    public array $history = [];
    public $ibptLookup = null;
    public object $currentInvoice;
    public function __construct(private string $scope = 'one_') {
        $this->db = new DefaultsDatabase();
        $this->configuration = Config::defaults();
        $this->configuration['provider']['municipality_code'] = '3550308';
        $this->configuration['service']['national_code'] = '010101';
        $this->configuration['service']['description'] = 'Padrão da empresa';
        $this->currentInvoice = (object) ['id' => 41, 'clientid' => 7, 'status' => 1, 'currency' => 1,
            'date' => '2026-11-01', 'subtotal' => '100.00', 'discount_total' => '0.00', 'total' => '100.00',
            'items' => [['description' => 'Serviço da fatura', 'long_description' => '<p>Competência mensal</p>']]];
        $this->db->tables[db_prefix() . 'currencies'] = [['id' => 1, 'name' => 'BRL']];
        $this->db->tables[db_prefix() . 'contacts'] = [['userid' => 7, 'is_primary' => 1, 'active' => 1, 'firstname' => 'Contato', 'lastname' => 'Principal', 'email' => 'contato@example.invalid']];
    }
    public function table(string $suffix): string { return db_prefix() . $this->scope . 'nfse_nacional_' . $suffix; }
    public function client(int $id): object {
        if ($id !== 7) { throw new RuntimeException('Cliente fora do escopo.'); }
        return (object) ['userid' => 7, 'company' => 'CLIENTE', 'vat' => '52998224725', 'address' => 'Rua Exemplo, 10', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01001000'];
    }
    public function invoice(int $id): object {
        if ($id !== 41) { throw new RuntimeException('Fatura fora do escopo.'); }
        return $this->currentInvoice;
    }
    public function settings(): array { return $this->configuration; }
    public function refreshDraftTaxes(array $draft): array { return \NfseNacional\DraftTax::refresh($draft, $this->ibptLookup); }
    public function lock(string $name, callable $operation) { return $operation(); }
    public function event(int $id, string $type, string $message): void { $this->history[] = [$id, $type]; }
}

check(Config::serviceCode('01.01.01') === '010101', 'Código pontuado é convertido sem perder zeros iniciais');
refuses(fn() => Config::serviceCode('10101'), 'Código numérico truncado não é adivinhado');
refuses(fn() => Config::serviceCode('999999'), 'Código ausente da tabela oficial é recusado');
refuses(fn() => Config::serviceCode(['010101']), 'Entrada estruturada não contorna validação do catálogo');
refuses(fn() => Config::sanitize(['service' => ['national_code' => '999999']]), 'Configuração da empresa também exige um código oficial');

$model = new DefaultsModel();
$model->saveProfile(7, ['service_code' => '010201', 'description' => 'Prestação mensal do cliente']);
check($model->profile(7)['emission_profile']['service']['national_code'] === '010201' && $model->profile(7)['emission_profile']['service']['description'] === 'Prestação mensal do cliente', 'Preferências do cliente são gravadas e recuperadas pelo modelo real');
refuses(fn() => $model->saveProfile(7, ['service_code' => '999999']), 'Salvar perfil rejeita serviço que não pertence ao catálogo');
check($model->profile(7)['emission_profile']['service']['national_code'] === '010201', 'Falha de validação preserva a configuração anterior');
$recipient = ['name' => 'TOMADOR', 'tax_id' => '11144477735', 'email' => 'tomador@example.invalid', 'municipality_code' => '3550308', 'zip' => '01001000', 'street' => 'Rua Exemplo', 'number' => '1', 'district' => 'Centro', 'service_code' => '010301', 'description' => 'Atendimento contratado para o tomador'];
$recipientId = $model->saveRecipient(7, 0, $recipient);
check($model->recipient($recipientId, 7)['emission_profile']['service']['national_code'] === '010301', 'Serviço do dependente é armazenado junto de seu cadastro');
check($model->recipient($recipientId, 7)['emission_profile']['service']['description'] === $recipient['description'], 'Descrição da prestação é preservada sem substituir pela descrição oficial');
refuses(fn() => $model->saveRecipient(7, $recipientId, array_replace($recipient, ['service_code' => '999999'])), 'Alteração do tomador valida o código no servidor');
refuses(fn() => $model->recipient($recipientId, 8), 'ID de tomador de outro cliente não é aceito');
$legacyInput = $recipient; unset($legacyInput['service_code'], $legacyInput['description']);
$model->saveRecipient(7, $recipientId, $legacyInput);
check($model->recipient($recipientId, 7)['emission_profile']['service']['national_code'] === '010301', 'Edição cadastral que omite preferências não apaga o serviço já salvo');
$model->saveProfile(7, ['source' => 'recipient', 'recipient_id' => (string) $recipientId]);
$id = $model->prepare(41); $doc = $model->document($id); $draft = $model::decode($doc['draft_json']);
check($draft['service']['national_code'] === '010301' && $draft['service']['description'] === $recipient['description'], 'Preparar pela fatura aplica serviço e descrição do tomador escolhido');
check($draft['recipient']['tax_id'] === '11144477735' && !isset($draft['recipient']['service_code']), 'Snapshot do tomador contém dados pessoais e serviço fica no grupo fiscal correto');
check($draft['service']['municipality_code'] === '3550308' && $draft['values']['service_amount'] === '100.00', 'Município, valor e vínculo continuam derivados da empresa e da fatura');
$restrictedDraft = $draft; $restrictedDraft['environment'] = '1'; $restrictedDraft['service']['national_code'] = '990301';
$environmentError = static fn(array $errors): bool => (bool) array_filter($errors, static fn($error) => str_contains($error, 'disponível somente em testes'));
check($environmentError(\NfseNacional\Validator::draft($restrictedDraft)), 'Validação fiscal bloqueia código exclusivo de testes antes da assinatura em produção');
$restrictedDraft['environment'] = '2';
check(!$environmentError(\NfseNacional\Validator::draft($restrictedDraft)), 'Disponibilidade do catálogo usa o ambiente do documento, sem ativação por calendário');
check($doc['invoice_id'] === 41 && $doc['client_id'] === 7, 'Rascunho mantém fatura e responsável financeiro originais');
$options = $model->recipientsForInvoice(7, $model->currentInvoice);
$selectedOptions = array_values(array_filter($options, static fn($option) => ($option['selection'] ?? '') === 'recipient:' . $recipientId));
check(count($selectedOptions) === 1 && $selectedOptions[0]['service_defaults']['national_code'] === '010301', 'Troca de tomador no rascunho recebe os mesmos padrões efetivos');
refuses(fn() => $model->recipientsForInvoice(7, (object) ['clientid' => 8]), 'Não mistura padrões entre faturas de clientes diferentes');
$model->saveRecipient(7, $recipientId, array_replace($recipient, ['description' => 'Padrão alterado depois']));
check($model->prepare(41) === $id && $model->document($id)['draft_json'] === $doc['draft_json'], 'Novo clique reaproveita o rascunho e não sobrescreve sua revisão com cadastros posteriores');
$recipientService = new Service($model);
$recipientService->saveDraft($id, [], $model->document($id)['draft_hash'], false, $recipientId);
$selectedDraft = $model::decode($model->document($id)['draft_json']);
check($selectedDraft['service']['national_code'] === '010301' && $selectedDraft['service']['description'] === $recipient['description'], 'Salvar novamente o mesmo tomador preserva o snapshot mesmo após alteração do cadastro');
$recipientService->saveDraft($id, ['recipient' => ['email' => 'revisado@example.invalid', 'number' => '99'],
    'service' => ['national_code' => '010201', 'description' => 'Descrição revisada na nota']], $model->document($id)['draft_hash'], false, $recipientId);
$revised = $model::decode($model->document($id)['draft_json']);
check($revised['recipient']['email'] === 'revisado@example.invalid' && $revised['recipient']['number'] === '99'
    && $revised['recipient']['tax_id'] === '11144477735', 'Ajustes visíveis após selecionar tomador são respeitados e campos omitidos preservados');
check($revised['service']['national_code'] === '010201' && $revised['service']['description'] === 'Descrição revisada na nota', 'Revisão explícita de serviço prevalece sobre os valores sugeridos do tomador');
check($model->recipient($recipientId, 7)['email'] === 'tomador@example.invalid'
    && $model->recipient($recipientId, 7)['emission_profile']['service']['national_code'] === '010301', 'Editar a nota não altera silenciosamente o cadastro do tomador');
refuses(fn() => $recipientService->saveDraft($id, [], $model->document($id)['draft_hash'], false, 999), 'Troca de tomador exige cadastro existente no cliente da nota');

$clientModel = new DefaultsModel();
$clientModel->saveProfile(7, ['service_code' => '010201', 'description' => 'Descrição cliente']);
$clientDraft = $clientModel::decode($clientModel->document($clientModel->prepare(41))['draft_json']);
check($clientDraft['service']['national_code'] === '010201' && $clientDraft['service']['description'] === 'Descrição cliente', 'Sem tomador específico, perfil do cliente prevalece sobre empresa');
$contactModel = new DefaultsModel();
$contactModel->saveProfile(7, ['source' => 'primary_contact', 'contact_tax_id' => '11144477735', 'service_code' => '010201', 'description' => 'Descrição contato']);
$contactDraft = $contactModel::decode($contactModel->document($contactModel->prepare(41))['draft_json']);
check($contactDraft['recipient']['name'] === 'Contato Principal' && $contactDraft['service']['description'] === 'Descrição contato', 'Contato principal reutiliza a preferência fiscal configurada no cliente');
$companyModel = new DefaultsModel();
$companyDraft = $companyModel::decode($companyModel->document($companyModel->prepare(41))['draft_json']);
check($companyDraft['service']['national_code'] === '010101' && $companyDraft['service']['description'] === 'Padrão da empresa', 'Campos vazios herdam os padrões da empresa ativa');
$invoiceModel = new DefaultsModel(); $invoiceModel->configuration['service']['description'] = '';
$invoiceDraft = $invoiceModel::decode($invoiceModel->document($invoiceModel->prepare(41))['draft_json']);
check($invoiceDraft['service']['description'] === 'Serviço da fatura Competência mensal', 'Sem descrição configurada, os itens da fatura fornecem o texto da prestação');
$otherCompany = new DefaultsModel('two_'); $otherCompany->db = $model->db;
check($otherCompany->recipients(7) === [] && $otherCompany->profile(7)['emission_profile'] === [], 'Prefixo de outra empresa não reaproveita tomadores nem padrões locais');

// Old installations are read as stored JSON, rather than recreated through the new sanitizer.
$legacyModel = new DefaultsModel();
$legacyProfile = ['source' => 'recipient', 'recipient_id' => '5', 'service_code' => '010201', 'municipal_service_code' => '123', 'nbs' => '123456789', 'description' => 'Texto antigo do cliente', 'number' => '90'];
$legacyPerson = array_replace($recipient, ['service_code' => '010301', 'description' => 'Texto antigo do tomador']);
$legacyModel->db->tables[$legacyModel->table('profiles')] = [['client_id' => 7, 'profile_json' => $legacyModel::json($legacyProfile)]];
$legacyModel->db->tables[$legacyModel->table('recipients')] = [['id' => 5, 'client_id' => 7, 'label' => 'Tomador anterior', 'data_json' => $legacyModel::json($legacyPerson)]];
$legacyRows = serialize([$legacyModel->db->tables[$legacyModel->table('profiles')], $legacyModel->db->tables[$legacyModel->table('recipients')]]);
check($legacyModel->profile(7)['emission_profile']['service']['municipal_code'] === '123' && $legacyModel->recipient(5, 7)['emission_profile']['service']['description'] === 'Texto antigo do tomador', 'Leitura de JSON legado disponibiliza o novo perfil fiscal sem migração destrutiva');
check(serialize([$legacyModel->db->tables[$legacyModel->table('profiles')], $legacyModel->db->tables[$legacyModel->table('recipients')]]) === $legacyRows, 'Consultar cadastros antigos não regrava suas tabelas');
$legacyModel->saveProfile(7, ['number' => '91']);
$legacyModel->saveRecipient(7, 5, ['email' => 'alterado@example.invalid']);
check($legacyModel->profile(7)['recipient_id'] === '5' && $legacyModel->profile(7)['emission_profile']['service']['nbs'] === '123456789' && $legacyModel->recipient(5, 7)['emission_profile']['service']['description'] === 'Texto antigo do tomador' && $legacyModel->recipient(5, 7)['name'] === $recipient['name'], 'Primeira edição parcial preserva identidade, seleção e preferências antigas ao gravar formato canônico');
check($legacyModel->db->tables[$legacyModel->table('recipients')][0]['label'] === 'Tomador anterior', 'Editar apenas o e-mail preserva a identificação personalizada do tomador');
$storedLegacy = $legacyModel::decode($legacyModel->db->tables[$legacyModel->table('recipients')][0]['data_json']);
check(isset($storedLegacy['emission_profile']) && !array_key_exists('service_code', $storedLegacy) && !array_key_exists('description', $storedLegacy), 'Persistência canônica remove duplicação legada que poderia reativar padrões antigos');

$fullModel = new DefaultsModel();
$fullModel->configuration['service'] = array_replace($fullModel->configuration['service'], ['municipal_code' => '111', 'nbs' => '111111111']);
$fullModel->configuration['tax'] = array_replace($fullModel->configuration['tax'], ['iss_rate' => '5.00', 'total_tax_mode' => 'amount', 'total_federal' => '10.00', 'total_state' => '20.00', 'total_municipal' => '30.00']);
$fullModel->currentInvoice->discount_total = '15.00'; $fullModel->currentInvoice->total = '85.00';
$fullModel->saveProfile(7, ['emission_profile_present' => '1', 'emission_profile' => ['service' => ['description' => 'Padrão fiscal do cliente'], 'tax' => ['iss_rate' => '3.00', 'retention' => '2']]]);
$ownA = ['service' => ['national_code' => '010301', 'municipal_code' => '222', 'nbs' => '222222222', 'description' => 'Serviço próprio A'], 'tax' => ['iss_rate' => '4.25', 'retention' => '3', 'total_state' => '42.00'], 'ibscbs' => ['enabled' => '1', 'operation_indicator' => '100301', 'cst' => '000', 'class_code' => '000001', 'final_consumer' => '0']];
$ownB = ['service' => ['national_code' => '010201', 'municipal_code' => '', 'nbs' => '', 'description' => 'Serviço próprio B'], 'tax' => ['iss_rate' => '0.00', 'simple_rate' => '', 'retention' => '1', 'total_tax_mode' => 'none', 'total_federal' => '0.00', 'total_state' => '0.00', 'total_municipal' => '0.00'], 'ibscbs' => ['enabled' => '0', 'operation_indicator' => '', 'cst' => '', 'class_code' => '', 'final_consumer' => '']];
$a = $fullModel->saveRecipient(7, 0, array_replace($recipient, ['name' => 'TOMADOR A', 'emission_profile_present' => '1', 'emission_profile' => $ownA]));
$b = $fullModel->saveRecipient(7, 0, array_replace($recipient, ['name' => 'TOMADOR B', 'emission_profile_present' => '1', 'emission_profile' => $ownB]));
$fullId = $fullModel->prepare(41, 'client');
$fullService = new Service($fullModel);
$fullService->saveDraft($fullId, [], $fullModel->document($fullId)['draft_hash'], false, null, 'recipient:' . $a);
$aDraft = $fullModel::decode($fullModel->document($fullId)['draft_json']);
check($aDraft['service']['municipal_code'] === '222' && $aDraft['service']['nbs'] === '222222222' && $aDraft['values']['iss_rate'] === '4.25' && $aDraft['values']['total_state'] === '42.00' && $aDraft['ibscbs']['enabled'] === '1' && $aDraft['ibscbs']['class_code'] === '000001', 'Troca efetiva de tomador aplica todos os grupos fiscais pelo Service real');
$fullService->saveDraft($fullId, ['service' => ['description' => 'Texto revisado na nota A', 'nbs' => ''], 'values' => ['iss_rate' => '1.25'], 'ibscbs' => ['enabled' => '0']], $fullModel->document($fullId)['draft_hash'], false, null, 'recipient:' . $a);
$reviewedA = $fullModel->document($fullId);
$fullModel->saveRecipient(7, $a, ['emission_profile' => ['service' => ['description' => 'Cadastro alterado depois'], 'tax' => ['iss_rate' => '6.00']]]);
$fullService->saveDraft($fullId, [], $reviewedA['draft_hash'], false, null, 'recipient:' . $a);
check($fullModel->document($fullId)['draft_json'] === $reviewedA['draft_json'], 'Salvar novamente a mesma seleção não restaura defaults nem apaga revisão, mesmo com cadastro alterado');
$fullService->saveDraft($fullId, [], $fullModel->document($fullId)['draft_hash'], false, null, 'recipient:' . $b);
$bDraft = $fullModel::decode($fullModel->document($fullId)['draft_json']);
check($bDraft['service']['description'] === 'Serviço próprio B' && $bDraft['service']['municipal_code'] === '' && $bDraft['service']['nbs'] === '' && $bDraft['values']['iss_rate'] === '0.00' && $bDraft['values']['retention'] === '1' && $bDraft['values']['total_state'] === '0.00', 'Trocar A por B remove os códigos anteriores e substitui alíquota, retenção e tributos inclusive por zero');
check($bDraft['ibscbs']['enabled'] === '0' && $bDraft['ibscbs']['cst'] === '' && $bDraft['ibscbs']['class_code'] === '' && $bDraft['ibscbs']['operation_indicator'] === '' && $bDraft['ibscbs']['final_consumer'] === '', 'Tomador que desabilita ou omite IBS/CBS não recebe os campos do tomador anterior');
check($bDraft['values']['service_amount'] === '100.00' && $bDraft['values']['unconditional_discount'] === '15.00' && $bDraft['values']['conditional_discount'] === '0.00' && $bDraft['competence'] === '2026-11-01' && $fullModel->document($fullId)['service_amount'] === '85.00', 'Troca fiscal conserva valores, descontos e competência da cobrança');
$fullService->saveDraft($fullId, ['service' => ['description' => 'Revisão explícita após selecionar A'], 'values' => ['iss_rate' => '1.75']], $fullModel->document($fullId)['draft_hash'], false, null, 'recipient:' . $a);
$explicit = $fullModel::decode($fullModel->document($fullId)['draft_json']);
check($explicit['service']['description'] === 'Revisão explícita após selecionar A' && $explicit['values']['iss_rate'] === '1.75' && $explicit['recipient_selection'] === 'recipient:' . $a, 'Ajustes explícitos da nota prevalecem após aplicar o perfil do novo tomador');
$fullService->saveDraft($fullId, [], $fullModel->document($fullId)['draft_hash'], false, null, 'client');
$clientReset = $fullModel::decode($fullModel->document($fullId)['draft_json']);
check($clientReset['service']['description'] === 'Padrão fiscal do cliente' && $clientReset['service']['municipal_code'] === '111' && $clientReset['values']['iss_rate'] === '3.00' && $clientReset['values']['retention'] === '2' && $clientReset['values']['total_state'] === '20.00' && $clientReset['ibscbs']['enabled'] === '0', 'Voltar ao cliente recompõe padrões completos dos pais sem valores residuais do dependente');
$fullOptions = $fullModel->recipientsForInvoice(7, $fullModel->currentInvoice);
$bOption = array_values(array_filter($fullOptions, static fn($option) => $option['selection'] === 'recipient:' . $b))[0];
check($bOption['emission_defaults']['service']['nbs'] === '' && $bOption['emission_defaults']['tax']['iss_rate'] === '0.00' && $bOption['emission_defaults']['ibscbs']['enabled'] === '0' && $bOption['service_defaults']['national_code'] === '010201', 'Mapa enviado ao editor oferece perfil completo e mantém serviço legado para compatibilidade');
$beforeInvalidProfile = $fullModel->recipient($b, 7);
refuses(fn() => $fullModel->saveRecipient(7, $b, ['name' => 'Não salvar', 'emission_profile' => ['tax' => ['iss_rate' => '10']]]), 'Perfil fiscal inválido impede a gravação do tomador');
check($fullModel->recipient($b, 7) === $beforeInvalidProfile, 'Falha do perfil não deixa alteração cadastral parcial');
$fullModel->saveRecipient(7, $b, ['emission_profile_present' => '1']);
check($fullModel->recipient($b, 7)['emission_profile'] === [] && $fullModel->recipient($b, 7)['name'] === 'TOMADOR B', 'Retornar à herança elimina overrides sem apagar identidade do tomador');
$inheritedB = EmissionProfile::resolve($fullModel->settings(), $fullModel->profile(7), $fullModel->recipient($b, 7));
check($inheritedB['service']['national_code'] === '010101' && $inheritedB['service']['description'] === 'Padrão fiscal do cliente' && $inheritedB['tax']['iss_rate'] === '3.00' && $inheritedB['service']['nbs'] === '111111111', 'Serviço antigo não reaparece após remover o perfil próprio do tomador');

$service = new Service($clientModel);
$service->saveDraft(1, ['service' => ['description' => 'Texto revisado para esta emissão']], $clientModel->document(1)['draft_hash'], false);
check($clientModel::decode($clientModel->document(1)['draft_json'])['service']['description'] === 'Texto revisado para esta emissão', 'Revisão da descrição no rascunho pode divergir do padrão sem alterar o cadastro');
refuses(fn() => $service->saveDraft(1, ['service' => ['national_code' => '999999']], $clientModel->document(1)['draft_hash'], false), 'Rascunho também rejeita código adulterado fora do catálogo');
$clientModel->currentInvoice->status = 5;
refuses(fn() => $service->saveDraft(1, [], $clientModel->document(1)['draft_hash'], false), 'Fatura cancelada não permite alterar o rascunho fiscal');
$clientModel->currentInvoice->status = 1; $clientModel->currentInvoice->clientid = 8;
refuses(fn() => $service->saveDraft(1, [], $clientModel->document(1)['draft_hash'], false), 'Mudança do cliente da fatura bloqueia a gravação do rascunho original');

$ibptModel = new DefaultsModel('ibpt_');
$ibptModel->configuration['tax']['total_tax_mode'] = 'ibpt';
$ibptModel->configuration['tax']['iss_rate'] = '3.00';
$ibptModel->configuration['service']['nbs'] = '115011000';
$ibptModel->configuration['provider']['state'] = 'SP';
$ibptCalls = [];
$ibptModel->ibptLookup = static function (string $uf, string $nbs, string $date) use (&$ibptCalls): array {
    $ibptCalls[] = [$uf, $nbs, $date];
    return ['uf' => $uf, 'nbs' => $nbs, 'description' => 'TESTE SINTÉTICO', 'nacionalfederal' => '13.4500', 'estadual' => '0.0000', 'municipal' => '5.0000',
        'valid_from' => $date, 'valid_to' => $date, 'chave' => 'TEST071', 'versao' => 'TEST.071', 'fonte' => 'IBPT/empresometro.com.br',
        'fetched_at' => gmdate('Y-m-d\TH:i:s\Z'), 'source_hash' => hash('sha256', 'service-defaults-fixture-ibpt')];
};
$ibptId = $ibptModel->prepare(41);
$ibptDraft = $ibptModel::decode($ibptModel->document($ibptId)['draft_json']);
check($ibptDraft['ibpt']['uf'] === 'SP' && $ibptDraft['ibpt']['nbs'] === '115011000' && count($ibptCalls) === 1, 'Preparar fatura consulta a tabela da empresa e persiste a fonte do serviço');
check($ibptDraft['values']['total_federal'] === '13.45' && $ibptDraft['values']['total_municipal'] === '5.00', 'Preparação persiste os valores aproximados derivados da fatura');
$ibptService = new Service($ibptModel, null, $ibptModel->ibptLookup);
$response = $ibptService->saveDraft($ibptId, ['ibpt' => ['chave' => 'ADULTERADA'], 'values' => ['total_federal' => '999.99', 'service_amount' => '252.00']], $ibptModel->document($ibptId)['draft_hash'], false);
$ibptSaved = $ibptModel::decode($ibptModel->document($ibptId)['draft_json']);
check($ibptSaved['ibpt']['chave'] === 'TEST071' && $ibptSaved['values']['total_federal'] === '33.89' && $ibptSaved['values']['total_municipal'] === '12.60', 'Salvar ignora chave enviada pelo navegador e substitui total manual pelo cálculo protegido');
check(count($ibptCalls) === 1 && $response['tax_summary']['iss']['amount'] === '7.56' && $response['tax_summary']['ibpt']['total_municipal'] === '12.60', 'Resposta de salvar atualiza ISSQN e IBPT separadamente sem nova consulta');
check($response['tax_summary']['inputs']['service_amount'] === '252.00' && !isset($response['draft_json'], $response['request_enc'], $response['response_enc']), 'Resumo público confirma a base revisada e preserva a fronteira privada do documento');
$prior = $ibptModel->document($ibptId);
refuses(fn() => $ibptService->saveDraft($ibptId, [], 'hash-antigo', false), 'Conflito de revisão impede reprocessar snapshot de outra janela');
check($ibptModel->document($ibptId)['draft_json'] === $prior['draft_json'], 'Conflito não altera snapshot ou totais já revisados');
$response = $ibptService->saveDraft($ibptId, ['service' => ['nbs' => '115012000']], $prior['draft_hash'], false);
check(count($ibptCalls) === 2 && $response['tax_summary']['ibpt']['snapshot']['nbs'] === '115012000', 'Revisão do NBS no rascunho obtém a classificação correta sem usar a anterior');
$outageService = new Service($ibptModel, null, static function (): array { throw new RuntimeException('Tabela temporariamente indisponível.'); });
$response = $outageService->saveDraft($ibptId, ['service' => ['nbs' => '115013000']], $ibptModel->document($ibptId)['draft_hash'], false);
check($response['status'] === 'draft' && $response['tax_summary']['ibpt'] === null && $response['tax_summary']['ibpt_error'] === 'Tabela temporariamente indisponível.', 'Falha da fonte salva rascunho pendente sem apresentar percentuais antigos');
check($ibptModel::decode($ibptModel->document($ibptId)['draft_json'])['service']['nbs'] === '115013000', 'Falha externa preserva a edição do operador');
refuses(fn() => $outageService->validate($ibptId), 'Validação exige cálculo IBPT confirmado antes de certificado, numeração ou transmissão');
$response = $outageService->saveDraft($ibptId, ['values' => ['total_tax_mode' => 'amount', 'total_federal' => '20.00']], $ibptModel->document($ibptId)['draft_hash'], false);
check($response['tax_summary']['ibpt'] === null && $response['tax_summary']['ibpt_error'] === '' && $ibptModel::decode($ibptModel->document($ibptId)['draft_json'])['values']['total_federal'] === '20.00', 'Revisão explícita para valores manuais remove IBPT e respeita os valores informados');

echo PHP_EOL . $checks . " verificações de catálogo, persistência e preenchimento aprovadas. Sem transmissão ou banco externo.\n";
