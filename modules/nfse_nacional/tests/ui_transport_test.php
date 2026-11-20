<?php
declare(strict_types=1);

// Offline boundary tests for UI assets and registration lookup requests.
// Native Perfex routing, its session middleware and actual CSRF validation are
// outside this fixture. No CRM database, certificate, fiscal write or HTTP call.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');

final class UiTransportInput
{
    public string $verb = 'GET';
    public array $query = [];
    public array $posted = [];
    public array $headers = [];
    public function method(): string { return strtolower($this->verb); }
    public function get(string $key) { return $this->query[$key] ?? null; }
    public function post(string $key, bool $filter = true) { return $this->posted[$key] ?? null; }
    public function get_request_header(string $name) { return $this->headers[strtolower($name)] ?? null; }
}

final class UiTransportOutput
{
    public int $status = 200;
    public array $headers = [];
    public string $body = '';
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

final class UiTransportConfig
{
    public bool $csrf = true;
    public function item(string $key) { return $key === 'csrf_protection' ? $this->csrf : null; }
}

final class UiTransportSecurity
{
    public function get_csrf_token_name(): string { return 'csrf_transport'; }
    public function get_csrf_hash(): string { return 'csrf-rotated-offline'; }
}

class AdminController
{
    public UiTransportInput $input;
    public UiTransportOutput $output;
    public UiTransportConfig $config;
    public UiTransportSecurity $security;
    public function __construct() { throw new LogicException('The real CRM bootstrap is not part of this test.'); }
}
final class UiTransportDenied extends RuntimeException {}
function show_error($message, $status = 500, $heading = ''): void { throw new UiTransportDenied((string) $message, (int) $status); }
function access_denied($feature = ''): void { throw new UiTransportDenied('Acesso negado.', 403); }
final class UiRecipientOptionsModel
{
    public $db = null;
    public function settings(): array { return \NfseNacional\Config::defaults(); }
    public array $calls = [];
    public bool $deny = false;
    public function invoiceRecipientContext(int $clientId, int $invoiceId): array
    {
        $this->calls[] = [$clientId, $invoiceId];
        if ($this->deny) { throw new RuntimeException('PRIVATE_OTHER_CUSTOMER_AND_DATABASE_DETAILS'); }
        return ['client_id' => $clientId, 'invoice_id' => $invoiceId, 'selection' => '',
            'options' => [['value' => '', 'label' => 'Usar padrão do cliente']], 'company_scope' => str_repeat('a', 64)];
    }
}

$transportState = [
    'logged' => true,
    'permissions' => ['nfse_nacional' => ['view', 'edit'], 'settings' => ['view']],
    'admin_base' => 'https://crm.example.invalid/' . str_repeat('a', 32) . '/admin/',
    'context_calls' => 0,
];
function is_staff_logged_in(): bool { return $GLOBALS['transportState']['logged']; }
function is_admin(): bool { return false; }
function staff_can(string $action, string $feature): bool
{
    return in_array($action, $GLOBALS['transportState']['permissions'][$feature] ?? [], true);
}
function get_staff_user_id(): int { return 19; }
function db_prefix(): string { return 'ui_transport_'; }
function admin_url(string $path = ''): string { return $GLOBALS['transportState']['admin_base'] . $path; }
function &get_instance()
{
    ++$GLOBALS['transportState']['context_calls'];
    // Invalid lookup requests must stop before accessing company state or HTTP.
    throw new LogicException('Forbidden company/database access in an offline boundary test.');
}
function log_message($level, $message): void {}

require_once dirname(__DIR__) . '/autoload.php';
require_once dirname(__DIR__) . '/helpers/nfse_nacional_helper.php';
require_once dirname(__DIR__) . '/helpers/nfse_nacional_integration_helper.php';
require_once dirname(__DIR__) . '/controllers/Nfse_nacional.php';

$transportChecks = 0;
function transportCheck(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['transportChecks'];
    echo 'OK: ' . $label . PHP_EOL;
}
function transportController(string $verb = 'GET'): Nfse_nacional
{
    $controller = (new ReflectionClass(Nfse_nacional::class))->newInstanceWithoutConstructor();
    $controller->input = new UiTransportInput();
    $controller->input->verb = $verb;
    $controller->output = new UiTransportOutput();
    $controller->config = new UiTransportConfig();
    $controller->security = new UiTransportSecurity();
    return $controller;
}
function transportLookup(string $verb, $type, $value, bool $csrf = true): Nfse_nacional
{
    $controller = transportController($verb);
    $controller->input->posted['value'] = $value;
    $controller->config->csrf = $csrf;
    $controller->lookup($type);
    return $controller;
}
function transportJson(Nfse_nacional $controller): array
{
    return json_decode($controller->output->body, true, 32, JSON_THROW_ON_ERROR);
}

// Compare the transmitted bytes to this installed module copy, not to a URL or
// a caller-selected company directory. This protects against the mixed-version
// UI failure when central assets differ from a company's PHP module copy.
foreach ([
    'style' => ['nfse_nacional.css', 'text/css'],
    'script' => ['nfse_nacional.js', 'application/javascript'],
    'navigation' => ['navigation-icons.js', 'application/javascript'],
] as $key => [$file, $mime]) {
    $localBytes = file_get_contents(dirname(__DIR__) . '/assets/' . $file);
    $controller = transportController();
    $controller->input->query = ['company' => 'another-company', 'scope' => 'untrusted', 'file' => '../README.md'];
    $controller->asset($key);
    transportCheck($controller->output->status === 200 && $controller->output->body === $localBytes,
        $key . ' entrega os bytes da cópia executada e ignora seletores de empresa/arquivo');
    transportCheck($controller->output->headers['content-type'] === $mime . '; charset=utf-8'
        && $controller->output->headers['x-content-type-options'] === 'nosniff',
        $key . ' declara o tipo correto e impede interpretação de conteúdo como outro tipo');
}

$style = \NfseNacional\Assets::read('style');
$styleUrl = \NfseNacional\Assets::url('style');
$parts = parse_url($styleUrl); parse_str($parts['query'] ?? '', $versionQuery);
transportCheck(str_starts_with($styleUrl, $transportState['admin_base'] . 'nfse_nacional/asset/style?')
    && !str_contains($styleUrl, '/modules/') && pathinfo($parts['path'], PATHINFO_EXTENSION) === '',
    'asset usa rota administrativa da empresa sem extensão nem diretório público central');
transportCheck(($versionQuery['v'] ?? '') === hash('sha256', $style['content']),
    'URL identifica o conteúdo real que será entregue, independentemente da versão declarada');
$transportState['admin_base'] = 'https://crm.example.invalid/' . str_repeat('b', 32) . '/admin/';
transportCheck(str_starts_with(\NfseNacional\Assets::url('style'), $transportState['admin_base']),
    'URL não reutiliza a raiz de outra empresa quando a versão do arquivo é a mesma');

$controller = transportController(); $controller->input->query['v'] = $style['hash']; $controller->asset('style');
transportCheck($controller->output->status === 200 && $controller->output->body === $style['content'],
    'versão correspondente devolve o arquivo completo');
transportCheck(str_contains($controller->output->headers['cache-control'], 'private')
    && !str_contains($controller->output->headers['cache-control'], 'public')
    && $controller->output->headers['vary'] === 'Cookie'
    && $controller->output->headers['etag'] === '"' . $style['hash'] . '"',
    'cache é privado, varia pela sessão e identifica os bytes com ETag');

$head = transportController('HEAD'); $head->input->query['v'] = $style['hash']; $head->asset('style');
transportCheck($head->output->status === 200 && $head->output->body === ''
    && $head->output->headers['etag'] === $controller->output->headers['etag']
    && $head->output->headers['content-type'] === $controller->output->headers['content-type'],
    'HEAD mantém metadados de GET sem enviar o corpo');

$conditional = transportController();
$conditional->input->query['v'] = $style['hash'];
$conditional->input->headers['if-none-match'] = $controller->output->headers['etag'];
$conditional->asset('style');
transportCheck($conditional->output->status === 304 && $conditional->output->body === '',
    'ETag correspondente responde 304 sem retransmitir o arquivo');

foreach ([str_repeat('0', 64), ['unexpected' => 'array']] as $wrongVersion) {
    $mismatch = transportController(); $mismatch->input->query['v'] = $wrongVersion; $mismatch->asset('style');
    transportCheck($mismatch->output->status === 409 && $mismatch->output->body === '',
        'versão divergente ou malformada não entrega bytes de outra revisão');
}
foreach (['../helpers/nfse_nacional_helper.php', 'https://other.example.invalid/code.js', 'nfse_nacional.css'] as $unsafeKey) {
    $unknown = transportController(); $unknown->asset($unsafeKey);
    transportCheck($unknown->output->status === 404 && $unknown->output->body === '',
        'recurso fora da lista fechada é rejeitado: ' . $unsafeKey);
}
$wrongMethod = transportController('POST'); $wrongMethod->asset('style');
transportCheck($wrongMethod->output->status === 405 && $wrongMethod->output->body === ''
    && $wrongMethod->output->headers['allow'] === 'GET, HEAD',
    'endpoint de assets rejeita métodos de alteração');

$transportState['logged'] = false;
$anonymous = transportController(); $anonymous->input->headers['if-none-match'] = '"' . $style['hash'] . '"'; $anonymous->asset('style');
transportCheck($anonymous->output->status === 403 && $anonymous->output->body === '',
    'sessão ausente é recusada mesmo com um ETag conhecido');
$transportState['logged'] = true; $transportState['permissions']['nfse_nacional'] = [];
$forbidden = transportController(); $forbidden->asset('script');
transportCheck($forbidden->output->status === 403 && $forbidden->output->body === '',
    'funcionário sem acesso ao módulo não recebe o script pela rota autenticada');

// Inputs are deliberately invalid, so successful permission checks must advance
// only as far as validation. The get_instance() sentinel would detect any early
// company/database access; the real lookup factory cannot reach HTTP here.
$transportState['permissions']['nfse_nacional'] = ['view', 'edit'];
$get = transportLookup('GET', 'cnpj', '11222333000181');
transportCheck($get->output->status === 405 && transportJson($get)['ok'] === false,
    'consulta cadastral recusa GET antes de consultar identificadores válidos');
$withoutCsrf = transportLookup('POST', 'cnpj', '11222333000181', false);
transportCheck($withoutCsrf->output->status === 503 && transportJson($withoutCsrf)['ok'] === false,
    'consulta não funciona com a proteção CSRF nativa desativada');
$transportState['permissions']['nfse_nacional'] = ['view'];
$readOnly = transportLookup('POST', 'cnpj', '11222333000181');
transportCheck($readOnly->output->status === 403 && transportJson($readOnly)['ok'] === false,
    'permissão somente de leitura não autoriza consultas cadastrais');
$transportState['logged'] = false;
$anonymousLookup = transportLookup('POST', 'cep', '01001000');
transportCheck($anonymousLookup->output->status === 403,
    'consulta cadastral também exige sessão autenticada');
$transportState['logged'] = true;

foreach (['edit', 'settings'] as $capability) {
    $transportState['permissions']['nfse_nacional'] = ['view', $capability];
    $invalid = transportLookup('POST', 'cnpj', '11.111.111/1111-11');
    $payload = transportJson($invalid);
    transportCheck($invalid->output->status === 422 && $payload['ok'] === false
        && str_contains($payload['message'], 'CNPJ válido'),
        'permissão ' . $capability . ' alcança a validação e rejeita CNPJ inválido antes do contexto/HTTP');
    transportCheck(($payload['csrf'] ?? null) === ['name' => 'csrf_transport', 'hash' => 'csrf-rotated-offline'],
        'erro de validação preserva o token CSRF atual para a próxima ação (' . $capability . ')');
}
foreach ([['cnpj', ['unexpected' => 'array']], ['url', 'https://other.example.invalid/'], ['cep', '0100']] as [$type, $value]) {
    $invalid = transportLookup('POST', $type, $value);
    transportCheck($invalid->output->status === 422 && transportJson($invalid)['ok'] === false,
        'consulta rejeita tipo, valor estruturado ou CEP fora do contrato');
}
transportCheck($transportState['context_calls'] === 0,
    'todos os casos recusados terminam antes de abrir contexto empresarial, banco ou serviço externo');

function transportRecipientOptions(string $verb, array $query, UiRecipientOptionsModel $model): Nfse_nacional
{
    $controller = transportController($verb);
    $controller->input->query = $query;
    (new ReflectionProperty(Nfse_nacional::class, 'fiscal'))->setValue($controller, $model);
    try { $controller->invoice_recipient_options(); }
    catch (UiTransportDenied $expected) { $controller->output->set_status_header($expected->getCode()); }
    return $controller;
}
$transportState['logged'] = true; $transportState['permissions']['nfse_nacional'] = ['view'];
$optionsModel = new UiRecipientOptionsModel();
$options = transportRecipientOptions('GET', ['client_id' => '12', 'invoice_id' => '144', 'company_scope' => 'another-company'], $optionsModel);
transportCheck($options->output->status === 200 && transportJson($options)['ok'] === true && $optionsModel->calls === [[12, 144]], 'opções de tomador usam GET e delegam cliente/fatura tipados ao contexto autorizado');
transportCheck(transportJson($options)['data']['company_scope'] === str_repeat('a', 64), 'empresa enviada na query não substitui o contexto do servidor');
$creationOptionsModel = new UiRecipientOptionsModel();
$creationOptions = transportRecipientOptions('GET', ['client_id' => '12', 'invoice_id' => '0'], $creationOptionsModel);
transportCheck($creationOptions->output->status === 200 && $creationOptionsModel->calls === [[12, 0]], 'nova fatura consulta tomadores com invoice_id zero sem exigir documento existente');
$wrongOptionsMethod = new UiRecipientOptionsModel();
$wrongOptions = transportRecipientOptions('POST', ['client_id' => '12'], $wrongOptionsMethod);
transportCheck($wrongOptions->output->status === 405 && $wrongOptionsMethod->calls === [], 'endpoint de opções recusa POST antes de acessar dados');
$transportState['logged'] = false;
$anonymousOptions = transportRecipientOptions('GET', ['client_id' => '12'], $wrongOptionsMethod);
transportCheck($anonymousOptions->output->status === 403 && $wrongOptionsMethod->calls === [], 'sessão ausente não consulta opções de tomadores');
$transportState['logged'] = true; $transportState['permissions']['nfse_nacional'] = [];
$forbiddenOptions = transportRecipientOptions('GET', ['client_id' => '12'], $wrongOptionsMethod);
transportCheck($forbiddenOptions->output->status === 403 && $wrongOptionsMethod->calls === [], 'permissão fiscal de visualização é exigida antes do contexto');
$transportState['permissions']['nfse_nacional'] = ['view'];
foreach ([['client_id' => ['12']], ['client_id' => '0'], ['client_id' => '12', 'invoice_id' => '../144']] as $query) {
    $invalidOptions = transportRecipientOptions('GET', $query, $wrongOptionsMethod);
    transportCheck($invalidOptions->output->status === 403 && $wrongOptionsMethod->calls === [], 'IDs inválidos de cliente/fatura são recusados antes de consultar o modelo');
}
$deniedModel = new UiRecipientOptionsModel(); $deniedModel->deny = true;
$deniedOptions = transportRecipientOptions('GET', ['client_id' => '12', 'invoice_id' => '144'], $deniedModel);
transportCheck($deniedOptions->output->status === 403 && transportJson($deniedOptions)['ok'] === false && !str_contains($deniedOptions->output->body, 'PRIVATE_OTHER_CUSTOMER'), 'negação do contexto não expõe detalhes privados de outro cliente');

echo 'UI transport: ' . $transportChecks . ' verificações aprovadas.' . PHP_EOL;
