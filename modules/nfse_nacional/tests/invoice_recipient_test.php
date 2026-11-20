<?php
declare(strict_types=1);

// Invoice-specific recipient preferences, using the real model and an in-memory query adapter.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
class App_Model { public $db; }
require dirname(__DIR__) . '/models/Nfse_nacional_model.php';

function db_prefix(): string { return 'invoice_recipient_test_'; }
function nfse_nacional_can(string $capability): bool { return $GLOBALS['recipientPermissions'][$capability] ?? false; }
function nfse_nacional_scope(): string { return $GLOBALS['recipientScope'] ?? 'company_a'; }
function nfse_nacional_client_allowed(int $id): bool { return in_array($id, [7, 8], true); }
function get_staff_user_id(): int { return 9; }
function is_admin(): bool { return true; }
function admin_url(string $path = ''): string { return '/admin/' . $path; }
$checks = 0;
$recipientPermissions = array_fill_keys(['view', 'create', 'edit'], true);
function recipientCheck(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function recipientRefuses(callable $operation, string $label): void
{
    try { $operation(); } catch (InvalidArgumentException | RuntimeException $expected) { recipientCheck(true, $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}
final class RecipientTestResult
{
    public function __construct(private array $rows) {}
    public function row_array(): ?array { return $this->rows[0] ?? null; }
    public function result_array(): array { return $this->rows; }
}
final class RecipientTestDatabase
{
    public array $tables = [];
    private array $where = [];
    private int $lastId = 0;
    private ?int $maximum = null;
    public function where(string $column, $value): self
    {
        if (!preg_match('/^([a-z_]+)(?:\s+(!=|=))?$/D', $column, $match)) { throw new LogicException('Condição não suportada no teste.'); }
        $this->where[] = [$match[1], $match[2] ?? '=', $value]; return $this;
    }
    public function select(string $columns): self { return $this; }
    public function order_by(string $column, string $direction): self { return $this; }
    public function limit(int $limit): self { $this->maximum = $limit; return $this; }
    public function table_exists(string $table): bool { return array_key_exists($table, $this->tables); }
    private function matches(array $row): bool
    {
        foreach ($this->where as [$key, $operator, $value]) {
            $equal = (string) ($row[$key] ?? '') === (string) $value;
            if (($operator === '=' && !$equal) || ($operator === '!=' && $equal)) { return false; }
        }
        return true;
    }
    private function reset(): void { $this->where = []; $this->maximum = null; }
    public function get(string $table): RecipientTestResult
    {
        $rows = array_values(array_filter($this->tables[$table] ?? [], fn($row) => $this->matches($row)));
        if ($this->maximum !== null) { $rows = array_slice($rows, 0, $this->maximum); }
        $this->reset(); return new RecipientTestResult($rows);
    }
    public function insert(string $table, array $row): bool
    {
        $this->lastId = max(array_column($this->tables[$table] ?? [], 'id') ?: [0]) + 1;
        if (!isset($row['invoice_id']) || str_ends_with($table, '_documents')) { $row['id'] = $this->lastId; }
        $this->tables[$table][] = $row; return true;
    }
    public function insert_id(): int { return $this->lastId; }
    public function update(string $table, array $values): bool
    {
        foreach ($this->tables[$table] as &$row) { if ($this->matches($row)) { $row = array_replace($row, $values); } }
        unset($row); $this->reset(); return true;
    }
    public function delete(string $table): bool
    {
        $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? [], fn($row) => !$this->matches($row)));
        $this->reset(); return true;
    }
    public function query(string $sql, array $params): bool
    {
        if (!preg_match('/^INSERT INTO `([^`]+)`\s*\(([^)]+)\) VALUES/i', $sql, $match)) { throw new LogicException('SQL não previsto na fixture: ' . $sql); }
        $columns = array_map(static fn($column) => trim($column, " \t\n\r`"), explode(',', $match[2]));
        if (count($columns) !== count($params)) { throw new LogicException('Colunas e valores divergentes na fixture.'); }
        $row = array_combine($columns, $params); $table = $match[1];
        $key = str_ends_with($table, '_invoice_preferences') ? 'invoice_id' : 'client_id';
        $this->tables[$table] ??= [];
        foreach ($this->tables[$table] as &$existing) {
            if ((string) $existing[$key] === (string) $row[$key]) { $existing = array_replace($existing, $row); return true; }
        }
        unset($existing); $this->tables[$table][] = $row; return true;
    }
}
final class InvoiceRecipientTestModel extends Nfse_nacional_model
{
    public array $invoices = [];
    public array $events = [];
    private array $configuration;
    public function __construct(private string $company = 'company_a', ?RecipientTestDatabase $database = null)
    {
        $this->db = $database ?? new RecipientTestDatabase();
        $this->configuration = NfseNacional\Config::defaults();
        $this->configuration['provider']['municipality_code'] = '3550308';
        $this->configuration['service']['national_code'] = '010101';
        $this->configuration['service']['description'] = 'Serviço da empresa';
        foreach ([41, 42] as $id) {
            $this->invoices[$id] = (object) ['id' => $id, 'clientid' => 7, 'status' => 1, 'currency' => 1, 'date' => '2026-11-01',
                'subtotal' => '100.00', 'discount_total' => '0.00', 'total' => '100.00', 'items' => [['description' => 'Item da fatura ' . $id, 'long_description' => '']]];
        }
        foreach (['profiles', 'recipients', 'documents', 'invoice_preferences'] as $table) { $this->db->tables[$this->table($table)] ??= []; }
        $this->db->tables[db_prefix() . 'invoices'] = array_map(static fn($invoice) => (array) $invoice, array_values($this->invoices));
        $this->db->tables[db_prefix() . 'currencies'] = [['id' => 1, 'name' => 'BRL']];
        $this->db->tables[db_prefix() . 'contacts'] = [['userid' => 7, 'is_primary' => 1, 'active' => 1, 'firstname' => 'Contato', 'lastname' => 'Principal', 'email' => 'contato@example.invalid']];
    }
    public function table(string $suffix): string { return db_prefix() . $this->company . '_nfse_nacional_' . $suffix; }
    public function client(int $id): object
    {
        if (!in_array($id, [7, 8], true)) { throw new RuntimeException('Cliente fora do contexto autorizado.'); }
        return (object) ['userid' => $id, 'company' => 'CLIENTE ' . $id, 'vat' => $id === 7 ? '52998224725' : '12345678909', 'address' => 'Rua Exemplo', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01001000'];
    }
    public function invoice(int $id): object
    {
        if (!isset($this->invoices[$id])) { throw new RuntimeException('Fatura fora do contexto autorizado.'); }
        return $this->invoices[$id];
    }
    public function settings(): array { return $this->configuration; }
    public function lock(string $name, callable $action) { return $action(); }
    public function event(int $id, string $type, string $message): void { $this->events[] = [$id, $type]; }
}

$model = new InvoiceRecipientTestModel();
$model->saveProfile(7, ['source' => 'client', 'service_code' => '010201', 'description' => 'Padrão do cliente', 'contact_tax_id' => '11144477735']);
$data = ['label' => 'Dependente A', 'name' => 'TOMADOR A', 'tax_id' => '11144477735', 'email' => 'tomador@example.invalid', 'municipality_code' => '3550308', 'zip' => '01001000', 'street' => 'Rua Exemplo', 'number' => '10', 'district' => 'Centro', 'service_code' => '010301', 'description' => 'Prestação do tomador A'];
$own = $model->saveRecipient(7, 0, $data);
$foreign = $model->saveRecipient(8, 0, array_replace($data, ['label' => 'PRIVADO CLIENTE 8', 'name' => 'TOMADOR CLIENTE 8']));
$context = $model->invoiceRecipientContext(7, 41);
$values = array_column($context['options'], 'value');
recipientCheck($context['client_id'] === 7 && $context['invoice_id'] === 41 && $context['selection'] === '', 'fatura sem preferência recebe contexto e opção de herdar padrão');
recipientCheck(in_array('', $values, true) && in_array('client', $values, true) && in_array('primary_contact', $values, true) && in_array('recipient:' . $own, $values, true), 'opções incluem cadastro, contato e tomadores do cliente');
recipientCheck(!in_array('recipient:' . $foreign, $values, true) && !str_contains(json_encode($context), 'PRIVADO CLIENTE 8'), 'opções não expõem tomadores de outro cliente');
recipientRefuses(fn() => $model->validateInvoiceRecipientSelection(7, 'recipient:' . $foreign), 'servidor recusa tomador de outro cliente');
recipientRefuses(fn() => $model->validateInvoiceRecipientSelection(999, 'client'), 'cliente não autorizado é recusado');
foreach (['recipient:0', 'recipient:-1', 'recipient:1x', 'unknown'] as $invalid) {
    recipientRefuses(fn() => $model->validateInvoiceRecipientSelection(7, $invalid), 'seleção inválida recusada: ' . $invalid);
}
$selection = 'recipient:' . $own;
$model->saveInvoiceRecipientSelection(41, 7, $selection);
recipientCheck($model->invoiceRecipientContext(7, 41)['selection'] === $selection, 'preferência do tomador é persistida na fatura');
recipientCheck($model->invoiceRecipientContext(7, 42)['selection'] === '', 'preferência não altera outra fatura do mesmo cliente');
recipientRefuses(fn() => $model->saveInvoiceRecipientSelection(41, 8, 'client'), 'cliente esperado divergente impede gravação');
recipientCheck($model->invoiceRecipientContext(7, 41)['selection'] === $selection, 'tentativa inválida preserva a preferência anterior');
$changedContext = $model->invoiceRecipientContext(8, 41);
recipientCheck($changedContext['selection'] === '' && !in_array($selection, array_column($changedContext['options'], 'value'), true), 'troca de cliente em edição carrega novas opções sem reutilizar seleção antiga');
$model->syncInvoiceRecipientClient(41);
recipientCheck($model->invoiceRecipientContext(7, 41)['selection'] === $selection, 'sincronização sem alteração do cliente preserva preferência quando o campo está ausente');
$id = $model->prepare(41);
$document = $model->document($id); $draft = $model::decode($document['draft_json']);
recipientCheck($draft['recipient']['name'] === 'TOMADOR A' && $draft['recipient']['tax_id'] === $data['tax_id'], 'novo rascunho herda o tomador predefinido na fatura');
recipientCheck($draft['service']['national_code'] === '010301' && $draft['service']['description'] === $data['description'], 'serviço e descrição acompanham o tomador selecionado');
recipientCheck($document['invoice_id'] === 41 && $document['client_id'] === 7 && $draft['values']['service_amount'] === '100.00', 'seleção conserva cliente financeiro, fatura e valor');
$model->saveInvoiceRecipientSelection(41, 7, 'client');
recipientCheck($model->prepare(41) === $id && $model->document($id)['draft_json'] === $document['draft_json'] && $model->document($id)['draft_hash'] === $document['draft_hash'], 'nova preferência não sobrescreve nem duplica rascunho existente');
recipientCheck($model->prepare(41, $selection) === $id, 'novo clique com mesmo tomador reutiliza o documento existente');
recipientRefuses(fn() => $model->prepare(41, 'client'), 'novo clique com tomador diferente exige revisão explícita do rascunho');
$model->saveInvoiceRecipientSelection(42, 7, $selection);
$model->saveInvoiceRecipientSelection(42, 7, '');
recipientCheck($model->invoiceRecipientContext(7, 42)['selection'] === '', 'seleção vazia restaura herança do cadastro do cliente');
$defaultId = $model->prepare(42);
$defaultDraft = $model::decode($model->document($defaultId)['draft_json']);
recipientCheck($defaultDraft['recipient']['name'] === 'CLIENTE 7' && $defaultDraft['service']['description'] === 'Padrão do cliente', 'herança usa tomador e serviço padrão do cliente');

$changed = new InvoiceRecipientTestModel();
$changedRecipient = $changed->saveRecipient(7, 0, $data);
$changed->saveInvoiceRecipientSelection(42, 7, 'recipient:' . $changedRecipient);
$changed->invoices[42]->clientid = 8;
$changed->db->where('id', 42)->update(db_prefix() . 'invoices', ['clientid' => 8]);
$changed->syncInvoiceRecipientClient(42);
recipientCheck($changed->invoiceRecipientContext(8, 42)['selection'] === '', 'trocar cliente da fatura remove preferência do cliente anterior');
$newId = $changed->prepare(42);
recipientCheck($changed::decode($changed->document($newId)['draft_json'])['recipient']['name'] === 'CLIENTE 8', 'emissão após troca usa somente dados do novo cliente');

$contactModel = new InvoiceRecipientTestModel();
$contactModel->saveProfile(7, ['source' => 'client', 'contact_tax_id' => '11144477735']);
$contactModel->saveInvoiceRecipientSelection(41, 7, 'primary_contact');
$contactId = $contactModel->prepare(41);
$contactDraft = $contactModel::decode($contactModel->document($contactId)['draft_json']);
recipientCheck($contactDraft['recipient']['name'] === 'Contato Principal' && $contactDraft['recipient']['tax_id'] === '11144477735', 'contato principal selecionado usa sua identidade sem substituir pelo CPF do cliente');
recipientRefuses(fn() => $contactModel->validateInvoiceRecipientSelection(8, 'primary_contact'), 'contato principal inexistente não é aceito como tomador');
$recipientPermissions['create'] = false; $recipientPermissions['edit'] = false;
recipientRefuses(fn() => $contactModel->saveInvoiceRecipientSelection(42, 7, 'client'), 'leitura não permite salvar preferência de tomador');
recipientCheck($contactModel->invoiceRecipientContext(7, 42)['selection'] === '', 'negação de alteração preserva a preferência anterior');
$recipientPermissions['create'] = true; $recipientPermissions['edit'] = true;

$switch = new InvoiceRecipientTestModel();
$switch->saveProfile(7, ['source' => 'client', 'contact_tax_id' => '11144477735', 'service_code' => '010201', 'description' => 'Padrão do cliente']);
$switchRecipient = $switch->saveRecipient(7, 0, $data);
$switchForeign = $switch->saveRecipient(8, 0, array_replace($data, ['name' => 'Outro cliente']));
$switchId = $switch->prepare(41, 'client');
$service = new NfseNacional\Service($switch);
$service->saveDraft($switchId, [], $switch->document($switchId)['draft_hash'], false, null, 'recipient:' . $switchRecipient);
$switched = $switch::decode($switch->document($switchId)['draft_json']);
recipientCheck($switched['recipient_selection'] === 'recipient:' . $switchRecipient && $switched['recipient']['name'] === 'TOMADOR A' && $switched['service']['national_code'] === '010301' && $switched['service']['description'] === $data['description'], 'troca explícita no rascunho aplica identidade e padrões do tomador no servidor');
$service->saveDraft($switchId, [], $switch->document($switchId)['draft_hash'], false, null, '');
recipientCheck($switch::decode($switch->document($switchId)['draft_json'])['recipient'] === $switched['recipient'], 'seleção vazia ao salvar rascunho preserva o snapshot revisado');
$service->saveDraft($switchId, [], $switch->document($switchId)['draft_hash'], false, null, 'primary_contact');
$switched = $switch::decode($switch->document($switchId)['draft_json']);
recipientCheck($switched['recipient_selection'] === 'primary_contact' && $switched['recipient']['name'] === 'Contato Principal' && $switched['service']['description'] === 'Padrão do cliente', 'troca para contato principal no rascunho recupera padrões do perfil');
$service->saveDraft($switchId, [], $switch->document($switchId)['draft_hash'], false, null, 'client');
$switched = $switch::decode($switch->document($switchId)['draft_json']);
recipientCheck($switched['recipient']['name'] === 'CLIENTE 7' && $switched['service']['national_code'] === '010201', 'troca para próprio cliente no rascunho aplica seus dados e serviço');
$beforeInvalid = $switch->document($switchId);
recipientRefuses(fn() => $service->saveDraft($switchId, [], $beforeInvalid['draft_hash'], false, null, 'recipient:' . $switchForeign), 'rascunho recusa seleção explícita de tomador de outro cliente');
recipientCheck($switch->document($switchId)['draft_json'] === $beforeInvalid['draft_json'] && $switch->profile(7)['source'] === 'client', 'tentativa inválida não altera rascunho nem o padrão compartilhado do cliente');

$cloneModel = new InvoiceRecipientTestModel();
$fullProfile = [
    'service' => ['municipality_code' => '3304557', 'national_code' => '010301', 'municipal_code' => '123', 'nbs' => '123456789', 'description' => 'Prestação específica do tomador'],
    'tax' => ['iss_taxation' => '1', 'retention' => '2', 'iss_rate' => '2.50', 'total_tax_mode' => 'amount', 'total_federal' => '1.00', 'total_state' => '2.00', 'total_municipal' => '3.00', 'simple_rate' => ''],
    'ibscbs' => ['enabled' => '1', 'operation_indicator' => '100301', 'destination_indicator' => '0', 'cst' => '000', 'class_code' => '000001', 'final_consumer' => '0', 'purpose' => '0'],
];
$originalId = $cloneModel->saveRecipient(7, 0, array_replace($data, ['emission_profile_present' => '1', 'emission_profile' => $fullProfile]));
$cloneModel->saveProfile(7, ['source' => 'recipient', 'recipient_id' => (string) $originalId]);
$cloneModel->saveInvoiceRecipientSelection(41, 7, 'recipient:' . $originalId);
$originalDocumentId = $cloneModel->prepare(41);
$originalDocument = $cloneModel->document($originalDocumentId);
$originalDraft = $cloneModel::decode($originalDocument['draft_json']);
recipientCheck($originalDraft['service'] === $fullProfile['service'] && array_intersect_key($originalDraft['values'], $fullProfile['tax']) === $fullProfile['tax'] && $originalDraft['ibscbs'] === $fullProfile['ibscbs'], 'preferência da fatura aplica todo o perfil fiscal do tomador ao novo rascunho');
recipientCheck($originalDraft['values']['service_amount'] === '100.00' && $originalDraft['values']['unconditional_discount'] === '0.00' && $originalDraft['competence'] === '2026-11-01' && $originalDraft['provider'] === $cloneModel->settings()['provider'], 'perfil completo preserva cobrança, competência e emitente da fatura');
$originalRecord = $cloneModel->db->tables[$cloneModel->table('recipients')][0]['data_json'];
$cloneInput = array_replace($cloneModel->recipient($originalId, 7), [
    'label' => 'Cópia editável', 'emission_profile_present' => '1',
    'id' => $originalId, 'client_id' => 8, 'invoice_id' => 42, 'document_id' => $originalDocumentId,
    'draft_json' => 'não copiar', 'recipient_selection' => 'recipient:' . $originalId,
]);
$copyId = $cloneModel->saveRecipient(7, 0, $cloneInput);
$copy = $cloneModel->recipient($copyId, 7);
recipientCheck($copyId !== $originalId && $copy['emission_profile'] === $fullProfile && $copy['name'] === $data['name'], 'salvar cópia cria novo ID com cadastro e perfil fiscal completos');
recipientCheck(!array_intersect(['id', 'client_id', 'invoice_id', 'document_id', 'draft_json', 'recipient_selection'], array_keys($copy)), 'criação ignora vínculos e identificadores forjados no payload do clone');
recipientCheck($cloneModel->db->tables[$cloneModel->table('recipients')][0]['data_json'] === $originalRecord, 'criação da cópia mantém o registro original intacto');
recipientCheck($cloneModel->profile(7)['recipient_id'] === (string) $originalId && $cloneModel->invoiceRecipientContext(7, 41)['selection'] === 'recipient:' . $originalId && $cloneModel->invoiceRecipientContext(7, 42)['selection'] === '', 'clone não transfere seleção padrão do cliente ou preferências das faturas');
recipientCheck(count($cloneModel->db->tables[$cloneModel->table('documents')]) === 1 && $cloneModel->document($originalDocumentId)['draft_json'] === $originalDocument['draft_json'], 'clonagem não duplica documentos nem altera histórico de emissão');
$copyInput = array_replace($cloneInput, ['name' => 'TOMADOR CÓPIA']);
$copyInput['emission_profile']['tax']['iss_rate'] = '0.00';
$cloneModel->saveRecipient(7, $copyId, $copyInput);
recipientCheck($cloneModel->recipient($copyId, 7)['emission_profile']['tax']['iss_rate'] === '0.00' && $cloneModel->recipient($originalId, 7)['emission_profile']['tax']['iss_rate'] === '2.50' && $cloneModel->recipient($originalId, 7)['name'] === $data['name'], 'edição do clone preserva zero próprio sem modificar nome ou tributos do original');
recipientRefuses(fn() => $cloneModel->recipient($copyId, 8), 'ID do clone não permite consultar cadastro de outro cliente');
$cloneModel->deleteRecipient(7, $copyId);
recipientCheck(count($cloneModel->recipients(7)) === 1 && $cloneModel->recipient($originalId, 7)['emission_profile'] === $fullProfile && $cloneModel->document($originalDocumentId)['draft_hash'] === $originalDocument['draft_hash'], 'excluir cópia sem vínculo preserva tomador original e documento histórico');

$isolated = new InvoiceRecipientTestModel('company_b', $model->db);
$GLOBALS['recipientScope'] = 'company_b';
recipientCheck($isolated->invoiceRecipientContext(7, 41)['selection'] === '', 'mesmo número de fatura em outra empresa não herda preferência fiscal');
recipientCheck($model->document($id)['draft_json'] === $document['draft_json'], 'operações em outra empresa preservam o histórico fiscal existente');
echo 'OK: ' . $checks . " verificações de tomador por fatura, isolamento e preservação de rascunhos.\n";
