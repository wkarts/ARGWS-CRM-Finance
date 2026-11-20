<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'NfseNacional\\')) { require_once dirname(__DIR__) . '/libraries/NfseNacional/' . substr($class, 13) . '.php'; }
});

use NfseNacional\Certificate;
use NfseNacional\Pkcs12Reader;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$checks; echo 'OK: ' . $label . PHP_EOL;
}
function refuses(callable $operation, string $label, ?callable $predicate = null): void {
    try { $operation(); } catch (Throwable $error) { check($predicate === null || $predicate($error), $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}
function fixture(#[\SensitiveParameter] string $pem, #[\SensitiveParameter] string $password, array $options = []): string {
    // Only test generation needs a seekable PEM bundle. It contains synthetic
    // material, is mode0600, and is removed on every path. The reader never writes it.
    $input = tempnam(sys_get_temp_dir(), 'nfse_pkcs12_fixture_');
    if ($input === false) { throw new RuntimeException('Não foi possível preparar a fixture A1.'); }
    chmod($input, 0600); file_put_contents($input, $pem);
    try {
        $command = array_merge(['/usr/bin/openssl', 'pkcs12', '-export', '-in', $input, '-passout', 'fd:3'], $options);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r']], $pipes, null,
            ['OPENSSL_CONF' => '/dev/null', 'LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8'], ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Não foi possível gerar a fixture A1.'); }
        fwrite($pipes[3], $password . "\n"); fclose($pipes[3]); fclose($pipes[0]);
        $bytes = stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        // No fixture password, key material or raw subprocess output is printed.
        if ($exit !== 0 || $bytes === false || $bytes === '') { throw new RuntimeException('Falha ao exportar a fixture A1 (código ' . $exit . ').'); }
        return $bytes;
    } finally { unlink($input); }
}

if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open') || !is_executable('/usr/bin/openssl')) {
    echo "IGNORADO: testes de compatibilidade exigem OpenSSL CLI 3 e pipes Unix.\n"; exit(0);
}
$configuration = tempnam(sys_get_temp_dir(), 'nfse_pkcs12_config_');
if ($configuration === false) { throw new RuntimeException('Não foi possível preparar a configuração pública da fixture.'); }
chmod($configuration, 0600);
file_put_contents($configuration, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=OFFLINE\n");
try {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $configuration]);
    $csr = openssl_csr_new(['commonName' => 'A1 SINTETICO OFFLINE:11222333000181'], $key, ['config' => $configuration, 'digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 2, ['config' => $configuration, 'digest_alg' => 'sha256'], 71);
    openssl_x509_export($cert, $certPem); openssl_pkey_export($key, $keyPem, null, ['config' => $configuration]);
    $fingerprint = openssl_x509_fingerprint($cert, 'sha256');
    $beforeCiphers = openssl_get_cipher_methods();
    $beforeConfiguration = getenv('OPENSSL_CONF');
    $beforeModules = getenv('OPENSSL_MODULES');
    $legacyOptions = ['-legacy', '-keypbe', 'PBE-SHA1-3DES', '-certpbe', 'PBE-SHA1-RC2-40', '-macalg', 'sha1'];
    $cases = [
        'AES-128/PBES2' => ['senha-offline', ['-keypbe', 'AES-128-CBC', '-certpbe', 'AES-128-CBC', '-macalg', 'sha256']],
        'AES-192/PBES2' => ['senha-offline', ['-keypbe', 'AES-192-CBC', '-certpbe', 'AES-192-CBC', '-macalg', 'sha384']],
        'AES-256/PBES2' => ['senha-offline', ['-keypbe', 'AES-256-CBC', '-certpbe', 'AES-256-CBC', '-macalg', 'sha512']],
        '3DES/PKCS12' => ['senha-offline', ['-legacy', '-keypbe', 'PBE-SHA1-3DES', '-certpbe', 'PBE-SHA1-3DES', '-macalg', 'sha1']],
        'RC2-40/3DES' => ['senha-offline', $legacyOptions],
        'RC2-128/3DES' => ['senha-offline', ['-legacy', '-keypbe', 'PBE-SHA1-3DES', '-certpbe', 'PBE-SHA1-RC2-128', '-macalg', 'sha256']],
        'RC2 com senha Unicode' => ['A1-ação-ÁÉÇ-€-漢字', $legacyOptions],
        'RC2 com senha longa' => [str_repeat('Senha-longa-0123456789-', 20), $legacyOptions],
        'RC2 com senha vazia' => ['', $legacyOptions],
    ];
    $helpProcess = proc_open(['/usr/bin/openssl', 'pkcs12', '-help'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $helpPipes, null,
        ['OPENSSL_CONF' => '/dev/null', 'LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8'], ['bypass_shell' => true]);
    if (!is_resource($helpProcess)) { throw new RuntimeException('Não foi possível conferir os recursos do OpenSSL de teste.'); }
    $help = stream_get_contents($helpPipes[1]); fclose($helpPipes[1]); proc_close($helpProcess);
    if (is_string($help) && str_contains($help, '-pbmac1_pbkdf2')) {
        $cases['AES-256 com PBMAC1/PBKDF2'] = ['senha-offline', ['-keypbe', 'AES-256-CBC', '-certpbe', 'AES-256-CBC',
            '-pbmac1_pbkdf2', '-pbmac1_pbkdf2_md', 'sha256', '-macalg', 'sha256']];
    } else { echo "IGNORADO: fixture PBMAC1 exige OpenSSL CLI com -pbmac1_pbkdf2.\n"; }
    $fixtures = [];
    foreach ($cases as $label => [$password, $options]) {
        $pfx = fixture($certPem . $keyPem, $password, $options);
        $fixtures[$label] = $pfx;
        $parts = Pkcs12Reader::read($pfx, $password);
        check(openssl_x509_fingerprint($parts['cert'], 'sha256') === $fingerprint
            && openssl_x509_check_private_key($parts['cert'], $parts['pkey']), $label . ': leitura isolada mantém o certificado e sua chave');
        check(Certificate::fromPkcs12($pfx, $password, '11222333000181')->metadata()['fingerprint_sha256'] === $fingerprint,
            $label . ': importação integrada preserva identidade do emitente');
    }
    $parts = [];
    $nativeLegacy = @openssl_pkcs12_read($fixtures['RC2-40/3DES'], $parts, 'senha-offline');
    while (openssl_error_string() !== false) {}
    if (!$nativeLegacy) { check(true, 'RC2 é aberto pelo fallback sem habilitar o provider legado no PHP'); }
    else { echo "INFO: este PHP já aceita RC2 nativamente; a leitura isolada também foi exercitada.\n"; }
    if (isset($fixtures['AES-256 com PBMAC1/PBKDF2'])) {
        $nativePbmac1 = @openssl_pkcs12_read($fixtures['AES-256 com PBMAC1/PBKDF2'], $parts, 'senha-offline');
        while (openssl_error_string() !== false) {}
        echo $nativePbmac1 ? "INFO: PBMAC1 é aceito também pelo PHP nativo.\n"
            : "INFO: PBMAC1 é aberto pelo fallback; o PHP nativo desta execução não o aceita.\n";
    }

    foreach (['AES-256/PBES2', 'RC2-40/3DES'] as $label) {
        refuses(fn() => Certificate::fromPkcs12($fixtures[$label], 'senha-incorreta-confidencial', '11222333000181'),
            $label . ': senha errada não é aceita nem exposta', fn($e) => !str_contains($e->getMessage(), 'senha-incorreta-confidencial') && !str_contains($e->getMessage(), 'BEGIN'));
        $damaged = $fixtures[$label]; $damaged[strlen($damaged) - 1] = chr(ord($damaged[strlen($damaged) - 1]) ^ 1);
        refuses(fn() => Certificate::fromPkcs12($damaged, 'senha-offline', '11222333000181'), $label . ': integridade adulterada é rejeitada');
    }
    refuses(fn() => Pkcs12Reader::read('arquivo-corrompido', 'senha-offline'), 'Conteúdo que não é PKCS12 é rejeitado');
    $withoutMac = fixture($certPem . $keyPem, 'senha-offline', array_merge($legacyOptions, ['-nomac']));
    refuses(fn() => Pkcs12Reader::read($withoutMac, 'senha-offline'), 'Fallback recusa PKCS12 sem MAC mesmo quando a decriptação é possível');
    $nativeWithoutMac = fixture($certPem . $keyPem, 'senha-offline', ['-keypbe', 'AES-256-CBC', '-certpbe', 'AES-256-CBC', '-nomac']);
    check(Certificate::fromPkcs12($nativeWithoutMac, 'senha-offline', '11222333000181')->metadata()['fingerprint_sha256'] === $fingerprint,
        'Caminho nativo preserva a aceitação de AES sem MAC pelo OpenSSL');
    refuses(fn() => Pkcs12Reader::read($nativeWithoutMac, 'senha-offline'), 'Leitura adicional do mesmo AES sem MAC é explicitamente recusada');
    refuses(fn() => Certificate::fromPkcs12($fixtures['RC2-40/3DES'], 'senha-offline', '11444777000161'),
        'Fallback continua rejeitando CPF/CNPJ de outro emitente', fn($e) => str_contains($e->getMessage(), 'CPF/CNPJ'));

    $otherCsr = openssl_csr_new(['commonName' => 'SEGUNDA IDENTIDADE OFFLINE:11444777000161'], $key, ['config' => $configuration, 'digest_alg' => 'sha256']);
    $otherCert = openssl_csr_sign($otherCsr, null, $key, 2, ['config' => $configuration, 'digest_alg' => 'sha256'], 72);
    openssl_x509_export($otherCert, $otherPem);
    $ambiguous = fixture($certPem . $otherPem . $keyPem, 'senha-offline', $legacyOptions);
    refuses(fn() => Pkcs12Reader::read($ambiguous, 'senha-offline'), 'Duas identidades associadas à mesma chave são recusadas');
    $extract = new ReflectionMethod(Pkcs12Reader::class, 'extractParts');
    refuses(fn() => $extract->invoke(null, $certPem . $keyPem . $keyPem), 'Extração recusa múltiplas chaves privadas');
    refuses(fn() => $extract->invoke(null, $certPem), 'Extração recusa arquivo sem chave privada');

    $expensive = fixture($certPem . $keyPem, 'senha-offline', array_merge($legacyOptions, ['-iter', '1000001']));
    $started = microtime(true);
    refuses(fn() => Pkcs12Reader::read($expensive, 'senha-offline'), 'Fallback limita as iterações anunciadas pelo arquivo', fn($e) => str_contains($e->getMessage(), 'limite'));
    check(microtime(true) - $started < 8.5, 'Limite interrompe a leitura dentro do prazo do processo');
    refuses(fn() => Pkcs12Reader::read(str_repeat('x', 4194305), 'senha-offline'), 'Arquivo acima de 4 MB é recusado antes do subprocesso');
    foreach (["senha\nsegunda-linha", "senha\r", "senha\0", str_repeat('x', 1024)] as $unsupportedPassword) {
        refuses(fn() => Pkcs12Reader::read($fixtures['RC2-40/3DES'], $unsupportedPassword), 'Senha fora do contrato do pipe é recusada sem truncamento');
    }
    check(openssl_get_cipher_methods() === $beforeCiphers, 'Providers e algoritmos globais do PHP permanecem inalterados');
    check(getenv('OPENSSL_CONF') === $beforeConfiguration && getenv('OPENSSL_MODULES') === $beforeModules,
        'Variáveis globais de configuração e providers não são modificadas');
    echo $checks . " verificações PKCS12 concluídas.\n";
} finally { unlink($configuration); }
