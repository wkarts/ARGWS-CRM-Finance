<?php
declare(strict_types=1);

// Only the isolated loopback test runner may load this fixture. It is not a CRM route.
if (PHP_SAPI !== 'cli-server' || !getenv('NFSE_IBPT_IMPORT_TEST_DIR') || !getenv('NFSE_IBPT_IMPORT_TEST_TOKEN')) {
    http_response_code(404); exit;
}
define('BASEPATH', dirname(__DIR__) . '/');
define('APPPATH', (string) getenv('NFSE_IBPT_IMPORT_TEST_DIR') . '/');
$fixturePath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($fixturePath === '/health') { echo 'ok'; return; }
if (!preg_match('~^/(company-a|company-b)/admin/nfse_nacional/ibpt_import$~D', $fixturePath, $route)) {
    http_response_code(404); return;
}
$ibptImportFixtureCompany = $route[1];
// Perfex's session/CSRF middleware is outside this test. This fixture token closes
// the local endpoint and checks that multipart carries a token before the controller.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (!is_string($_POST['fixture_csrf'] ?? null)
    || !hash_equals((string) getenv('NFSE_IBPT_IMPORT_TEST_TOKEN'), $_POST['fixture_csrf']))) {
    http_response_code(403); header('Content-Type: application/json');
    echo '{"ok":false,"message":"Token de teste inválido."}'; return;
}

final class IbptImportFixtureInput
{
    public function method(): string { return strtolower($_SERVER['REQUEST_METHOD'] ?? ''); }
    public function post(string $key, bool $filter = true): mixed { return $_POST[$key] ?? null; }
}
final class IbptImportFixtureOutput
{
    public function set_status_header(int $status): self { http_response_code($status); return $this; }
    public function set_header(string $header): self { header($header); return $this; }
    public function set_content_type(string $mime, string $charset = ''): self
    { header('Content-Type: ' . $mime . ($charset !== '' ? '; charset=' . $charset : '')); return $this; }
    public function set_output(string $body): self { echo $body; return $this; }
}
final class IbptImportFixtureConfig
{
    public function item(string $key): mixed
    {
        return match ($key) {
            'csrf_protection' => empty($_SERVER['HTTP_X_FIXTURE_CSRF_DISABLED']),
            'cache_path' => APPPATH . 'cache/', 'log_path' => APPPATH . 'logs/', default => null,
        };
    }
}
final class IbptImportFixtureSecurity
{
    public function get_csrf_token_name(): string { return 'fixture_csrf'; }
    public function get_csrf_hash(): string { return (string) getenv('NFSE_IBPT_IMPORT_TEST_TOKEN'); }
}
final class IbptImportFixtureModel
{
    public function settings(): array
    {
        $row = json_decode((string) file_get_contents(APPPATH . $GLOBALS['ibptImportFixtureCompany'] . '.json'), true, 16, JSON_THROW_ON_ERROR);
        if (!empty($_SERVER['HTTP_X_FIXTURE_BAD_ISSUER'])) { $row['provider']['municipality_code'] = '3550308'; }
        return $row;
    }
    public function __call(string $name, array $arguments): never
    { throw new LogicException('Importar IBPT não pode gravar configurações, certificados ou documentos.'); }
}
class AdminController
{
    public IbptImportFixtureInput $input;
    public IbptImportFixtureOutput $output;
    public IbptImportFixtureConfig $config;
    public IbptImportFixtureSecurity $security;
}
function &get_instance() { return $GLOBALS['ibptImportFixtureCi']; }
function db_prefix(): string { return $GLOBALS['ibptImportFixtureCompany'] === 'company-a' ? 'fixture_a_' : 'fixture_b_'; }
function is_staff_logged_in(): bool { return empty($_SERVER['HTTP_X_FIXTURE_NO_SESSION']); }
function get_staff_user_id(): int { return is_staff_logged_in() ? 7 : 0; }
function is_admin(): bool { return false; }
function staff_can(string $action, string $feature): bool
{
    return $feature === 'nfse_nacional' && in_array($action, ['view', 'settings', 'logs'], true)
        && !($action === 'view' && !empty($_SERVER['HTTP_X_FIXTURE_NO_VIEW']))
        && !($action === 'settings' && !empty($_SERVER['HTTP_X_FIXTURE_NO_SETTINGS']));
}
function admin_url(string $path = ''): string { return '/'. $GLOBALS['ibptImportFixtureCompany'] . '/admin/' . $path; }
function log_message($level, $message): void {}

require dirname(__DIR__, 2) . '/controllers/Nfse_nacional.php';
$ibptImportFixtureCi = (object) ['config' => new IbptImportFixtureConfig(),
    'db' => (object) ['database' => 'synthetic-ibpt-import', 'hostname' => 'loopback']];
$controller = (new ReflectionClass(Nfse_nacional::class))->newInstanceWithoutConstructor();
$controller->input = new IbptImportFixtureInput();
$controller->output = new IbptImportFixtureOutput();
$controller->config = $ibptImportFixtureCi->config;
$controller->security = new IbptImportFixtureSecurity();
(new ReflectionProperty(Nfse_nacional::class, 'fiscal'))->setValue($controller, new IbptImportFixtureModel());
(new ReflectionProperty(Nfse_nacional::class, 'logFactory'))->setValue($controller,
    static fn(): \NfseNacional\OperationalLog => \NfseNacional\OperationalLog::current());
$controller->ibpt_import();
