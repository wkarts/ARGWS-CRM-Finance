<?php
declare(strict_types=1);

// Loopback test server only. This is not a Perfex route or a usable application.
if (PHP_SAPI !== 'cli-server' || !getenv('NFSE_SETTINGS_TEST_DIR') || !getenv('NFSE_SETTINGS_TEST_TOKEN')) {
    http_response_code(404); exit;
}
define('BASEPATH', dirname(__DIR__) . '/');
define('APP_ENC_KEY', (string) getenv('NFSE_SETTINGS_TEST_KEY'));

$fixturePath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($fixturePath === '/health') { echo 'ok'; return; }
if (!preg_match('~^/(company-a|company-b)/admin/nfse_nacional/settings$~D', $fixturePath, $route)) {
    http_response_code(404); return;
}
$fixtureCompany = $route[1];
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); return; }
// The real Perfex session and CSRF middleware are outside this fixture. A local
// token keeps the transport test closed and proves the request carries a token.
if (!is_string($_POST['fixture_csrf'] ?? null)
    || !hash_equals((string) getenv('NFSE_SETTINGS_TEST_TOKEN'), $_POST['fixture_csrf'])) {
    http_response_code(403); echo '{"ok":false,"message":"Token de teste inválido."}'; return;
}

final class UploadFixtureInput
{
    public function method(): string { return strtolower($_SERVER['REQUEST_METHOD'] ?? ''); }
    public function post(string $key, bool $filter = true) { return $_POST[$key] ?? null; }
    public function get(string $key) { return $_GET[$key] ?? null; }
}
final class UploadFixtureOutput
{
    public function set_status_header(int $status): self { http_response_code($status); return $this; }
    public function set_content_type(string $mime, string $charset = ''): self
    { header('Content-Type: ' . $mime . ($charset !== '' ? '; charset=' . $charset : '')); return $this; }
    public function set_output(string $body): self { echo $body; return $this; }
}
final class UploadFixtureConfig
{
    public function item(string $key) { return $key === 'csrf_protection' && empty($_SERVER['HTTP_X_FIXTURE_CSRF_DISABLED']); }
}
final class UploadFixtureSecurity
{
    public function get_csrf_token_name(): string { return 'fixture_csrf'; }
    public function get_csrf_hash(): string { return (string) getenv('NFSE_SETTINGS_TEST_TOKEN'); }
}
final class UploadFixtureQuery
{
    public function __construct(private array $row) {}
    public function row_array(): array { return $this->row; }
}
final class UploadFixtureDatabase
{
    public string $database = 'synthetic-upload-test';
    public string $hostname = 'loopback';
    public bool $save_queries = false;
    public bool $db_debug = false;
    private array $where = [];
    public function where(string $field, $value): self { $this->where[$field] = $value; return $this; }
    public function get(string $table): UploadFixtureQuery
    {
        $this->assertTable($table); $this->where = [];
        $file = $this->file();
        $row = is_file($file) ? json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : [
            'id' => 1, 'settings_json' => json_encode(\NfseNacional\Config::defaults(), JSON_THROW_ON_ERROR),
            'certificate_enc' => null, 'certificate_meta' => null,
        ];
        return new UploadFixtureQuery($row);
    }
    public function update(string $table, array $changes): bool
    {
        $this->assertTable($table);
        if (!empty($_SERVER['HTTP_X_FIXTURE_WRITE_FAILURE'])) { $this->where = []; return false; }
        $row = $this->get($table)->row_array();
        $bytes = json_encode(array_replace($row, $changes), JSON_THROW_ON_ERROR);
        $file = $this->file();
        if (file_put_contents($file, $bytes, LOCK_EX) !== strlen($bytes)) { return false; }
        chmod($file, 0600); return true;
    }
    private function assertTable(string $table): void
    {
        if ($table !== db_prefix() . 'nfse_nacional_settings' || $this->where !== ['id' => 1]) {
            throw new RuntimeException('Contrato de persistência inesperado no teste.');
        }
    }
    private function file(): string { return getenv('NFSE_SETTINGS_TEST_DIR') . '/' . $GLOBALS['fixtureCompany'] . '.json'; }
}
class App_Model
{
    public UploadFixtureDatabase $db;
    public function __construct() { $this->db = get_instance()->db; }
}
class AdminController
{
    public UploadFixtureInput $input;
    public UploadFixtureOutput $output;
    public UploadFixtureConfig $config;
    public UploadFixtureSecurity $security;
}
function &get_instance() { return $GLOBALS['uploadFixtureCi']; }
function db_prefix(): string { return $GLOBALS['fixtureCompany'] === 'company-a' ? 'fixture_a_' : 'fixture_b_'; }
function is_staff_logged_in(): bool { return empty($_SERVER['HTTP_X_FIXTURE_NO_PERMISSION']); }
function is_admin(): bool { return true; }
function get_option(string $name): string
{
    return $name === 'company_vat' && !empty($_SERVER['HTTP_X_FIXTURE_FOREIGN_COMPANY']) ? '11444777000161' : '';
}
function log_message($level, $message): void {}

require dirname(__DIR__, 2) . '/autoload.php';
require dirname(__DIR__, 2) . '/helpers/nfse_nacional_helper.php';
require dirname(__DIR__, 2) . '/models/Nfse_nacional_model.php';
require dirname(__DIR__, 2) . '/controllers/Nfse_nacional.php';
$uploadFixtureCi = (object) ['db' => new UploadFixtureDatabase()];
$model = new Nfse_nacional_model();
$controller = (new ReflectionClass(Nfse_nacional::class))->newInstanceWithoutConstructor();
$controller->input = new UploadFixtureInput();
$controller->output = new UploadFixtureOutput();
$controller->config = new UploadFixtureConfig();
$controller->security = new UploadFixtureSecurity();
(new ReflectionProperty(Nfse_nacional::class, 'fiscal'))->setValue($controller, $model);
$controller->settings();
