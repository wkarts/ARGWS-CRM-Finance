<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\IbptStore;
use NfseNacional\IbptTable;

$checks = 0; $now = strtotime('2026-11-11T12:00:00Z'); $requests = 0;
$directory = sys_get_temp_dir() . '/nfse-ibpt-import-' . bin2hex(random_bytes(8));
function importCheck(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException('FALHOU: ' . $message); } ++$GLOBALS['checks'];
}
function importReject(callable $operation, string $fragment): void {
    try { $operation(); } catch (Throwable $error) {
        importCheck(str_contains($error->getMessage(), $fragment), 'rejeição específica: ' . $fragment); return;
    }
    throw new RuntimeException('FALHOU: operação deveria rejeitar: ' . $fragment);
}
function importClock(): int { return $GLOBALS['now']; }
function importStore(string $company): IbptStore { return new IbptStore($GLOBALS['directory'], hash('sha256', $company), 'importClock'); }
function importFile(string $company, string $uf = 'BA'): string { return $GLOBALS['directory'] . '/' . hash('sha256', $company) . '/table-' . $uf . '.php'; }
function importRow(array $values = []): array {
    return array_replace(['codigo'=>'101011000','ex'=>'','tipo'=>'1','descricao'=>'Serviço de construção – teste',
        'nacionalfederal'=>'13.45','importadosfederal'=>'15.45','estadual'=>'0','municipal'=>'5',
        'vigenciainicio'=>'01/11/2026','vigenciafim'=>'30/11/2026','chave'=>'TST073','versao'=>'26.2.C','fonte'=>'IBPT/empresometro.com.br'], $values);
}
function importCsv(array $rows): string {
    $stream = fopen('php://temp', 'w+b'); fputcsv($stream, array_keys(importRow()), ';', '"', '');
    foreach ($rows as $row) { fputcsv($stream, $row, ';', '"', ''); }
    rewind($stream); $body = stream_get_contents($stream); fclose($stream); return $body;
}
function importRemove(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (glob($path . '/*') ?: [] as $child) { importRemove($child); }
    if (is_dir($path)) { rmdir($path); }
}
try {
    $body = importCsv([importRow(), importRow(['codigo'=>'101012000'])]);
    $network = static function (): string { ++$GLOBALS['requests']; throw new RuntimeException('rede indisponível'); };
    $table = new IbptTable(importStore('a'), $network, 'importClock');
    importCheck($table->status('BA')['acquisition'] === null, 'sem tabela não inventa forma de obtenção');
    $result = $table->import('ba', "\xef\xbb\xbf" . $body, 'TabelaIBPTaxBA26.2.C.csv');
    importCheck($result['status'] === 'current' && $result['refresh_state'] === 'updated', 'CSV vigente importado com sucesso');
    importCheck($result['uf'] === 'BA' && $result['service_count'] === 2, 'UF normalizada e todos os serviços armazenados');
    importCheck($result['version'] === '26.2.C' && $result['key'] === 'TST073', 'versão e chave do arquivo preservadas');
    importCheck($result['acquisition'] === 'upload' && $result['acquisition_label'] === 'Arquivo enviado', 'identificação de obtenção por arquivo');
    importCheck($result['fetched_at'] === '2026-11-11T12:00:00Z' && $result['last_attempt_at'] === $result['fetched_at'], 'data da importação visível');
    importCheck($result['valid_from'] === '2026-11-01' && $result['valid_to'] === '2026-11-30', 'vigência não substituída pela data de importação');
    importCheck($result['next_retry_at'] === null && $result['next_auto_check_at'] === '2026-12-01T03:00:00Z', 'renovação após vencimento e sem tentativas pendentes');
    importCheck(str_contains($result['refresh_message'], 'importada'), 'mensagem confirma importação');
    $first = $table->lookup('BA', '101011000');
    importCheck($first['description'] === 'Serviço de construção – teste' && $first['municipal'] === '5.0000', 'acentos e percentuais conservados');
    for ($i = 0; $i < 5; $i++) { $table->lookup('BA', $i % 2 ? '101011000' : '101012000'); $table->status('BA'); }
    importCheck($requests === 0, 'upload e reutilização não fazem requisições HTTP');
    importCheck($table->status('BA')['acquisition'] === 'upload', 'origem persistida ao reabrir');
    foreach (['IBPT_BA_26.2.C.csv', 'BA.csv', 'ba.CSV', 'TabelaIBPTaxBA26.2.C (1).csv', 'Tabela IBPT BA 26.2.C.csv'] as $name) {
        importCheck($table->import('BA', $body, $name)['status'] === 'current', 'nomes usuais aceitos: ' . $name);
    }
    importCheck($requests === 0, 'importação independente do intervalo entre consultas externas');
    $file = importFile('a'); $preserved = file_get_contents($file);
    foreach (['IBPT.csv', 'tabela.csv', 'IBPT_BA_SP.csv', 'BA.csv.php', 'BA.csv.exe', '../BA.csv', 'C:\\temp\\BA.csv', "BA\0.csv", str_repeat('a', 256) . '.csv'] as $name) {
        importReject(fn() => $table->import('BA', $body, $name), 'CSV original');
        importCheck(file_get_contents($file) === $preserved, 'nome inválido não modifica cache');
    }
    foreach (['TabelaIBPTaxSP26.2.C.csv', 'IBPT_RJ_26.2.C.csv', 'SP.csv', 'ZZ.csv'] as $name) {
        importReject(fn() => $table->import('BA', $body, $name), 'não corresponde');
        importCheck(file_get_contents($file) === $preserved, 'UF divergente não modifica cache');
    }
    importReject(fn() => $table->import('ZZ', $body, 'ZZ.csv'), 'UF do estabelecimento');
    $invalid = [
        [importCsv([importRow(['vigenciainicio'=>'01/10/2026','vigenciafim'=>'31/10/2026'])]), 'anterior'],
        [importCsv([importRow(['vigenciainicio'=>'01/12/2026','vigenciafim'=>'31/12/2026'])]), 'futura'],
        [importCsv([importRow(), importRow(['codigo'=>'101012000','chave'=>'OUTRA73'])]), 'divergentes'],
        [importCsv([importRow(), importRow(['codigo'=>'101012000','versao'=>'26.2.D'])]), 'divergentes'],
        [importCsv([importRow(), importRow(['codigo'=>'101012000','vigenciafim'=>'29/11/2026'])]), 'divergentes'],
        [importCsv([importRow(['municipal'=>'101'])]), 'intervalo'],
        [importCsv([importRow(['municipal'=>'segredo'])]), 'Alíquota inválida'],
        [importCsv([importRow(), importRow()]), 'duplicado'],
        [importCsv([importRow(['tipo'=>'0','codigo'=>'12345678'])]), 'não contém serviços'],
        [str_replace('nacionalfederal;', 'outrocampo;', $body), 'campos necessários'],
        ["\xff\xfe" . $body, 'Arquivo'],
        ["\0" . $body, 'Arquivo'],
        [str_repeat('x', 8388609), 'limite'],
        ['', 'Arquivo'],
        [importCsv([importRow(['vigenciainicio'=>'31/02/2026'])]), 'Vigência inválida'],
        [importCsv([importRow(['versao'=>'26.2.B'])]), 'anterior'],
        [importCsv([importRow(['vigenciainicio'=>'01/10/2026'])]), 'anterior'],
        [importCsv([importRow(['vigenciafim'=>'29/11/2026'])]), 'anterior'],
    ];
    foreach ($invalid as [$raw, $message]) {
        importReject(fn() => $table->import('BA', $raw, 'BA.csv'), $message);
        importCheck(file_get_contents($file) === $preserved, 'falha mantém bytes e metadados locais: ' . $message);
    }
    importCheck($requests === 0, 'arquivo rejeitado não dispara consulta remota');
    $GLOBALS['now'] += 10;
    $newBody = importCsv([importRow(['chave'=>'NOVO073','versao'=>'26.2.D','municipal'=>'4,5'])]);
    $cp1252 = mb_convert_encoding($newBody, 'Windows-1252', 'UTF-8');
    $new = $table->import('BA', $cp1252, 'IBPT_BA_26.2.D.csv');
    importCheck($new['key'] === 'NOVO073' && $new['fetched_at'] === '2026-11-11T12:00:10Z', 'nova tabela pode ser enviada sem aguardar consulta automática');
    $after = $table->lookup('BA', '101011000');
    importCheck($after['description'] === $first['description'] && $after['municipal'] === '4.5000', 'arquivo Windows-1252 e decimal com vírgula');
    importCheck($first['chave'] === 'TST073' && $first['municipal'] === '5.0000' && $first['source_hash'] !== $after['source_hash'], 'snapshot anteriormente calculado permanece inalterado');
    $other = new IbptTable(importStore('b'), $network, 'importClock');
    importCheck($other->status('BA')['status'] === 'missing', 'outra empresa não enxerga importação');
    $other->import('SP', $body, 'SP.csv');
    importCheck($other->status('SP')['key'] === 'TST073' && $other->status('BA')['status'] === 'missing', 'importação restrita à UF selecionada do contexto');
    importCheck($table->status('BA')['key'] === 'NOVO073' && $table->status('SP')['status'] === 'missing', 'outra empresa e UF não alteradas');

    $remoteBody = importCsv([importRow(['chave'=>'ANTIGO73','versao'=>'26.2.B','vigenciainicio'=>'01/10/2026','vigenciafim'=>'31/10/2026'])]); $remoteCalls = 0;
    $remote = new IbptTable(importStore('a'), static function() use (&$remoteBody, &$remoteCalls): string { ++$remoteCalls; return $remoteBody; }, 'importClock');
    $GLOBALS['now'] += 61;
    $refreshed = $remote->refresh('BA');
    importCheck($refreshed['acquisition'] === 'upload' && $refreshed['key'] === 'NOVO073' && $refreshed['refresh_state'] === 'failed', 'fonte desatualizada não substitui arquivo vigente enviado');
    importCheck($remote->lookup('BA', '101011000')['chave'] === 'NOVO073' && $remoteCalls === 1, 'cálculo permanece disponível com arquivo manual após consulta regressiva');
    $GLOBALS['now'] += 61;
    $remoteBody = importCsv([importRow(['chave'=>'AUTO073','versao'=>'26.2.E'])]);
    $refreshed = $remote->refresh('BA');
    importCheck($refreshed['acquisition'] === 'download' && $refreshed['acquisition_label'] === 'Consulta à fonte' && $refreshed['key'] === 'AUTO073' && $remoteCalls === 2, 'atualização posterior pela fonte mantém origem correta');
    $old = json_decode(substr(file_get_contents($file), strlen("<?php exit; ?>\n")), true, 16, JSON_THROW_ON_ERROR);
    unset($old['acquisition']); file_put_contents($file, "<?php exit; ?>\n" . json_encode($old));
    importCheck($remote->status('BA')['acquisition'] === 'download', 'cache anterior sem origem permanece compatível');
    $lock = fopen(dirname($file) . '/lock-BA.php', 'r+b'); flock($lock, LOCK_EX);
    try { importReject(fn() => $table->import('BA', $body, 'BA.csv'), 'sendo atualizada'); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    importCheck($remote->status('BA')['key'] === 'AUTO073', 'importação concorrente não sobrescreve tabela');

    $boundary = new IbptTable(importStore('boundary'), $network, 'importClock');
    $GLOBALS['now'] = strtotime('2026-12-01T02:59:59Z');
    importCheck($boundary->import('BA', $body, 'BA.csv')['status'] === 'current', 'último segundo da vigência no horário da Bahia é aceito');
    $GLOBALS['now']++;
    importCheck($boundary->import('BA', $body, 'BA.csv')['status'] === 'expired' && $boundary->lookup('BA','101011000')['chave'] === 'TST073', 'tabela vencida pode ser importada e utilizada sob responsabilidade do emitente');
    importCheck($requests === 1, 'importação vencida não consulta a rede; primeiro uso tenta renovar uma vez e continua calculando');
    echo 'OK: ' . $checks . " verificações de importação manual IBPT.\n";
} finally { importRemove($directory); }
