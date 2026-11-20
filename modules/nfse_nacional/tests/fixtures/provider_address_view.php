<?php
declare(strict_types=1);

// Render the actual native settings partial for offline DOM integration checks.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__, 2) . '/autoload.php';
function admin_url(string $path = ''): string { return 'https://crm.example.invalid/empresa-a/admin/' . $path; }
function nfse_nacional_can(string $capability): bool { return true; }
function &get_instance() {
    static $ci;
    if (!$ci) {
        $ci = (object) ['security' => new class {
            public function get_csrf_token_name(): string { return 'fixture_csrf'; }
            public function get_csrf_hash(): string { return 'fixture-initial'; }
        }];
    }
    return $ci;
}
$settings = \NfseNacional\Config::defaults();
$settings['provider'] = array_replace($settings['provider'], [
    'tax_id' => '11222333000181', 'name' => 'Emitente de demonstração',
    'municipal_registration' => '000123', 'email' => 'financeiro@example.test', 'phone' => '71999999999',
    'zip' => '40020000', 'street' => 'Rua de demonstração', 'number' => '20',
    'complement' => 'Sala 3', 'district' => 'Centro', 'city' => 'Salvador', 'state' => 'BA',
    'municipality_code' => '2927408',
]);
if (isset($argv[1])) { $settings['provider'] = array_replace($settings['provider'], json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR)); }
echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body><nav><i class="fa fa-users"></i>Menu nativo</nav>';
echo '<form id="settings-form" action="' . admin_url('settings') . '" method="post" enctype="multipart/form-data"><input name="unrelated_native_option" value="unchanged">';
require dirname(__DIR__, 2) . '/views/settings.php';
echo '<button type="submit">Salvar</button></form></body></html>';
