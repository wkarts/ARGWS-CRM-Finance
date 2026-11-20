<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/autoload.php';
class App_Model { public $db; }
require_once dirname(__DIR__) . '/models/Nfse_nacional_model.php';

$invoicePermissions = array_fill_keys(['view', 'create', 'edit', 'transmit', 'send', 'settings'], true);
function nfse_nacional_can(string $capability): bool { global $invoicePermissions; return $invoicePermissions[$capability] ?? false; }
function admin_url(string $path): string { return '/admin/' . $path; }
function db_prefix(): string { return 'invoice_test_'; }
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FALHOU: ' . $label); } ++$checks; echo 'OK: ' . $label . PHP_EOL; }
function refuses(callable $operation, string $label): void {
    try { $operation(); } catch (RuntimeException $error) { check(true, $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}

final class InvoiceQueryResult {
    public function __construct(private array $rows) {}
    public function row_array(): ?array { return $this->rows[0] ?? null; }
    public function result_array(): array { return $this->rows; }
}

/** Only the query-builder operations used to read fiscal links; no real database. */
final class InvoiceMemoryDatabase {
    public int $reads = 0;
    public int $inserts = 0;
    private array $conditions = [];
    private ?array $columns = null;
    private ?array $order = null;
    private ?int $maximum = null;
    public function __construct(public array $rows) {}
    public function select(string $columns): self { $this->columns = array_map('trim', explode(',', $columns)); return $this; }
    public function where(string $column, $value): self {
        if (!preg_match('/^([a-z_]+)(?:\s+(!=|=))?$/D', $column, $matches)) { throw new LogicException('Condição SQL não suportada no teste.'); }
        $this->conditions[] = [$matches[1], $matches[2] ?? '=', $value]; return $this;
    }
    public function order_by(string $column, string $direction): self { $this->order = [$column, strtolower($direction)]; return $this; }
    public function limit(int $maximum): self { $this->maximum = $maximum; return $this; }
    public function get(string $table): InvoiceQueryResult {
        if ($table !== 'invoice_test_nfse_nacional_documents') { throw new LogicException('Tabela fora do escopo fiscal do teste.'); }
        ++$this->reads;
        $rows = array_values(array_filter($this->rows, function (array $row): bool {
            foreach ($this->conditions as [$column, $operator, $value]) {
                $equal = (string) ($row[$column] ?? '') === (string) $value;
                if (($operator === '=' && !$equal) || ($operator === '!=' && $equal)) { return false; }
            }
            return true;
        }));
        if ($this->order !== null) {
            [$column, $direction] = $this->order;
            usort($rows, static fn($left, $right) => (($left[$column] ?? '') <=> ($right[$column] ?? '')) * ($direction === 'desc' ? -1 : 1));
        }
        if ($this->maximum !== null) { $rows = array_slice($rows, 0, $this->maximum); }
        if ($this->columns !== null) {
            $columns = array_flip($this->columns);
            $rows = array_map(static fn($row) => array_intersect_key($row, $columns), $rows);
        }
        $this->conditions = []; $this->columns = null; $this->order = null; $this->maximum = null;
        return new InvoiceQueryResult($rows);
    }
    public function insert(string $table, array $row): bool { ++$this->inserts; throw new LogicException('Estes cenários não podem criar outro documento.'); }
}

final class InvoiceContextModel extends Nfse_nacional_model {
    public object $currentInvoice;
    public array $configuration;
    public int $validations = 0;
    public bool $invoiceAllowed = true;
    public function __construct(array $rows, string $environment = '2') {
        $this->db = new InvoiceMemoryDatabase($rows);
        $this->configuration = NfseNacional\Config::defaults(); $this->configuration['environment'] = $environment;
        $this->currentInvoice = (object) ['id' => 41, 'clientid' => 7, 'status' => 1];
    }
    public function table(string $suffix): string { return db_prefix() . 'nfse_nacional_' . $suffix; }
    public function invoice(int $id): object {
        if ($id !== 41 || !$this->invoiceAllowed) { throw new RuntimeException('Fatura não autorizada.'); }
        return $this->currentInvoice;
    }
    public function client(int $id): object {
        if ($id !== 7) { throw new RuntimeException('Cliente não autorizado.'); }
        return (object) ['userid' => 7, 'company' => 'Cliente atual'];
    }
    public function settings(): array { return $this->configuration; }
    public function lock(string $name, callable $operation) { return $operation(); }
    public function assertInvoice(object $invoice): void { ++$this->validations; }
}

$base = ['invoice_id' => 41, 'client_id' => 7, 'nfse_number' => null, 'access_key' => null, 'last_error' => null, 'email_status' => 'pending', 'created_at' => '2026-11-10 12:00:00'];
$testDraft = $base + ['id' => 10, 'environment' => '2', 'status' => 'draft'];
$production = array_replace($base, ['id' => 20, 'environment' => '1', 'status' => 'authorized', 'nfse_number' => '100', 'access_key' => str_repeat('1', 50)]);
$foreign = array_replace($base, ['id' => 30, 'client_id' => 99, 'environment' => '1', 'status' => 'authorized', 'nfse_number' => 'SECRET_OLD_NUMBER',
    'access_key' => str_repeat('9', 50), 'last_error' => 'PRIVATE_OLD_CLIENT_NAME', 'client_name' => 'PRIVATE_OLD_CLIENT_NAME']);

$model = new InvoiceContextModel([$testDraft, $production]);
$context = $model->invoiceContext(41);
check($context['history_conflict'] === false && count($context['documents']) === 2, 'Sem conflito, o painel preserva os documentos dos dois ambientes');
check($context['current_document']['id'] === 10 && $context['can']['create'] && $context['can']['transmit'], 'Rascunho corrente é identificado sem retirar as permissões válidas');
check($model->prepare(41) === 10 && $model->db->inserts === 0, 'Preparar novamente devolve o mesmo rascunho no ambiente corrente');
check($model->db->rows === [$testDraft, $production] && $model->validations === 0, 'Reaproveitar rascunho não modifica arquivos fiscais nem prepara outra emissão');

$foreignOnly = new InvoiceContextModel([$foreign], '2');
refuses(fn() => $foreignOnly->prepare(41), 'Histórico de outro cliente em produção bloqueia preparação em testes');
check($foreignOnly->db->inserts === 0 && $foreignOnly->validations === 0, 'Conflito bloqueia antes de reservar ou criar um documento');
$conflict = $foreignOnly->invoiceContext(41);
check($conflict['history_conflict'] === true && $conflict['documents'] === [] && $conflict['current_document'] === null, 'Painel sinaliza conflito sem devolver a nota do cliente anterior');
check(!$conflict['can']['create'] && !$conflict['can']['transmit'] && $conflict['can']['view'], 'Conflito impede preparação e transmissão sem impedir consulta autorizada');
$serialized = json_encode($conflict, JSON_THROW_ON_ERROR);
check(!str_contains($serialized, 'PRIVATE_OLD_CLIENT_NAME') && !str_contains($serialized, 'SECRET_OLD_NUMBER') && !str_contains($serialized, str_repeat('9', 50)), 'Resposta não expõe nome, erro, número ou chave do cliente anterior');

$mixed = new InvoiceContextModel([$testDraft, $foreign]);
refuses(fn() => $mixed->prepare(41), 'Conflito em qualquer ambiente prevalece sobre um rascunho corrente existente');
$mixedContext = $mixed->invoiceContext(41);
check($mixedContext['history_conflict'] && array_column($mixedContext['documents'], 'id') === [10]
    && $mixedContext['current_document']['id'] === 10 && !$mixedContext['can']['create'] && !$mixedContext['can']['transmit'], 'Histórico misto preserva somente o rascunho do cliente atual e bloqueia emissão');

$foreignTest = array_replace($foreign, ['environment' => '2']);
$productionContext = new InvoiceContextModel([$foreignTest], '1');
refuses(fn() => $productionContext->prepare(41), 'Histórico de outro cliente em testes também bloqueia preparação em produção');
$unrelated = new InvoiceContextModel([$testDraft, array_replace($foreign, ['invoice_id' => 55])]);
check(!$unrelated->invoiceContext(41)['history_conflict'] && $unrelated->prepare(41) === 10, 'Histórico de outra fatura não contamina a fatura consultada');

$hidden = new InvoiceContextModel([$testDraft]); $hidden->invoiceAllowed = false;
refuses(fn() => $hidden->invoiceContext(41), 'Painel respeita a autorização nativa da fatura antes de consultar o histórico');
check($hidden->db->reads === 0, 'Fatura não autorizada não provoca consulta às notas');
$invoicePermissions['view'] = false;
$noFiscalAccess = new InvoiceContextModel([$testDraft]);
refuses(fn() => $noFiscalAccess->invoiceContext(41), 'Permissão nativa da fatura não substitui a permissão fiscal de visualização');
check($noFiscalAccess->db->reads === 0, 'Acesso fiscal negado não consulta o histórico');
echo PHP_EOL . $checks . " verificações aprovadas. Model real, dependências de leitura simuladas; sem rede ou banco real.\n";
