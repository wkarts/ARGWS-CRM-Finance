<?php
declare(strict_types=1);

/** Controller boundaries with real temporary scoped logs; no CRM DB, HTTP, SMTP or fiscal transmission. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');

final class LogControllerInput
{
    public string $verb = 'GET';
    public array $query = [];
    public array $posted = [];
    public function method(): string { return strtolower($this->verb); }
    public function get(string $name) { return $this->query[$name] ?? null; }
    public function post(string $name, bool $filter = true) { return $this->posted[$name] ?? null; }
}

final class LogControllerOutput
{
    public int $status = 200;
    public string $body = '';
    public array $headers = [];
    public function set_status_header(int $status): self { $this->status = $status; return $this; }
    public function set_header(string $header): self
    {
        [$name, $value] = explode(':', $header, 2);
        $this->headers[strtolower(trim($name))] = trim($value);
        return $this;
    }
    public function set_content_type(string $mime, string $charset = ''): self
    {
        return $this->set_header('Content-Type: ' . $mime . ($charset !== '' ? '; charset=' . $charset : ''));
    }
    public function set_output(string $body): self { $this->body = $body; return $this; }
}

final class LogControllerConfig
{
    public bool $csrf = true;
    public function item(string $name) { return $name === 'csrf_protection' ? $this->csrf : null; }
}
final class LogControllerSecurity
{
    public function get_csrf_token_name(): string { return 'csrf_logs_fixture'; }
    public function get_csrf_hash(): string { return 'csrf-offline-rotated'; }
}
final class LogControllerLoader
{
    public array $views = [];
    public function view(string $view, array $data, bool $return = false): string
    {
        $this->views[] = ['view' => $view, 'data' => $data];
        return '';
    }
}
class AdminController
{
    public LogControllerInput $input;
    public LogControllerOutput $output;
    public LogControllerConfig $config;
    public LogControllerSecurity $security;
    public LogControllerLoader $load;
    public function __construct() { throw new LogicException('No real CRM bootstrap in this offline fixture.'); }
}
final class LogControllerHttpError extends RuntimeException {}
function show_error($message, $status = 500, $heading = ''): void { throw new LogControllerHttpError((string) $message, (int) $status); }
function show_404($page = '', $logError = true): void { throw new LogControllerHttpError('Não encontrado.', 404); }
function access_denied($feature = ''): void { throw new LogControllerHttpError('Acesso negado.', 403); }
function html_escape($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$logControllerState = [
    'logged' => true, 'permissions' => ['view', 'logs', 'edit', 'settings', 'transmit'],
    'admin_base' => 'https://crm.example.invalid/' . str_repeat('a', 32) . '/admin/',
    'context_calls' => 0, 'messages' => [],
];
function is_staff_logged_in(): bool { return $GLOBALS['logControllerState']['logged']; }
function is_admin(): bool { return false; }
function staff_can(string $action, string $feature): bool
{
    return $feature === 'nfse_nacional' && in_array($action, $GLOBALS['logControllerState']['permissions'], true);
}
function get_staff_user_id(): int { return 19; }
function admin_url(string $path = ''): string { return $GLOBALS['logControllerState']['admin_base'] . $path; }
function log_message($level, $message): void { $GLOBALS['logControllerState']['messages'][] = [(string) $level, (string) $message]; }
function &get_instance()
{
    ++$GLOBALS['logControllerState']['context_calls'];
    throw new LogicException('This test must not access CRM company state, DB or external services.');
}

require_once dirname(__DIR__) . '/controllers/Nfse_nacional.php';

use NfseNacional\ApiException;
use NfseNacional\OperationalLog;
use NfseNacional\ValidationException;

$logControllerChecks = 0;
function logControllerCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['logControllerChecks'];
    echo 'OK: ' . $message . PHP_EOL;
}
function logControllerSet(Nfse_nacional $controller, string $property, $value): void
{
    (new ReflectionProperty(Nfse_nacional::class, $property))->setValue($controller, $value);
}
function logControllerPrivate(Nfse_nacional $controller, string $method, ...$arguments)
{
    return (new ReflectionMethod(Nfse_nacional::class, $method))->invokeArgs($controller, $arguments);
}
function logController(?OperationalLog $store = null, string $verb = 'GET'): Nfse_nacional
{
    $controller = (new ReflectionClass(Nfse_nacional::class))->newInstanceWithoutConstructor();
    $controller->input = new LogControllerInput(); $controller->input->verb = $verb;
    $controller->output = new LogControllerOutput(); $controller->config = new LogControllerConfig();
    $controller->security = new LogControllerSecurity(); $controller->load = new LogControllerLoader();
    if ($store !== null) { logControllerSet($controller, 'logFactory', static fn(): OperationalLog => $store); }
    return $controller;
}
function logControllerRoute(Nfse_nacional $controller, string $method): void
{
    try { $controller->{$method}(); }
    catch (LogControllerHttpError $error) { $controller->output->set_status_header($error->getCode()); }
}
function logControllerJson(Nfse_nacional $controller): array
{
    return json_decode($controller->output->body, true, 32, JSON_THROW_ON_ERROR);
}
function logControllerRows(OperationalLog $store, string $correlation): array
{
    return $store->report(['correlation_id' => $correlation], 1, 100)['rows'];
}
function logControllerStages(array $rows): array
{
    $stages = [];
    foreach ($rows as $row) { $stages[$row['context']['stage'] ?? ''] = $row; }
    return $stages;
}
function logControllerDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) { if ($name !== '.' && $name !== '..') { logControllerDelete($path . '/' . $name); } }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) { unlink($path); }
}

$logControllerRoot = sys_get_temp_dir() . '/nfse_log_controller_' . bin2hex(random_bytes(8));
if (!mkdir($logControllerRoot, 0700)) { throw new RuntimeException('Cannot create isolated fixture storage.'); }
register_shutdown_function(static function () use ($logControllerRoot): void { logControllerDelete($logControllerRoot); });
$clock = static fn(): int => 1794312000;
$scopeA = str_repeat('a', 64); $scopeB = str_repeat('b', 64);
$storeA = new OperationalLog($logControllerRoot, $scopeA, 19, $clock);
$storeB = new OperationalLog($logControllerRoot, $scopeB, 27, $clock);
$correlationA = str_repeat('1', 32); $correlationB = str_repeat('2', 32);
$eventA = $storeA->record('warning', 'api.municipality', 'Consulta municipal inconclusiva (HTTP 404). senha=SECRET_A_PASSWORD; e-mail pessoa@example.invalid', [
    'correlation_id' => $correlationA, 'environment' => '2', 'http_status' => 404,
    'service' => 'adn_parametrizacao', 'operation_status' => 'uncertain', 'stage' => 'http',
    'endpoint' => 'https://user:SECRET_URL_PASSWORD@adn.nfse.gov.br/parametrizacao/3550308/convenio?token=SECRET_QUERY_TOKEN',
    'password' => 'SECRET_CONTEXT_PASSWORD', 'certificate' => 'SECRET_CERTIFICATE', 'xml' => 'SECRET_XML',
    'errors' => [['code' => 'E404', 'message' => 'Município não encontrado. token=SECRET_ERROR_TOKEN; CPF 52998224725']],
]);
$eventB = $storeB->record('error', 'settings.save', 'ONLY_SCOPE_B_EVENT', ['correlation_id' => $correlationB, 'environment' => '1']);
logControllerCheck(is_string($eventA) && is_string($eventB), 'Fixture grava dois históricos independentes em diretórios temporários');

// Permission and verb gates must precede opening a company log store.
foreach (['logs', 'log_detail', 'logs_download', 'logs_diagnostic'] as $route) {
    foreach ([['logged' => false, 'permissions' => ['view', 'logs']], ['logged' => true, 'permissions' => ['view']], ['logged' => true, 'permissions' => ['logs']], ['logged' => true, 'permissions' => []]] as $access) {
        $logControllerState['logged'] = $access['logged']; $logControllerState['permissions'] = $access['permissions'];
        $controller = logController(); $factoryCalls = 0;
        logControllerSet($controller, 'logFactory', static function () use (&$factoryCalls, $storeA): OperationalLog { ++$factoryCalls; return $storeA; });
        $controller->input->query = ['event_id' => $eventA];
        logControllerRoute($controller, $route);
        logControllerCheck($controller->output->status === 403 && $factoryCalls === 0 && $controller->load->views === [] && $controller->output->body === '',
            $route . ' exige sessão e permissões conjuntas view/logs antes de ler os registros');
    }
    $logControllerState['logged'] = true; $logControllerState['permissions'] = ['view', 'logs'];
    foreach (['POST', 'DELETE', 'HEAD'] as $verb) {
        $controller = logController($storeA, $verb); $controller->input->query['event_id'] = $eventA;
        logControllerRoute($controller, $route);
        logControllerCheck($controller->output->status === 405 && $controller->load->views === [] && $controller->output->body === '', $route . ' recusa ' . $verb);
    }
}

$controller = logController($storeA);
$controller->input->query = ['company_scope' => $scopeB, 'company' => $scopeB, 'file' => '../../' . $scopeB . '/events.json', 'directory' => '/etc'];
$controller->logs(); $list = $controller->load->views[0];
logControllerCheck($list['view'] === 'nfse_nacional/logs' && $list['data']['report']['total'] === 1 && $list['data']['report']['rows'][0]['id'] === $eventA,
    'Listagem usa exclusivamente o store do contexto autenticado e ignora empresa/arquivo da query');
logControllerCheck(strpos(json_encode($list), 'ONLY_SCOPE_B_EVENT') === false && $list['data']['report']['retention_days'] === 30, 'Listagem não mistura histórico de outra empresa e informa retenção');

$detail = logController($storeA); $detail->input->query = ['event_id' => $eventA, 'correlation_id' => $correlationA, 'page' => '2']; $detail->log_detail();
$detailData = $detail->load->views[0]['data'];
logControllerCheck($detail->load->views[0]['view'] === 'nfse_nacional/log_detail' && $detailData['record']['id'] === $eventA
    && $detailData['filters']['page'] === '2' && $detailData['filters']['correlation_id'] === $correlationA, 'Detalhe autorizado preserva a referência e a paginação de retorno');
$foreign = logController($storeA); $foreign->input->query = ['event_id' => $eventB, 'company_scope' => $scopeB]; logControllerRoute($foreign, 'log_detail');
logControllerCheck($foreign->output->status === 404 && $foreign->load->views === [], 'ID válido de outro scope não pode ser obtido pelo detalhe');
$otherCompany = logController($storeB); $otherCompany->logs();
logControllerCheck($otherCompany->load->views[0]['data']['report']['rows'][0]['id'] === $eventB, 'Contexto B consulta seu próprio registro sem receber o contexto A');

foreach (['../../events.jsonl.php', '/etc/passwd', ['id' => $eventA], str_repeat('a', 33), str_repeat('g', 32), $eventA . "\0", ''] as $badId) {
    $invalid = logController(); $factoryCalls = 0;
    logControllerSet($invalid, 'logFactory', static function () use (&$factoryCalls, $storeA): OperationalLog { ++$factoryCalls; return $storeA; });
    $invalid->input->query['event_id'] = $badId; logControllerRoute($invalid, 'log_detail');
    logControllerCheck($invalid->output->status === 404 && $factoryCalls === 0, 'event_id inválido não abre arquivo nem permite traversal');
}

$invalidFilters = [
    ['date_from' => '2026-02-31'], ['date_to' => '../../events'], ['date_from' => '2026-11-11', 'date_to' => '2026-11-10'],
    ['level' => 'critical'], ['operation' => '../../settings'], ['environment' => '3'], ['http_status' => '604'], ['http_status' => ['404']],
    ['correlation_id' => '../' . $correlationB], ['search' => str_repeat('x', 201)], ['operation' => ['api.municipality']],
];
foreach (['logs', 'log_detail', 'logs_download', 'logs_diagnostic'] as $route) {
    foreach ($invalidFilters as $query) {
        $invalid = logController(); $factoryCalls = 0;
        logControllerSet($invalid, 'logFactory', static function () use (&$factoryCalls, $storeA): OperationalLog { ++$factoryCalls; return $storeA; });
        $invalid->input->query = $query + ['event_id' => $eventA]; logControllerRoute($invalid, $route);
        logControllerCheck($invalid->output->status === 400 && $factoryCalls === 0 && $invalid->load->views === [], $route . ' recusa filtro inválido antes de ler o histórico');
    }
}
foreach (['../1', ['2'], '-1', '999999'] as $badPage) {
    $page = logController($storeA); $page->input->query['page'] = $badPage; $page->logs();
    logControllerCheck($page->load->views[0]['data']['report']['page'] === 1, 'Paginação malformada volta à primeira página sem interpretar caminho');
}
$filtered = logController($storeA); $filtered->input->query = ['correlation_id' => $correlationA, 'http_status' => '404', 'operation' => 'api.municipality', 'environment' => '2']; $filtered->logs();
logControllerCheck($filtered->load->views[0]['data']['report']['total'] === 1, 'Filtros válidos de operação, ambiente, referência e HTTP404 recuperam o evento');

$export = logController($storeA); $export->input->query = ['correlation_id' => $correlationA, 'company_scope' => $scopeB]; $export->logs_download();
$exportRows = array_map(static fn($line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), array_filter(explode("\n", $export->output->body)));
logControllerCheck($export->output->status === 200 && count($exportRows) === 1 && $exportRows[0]['id'] === $eventA, 'Exportação é filtrada e limitada à empresa autenticada');
logControllerCheck($export->output->headers['content-type'] === 'application/x-ndjson; charset=utf-8'
    && $export->output->headers['x-nfse-log-records'] === '1' && $export->output->headers['x-nfse-log-truncated'] === '0'
    && strpos($export->output->headers['cache-control'], 'no-store') !== false && $export->output->headers['x-content-type-options'] === 'nosniff'
    && preg_match('/^attachment; filename="nfse-logs-[0-9]{8}-[0-9]{6}\.jsonl"$/D', $export->output->headers['content-disposition']) === 1,
    'Download usa JSONL, nome controlado, no-store, nosniff e contagem explícita');
// Detailed diagnostic report: one selected event includes the full correlated
// operation timeline, not only that row; tenant boundaries remain server-side.
$extraA = $storeA->record('info', 'api.distribution', 'Resposta ADN conferida.', [
    'correlation_id' => $correlationA, 'environment' => '2', 'http_status' => 200,
    'duration_ms' => 1974.63, 'stage' => 'document_validation', 'processing_status' => 'DOCUMENTOS_LOCALIZADOS',
    'generation_date_fallbacks' => 1, 'batch_items' => 2, 'dns_ms' => 19.7,
    'curl_errno' => 0, 'secret' => 'SECRET_REPORT_TOKEN',
]);
$detailed = logController($storeA); $detailed->input->query = ['event_id' => $eventA, 'company_scope' => $scopeB, 'date_from' => '2026-10-01'];
$detailed->logs_diagnostic();
$detailedJson = logControllerJson($detailed);
logControllerCheck($detailed->output->status === 200
    && $detailed->output->headers['content-type'] === 'application/json; charset=utf-8'
    && strpos($detailed->output->headers['cache-control'], 'no-store') !== false
    && $detailed->output->headers['x-content-type-options'] === 'nosniff'
    && $detailed->output->headers['x-frame-options'] === 'DENY'
    && str_contains($detailed->output->headers['content-disposition'], $eventA . '.json'),
    'Relatorio estruturado gera arquivo JSON isolado e desabilita cache');
logControllerCheck($detailedJson['format'] === 'nfse-nacional-diagnostico-v1'
    && $detailedJson['selection'] === 'operacao do registro selecionado'
    && count($detailedJson['events']) === 2
    && $detailedJson['summary']['http_statuses']['200'] === 1
    && $detailedJson['summary']['http_statuses']['404'] === 1
    && $detailedJson['summary']['generation_date_fallbacks'] === 1
    && $detailedJson['summary']['stages']['document_validation'] === 1
    && $detailedJson['summary']['duration_ms']['maximum'] === 1974.63,
    'Relatorio consolida todos os registros da correlacao, tempos, HTTP e etapas sem perder detalhes');
$filteredDiagnostic = logController($storeA); $filteredDiagnostic->input->query = ['operation' => 'api.distribution'];
$filteredDiagnostic->logs_diagnostic();
$filteredJson = logControllerJson($filteredDiagnostic);
logControllerCheck($filteredJson['selection'] === 'filtros da listagem' && $filteredJson['summary']['records'] === 1
    && $filteredJson['events'][0]['id'] === $extraA,
    'Relatorio geral respeita filtros da listagem e traz eventos detalhados');
$foreignDiagnostic = logController($storeA); $foreignDiagnostic->input->query = ['event_id' => $eventB, 'company_scope' => $scopeB];
logControllerRoute($foreignDiagnostic, 'logs_diagnostic');
logControllerCheck($foreignDiagnostic->output->status === 404 && $foreignDiagnostic->output->body === '',
    'Nao permite baixar relatorios detalhados de eventos de outra empresa');
foreach (['SECRET_A_PASSWORD', 'SECRET_URL_PASSWORD', 'SECRET_QUERY_TOKEN', 'SECRET_CONTEXT_PASSWORD',
    'SECRET_CERTIFICATE', 'SECRET_XML', 'SECRET_ERROR_TOKEN', 'SECRET_REPORT_TOKEN', 'pessoa@example.invalid',
    '52998224725', 'ONLY_SCOPE_B_EVENT'] as $secret) {
    logControllerCheck(!str_contains($detailed->output->body, $secret) && !str_contains($filteredDiagnostic->output->body, $secret),
        'Exportacao estruturada nao divulga dados sensiveis ' . $secret);
}

$protected = json_encode([$list, $detailData, $exportRows], JSON_UNESCAPED_SLASHES);
foreach (['SECRET_A_PASSWORD', 'SECRET_URL_PASSWORD', 'SECRET_QUERY_TOKEN', 'SECRET_CONTEXT_PASSWORD', 'SECRET_CERTIFICATE', 'SECRET_XML', 'SECRET_ERROR_TOKEN', 'pessoa@example.invalid', '52998224725', 'ONLY_SCOPE_B_EVENT'] as $secret) {
    logControllerCheck(strpos($protected, $secret) === false, 'Listagem, detalhe e download não expõem ' . $secret);
}
$exportAudit = $storeA->report(['operation' => 'logs.export'])['rows'];
logControllerCheck(count($exportAudit) === 6 && isset(logControllerStages($exportAudit)['start'], logControllerStages($exportAudit)['finish']), 'Downloads JSONL e JSON registram inicio e conclusao de cada exportacao');

$logControllerState['permissions'] = ['view', 'logs', 'edit', 'settings', 'transmit'];
$success = logController($storeA, 'POST'); $calls = 0;
logControllerPrivate($success, 'mutation', 'edit', static function () use (&$calls): array { ++$calls; return ['message' => 'Rascunho salvo.', 'data' => ['document' => ['id' => 41, 'invoice_id' => 91, 'environment' => '2', 'status' => 'draft']]]; }, 'draft.save', ['document_id' => 41, 'invoice_id' => 91, 'password' => 'SECRET_MUTATION_PASSWORD']);
$successPayload = logControllerJson($success); $successCorrelation = $successPayload['data']['correlation_id'] ?? '';
$successRows = logControllerRows($storeA, $successCorrelation); $successStages = logControllerStages($successRows);
logControllerCheck($calls === 1 && $successPayload['ok'] && $success->output->status === 200 && preg_match('/^[a-f0-9]{32}$/D', $successCorrelation) === 1, 'Mutação bem-sucedida executa uma vez e devolve referência aleatória de 32 caracteres');
logControllerCheck(count($successRows) === 2 && isset($successStages['start'], $successStages['finish']) && $successStages['start']['correlation_id'] === $successStages['finish']['correlation_id']
    && $successStages['finish']['context']['operation_status'] === 'success' && $successStages['finish']['document_id'] === 41 && $successStages['finish']['invoice_id'] === 91,
    'Início e conclusão compartilham referência, documento e fatura no mesmo scope');
logControllerCheck($successPayload['data']['log_url'] === admin_url('nfse_nacional/logs?correlation_id=' . $successCorrelation)
    && $successPayload['csrf'] === ['name' => 'csrf_logs_fixture', 'hash' => 'csrf-offline-rotated'] && strpos(json_encode($successRows), 'SECRET_MUTATION_PASSWORD') === false,
    'Sucesso conserva CSRF, oferece link autorizado e não persiste senha do contexto');

$municipalFailure = logController($storeA, 'POST'); $calls = 0;
logControllerPrivate($municipalFailure, 'mutation', 'settings', static function () use (&$calls, $municipalFailure): array {
    ++$calls;
    logControllerPrivate($municipalFailure, 'recordApi', [
        'operation' => 'api.municipality', 'environment' => '2', 'service' => 'adn_parametrizacao', 'method' => 'GET',
        'endpoint' => 'https://adn.producaorestrita.nfse.gov.br/parametrizacao/{codigoMunicipio}/convenio',
        'http_status' => 404, 'duration_ms' => 72, 'request_bytes' => 0, 'response_bytes' => 82,
        'request_sha256' => hash('sha256', ''), 'response_sha256' => hash('sha256', 'response-fixture'),
        'response_type' => 'json_object', 'operation_status' => 'uncertain', 'error_codes' => ['E404'],
        'errors' => [['code' => 'E404', 'message' => 'Consulta inconclusiva. senha=SECRET_API_PASSWORD; e-mail person@example.invalid']],
        'request' => ['password' => 'SECRET_RAW_REQUEST', 'xml' => 'SECRET_RAW_XML'], 'response' => ['body' => 'SECRET_RAW_BODY'],
    ]);
    throw new ApiException('Consulta do convênio no ADN inconclusiva (HTTP 404).', 404, true, [['code' => 'E404', 'message' => 'Município não encontrado neste ambiente.']]);
}, 'municipality.consult', ['environment' => '2', 'municipality_code' => '3550308']);
$failurePayload = logControllerJson($municipalFailure); $failureCorrelation = $failurePayload['data']['correlation_id'] ?? '';
$failureRows = logControllerRows($storeA, $failureCorrelation); $failureStages = logControllerStages($failureRows);
logControllerCheck($calls === 1 && !$failurePayload['ok'] && $municipalFailure->output->status === 503 && $failurePayload['data']['uncertain'] === true, '404 municipal incerto responde 503 na aplicação sem repetir callback');
logControllerCheck(count($failureRows) === 3 && isset($failureStages['start'], $failureStages['http'], $failureStages['finish'])
    && $failureStages['http']['context']['http_status'] === 404 && $failureStages['finish']['context']['http_status'] === 404
    && $failureStages['http']['operation'] === 'api.municipality' && $failureStages['finish']['operation'] === 'municipality.consult'
    && $failureStages['http']['context']['service'] === 'adn_parametrizacao', 'Início, transporte e falha compartilham correlação e preservam HTTP 404 original');
logControllerCheck(strpos($failurePayload['message'], 'Referência: ' . $failureCorrelation) !== false && $failurePayload['data']['log_url'] === admin_url('nfse_nacional/logs?correlation_id=' . $failureCorrelation), 'Erro oferece referência e link para consultar exatamente a operação');
foreach (['SECRET_API_PASSWORD', 'person@example.invalid', 'SECRET_RAW_REQUEST', 'SECRET_RAW_XML', 'SECRET_RAW_BODY'] as $secret) {
    logControllerCheck(strpos(json_encode($failureRows), $secret) === false, 'Eventos HTTP e falha removem ' . $secret);
}

foreach ([
    'validation' => [new ValidationException(['Código municipal obrigatório.']), 422],
    'argument' => [new InvalidArgumentException('Dados de entrada inválidos.'), 422],
    'runtime' => [new RuntimeException('Não foi possível salvar. senha=SECRET_RUNTIME_PASSWORD;'), 409],
    'rate_limit' => [new RuntimeException('Aguarde antes de consultar novamente.', 429), 429],
    'internal' => [new Error('PRIVATE_INTERNAL_DETAILS'), 500],
] as $case => [$error, $expectedStatus]) {
    $failed = logController($storeA, 'POST'); $calls = 0;
    logControllerPrivate($failed, 'mutation', 'edit', static function () use ($error, &$calls) { ++$calls; throw $error; }, 'draft.save', ['document_id' => 41]);
    $payload = logControllerJson($failed); $rows = logControllerRows($storeA, $payload['data']['correlation_id']); $stages = logControllerStages($rows);
    logControllerCheck($calls === 1 && $failed->output->status === $expectedStatus && !$payload['ok'] && count($rows) === 2 && isset($stages['finish'])
        && $stages['finish']['level'] === 'error' && $stages['finish']['context']['http_status'] === $expectedStatus, 'Falha ' . $case . ' registra conclusão uma vez e conserva resposta da aplicação');
    logControllerCheck(strpos(json_encode($rows), 'SECRET_RUNTIME_PASSWORD') === false && strpos(json_encode($rows), 'PRIVATE_INTERNAL_DETAILS') === false, 'Falha ' . $case . ' não grava segredos ou detalhes internos');
    if ($case === 'internal') { logControllerCheck(strpos($failed->output->body, 'PRIVATE_INTERNAL_DETAILS') === false, 'Erro interno mantém mensagem pública genérica'); }
}

$logControllerState['permissions'] = ['view', 'edit'];
$withoutLogAccess = logController($storeA, 'POST');
logControllerPrivate($withoutLogAccess, 'mutation', 'edit', static function () { throw new InvalidArgumentException('Revisar o rascunho.'); }, 'draft.save', []);
$withoutAccessPayload = logControllerJson($withoutLogAccess);
logControllerCheck(isset($withoutAccessPayload['data']['correlation_id']) && !isset($withoutAccessPayload['data']['log_url']), 'Usuário sem permissão logs recebe referência para suporte sem link de acesso ao histórico');
$logControllerState['permissions'] = ['view', 'logs', 'edit'];

foreach ([['GET', true, true], ['POST', false, true], ['POST', true, false]] as [$verb, $permitted, $csrf]) {
    $controller = logController($storeA, $verb); $controller->config->csrf = $csrf; $calls = 0;
    $before = $storeA->report()['total'];
    if (!$permitted) { $logControllerState['permissions'] = ['view', 'logs']; }
    logControllerPrivate($controller, 'mutation', 'edit', static function () use (&$calls): array { ++$calls; return ['message' => 'Não deveria executar.', 'data' => []]; }, 'draft.save', []);
    $logControllerState['permissions'] = ['view', 'logs', 'edit'];
    logControllerCheck($calls === 0 && $storeA->report()['total'] === $before && !isset(logControllerJson($controller)['data']['correlation_id']), 'Método, permissão ou CSRF recusados não executam callback nem inventam correlação gravada');
}

foreach ([false, true] as $callbackFails) {
    $broken = logController(null, 'POST'); $calls = 0; $factoryCalls = 0;
    logControllerSet($broken, 'logFactory', static function () use (&$factoryCalls): OperationalLog { ++$factoryCalls; throw new RuntimeException('SECRET_FACTORY_FAILURE'); });
    logControllerPrivate($broken, 'mutation', 'edit', static function () use (&$calls, $callbackFails): array {
        ++$calls;
        if ($callbackFails) { throw new InvalidArgumentException('Falha funcional original.'); }
        return ['message' => 'Resultado funcional preservado.', 'data' => ['id' => 41]];
    }, 'draft.save', []);
    $payload = logControllerJson($broken);
    logControllerCheck($calls === 1 && $factoryCalls === 1 && $broken->output->status === ($callbackFails ? 422 : 200)
        && $payload['ok'] === !$callbackFails && !isset($payload['data']['correlation_id']) && !isset($payload['data']['log_url'])
        && strpos($broken->output->body, 'SECRET_FACTORY_FAILURE') === false,
        'Falha da factory mantém ' . ($callbackFails ? 'erro' : 'sucesso') . ' original, sem nova execução nem referência inexistente');
}

$unavailable = logController(); $unavailable->logs();
logControllerCheck($unavailable->load->views[0]['data']['report']['storage'] === 'unavailable' && !$unavailable->load->views[0]['data']['report']['storage_available'], 'Lista informa armazenamento indisponível sem confundir com histórico vazio');
foreach (['log_detail', 'logs_download', 'logs_diagnostic'] as $route) {
    $unavailable = logController(); $unavailable->input->query['event_id'] = $eventA; logControllerRoute($unavailable, $route);
    logControllerCheck($unavailable->output->status === 503, $route . ' sinaliza 503 quando armazenamento não está disponível');
}

logControllerCheck($logControllerState['context_calls'] === 0, 'Toda a suíte permaneceu sem contexto real do CRM, banco ou rede');
echo 'Log controller: ' . $logControllerChecks . ' verificações offline aprovadas.' . PHP_EOL;
