<?php
declare(strict_types=1);

// Actual local HTTP multipart upload through the production controller, model,
// Certificate and Crypto. Database/session/CSRF middleware are explicit fixtures;
// no licensed Perfex core, user certificate, CRM database or external API is used.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');
require dirname(__DIR__) . '/autoload.php';
if (!extension_loaded('curl') || !function_exists('proc_open') || !class_exists(CURLStringFile::class)) {
    throw new RuntimeException('O teste HTTP de A1 requer PHP 8.1+, cURL e proc_open.');
}
$checks = 0;
function uploadCheck(bool $valid, string $label): void
{
    if (!$valid) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks']; echo 'OK: ' . $label . PHP_EOL;
}
function uploadRequest(string $url, array $fields, array $headers = []): array
{
    $request = curl_init($url);
    curl_setopt_array($request, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => $headers, CURLOPT_PROXY => '']);
    $body = curl_exec($request); $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE); curl_close($request);
    if (!is_string($body)) { throw new RuntimeException('Falha na requisição HTTP local de teste.'); }
    try { $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { throw new RuntimeException('A rota de teste não devolveu JSON válido; status ' . $status . '.'); }
    return [$status, $json, $body];
}
function uploadRow(string $directory, string $company = 'company-a'): array
{
    $file = $directory . '/' . $company . '.json';
    return is_file($file) ? json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : [];
}

$directory = sys_get_temp_dir() . '/nfse_settings_' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) { throw new RuntimeException('Não foi possível preparar o teste isolado.'); }
$process = null;
try {
    $configuration = $directory . '/openssl.cnf';
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=OFFLINE\n"); chmod($configuration, 0600);
    $password = 'A1-sintético-offline-€';
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $configuration]);
    $csr = openssl_csr_new(['commonName' => 'A1 HTTP SINTETICO:11222333000181'], $key, ['config' => $configuration, 'digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 2, ['config' => $configuration, 'digest_alg' => 'sha256']);
    $pfx = ''; $emptyPasswordPfx = '';
    if (!openssl_pkcs12_export($certificate, $pfx, $key, $password)
        || !openssl_pkcs12_export($certificate, $emptyPasswordPfx, $key, '')) { throw new RuntimeException('Falha ao gerar A1 sintético.'); }
    $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
    $token = bin2hex(random_bytes(24)); $appKey = bin2hex(random_bytes(32));
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
    if ($socket === false) { throw new RuntimeException('Não foi possível reservar a porta local.'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $binary = getenv('PHP_BIN') ?: PHP_BINARY;
    $command = [$binary, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'upload_max_filesize=5M', '-d', 'post_max_size=6M',
        '-d', 'upload_tmp_dir=' . $directory, '-S', $address, __DIR__ . '/fixtures/settings_upload_router.php'];
    $environment = getenv();
    $environment['NFSE_SETTINGS_TEST_DIR'] = $directory; $environment['NFSE_SETTINGS_TEST_TOKEN'] = $token; $environment['NFSE_SETTINGS_TEST_KEY'] = $appKey;
    $log = $directory . '/server.log'; touch($log); chmod($log, 0600);
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes,
        __DIR__, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar o servidor HTTP local.'); }
    $base = 'http://' . $address;
    $ready = false;
    for ($attempt = 0; $attempt < 60; ++$attempt) {
        $health = curl_init($base . '/health');
        curl_setopt_array($health, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 100, CURLOPT_PROXY => '']);
        $ready = curl_exec($health) === 'ok'; curl_close($health);
        if ($ready) { break; } usleep(50000);
    }
    uploadCheck($ready, 'servidor de teste escuta somente no loopback');
    $url = $base . '/company-a/admin/nfse_nacional/settings';
    $fields = [
        'fixture_csrf' => $token, 'settings[provider][tax_id]' => '11.222.333/0001-81',
        'settings[provider][name]' => 'Emitente sintético HTTP', 'settings[provider][municipality_code]' => '2927408',
        'settings[provider][zip]' => '40030-170', 'settings[provider][street]' => 'Rua do Estabelecimento',
        'settings[provider][number]' => '0', 'settings[provider][complement]' => 'Sala 2',
        'settings[provider][district]' => 'Centro', 'settings[provider][city]' => 'Salvador', 'settings[provider][state]' => 'ba',
        'settings[use_local_certificate]' => '0', 'certificate_password' => $password,
        'certificate' => new CURLStringFile($pfx, 'synthetic.pfx', 'application/x-pkcs12'),
    ];
    [$status, $result, $response] = uploadRequest($url, $fields);
    uploadCheck($status === 200 && ($result['ok'] ?? false) && !empty($result['data']['reload']), 'multipart real importa A1 mesmo com prioridade local desligada');
    uploadCheck(($result['csrf']['name'] ?? '') === 'fixture_csrf' && ($result['csrf']['hash'] ?? '') === $token, 'resposta devolve contrato CSRF para a interface');
    $row = uploadRow($directory); $settings = json_decode($row['settings_json'] ?? '', true, 32, JSON_THROW_ON_ERROR);
    uploadCheck(($settings['provider']['tax_id'] ?? '') === '11222333000181' && ($settings['use_local_certificate'] ?? '') === '1', 'model persiste emitente normalizado e prioridade do A1 local configurado');
    $expectedAddress = ['zip' => '40030170', 'street' => 'Rua do Estabelecimento', 'number' => '0', 'complement' => 'Sala 2',
        'district' => 'Centro', 'city' => 'Salvador', 'state' => 'BA', 'municipality_code' => '2927408'];
    uploadCheck(array_intersect_key($settings['provider'], $expectedAddress) == $expectedAddress,
        'controller e model persistem todos os campos do endereço via multipart, normalizando CEP e UF');
    $cipher = new \NfseNacional\Crypto($appKey, 'nfse_nacional:standalone:fixture_a_');
    $stored = json_decode($cipher->decrypt($row['certificate_enc']), true, 16, JSON_THROW_ON_ERROR);
    uploadCheck(($stored['password'] ?? null) === $password && base64_decode($stored['pfx'] ?? '', true) === $pfx,
        'arquivo e senha chegam completos ao armazenamento protegido do model');
    $metadata = json_decode($row['certificate_meta'], true, 16, JSON_THROW_ON_ERROR);
    uploadCheck(($metadata['fingerprint_sha256'] ?? '') === $fingerprint, 'metadados pertencem ao A1 importado pelo controller');
    uploadCheck(!str_contains(json_encode($row, JSON_THROW_ON_ERROR), $password) && !str_contains($response, $password)
        && !str_contains($response, base64_encode($pfx)), 'senha e material do A1 não aparecem em texto no banco nem na resposta');
    uploadCheck(uploadRow($directory, 'company-b') === [], 'gravação deixa a outra empresa intacta');
    try { (new \NfseNacional\Crypto($appKey, 'nfse_nacional:standalone:fixture_b_'))->decrypt($row['certificate_enc']); $crossScopeDenied = false; }
    catch (RuntimeException $expected) { $crossScopeDenied = true; }
    uploadCheck($crossScopeDenied, 'certificado armazenado não abre com o contexto criptográfico de outra empresa');

    $initialCipher = $row['certificate_enc'];
    $plainFields = $fields; unset($plainFields['certificate'], $plainFields['certificate_password']);
    $plainFields['settings[use_local_certificate]'] = '1';
    [$status, $result] = uploadRequest($url, $plainFields);
    $row = uploadRow($directory);
    uploadCheck($status === 200 && ($result['ok'] ?? false) && $row['certificate_enc'] === $initialCipher
        && json_decode($row['settings_json'], true)['use_local_certificate'] === '1', 'salvar sem novo arquivo preserva o A1 e habilita sua prioridade');
    [$status, $result] = uploadRequest($url, ['fixture_csrf' => $token, 'settings[provider][complement]' => '']);
    $row = uploadRow($directory);
    $updatedAddress = json_decode($row['settings_json'], true)['provider'];
    uploadCheck($status === 200 && ($result['ok'] ?? false) && $updatedAddress['complement'] === ''
        && $updatedAddress['street'] === $expectedAddress['street'] && $updatedAddress['zip'] === $expectedAddress['zip']
        && $row['certificate_enc'] === $initialCipher, 'edição parcial limpa complemento explicitamente sem apagar endereço ou A1');
    $snapshot = json_encode($row, JSON_THROW_ON_ERROR);
    foreach ([
        'senha incorreta' => [array_replace($fields, ['certificate_password' => 'segredo-incorreto-offline']), [], 422],
        'extensão inválida' => [array_replace($fields, ['certificate' => new CURLStringFile($pfx, 'synthetic.txt')]), [], 422],
        'arquivo corrompido' => [array_replace($fields, ['certificate' => new CURLStringFile('not-a-pkcs12', 'synthetic.p12')]), [], 422],
        'emitente ausente' => [array_replace($fields, ['settings[provider][tax_id]' => '']), [], 422],
        'emitente de outro CNPJ' => [array_replace($fields, ['settings[provider][tax_id]' => '11444777000161']), [], 422],
        'cadastro da empresa divergente' => [$fields, ['X-Fixture-Foreign-Company: 1'], 422],
        'CEP inválido' => [array_replace($fields, ['settings[provider][zip]' => '4003A170']), [], 422],
        'município e UF divergentes' => [array_replace($fields, ['settings[provider][state]' => 'SP']), [], 422],
        'sem permissão autenticada' => [$fields, ['X-Fixture-No-Permission: 1'], 403],
        'proteção CSRF desativada' => [$fields, ['X-Fixture-Csrf-Disabled: 1'], 503],
        'token ausente' => [array_replace($fields, ['fixture_csrf' => '']), [], 403],
        'falha de persistência' => [$fields, ['X-Fixture-Write-Failure: 1'], 409],
    ] as $label => [$posted, $headers, $expectedStatus]) {
        [$status, $result, $response] = uploadRequest($url, $posted, $headers);
        uploadCheck($status === $expectedStatus && ($result['ok'] ?? true) === false, $label . ': erro explícito, sem sucesso falso');
        uploadCheck(json_encode(uploadRow($directory), JSON_THROW_ON_ERROR) === $snapshot
            && !str_contains($response, 'segredo-incorreto-offline') && !str_contains($response, $password),
            $label . ': configurações e certificado anterior preservados sem expor senha');
    }
    [$status, $result] = uploadRequest($url, array_replace($fields, ['certificate_password' => '',
        'certificate' => new CURLStringFile($emptyPasswordPfx, 'empty-password.p12', 'application/x-pkcs12')]));
    $row = uploadRow($directory); $stored = json_decode($cipher->decrypt($row['certificate_enc']), true, 16, JSON_THROW_ON_ERROR);
    uploadCheck($status === 200 && ($result['ok'] ?? false) && ($stored['password'] ?? null) === '', 'arquivo PKCS12 com senha vazia válida pode substituir o A1 local');
    $companyBFields = array_replace($fields, ['settings[provider][street]' => 'Rua de Outra Empresa', 'settings[provider][number]' => '85',
        'settings[provider][zip]' => '01001-000', 'settings[provider][city]' => 'São Paulo', 'settings[provider][state]' => 'SP',
        'settings[provider][municipality_code]' => '3550308']);
    [$status, $result] = uploadRequest($base . '/company-b/admin/nfse_nacional/settings', $companyBFields);
    uploadCheck($status === 200 && ($result['ok'] ?? false) && !empty(uploadRow($directory, 'company-b')['certificate_enc'])
        && uploadRow($directory)['certificate_enc'] === $row['certificate_enc'], 'segunda empresa salva seu próprio A1 sem substituir a primeira');
    $companyAAddress = json_decode(uploadRow($directory)['settings_json'], true)['provider'];
    $companyBAddress = json_decode(uploadRow($directory, 'company-b')['settings_json'], true)['provider'];
    uploadCheck($companyAAddress['street'] === 'Rua do Estabelecimento' && $companyAAddress['municipality_code'] === '2927408'
        && $companyBAddress['street'] === 'Rua de Outra Empresa' && $companyBAddress['municipality_code'] === '3550308',
        'endereço cadastrado permanece isolado por empresa na gravação HTTP');
    uploadCheck(count(glob($directory . '/php*') ?: []) === 0, 'temporários de upload são removidos pelo PHP após cada requisição');
    echo $checks . " verificações de upload A1 por HTTP concluídas.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($directory);
}
