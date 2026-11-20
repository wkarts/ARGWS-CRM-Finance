<?php
declare(strict_types=1);

/**
 * Offline controller boundary test. Controller, Service, model permission checks,
 * fiscal status and PDF renderer are real; only the CRM runtime/database are in memory.
 * Native session middleware and verification of the submitted CSRF token are outside
 * this fixture; the module's method, capability and CSRF-enabled gates are exercised.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');

final class PreviewBoundaryInput
{
    public string $verb = 'GET';
    public array $query = [];
    public array $posted = [];
    public function method(): string { return strtolower($this->verb); }
    public function get(string $name) { return $this->query[$name] ?? null; }
    public function post(string $name, bool $filter = true) { return $this->posted[$name] ?? null; }
}
final class PreviewBoundaryOutput
{
    public int $status = 200;
    public string $body = '';
    public array $headers = [];
    public bool $profiler = true;
    public function enable_profiler(bool $enabled): self { $this->profiler = $enabled; return $this; }
    public function set_status_header(int $status): self { $this->status = $status; return $this; }
    public function set_header(string $header): self
    {
        [$name, $value] = explode(':', $header, 2);
        $this->headers[strtolower(trim($name))] = trim($value);
        return $this;
    }
    public function set_content_type(string $mime, string $charset = ''): self
    {
        return $this->set_header('Content-Type: ' . $mime . ($charset === '' ? '' : '; charset=' . $charset));
    }
    public function set_output(string $body): self { $this->body = $body; return $this; }
}
final class PreviewBoundaryConfig
{
    public bool $csrf = true;
    public function item(string $name) { return $name === 'csrf_protection' ? $this->csrf : null; }
}
final class PreviewBoundarySecurity
{
    public function get_csrf_token_name(): string { return 'csrf_preview_fixture'; }
    public function get_csrf_hash(): string { return 'token-offline'; }
}
final class PreviewBoundaryResult
{
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function row_array(): array { return $this->rows[0] ?? []; }
}
final class PreviewBoundaryDb
{
    public string $database = 'preview_fixture';
    public string $hostname = 'localhost.invalid';
    public bool $save_queries = true;
    public bool $db_debug = true;
    public bool $failDocuments = false;
    public array $rows = [];
    public array $queries = [];
    public array $writes = [];
    private array $where = [];
    public function select(string $fields): self { return $this; }
    public function limit(int $count): self { return $this; }
    public function where(string $field, $value): self { $this->where[$field] = $value; return $this; }
    public function table_exists(string $table): bool { return array_key_exists($table, $this->rows); }
    public function get(string $table): PreviewBoundaryResult
    {
        $filters = $this->where; $this->where = [];
        $this->queries[] = [$table, $filters];
        if ($this->failDocuments && str_ends_with($table, 'nfse_nacional_documents')) { throw new RuntimeException('Banco temporariamente indisponível.'); }
        if (!array_key_exists($table, $this->rows)) { throw new LogicException('Unexpected fixture table: ' . $table); }
        $rows = array_values(array_filter($this->rows[$table], static function (array $row) use ($filters): bool {
            foreach ($filters as $key => $value) { if (!array_key_exists($key, $row) || (string) $row[$key] !== (string) $value) { return false; } }
            return true;
        }));
        return new PreviewBoundaryResult($rows);
    }
    public function __call(string $name, array $arguments)
    {
        $this->writes[] = [$name, $arguments];
        throw new LogicException('A preview must not write or start a transaction: ' . $name);
    }
}
class App_Model
{
    public PreviewBoundaryDb $db;
    public function __construct() { $this->db = get_instance()->db; }
}
final class PreviewBoundaryLoader
{
    private object $controller;
    public function __construct(object $controller) { $this->controller = $controller; }
    public function model(string $name): void
    {
        if ($name !== 'nfse_nacional/nfse_nacional_model') { throw new LogicException('Unexpected model loader: ' . $name); }
        $this->controller->nfse_nacional_model = new PreviewBoundaryModel();
    }
}
class AdminController extends stdClass
{
    public PreviewBoundaryInput $input;
    public PreviewBoundaryOutput $output;
    public PreviewBoundaryConfig $config;
    public PreviewBoundarySecurity $security;
    public PreviewBoundaryLoader $load;
    public function __construct()
    {
        $this->input = new PreviewBoundaryInput(); $this->output = new PreviewBoundaryOutput();
        $this->config = new PreviewBoundaryConfig(); $this->security = new PreviewBoundarySecurity();
        $this->load = new PreviewBoundaryLoader($this);
    }
}
final class PreviewBoundaryHttpError extends RuntimeException {}
function show_error($message, $status = 500, $heading = ''): void { throw new PreviewBoundaryHttpError((string) $message, (int) $status); }
function show_404($page = '', $logError = true): void { throw new PreviewBoundaryHttpError('Não encontrado.', 404); }
function access_denied($feature = ''): void { throw new PreviewBoundaryHttpError('Acesso negado.', 403); }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$previewState = ['logged' => true, 'permissions' => ['view'], 'native' => ['customers', 'invoices'], 'prefix' => 'company_a_', 'api_calls' => 0];
$previewApp = (object) ['db' => new PreviewBoundaryDb()];
function is_staff_logged_in(): bool { return $GLOBALS['previewState']['logged']; }
function is_admin(): bool { return false; }
function get_staff_user_id(): int { return 19; }
function db_prefix(): string { return $GLOBALS['previewState']['prefix']; }
function staff_can(string $action, string $feature): bool
{
    if ($feature === 'nfse_nacional') { return in_array($action, $GLOBALS['previewState']['permissions'], true); }
    return $action === 'view' && in_array($feature, $GLOBALS['previewState']['native'], true);
}
function get_option(string $name) { throw new LogicException('Preview must not read current issuer, certificate or mail configuration.'); }
function admin_url(string $path = ''): string { return 'https://crm.example.invalid/empresa-a/admin/' . $path; }
function log_message($level, $message): void {}
function &get_instance() { return $GLOBALS['previewApp']; }

require_once dirname(__DIR__) . '/controllers/Nfse_nacional.php';
require_once dirname(__DIR__) . '/models/Nfse_nacional_model.php';

use NfseNacional\Crypto;
use NfseNacional\OperationalLog;
use NfseNacional\Service;

final class PreviewBoundaryModel extends Nfse_nacional_model
{
    public array $documentIds = [];
    public array $forbiddenCalls = [];
    public function document(int $id): array { $this->documentIds[] = $id; return parent::document($id); }
    private function forbidden(string $method): void { $this->forbiddenCalls[] = $method; throw new LogicException('Preview used forbidden method: ' . $method); }
    public function crypto(): Crypto { $this->forbidden(__FUNCTION__); }
    public function settings(): array { $this->forbidden(__FUNCTION__); }
    public function settingsRow(): array { $this->forbidden(__FUNCTION__); }
    public function reserve(array $document, array $draft, array $settings): array { $this->forbidden(__FUNCTION__); }
    public function updateDocument(int $id, array $changes): void { $this->forbidden(__FUNCTION__); }
    public function event(int $id, string $type, string $message): void { $this->forbidden(__FUNCTION__); }
    public function lock(string $name, callable $operation) { $this->forbidden(__FUNCTION__); }
}

$previewChecks = 0;
function previewBoundaryCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['previewChecks'];
}
function previewBoundarySet(Nfse_nacional $controller, string $property, $value): void
{
    (new ReflectionProperty(Nfse_nacional::class, $property))->setValue($controller, $value);
}
function previewBoundaryController(?OperationalLog $store = null, string $verb = 'GET'): Nfse_nacional
{
    // Exercise the real constructor, including its company prefix validation and cache policy.
    $controller = new Nfse_nacional();
    $controller->input->verb = $verb;
    previewBoundarySet($controller, 'service', new Service($controller->nfse_nacional_model, static function () {
        ++$GLOBALS['previewState']['api_calls'];
        throw new LogicException('Preview must not instantiate a remote fiscal API client.');
    }));
    previewBoundarySet($controller, 'logFactory', $store === null ? null : static fn(): OperationalLog => $store);
    return $controller;
}
function previewBoundaryRoute(Nfse_nacional $controller, string $route, ...$arguments): void
{
    try { $controller->{$route}(...$arguments); }
    catch (PreviewBoundaryHttpError $error) { $controller->output->set_status_header($error->getCode()); }
}
function previewBoundaryDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) { if ($name !== '.' && $name !== '..') { previewBoundaryDelete($path . '/' . $name); } }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) { unlink($path); }
}

$directory = sys_get_temp_dir() . '/nfse_preview_controller_' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) { throw new RuntimeException('Unable to create isolated log fixture.'); }
register_shutdown_function(static function () use ($directory): void { previewBoundaryDelete($directory); });
$store = new OperationalLog($directory, str_repeat('a', 64), 19, static fn(): int => 1794312000);
$draft = ['environment' => '2', 'competence' => '', 'provider' => ['name' => 'PRIVATE_PREVIEW_PROVIDER', 'tax_id' => '11222333000181'],
    'recipient' => ['name' => 'PRIVATE_PREVIEW_RECIPIENT', 'email' => 'private@example.invalid'],
    'service' => ['description' => 'PRIVATE_PREVIEW_SERVICE'], 'values' => ['service_amount' => '10.00']];
$doc = ['id' => 8, 'client_id' => 12, 'invoice_id' => 51, 'environment' => '2', 'status' => 'draft', 'attempts' => 0,
    'draft_json' => Nfse_nacional_model::json($draft), 'draft_hash' => str_repeat('a', 64), 'dps_id' => null,
    'response_enc' => null, 'request_enc' => null, 'nfse_number' => null, 'access_key' => null, 'fiscal_meta_json' => null];
$previewApp->db->rows = [
    'company_a_nfse_nacional_documents' => [$doc],
    'company_a_clients' => [['userid' => 12]],
    'company_a_invoices' => [['id' => 51, 'clientid' => 12]],
    'company_b_nfse_nacional_documents' => [],
    'company_b_clients' => [],
    'company_b_invoices' => [],
];
$databaseSnapshot = $previewApp->db->rows;

$preview = previewBoundaryController($store);
$preview->input->query = ['company' => 'company_b_', 'company_scope' => str_repeat('b', 64), 'file' => '../../etc/passwd', 'draft' => ['provider' => ['name' => 'ATTACKER_NAME']]];
$preview->config->csrf = false; // Read-only access does not require a mutation token or certificate.
previewBoundaryRoute($preview, 'preview', '8');
previewBoundaryCheck($preview->output->status === 200 && str_starts_with($preview->output->body, '%PDF-'), 'GET com permissão apenas de leitura gera PDF real de rascunho incompleto');
previewBoundaryCheck($preview->output->headers['content-type'] === 'application/pdf'
    && $preview->output->headers['content-disposition'] === 'inline; filename="danfse-8.pdf"'
    && str_contains($preview->output->headers['cache-control'], 'no-store')
    && $preview->output->headers['pragma'] === 'no-cache'
    && $preview->output->headers['x-content-type-options'] === 'nosniff'
    && $preview->output->headers['x-frame-options'] === 'SAMEORIGIN'
    && !$preview->output->profiler, 'prévia entrega PDF inline sem cache, sniffing, frame externo ou profiler');
previewBoundaryCheck($preview->nfse_nacional_model->documentIds === [8, 8], 'controller e Service conferem o mesmo identificador pelo modelo autorizado');
previewBoundaryCheck($preview->nfse_nacional_model->forbiddenCalls === [] && $previewState['api_calls'] === 0
    && $previewApp->db->writes === [] && $previewApp->db->rows === $databaseSnapshot, 'prévia não lê certificado/configuração, reserva DPS, grava, envia ou altera dados');
foreach ($previewApp->db->queries as [$table, $filters]) {
    previewBoundaryCheck(str_starts_with($table, 'company_a_'), 'parâmetros da query não selecionam banco ou empresa de origem');
}
$rows = $store->report(['operation' => 'document.preview'], 1, 100)['rows'];
previewBoundaryCheck(count($rows) === 2 && $rows[0]['correlation_id'] === $rows[1]['correlation_id'], 'prévia registra início e término com uma correlação operacional');
$finish = array_values(array_filter($rows, static fn(array $row): bool => ($row['context']['stage'] ?? '') === 'finish'))[0] ?? [];
previewBoundaryCheck(($finish['context']['operation_status'] ?? '') === 'success' && ($finish['context']['http_status'] ?? 0) === 200
    && ($finish['context']['response_bytes'] ?? 0) === strlen($preview->output->body)
    && ($finish['document_id'] ?? 0) === 8 && ($finish['invoice_id'] ?? 0) === 51, 'log conserva resultado, tamanho, documento e fatura sem copiar o PDF');
$serializedLog = json_encode($rows);
foreach (['PRIVATE_PREVIEW_PROVIDER', 'PRIVATE_PREVIEW_RECIPIENT', 'PRIVATE_PREVIEW_SERVICE', '11222333000181', 'private@example.invalid', '%PDF-', 'ATTACKER_NAME', 'draft_json'] as $private) {
    previewBoundaryCheck(!str_contains($serializedLog, $private), 'log operacional não contém dado fiscal ou conteúdo privado: ' . $private);
}

foreach (['POST', 'PUT', 'DELETE', 'HEAD'] as $verb) {
    $controller = previewBoundaryController($store, $verb);
    $beforeLog = $store->report()['total'];
    previewBoundaryRoute($controller, 'preview', '8');
    previewBoundaryCheck($controller->output->status === 405 && $controller->output->body === ''
        && $controller->nfse_nacional_model->documentIds === [] && $store->report()['total'] === $beforeLog,
        'prévia recusa ' . $verb . ' antes de consultar documento ou registrar operação executada');
}
foreach ([['logged' => false, 'permissions' => ['view']], ['logged' => true, 'permissions' => []], ['logged' => true, 'permissions' => ['transmit']]] as $access) {
    $previewState = array_replace($previewState, $access);
    $controller = previewBoundaryController($store);
    previewBoundaryRoute($controller, 'preview', '8');
    previewBoundaryCheck($controller->output->status === 403 && $controller->output->body === '' && $controller->nfse_nacional_model->documentIds === [], 'sessão ou permissão fiscal view ausente bloqueia antes de acessar a nota');
}
$previewState['logged'] = true; $previewState['permissions'] = ['view'];

foreach (['0', '-1', '../8', '8 OR 1=1', '8.0', '8e1', str_repeat('1', 13), ['8'], ''] as $invalid) {
    $controller = previewBoundaryController($store);
    previewBoundaryRoute($controller, 'preview', $invalid);
    previewBoundaryCheck($controller->output->status === 422 && $controller->output->body === '' && $controller->nfse_nacional_model->documentIds === [], 'identificador malformado não chega ao modelo nem cria PDF');
}

foreach (['missing', 'customer', 'invoice', 'database'] as $reason) {
    $previewState['native'] = ['customers', 'invoices'];
    $previewApp->db->failDocuments = $reason === 'database';
    if ($reason === 'customer') { $previewState['native'] = ['invoices']; }
    if ($reason === 'invoice') { $previewApp->db->rows['company_a_invoices'] = []; }
    $controller = previewBoundaryController($store);
    previewBoundaryRoute($controller, 'preview', $reason === 'missing' ? '99' : '8');
    previewBoundaryCheck($controller->output->status === 422 && $controller->output->body === '' && count($controller->nfse_nacional_model->documentIds) === 1
        && !isset($controller->output->headers['content-disposition']) && $controller->nfse_nacional_model->forbiddenCalls === [],
        'falha ' . $reason . ' não retorna PDF nem contorna a autorização do modelo');
    $previewApp->db->rows = $databaseSnapshot;
}
$previewState['native'] = ['customers', 'invoices']; $previewApp->db->failDocuments = false;

$previewState['prefix'] = 'company_b_';
$other = previewBoundaryController($store);
$other->input->query = ['company' => 'company_a_', 'company_scope' => str_repeat('a', 64)];
previewBoundaryRoute($other, 'preview', '8');
previewBoundaryCheck($other->output->status === 422 && $other->output->body === '' && $other->nfse_nacional_model->documentIds === [8], 'mesmo ID de outra empresa não pode ser recuperado por troca de query');

foreach (['bad-prefix', '../company_a_', 'company_a_;DROP', str_repeat('x', 41)] as $invalidPrefix) {
    $previewState['prefix'] = $invalidPrefix;
    $beforeQueries = count($previewApp->db->queries);
    $failed = false;
    try { previewBoundaryController($store); }
    catch (RuntimeException $error) { $failed = true; }
    previewBoundaryCheck($failed && count($previewApp->db->queries) === $beforeQueries && $previewApp->db->writes === [], 'prefixo inválido interrompe o construtor antes de consulta, PDF ou gravação');
}
$previewState['prefix'] = 'company_a_';

$download = previewBoundaryController($store);
previewBoundaryRoute($download, 'download', '8', 'pdf');
previewBoundaryCheck($download->output->status === 200 && str_starts_with($download->output->body, '%PDF-')
    && $download->output->headers['content-type'] === 'application/pdf'
    && $download->output->headers['content-disposition'] === 'attachment; filename="nfse-8.pdf"'
    && str_contains($download->output->headers['cache-control'], 'no-store')
    && $download->output->headers['x-content-type-options'] === 'nosniff', 'download de rascunho usa PDF attachment sem exigir XML autorizado');
previewBoundaryCheck($download->nfse_nacional_model->forbiddenCalls === [] && $download->nfse_nacional_model->documentIds === [8, 8], 'download PDF conserva leitura autenticada e não tenta descriptografar XML ausente');
$xml = previewBoundaryController($store);
previewBoundaryRoute($xml, 'download', '8', 'xml');
previewBoundaryCheck($xml->output->status === 422 && $xml->output->body === '' && !isset($xml->output->headers['content-disposition'])
    && $xml->nfse_nacional_model->forbiddenCalls === [], 'rascunho não oferece XML fiscal nem tenta descriptografar conteúdo inexistente');

foreach ([['reconcile', 'GET', true, 405], ['reconcile', 'POST', false, 503], ['transmit', 'POST', true, 403], ['save', 'POST', true, 403]] as [$route, $verb, $csrf, $expectedStatus]) {
    $controller = previewBoundaryController($store, $verb); $controller->config->csrf = $csrf;
    $beforeLog = $store->report()['total'];
    previewBoundaryRoute($controller, $route, '8');
    $payload = json_decode($controller->output->body, true, 16, JSON_THROW_ON_ERROR);
    previewBoundaryCheck($controller->output->status === $expectedStatus && $payload['ok'] === false
        && $controller->nfse_nacional_model->documentIds === [] && $controller->nfse_nacional_model->forbiddenCalls === []
        && $store->report()['total'] === $beforeLog, $route . ' conserva método, capacidade e CSRF antes de alterar ou consultar situação remota');
}
// New workflows must stop at their HTTP/permission boundary before any issuer,
// A1, document storage, remote API or mail provider can be accessed.
$workflows = [
    ['recover_key', ['8'], 'view'], ['substitute', ['8'], 'substitute'],
    ['national_sync', [], 'national_sync'], ['national_sync_process', [], 'national_sync'], ['accounting_download', [], 'report_package'],
    ['accounting_send', [], 'accounting_send'],
    ['fiscal_event_submit', ['local', '8'], 'view'],
    ['fiscal_event_retry', ['national', '8'], 'view'],
    ['fiscal_event_consult', ['local', '8'], 'view'],
];
foreach ($workflows as [$route, $arguments, $capability]) {
    foreach (['method', 'permission', 'csrf'] as $gate) {
        $previewState['permissions'] = $gate === 'permission' ? [] : array_unique(['view', $capability]);
        $controller = previewBoundaryController($store, $gate === 'method' ? 'GET' : 'POST');
        $controller->config->csrf = $gate !== 'csrf';
        $controller->input->posted = ['access_key' => str_repeat('1', 50), 'event_type' => '101101',
            'environment' => '1', 'company' => 'company_b_'];
        $beforeLog = $store->report()['total'];
        previewBoundaryRoute($controller, $route, ...$arguments);
        $payload = json_decode($controller->output->body, true, 16, JSON_THROW_ON_ERROR);
        previewBoundaryCheck($controller->output->status === ['method' => 405, 'permission' => 403, 'csrf' => 503][$gate]
            && $payload['ok'] === false && $controller->nfse_nacional_model->documentIds === []
            && $controller->nfse_nacional_model->forbiddenCalls === [] && $store->report()['total'] === $beforeLog,
            $route . ' interrompe no gate ' . $gate . ' antes de acessar dados ou provedores');
        previewBoundaryCheck(($payload['csrf']['hash'] ?? '') === 'token-offline'
            && str_contains($controller->output->headers['cache-control'], 'no-store'),
            $route . ' retorna erro privado com token CSRF atual para a próxima ação');
    }
}
$previewState['permissions'] = ['view'];
foreach ([['national', ['issued']], ['accounting', []], ['national_preview', ['8']], ['national_download', ['8', 'xml']]] as [$route, $arguments]) {
    $controller = previewBoundaryController($store);
    previewBoundaryRoute($controller, $route, ...$arguments);
    previewBoundaryCheck($controller->output->status === 403 && $controller->output->body === ''
        && $controller->nfse_nacional_model->forbiddenCalls === [],
        $route . ' exige national_view mesmo com visualização das notas locais');
}
$controller = previewBoundaryController($store, 'POST');
$controller->input->posted = ['access_key' => '../outra-empresa', 'company' => 'company_b_'];
previewBoundaryRoute($controller, 'recover_key', '8');
$payload = json_decode($controller->output->body, true, 16, JSON_THROW_ON_ERROR);
previewBoundaryCheck($controller->output->status === 422 && $payload['ok'] === false
    && $controller->nfse_nacional_model->documentIds === [] && $controller->nfse_nacional_model->forbiddenCalls === [],
    'chave manual inválida é rejeitada antes do acesso ao documento, certificado ou API');
previewBoundaryCheck($previewState['api_calls'] === 0 && $previewApp->db->writes === [] && $previewApp->db->rows === $databaseSnapshot, 'toda a suíte terminou sem rede, certificado, numeração, gravação fiscal ou mudança do rascunho');

echo 'OK: ' . $previewChecks . " verificações do controller de prévia, isolamento de empresa e acesso fiscal.\n";
