<?php
declare(strict_types=1);

// Actual multipart through the production controller, UF resolver, IBPT parser and
// private store. Session/model/CSRF middleware are explicit local fixtures. No CRM
// database, certificate, external HTTP, SMTP or fiscal transmission is involved.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('curl') || !function_exists('proc_open') || !class_exists(CURLStringFile::class)) {
    throw new RuntimeException('O teste HTTP IBPT requer PHP 8.1+, cURL e proc_open.');
}
$checks = 0;
function ibptImportHttpCheck(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function ibptImportHttpRow(array $changes = []): array
{
    $today = new DateTimeImmutable('now', new DateTimeZone('America/Bahia'));
    return array_replace(['codigo' => '101011000', 'ex' => '', 'tipo' => '1', 'descricao' => 'Serviço sintético HTTP 073 çã',
        'nacionalfederal' => '13.45', 'importadosfederal' => '15.45', 'estadual' => '0', 'municipal' => '5',
        'vigenciainicio' => $today->modify('-1 day')->format('d/m/Y'),
        'vigenciafim' => $today->modify('+30 days')->format('d/m/Y'),
        'chave' => 'HTTP073', 'versao' => '26.2.C', 'fonte' => 'IBPT/empresometro.com.br'], $changes);
}
function ibptImportHttpCsv(array $rows): string
{
    $stream = fopen('php://temp', 'w+b'); fputcsv($stream, array_keys(ibptImportHttpRow()), ';', '"', '');
    foreach ($rows as $row) { fputcsv($stream, $row, ';', '"', ''); }
    rewind($stream); $bytes = stream_get_contents($stream); fclose($stream); return $bytes;
}
function ibptImportHttpAlternativeRow(array $changes = []): array
{
    $base = ibptImportHttpRow();
    return array_replace(['Codigo' => '101011000', 'Excecao' => '', 'Tipo' => 'nbs', 'UF' => 'BA',
        'Tabela' => '26.2.C', 'Descricao' => 'Serviço sintético HTTP 074 "çã"; região',
        'AliquotaNacionalFederal' => '13.45', 'AliquotaImportadosFederal' => '15.45',
        'AliquotaEstadual' => '0', 'AliquotaMunicipal' => '5',
        'VigenciaInicio' => $base['vigenciainicio'], 'VigenciaFim' => $base['vigenciafim']], $changes);
}
function ibptImportHttpAlternativeCsv(array $rows, string $delimiter = ';', array $columns = []): string
{
    $columns = $columns ?: array_keys($rows[0]);
    $stream = fopen('php://temp', 'w+b'); fputcsv($stream, $columns, $delimiter, '"', '');
    foreach ($rows as $row) {
        fputcsv($stream, array_map(static fn(string $column): string => $row[$column] ?? '', $columns), $delimiter, '"', '');
    }
    rewind($stream); $bytes = stream_get_contents($stream); fclose($stream); return $bytes;
}
function ibptImportHttpRequest(string $url, array $fields = [], array $headers = [], string $verb = 'POST'): array
{
    $curl = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_PROXY => ''];
    if ($verb === 'POST') { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $fields; }
    else { $options[CURLOPT_CUSTOMREQUEST] = $verb; }
    curl_setopt_array($curl, $options); $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    if (!is_string($body)) { throw new RuntimeException('Falha no transporte HTTP local IBPT.'); }
    try { $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { throw new RuntimeException('Resposta HTTP IBPT sem JSON; status ' . $status . ': ' . substr($body, 0, 200)); }
    return [$status, $json, $body];
}
function ibptImportHttpFiles(string $directory): array
{
    $files = glob($directory . '/cache/nfse_nacional_ibpt/*/table-*.php') ?: [];
    $result = []; foreach ($files as $file) { $result[$file] = file_get_contents($file); } ksort($result); return $result;
}
function ibptImportHttpRemove(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (is_dir($path) ? scandir($path) : [] as $name) {
        if ($name !== '.' && $name !== '..') { ibptImportHttpRemove($path . '/' . $name); }
    }
    if (is_dir($path)) { rmdir($path); }
}

$directory = sys_get_temp_dir() . '/nfse-ibpt-import-http-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) { throw new RuntimeException('Não foi possível criar o teste isolado.'); }
$process = null;
try {
    $saved = json_encode(['provider' => ['state' => 'BA', 'municipality_code' => '2928703'],
        'certificate_enc' => 'CERTIFICADO_SINTETICO_PRESERVADO', 'service' => ['nbs' => '']], JSON_THROW_ON_ERROR);
    foreach (['company-a', 'company-b'] as $company) {
        file_put_contents($directory . '/' . $company . '.json', $saved); chmod($directory . '/' . $company . '.json', 0600);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
    if ($socket === false) { throw new RuntimeException('Não foi possível reservar porta local.'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $token = bin2hex(random_bytes(24)); $environment = getenv();
    $environment['NFSE_IBPT_IMPORT_TEST_DIR'] = $directory; $environment['NFSE_IBPT_IMPORT_TEST_TOKEN'] = $token;
    $log = $directory . '/server.log'; touch($log); chmod($log, 0600);
    $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'upload_max_filesize=10M', '-d', 'post_max_size=12M', '-d', 'upload_tmp_dir=' . $directory,
        '-d', 'disable_functions=curl_init', '-S', $address, __DIR__ . '/fixtures/ibpt_import_router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes,
        __DIR__, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar servidor HTTP IBPT.'); }
    $base = 'http://' . $address; $ready = false;
    for ($attempt = 0; $attempt < 60; ++$attempt) {
        $health = curl_init($base . '/health');
        curl_setopt_array($health, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 100, CURLOPT_PROXY => '']);
        $ready = curl_exec($health) === 'ok'; curl_close($health);
        if ($ready) { break; } usleep(50000);
    }
    ibptImportHttpCheck($ready, 'servidor HTTP isolado disponível no loopback');
    $url = $base . '/company-a/admin/nfse_nacional/ibpt_import';
    $csv = ibptImportHttpCsv([ibptImportHttpRow(), ibptImportHttpRow(['codigo' => '101012000'])]);
    $fields = ['fixture_csrf' => $token, 'expected_uf' => 'BA',
        'ibpt_file' => new CURLStringFile($csv, 'TabelaIBPTaxBA26.2.C.csv', 'text/csv')];
    [$status, $result, $body] = ibptImportHttpRequest($url, $fields + ['uf' => 'SP', 'company_id' => 'company-b',
        'settings[provider][state]' => 'SP', 'certificate_password' => 'SENHA_NUNCA_LOGAR_HTTP073']);
    ibptImportHttpCheck($status === 200 && ($result['ok'] ?? false), 'multipart real importa tabela sem serviço de download disponível');
    $table = $result['data']['ibpt_table'] ?? [];
    ibptImportHttpCheck(($table['uf'] ?? '') === 'BA' && ($table['status'] ?? '') === 'current'
        && ($table['service_count'] ?? 0) === 2, 'UF salva prevalece sobre UF e empresa adulteradas no POST');
    ibptImportHttpCheck(($table['acquisition'] ?? '') === 'upload' && ($table['key'] ?? '') === 'HTTP073'
        && ($table['version'] ?? '') === '26.2.C', 'painel recebe obtenção manual, chave e versão do conteúdo');
    ibptImportHttpCheck(($result['csrf'] ?? []) === ['name' => 'fixture_csrf', 'hash' => $token], 'resposta mantém contrato CSRF');
    ibptImportHttpCheck(!isset($result['data']['reload']) && !isset($result['data']['url']), 'importação atualiza painel sem recarregar formulário');
    ibptImportHttpCheck(file_get_contents($directory . '/company-a.json') === $saved
        && file_get_contents($directory . '/company-b.json') === $saved, 'configurações e certificado não são gravados');
    ibptImportHttpCheck(!str_contains($body, 'SENHA_NUNCA_LOGAR_HTTP073') && !str_contains($body, 'Serviço sintético HTTP 073'),
        'resposta contém metadados permitidos, sem senha ou CSV bruto');
    $snapshot = ibptImportHttpFiles($directory);
    ibptImportHttpCheck(count($snapshot) === 1, 'somente cache da UF da empresa atual é criado');
    $cacheFile = array_key_first($snapshot);
    ibptImportHttpCheck(str_starts_with($snapshot[$cacheFile], "<?php exit; ?>\n"), 'cache não pode ser servido como JSON público executável');
    ibptImportHttpCheck((fileperms($cacheFile) & 0777) === 0600 && (fileperms(dirname($cacheFile)) & 0777) === 0700,
        'arquivo e diretório de cache têm permissões privadas');

    foreach (['GET', 'DELETE'] as $verb) {
        [$status, $result] = ibptImportHttpRequest($url, [], [], $verb);
        ibptImportHttpCheck($status === 405 && ($result['ok'] ?? true) === false, $verb . ' não importa tabela');
        ibptImportHttpCheck(ibptImportHttpFiles($directory) === $snapshot, $verb . ' preserva cache anterior');
    }
    $today = new DateTimeImmutable('now', new DateTimeZone('America/Bahia'));
    $withoutFile = $fields; unset($withoutFile['ibpt_file']);
    $withoutUf = $fields; unset($withoutUf['expected_uf']);
    $withoutToken = $fields; unset($withoutToken['fixture_csrf']);
    $arrayFile = $withoutFile + ['ibpt_file[0]' => new CURLStringFile($csv, 'BA.csv', 'text/csv')];
    $arrayUf = $withoutUf + ['expected_uf[0]' => 'BA'];
    $cases = [
        'sessão ausente' => [$fields, ['X-Fixture-No-Session: 1'], 403],
        'sem permissão settings' => [$fields, ['X-Fixture-No-Settings: 1'], 403],
        'sem permissão view' => [$fields, ['X-Fixture-No-View: 1'], 403],
        'CSRF desativada' => [$fields, ['X-Fixture-Csrf-Disabled: 1'], 503],
        'token ausente' => [$withoutToken, [], 403],
        'token incorreto' => [array_replace($fields, ['fixture_csrf' => 'incorreto']), [], 403],
        'UF esperada ausente' => [$withoutUf, [], 409],
        'UF esperada divergente' => [array_replace($fields, ['expected_uf' => 'SP']), [], 409],
        'UF em array' => [$arrayUf, [], 409],
        'UF salva inconsistente' => [$fields, ['X-Fixture-Bad-Issuer: 1'], 422],
        'arquivo ausente' => [$withoutFile, [], 422],
        'arquivo em array' => [$arrayFile, [], 422],
        'extensão errada' => [array_replace($fields, ['ibpt_file' => new CURLStringFile($csv, 'BA.txt')]), [], 422],
        'CSV vazio' => [array_replace($fields, ['ibpt_file' => new CURLStringFile('', 'BA.csv')]), [], 422],
        'CSV corrompido' => [array_replace($fields, ['ibpt_file' => new CURLStringFile('<html>não é tabela IBPT</html>', 'BA.csv')]), [], 422],
        'UF do arquivo errada' => [array_replace($fields, ['ibpt_file' => new CURLStringFile($csv, 'TabelaIBPTaxSP26.2.C.csv')]), [], 422],
        'nome sem UF' => [array_replace($fields, ['ibpt_file' => new CURLStringFile($csv, 'tabela.csv')]), [], 422],
        'tabela anterior e vencida' => [array_replace($fields, ['ibpt_file' => new CURLStringFile(ibptImportHttpCsv([ibptImportHttpRow([
            'vigenciainicio' => $today->modify('-31 days')->format('d/m/Y'), 'vigenciafim' => $today->modify('-1 day')->format('d/m/Y')])]), 'BA.csv')]), [], 422],
        'tabela futura' => [array_replace($fields, ['ibpt_file' => new CURLStringFile(ibptImportHttpCsv([ibptImportHttpRow([
            'vigenciainicio' => $today->modify('+1 day')->format('d/m/Y')])]), 'BA.csv')]), [], 422],
        'tabela regressiva' => [array_replace($fields, ['ibpt_file' => new CURLStringFile(ibptImportHttpCsv([ibptImportHttpRow([
            'versao' => '26.2.B'])]), 'BA.csv')]), [], 422],
        'metadados mistos' => [array_replace($fields, ['ibpt_file' => new CURLStringFile(ibptImportHttpCsv([ibptImportHttpRow(),
            ibptImportHttpRow(['codigo' => '101012000', 'chave' => 'OUTRA073'])]), 'BA.csv')]), [], 422],
        'limite de 8 MiB' => [array_replace($fields, ['ibpt_file' => new CURLStringFile(str_repeat('X', 8388609), 'BA.csv')]), [], 422],
    ];
    foreach ($cases as $label => [$posted, $headers, $expectedStatus]) {
        [$status, $result, $body] = ibptImportHttpRequest($url, $posted, $headers);
        ibptImportHttpCheck($status === $expectedStatus && ($result['ok'] ?? true) === false, $label . ': resposta explícita sem falso sucesso (' . $status . ')');
        ibptImportHttpCheck(ibptImportHttpFiles($directory) === $snapshot, $label . ': cache anterior preservado integralmente');
        ibptImportHttpCheck(file_get_contents($directory . '/company-a.json') === $saved
            && file_get_contents($directory . '/company-b.json') === $saved, $label . ': configurações e certificado intactos');
    }

    // Synthetic alternative export: no real table or invented key is installed in a CRM.
    $alternativeRows = [
        ibptImportHttpAlternativeRow(['Codigo' => '01012100', 'Tipo' => 'ncm']),
        ibptImportHttpAlternativeRow(),
        ibptImportHttpAlternativeRow(['Codigo' => '101012000']),
        ibptImportHttpAlternativeRow(['Codigo' => '0101', 'Tipo' => 'lc116']),
    ];
    $alternative = ibptImportHttpAlternativeCsv($alternativeRows);
    $alternativeFields = array_replace($fields, ['ibpt_file' => new CURLStringFile($alternative, 'ibpt-consulta (1).csv', 'text/csv')]);
    [$status, $result] = ibptImportHttpRequest($url, $alternativeFields);
    ibptImportHttpCheck($status === 422 && ($result['ok'] ?? true) === false
        && str_contains($result['message'] ?? '', 'chave original'),
        'layout alternativo sem chave pede o dado ausente, sem reutilizar chave da tabela anterior');
    ibptImportHttpCheck(ibptImportHttpFiles($directory) === $snapshot, 'chave ausente preserva bytes e metadados da cópia anterior');

    $alternativeWithKey = $alternativeFields + ['ibpt_key' => 'ALT074'];
    [$status, $result, $body] = ibptImportHttpRequest($url, $alternativeWithKey + [
        'settings[provider][state]' => 'SP', 'certificate_password' => 'SENHA_NUNCA_LOGAR_HTTP074']);
    $table = $result['data']['ibpt_table'] ?? [];
    ibptImportHttpCheck($status === 200 && ($result['ok'] ?? false), 'CSV alternativo com nome genérico é importado por multipart com chave sintética explícita');
    ibptImportHttpCheck(($table['uf'] ?? '') === 'BA' && ($table['service_count'] ?? 0) === 2,
        'UF vem da coluna conferida com o cadastro e somente registros nbs são armazenados');
    ibptImportHttpCheck(($table['key'] ?? '') === 'ALT074' && ($table['version'] ?? '') === '26.2.C'
        && ($table['source'] ?? '') === 'IBPT' && ($table['acquisition'] ?? '') === 'upload',
        'alias Tabela, chave complementar e fonte declarada compõem os metadados de importação');
    ibptImportHttpCheck(($result['csrf'] ?? []) === ['name' => 'fixture_csrf', 'hash' => $token]
        && !isset($result['data']['reload']), 'layout alternativo mantém CSRF e contrato de atualização sem recarga');
    $alternativeSnapshot = ibptImportHttpFiles($directory);
    $storedAlternative = json_decode(substr($alternativeSnapshot[$cacheFile], strlen("<?php exit; ?>\n")), true, 32, JSON_THROW_ON_ERROR);
    ibptImportHttpCheck(($storedAlternative['rows']['101011000']['source_hash'] ?? '') === hash('sha256', $alternative),
        'hash fiscal preserva os bytes originais do upload, sem usar CSV normalizado como fonte');
    ibptImportHttpCheck(($storedAlternative['rows']['101011000']['nacionalfederal'] ?? '') === '13.4500'
        && ($storedAlternative['rows']['101011000']['municipal'] ?? '') === '5.0000'
        && str_contains($storedAlternative['rows']['101011000']['description'] ?? '', '"çã"; região'),
        'aliases de alíquotas e descrição delimitada chegam completos ao armazenamento');
    ibptImportHttpCheck(file_get_contents($directory . '/company-a.json') === $saved
        && file_get_contents($directory . '/company-b.json') === $saved
        && !str_contains($body, 'SENHA_NUNCA_LOGAR_HTTP074'), 'importação alternativa não salva parâmetros nem expõe senha na resposta');

    $embeddedKeyCsv = ibptImportHttpAlternativeCsv([ibptImportHttpAlternativeRow(['Chave' => 'EMBED074'])]);
    $invalidAlternativeCases = [
        'chave em array' => [$alternativeFields + ['ibpt_key[0]' => 'ALT074']],
        'chave com marcação' => [$alternativeFields + ['ibpt_key' => '<script>SEGREDO074</script>']],
        'chave acima do limite' => [$alternativeFields + ['ibpt_key' => str_repeat('A', 33)]],
        'chave com espaços internos' => [$alternativeFields + ['ibpt_key' => 'ALT 074']],
        'chave complementar divergente do CSV' => [array_replace($alternativeWithKey, [
            'ibpt_file' => new CURLStringFile($embeddedKeyCsv, 'ibpt-consulta (1).csv', 'text/csv')])],
        'chave complementar divergente do formato original' => [$fields + ['ibpt_key' => 'ALT074']],
        'UF explícita divergente' => [array_replace($alternativeWithKey, ['ibpt_file' => new CURLStringFile(
            ibptImportHttpAlternativeCsv([ibptImportHttpAlternativeRow(['UF' => 'SP'])]), 'ibpt-consulta.csv')])],
        'UFs explícitas misturadas' => [array_replace($alternativeWithKey, ['ibpt_file' => new CURLStringFile(
            ibptImportHttpAlternativeCsv([ibptImportHttpAlternativeRow(), ibptImportHttpAlternativeRow(['Codigo' => '101012000', 'UF' => 'SP'])]), 'ibpt-consulta.csv')])],
    ];
    foreach ($invalidAlternativeCases as $label => [$posted]) {
        [$status, $result] = ibptImportHttpRequest($url, $posted);
        ibptImportHttpCheck($status === 422 && ($result['ok'] ?? true) === false, $label . ': rejeição explícita no upload real');
        ibptImportHttpCheck(ibptImportHttpFiles($directory) === $alternativeSnapshot, $label . ': tabela alternativa anterior preservada integralmente');
    }

    $reordered = "\xef\xbb\xbf" . ibptImportHttpAlternativeCsv($alternativeRows, ',', array_reverse(array_keys($alternativeRows[0])));
    [$status, $result] = ibptImportHttpRequest($url, array_replace($alternativeWithKey, [
        'ibpt_key' => 'REORDER074', 'ibpt_file' => new CURLStringFile($reordered, 'exportacao.csv', 'application/octet-stream')]));
    ibptImportHttpCheck($status === 200 && ($result['data']['ibpt_table']['key'] ?? '') === 'REORDER074'
        && ($result['data']['ibpt_table']['service_count'] ?? 0) === 2,
        'cabeçalhos reordenados, delimitador vírgula e BOM UTF-8 são reconhecidos no upload real');
    $reorderedStored = json_decode(substr(ibptImportHttpFiles($directory)[$cacheFile], strlen("<?php exit; ?>\n")), true, 32, JSON_THROW_ON_ERROR);
    ibptImportHttpCheck(($reorderedStored['rows']['101011000']['source_hash'] ?? '') === hash('sha256', $reordered),
        'BOM e organização alternativa permanecem representados no hash original');

    [$status, $result] = ibptImportHttpRequest($url, array_replace($alternativeFields, [
        'ibpt_file' => new CURLStringFile($embeddedKeyCsv, 'consulta.csv', 'text/csv')]));
    ibptImportHttpCheck($status === 200 && ($result['data']['ibpt_table']['key'] ?? '') === 'EMBED074',
        'coluna Chave no CSV alternativo dispensa metadado complementar');
    [$status, $result] = ibptImportHttpRequest($url, array_replace($alternativeFields, [
        'ibpt_key' => 'EMBED074', 'ibpt_file' => new CURLStringFile($embeddedKeyCsv, 'consulta.csv', 'text/csv')]));
    ibptImportHttpCheck($status === 200 && ($result['data']['ibpt_table']['key'] ?? '') === 'EMBED074',
        'chave complementar igual à original mantém identidade sem conflito');

    $nextCsv = mb_convert_encoding(ibptImportHttpCsv([ibptImportHttpRow(['chave' => 'HTTP074', 'versao' => '26.2.D'])]), 'Windows-1252', 'UTF-8');
    [$status, $result] = ibptImportHttpRequest($url, array_replace($fields, ['ibpt_file' => new CURLStringFile($nextCsv, 'IBPT_BA_26.2.D.CSV', 'application/octet-stream')]));
    ibptImportHttpCheck($status === 200 && ($result['data']['ibpt_table']['key'] ?? '') === 'HTTP074',
        'upload válido em Windows-1252 é aceito durante o intervalo da consulta remota');
    $updated = ibptImportHttpFiles($directory);
    ibptImportHttpCheck(count($updated) === 1 && $updated !== $snapshot, 'nova tabela substitui somente a cópia da mesma empresa/UF');
    [$status, $result] = ibptImportHttpRequest($base . '/company-b/admin/nfse_nacional/ibpt_import',
        array_replace($fields, ['ibpt_file' => new CURLStringFile($csv, 'BA.csv', 'text/plain')]));
    $both = ibptImportHttpFiles($directory);
    ibptImportHttpCheck($status === 200 && count($both) === 2 && ($both[$cacheFile] ?? '') === $updated[$cacheFile],
        'outra empresa importa sua própria tabela da mesma UF sem substituir a primeira');
    ibptImportHttpCheck(count(glob($directory . '/php*') ?: []) === 0, 'temporários multipart são removidos pelo PHP');
    $logs = '';
    foreach (glob($directory . '/logs/nfse_nacional/*/*.jsonl.php') ?: [] as $file) { $logs .= file_get_contents($file); }
    ibptImportHttpCheck(str_contains($logs, 'ibpt.import') && str_contains($logs, 'HTTP073'), 'log operacional identifica importação e chave');
    ibptImportHttpCheck(!str_contains($logs, 'SENHA_NUNCA_LOGAR_HTTP073') && !str_contains($logs, 'Serviço sintético HTTP 073')
        && !str_contains($logs, 'TabelaIBPTaxBA26.2.C.csv'), 'log não armazena senha, conteúdo bruto ou nome original do arquivo');
    ibptImportHttpCheck(!str_contains($logs, 'SENHA_NUNCA_LOGAR_HTTP074') && !str_contains($logs, 'Serviço sintético HTTP 074')
        && !str_contains($logs, 'ibpt-consulta (1).csv') && !str_contains($logs, '<script>SEGREDO074</script>'),
        'log da adaptação não vaza formulário, conteúdo, nome genérico nem chave maliciosa');
    ibptImportHttpCheck(!preg_match('/PHP (?:Warning|Notice|Fatal error|Deprecated)/', (string) file_get_contents($log)),
        'rotas válidas e inválidas não produzem warnings ou erros internos');
    echo 'OK: ' . $checks . " verificações da importação IBPT por HTTP multipart.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    ibptImportHttpRemove($directory);
}
