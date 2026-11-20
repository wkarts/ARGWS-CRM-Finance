<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
if (!getenv('NFSE_TEST_MYSQL_PORT')) { echo "PENDENTE: configure NFSE_TEST_MYSQL_PORT em uma instância descartável local de MariaDB.\n"; exit(2); }
define('BASEPATH', __DIR__); define('APP_ENC_KEY', str_repeat('offline-database-matrix-key', 3));
$case = $argv[1] ?? 'native_empty';
if (!in_array($case, ['native_prefix', 'native_empty', 'multi_database', 'multi_prefix'], true)) { exit(1); }
$prefix = in_array($case, ['native_prefix', 'multi_prefix'], true) ? 'crm_' : '';
$company = str_starts_with($case, 'multi') && getenv('NFSE_TEST_NATIVE_ONLY') !== '1' ? str_repeat('a', 32) : 'standalone';
if ($company !== 'standalone') {
    define('PME_CONTEXT_READY', true);
    $GLOBALS['pme_context'] = (object) ['companyId' => $company, 'organizationId' => str_repeat('b', 32), 'prefix' => $prefix, 'mode' => $prefix === '' ? 'database' : 'prefix'];
}
require __DIR__ . '/fixtures/PdoCrm.php';
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/helpers/nfse_nacional_helper.php';
$db = PdoCrm::connect('nfse_test_' . $case);
$GLOBALS['ci'] = (object) ['db' => $db];
class App_Model { public $db; public function __construct() { $this->db = get_instance()->db; } }
require dirname(__DIR__) . '/models/Nfse_nacional_model.php';
function &get_instance() { return $GLOBALS['ci']; }
function db_prefix() { return $GLOBALS['prefix']; }
function get_staff_user_id() { return 7; }
$n = 0;
function verify($ok, $message) { global $n; if (!$ok) { throw new RuntimeException($message); } ++$n; }
$all = $db->query('SHOW TABLES')->result_array();
foreach ($all as $row) { $db->query('DROP TABLE `' . array_values($row)[0] . '`'); }
$_GET = ['prefix' => 'other_', 'database' => 'outside', 'company_id' => 'attacker'];
$_POST = $_GET;
require dirname(__DIR__) . '/install.php';
verify($db->table_exists($prefix . 'nfse_nacional_settings'), 'native db_prefix used verbatim');
verify(!$db->table_exists('tblnfse_nacional_settings') && !$db->table_exists('other_nfse_nacional_settings'), 'no invented or request-derived prefix');
verify((getenv('NFSE_TEST_NATIVE_ONLY') !== '1') || (!defined('PME_CONTEXT_READY') && !isset($GLOBALS['pme_context']) && !class_exists('Multiempresa\\Domain\\Context', false)), 'native operation does not load or require Multiempresa context/classes');
$model = new Nfse_nacional_model(); $GLOBALS['ci']->nfse_nacional_model = $model;
$legacyCrypto = new NfseNacional\Crypto(APP_ENC_KEY, 'nfse_nacional:' . $company . ':' . $prefix);
$old = NfseNacional\Config::defaults(); unset($old['automations']); $old['auth_unified'] = '0';
$old['environment'] = '2'; $old['provider']['tax_id'] = '11222333000181';
$old['automation'] = ['mode' => 'selected', 'trigger' => 'paid', 'delay_minutes' => '30', 'staff_id' => '7'];
$old['accounting_recipients'] = [];
for ($i = 0; $i < 15; ++$i) { $old['accounting_recipients'][] = ['id' => str_pad(dechex($i + 1), 16, '0', STR_PAD_LEFT), 'name' => 'Contato ' . $i, 'email' => 'p' . $i . '@example.invalid']; }
$record = ['pfx' => base64_encode('synthetic-record'), 'password' => 'old-secret', 'metadata' => ['tax_id' => '11222333000181']];
$material = ['1' => ['certificate' => $record, 'login' => ['username' => 'prod', 'password' => 'secret-1']],
    '2' => ['certificate' => $record + ['x' => 1], 'login' => ['username' => 'test', 'password' => 'secret-2']]];
$db->where('id', 1)->update($model->table('settings'), ['settings_json' => json_encode($old), 'certificate_enc' => $legacyCrypto->encrypt(json_encode($record)), 'auth_material_enc' => $legacyCrypto->encrypt(json_encode($material))]);
$legacyBytes = $model->settingsRow()['certificate_enc'];
verify($model->crypto()->decrypt($legacyBytes) === json_encode($record), 'legacy cryptographic context unchanged');
$targets = $model->table('automation_targets'); $jobs = $model->table('automation_jobs');
$db->query('ALTER TABLE `' . $targets . '` DROP PRIMARY KEY, ADD PRIMARY KEY(client_id,recipient_selection), DROP COLUMN trigger_event');
$db->query('ALTER TABLE `' . $jobs . '` DROP INDEX invoice_environment_trigger, ADD UNIQUE KEY invoice_environment(invoice_id,environment)');
$db->insert($targets, ['client_id' => 9, 'recipient_selection' => '*']);
$db->failOn = 'ADD UNIQUE KEY invoice_environment_trigger'; $failed = false;
try { NfseNacional\Upgrade083::run($model); } catch (RuntimeException $error) { $failed = true; }
verify($failed, 'interrupted upgrade is reported');
verify($model->crypto()->decrypt($legacyBytes) === json_encode($record), 'credential remains readable after interrupted upgrade');
NfseNacional\Upgrade083::run($model); NfseNacional\Upgrade083::run($model);
verify(count($model->settings()['accounting_recipients']) === 15, '15 previous recipients preserved');
verify($model->authMaterial()['1']['login'] === $material['1']['login'] && $model->authMaterial()['2']['login'] === $material['2']['login'], 'different legacy logins retained');
verify($model->settings()['auth_unified'] === '1', 'shared authentication migrated');
verify($model->settings()['automations']['paid']['mode'] === 'selected' && $model->settings()['automations']['created']['mode'] === 'disabled', 'single old automation maps only to its original type');
verify($db->where('client_id', 9)->get($targets)->row_array()['trigger_event'] === 'paid', 'previous selections keep their type');
verify($db->insert($targets, ['client_id' => 9, 'recipient_selection' => '*', 'trigger_event' => 'created']), 'same target can belong to both types');
verify(!$db->insert($targets, ['client_id' => 9, 'recipient_selection' => '*', 'trigger_event' => 'created']), 'duplicate target rejected by database');
$rates = $model->table('competence_rates');
$row = ['issuer' => '11222333000181', 'competence' => '2026-11', 'rate' => '6.25', 'updated_by' => 7, 'updated_at' => date('Y-m-d H:i:s')];
verify($db->insert($rates, $row) && !$db->insert($rates, $row), 'competence uniqueness enforced in database');
$applied = $model->settings(); $applied['variable_simple_rate'] = '1'; $model->saveSettings($applied);
verify($model->applyCompetenceRate(['competence' => '2026-11-30', 'values' => []], true)['values']['simple_rate'] === '6.25', 'fiscal competence resolves from real database');
$before = $model->settingsRow(); require dirname(__DIR__) . '/install.php';
verify($model->settingsRow()['settings_json'] === $before['settings_json'], 'reactivation/install retry does not replace settings');
// Load the real entry point without dispatching admin UI hooks.
function register_activation_hook($name, $callback) { $GLOBALS['activation_callback'] = $callback; }
function register_uninstall_hook($name, $callback) { $GLOBALS['uninstall_callback'] = $callback; }
function hooks() { static $hooks; return $hooks ??= new class {
    public function add_action(...$args) { $GLOBALS['registered_actions'][] = $args[0]; }
    public function add_filter(...$args) {}
}; }
require dirname(__DIR__) . '/nfse_nacional.php';
$activation = $GLOBALS['activation_callback']; $activation();
verify($model->settingsRow()['settings_json'] === $before['settings_json'], 'real activation callback is repeatable without replacing settings');
verify(!array_filter($GLOBALS['registered_actions'], static fn($action) => str_contains($action, 'deactivat')), 'deactivation has no module-side data cleanup or teardown');
$activation();
verify($model->crypto()->decrypt($legacyBytes) === json_encode($record), 'deactivation/reactivation path retains readable legacy credentials');
// Same table names on another connection cannot alter this database's settings.
$other = PdoCrm::connect('nfse_test_other_' . $case);
$other->query('CREATE TABLE IF NOT EXISTS sentinel(id INT PRIMARY KEY)');
verify(!$other->table_exists($model->table('settings')), 'database connections stay isolated');
verify($db->where('id', 1)->get($model->table('settings'))->row_array()['settings_json'] === $before['settings_json'], 'current model uses its original connection');
echo "OK: $case — $n verificações em MariaDB real com contratos CodeIgniter adaptados; sem instalação Perfex externa.\n";
