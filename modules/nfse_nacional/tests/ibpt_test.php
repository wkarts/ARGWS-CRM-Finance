<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\IbptStore;
use NfseNacional\IbptTable;

$checks = 0; $now = strtotime('2026-11-11T12:00:00Z');
$directory = sys_get_temp_dir() . '/nfse-ibpt-' . bin2hex(random_bytes(8));
function ibptCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException('FALHOU: ' . $message); } ++$GLOBALS['checks']; }
function ibptReject(callable $call, string $message, string $contains = ''): void {
    try { $call(); } catch (Throwable $error) {
        ibptCheck($contains === '' || str_contains($error->getMessage(), $contains), $message . ' / mensagem: ' . $error->getMessage()); return;
    }
    throw new RuntimeException('FALHOU: ' . $message . ' / foi aceito');
}
function ibptClock(): int { return $GLOBALS['now']; }
function ibptStore(string $company): IbptStore { return new IbptStore($GLOBALS['directory'], hash('sha256', $company), 'ibptClock'); }
function ibptRow(array $replace = []): array {
    return array_replace(['codigo' => '101011000', 'ex' => '', 'tipo' => '1', 'descricao' => 'Construção de edificações',
        'nacionalfederal' => '13.45', 'importadosfederal' => '15.45', 'estadual' => '0.00', 'municipal' => '5.00',
        'vigenciainicio' => '01/11/2026', 'vigenciafim' => '31/12/2026', 'chave' => 'ABC123', 'versao' => '26.2.C', 'fonte' => 'IBPT/empresometro.com.br'], $replace);
}
function ibptCsv(array $rows = [], bool $cp1252 = false): string {
    $rows = $rows ?: [ibptRow()]; $stream = fopen('php://temp', 'w+b');
    fputcsv($stream, array_keys(ibptRow()), ';', '"', '');
    foreach ($rows as $row) { fputcsv($stream, $row, ';', '"', ''); }
    rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
    return $cp1252 ? mb_convert_encoding($csv, 'Windows-1252', 'UTF-8') : $csv;
}
function ibptRemove(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (glob($path . '/*') ?: [] as $child) { ibptRemove($child); }
    if (is_dir($path)) { rmdir($path); }
}
try {
    $calls = []; $csv = ibptCsv([], true);
    $table = new IbptTable(ibptStore('a'), function ($url, $timeout) use (&$calls, $csv): string { $calls[] = [$url, $timeout]; return $csv; }, 'ibptClock');
    $quote = $table->lookup('ba', '101011000');
    ibptCheck($calls === [['https://ibpt.wwsoftwares.com.br/tabela/ibpt/BA', 12000]], 'requisição tem destino HTTPS fixo, UF normalizada e prazo limitado');
    ibptCheck($quote['uf'] === 'BA' && $quote['nbs'] === '101011000' && $quote['description'] === 'Construção de edificações', 'CSV Windows-1252 é lido sem perder acentos ou identidade');
    ibptCheck($quote['nacionalfederal'] === '13.4500' && $quote['estadual'] === '0.0000' && $quote['municipal'] === '5.0000', 'alíquotas preservam precisão e zero estadual');
    ibptCheck($quote['chave'] === 'ABC123' && $quote['versao'] === '26.2.C' && $quote['fonte'] === 'IBPT/empresometro.com.br', 'chave, fonte e versão vêm do registro consultado');
    ibptCheck($quote['valid_from'] === '2026-11-01' && $quote['valid_to'] === '2026-12-31' && $quote['source_hash'] === hash('sha256', $csv), 'snapshot preserva vigência e hash do arquivo original');
    ibptCheck($quote['fetched_at'] === '2026-11-11T12:00:00Z' && !isset($quote['importadosfederal']), 'snapshot tem data rastreável e usa federal nacional');
    ibptCheck($table->lookup('BA', '101011000') === $quote && count($calls) === 1, 'cache evita nova consulta para a mesma UF e NBS');
    $GLOBALS['now'] += 86401;
    ibptCheck($table->lookup('BA', '101011000') === $quote && count($calls) === 1, 'cache vigente permanece local depois de um dia sem nova transferência');
    $table->lookup('BA', '101011000', null, true);
    ibptCheck(count($calls) === 2, 'atualização explícita pode consultar novamente a fonte de um cache vigente');

    foreach (['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $uf) {
        $u = $table->lookup($uf, '101011000');
        ibptCheck($u['uf'] === $uf && in_array('https://ibpt.wwsoftwares.com.br/tabela/ibpt/' . $uf, array_column($calls, 0), true), 'cada UF utiliza sua própria tabela: ' . $uf);
    }
    $before = count($calls);
    foreach ([['ZZ','101011000'],['BA/../SP','101011000'],['https://example.test','101011000'],['BA','10101100'],['BA','1.0101.10.00'],['BA','101011000/'],['BA','101011000?uf=SP']] as [$uf, $nbs]) {
        ibptReject(fn() => $table->lookup($uf, $nbs), 'entrada inválida é recusada antes de qualquer requisição');
        ibptCheck(count($calls) === $before, 'entrada inválida não alcança transporte');
    }
    ibptReject(fn() => $table->lookup('BA', '101011000', '2026-02-31'), 'data impossível rejeitada antes de consultar');

    $mixed = ibptCsv([ibptRow(), ibptRow(['codigo'=>'101012000','tipo'=>'2']), ibptRow(['codigo'=>'101013000','tipo'=>'0'])]);
    $mixedCalls = 0;
    $mixedTable = new IbptTable(ibptStore('mixed'), function () use (&$mixedCalls, $mixed): string { ++$mixedCalls; return $mixed; }, 'ibptClock');
    $mixedTable->lookup('BA', '101011000');
    ibptReject(fn() => $mixedTable->lookup('BA', '101012000'), 'serviço LC 116 não é usado como NBS', 'não foi encontrado');
    ibptReject(fn() => $mixedTable->lookup('BA', '101013000'), 'registro NCM não é usado como NBS', 'não foi encontrado');
    ibptCheck($mixedCalls === 1, 'NBS ausente em tabela fresca não dispara requisições repetidas');

    $expiredCalls = 0; $expiredCsv = ibptCsv([ibptRow(['vigenciainicio'=>'20/09/2026','vigenciafim'=>'31/10/2026','chave'=>'C44399'])]);
    $expiredTable = new IbptTable(ibptStore('expired'), function () use (&$expiredCalls, &$expiredCsv): string { ++$expiredCalls; return $expiredCsv; }, 'ibptClock');
    ibptCheck($expiredTable->lookup('BA', '101011000')['chave'] === 'C44399', 'tabela vencida calcula com a chave original');
    ibptCheck($expiredTable->lookup('BA', '101011000', null, true)['chave'] === 'C44399', 'atualização explícita em cooldown mantém a cópia vencida');
    ibptCheck($expiredCalls === 1, 'vencimento não gera tempestade de consultas');
    $expiredCsv = ibptCsv(); $GLOBALS['now'] += 3601;
    ibptCheck($expiredTable->lookup('BA','101011000')['chave'] === 'ABC123' && $expiredCalls === 2, 'nova tabela vigente substitui a vencida após cooldown');

    $futureTable = new IbptTable(ibptStore('future'), static fn(): string => ibptCsv([ibptRow(['vigenciainicio'=>'01/12/2026'])]), 'ibptClock');
    ibptReject(fn() => $futureTable->lookup('BA','101011000'), 'vigência futura não é antecipada', '01/12/2026');
    $unavailableCalls = 0;
    $unavailableTable = new IbptTable(ibptStore('offline'), function () use (&$unavailableCalls): string { ++$unavailableCalls; throw new RuntimeException('senha-ou-corpo-privado'); }, 'ibptClock');
    ibptReject(fn() => $unavailableTable->lookup('BA','101011000'), 'falha não retorna zero', 'Não foi possível obter');
    ibptReject(fn() => $unavailableTable->lookup('BA','101011000'), 'falha fica em cooldown', 'Não foi possível obter');
    ibptCheck($unavailableCalls === 1, 'indisponibilidade tem limitação de tentativas');
    $GLOBALS['now'] += 3601;
    ibptReject(fn() => $unavailableTable->lookup('BA','101011000'), 'consulta volta a ser permitida após cooldown');
    ibptCheck($unavailableCalls === 2, 'nova tentativa automática após uma hora');

    $fallbackFail = false; $fallbackCalls = 0;
    $fallback = new IbptTable(ibptStore('valid-offline'), function () use (&$fallbackFail, &$fallbackCalls): string {
        ++$fallbackCalls; if ($fallbackFail) { throw new RuntimeException('offline'); } return ibptCsv();
    }, 'ibptClock');
    $valid = $fallback->lookup('BA','101011000'); $fallbackFail = true; $GLOBALS['now'] += 86401;
    ibptCheck($fallback->lookup('BA','101011000', null, true) === $valid && $fallbackCalls === 2, 'uma fonte ainda vigente pode ser reutilizada se a atualização manual falhar');
    ibptCheck($fallback->lookup('BA','101011000') === $valid && $fallbackCalls === 2, 'cooldown também evita novas tentativas usando cache ainda válido');
    $GLOBALS['now'] = strtotime('2027-01-01T12:00:00Z');
    ibptCheck($fallback->lookup('BA','101011000')['chave'] === 'ABC123' && $fallbackCalls === 3, 'cache vencido continua disponível quando a atualização de rede falha');
    $GLOBALS['now'] = strtotime('2026-11-11T12:00:00Z');

    $otherCalls = 0;
    $other = new IbptTable(ibptStore('other-company'), function () use (&$otherCalls): string { ++$otherCalls; return ibptCsv([ibptRow(['chave'=>'OTHER1'])]); }, 'ibptClock');
    ibptCheck($other->lookup('BA','101011000')['chave'] === 'OTHER1' && $otherCalls === 1, 'cache é isolado pelo contexto da empresa');
    ibptCheck($mixedTable->lookup('BA','101011000')['chave'] === 'ABC123', 'consulta de outra empresa não altera snapshot anterior');
    ibptCheck(IbptTable::validateSnapshot($quote + ['url'=>'https://evil.test'], 'BA','101011000','2026-11-11') === $quote, 'snapshot é estritamente allowlisted');
    foreach ([['uf'=>'SP'],['nbs'=>'101012000'],['chave'=>''],['chave'=>'BAD\nKEY'],['fonte'=>''],['versao'=>'bad/version'],
        ['nacionalfederal'=>'100.0001'],['estadual'=>'-1'],['municipal'=>'1e1'],['municipal'=>'2.12345'],['municipal'=>3],
        ['source_hash'=>'123'],['fetched_at'=>'2026-99-11T12:00:00Z'],['valid_from'=>'2026-12-31','valid_to'=>'2026-11-01']] as $bad) {
        ibptReject(fn() => IbptTable::validateSnapshot(array_replace($quote,$bad),'BA','101011000','2026-11-11'), 'snapshot adulterado ou incompleto é recusado');
    }
    ibptCheck(IbptTable::validateSnapshot($quote,'BA','101011000','2027-01-01') === $quote, 'snapshot vencido permanece válido estruturalmente para emissão');
    $rates = IbptTable::parse(ibptCsv([ibptRow(['nacionalfederal'=>'100','estadual'=>'0,1234','municipal'=>'2.1234'])]),'BA',$now);
    ibptCheck($rates['101011000']['nacionalfederal'] === '100.0000' && $rates['101011000']['estadual'] === '0.1234', 'percentuais de quatro casas e vírgula são preservados');
    $bom = IbptTable::parse("\xef\xbb\xbf" . ibptCsv(), 'BA', $now);
    ibptCheck($bom['101011000']['description'] === 'Construção de edificações', 'CSV UTF-8 com BOM é aceito');
    foreach ([
        ibptCsv([ibptRow(), ibptRow()]),
        ibptCsv([ibptRow(['ex'=>'1'])]),
        ibptCsv([ibptRow(['tipo'=>'3'])]),
        ibptCsv([ibptRow(['nacionalfederal'=>''])]),
        ibptCsv([ibptRow(['vigenciafim'=>'31/02/2026'])]),
        ibptCsv([ibptRow(['descricao'=>"Serviço\nOutro"])]),
        ibptCsv([ibptRow(['codigo'=>'12345678'])]),
        ibptCsv([ibptRow(['chave'=>''])]),
        ibptCsv([ibptRow(['tipo'=>'2'])]),
        str_replace('nacionalfederal;', 'municipal;', ibptCsv()),
        ibptCsv() . "x;y\n",
        '<html>indisponível</html>',
        ibptCsv() . "\0",
        str_repeat('x', 8388609),
    ] as $invalidCsv) {
        ibptReject(fn() => IbptTable::parse($invalidCsv, 'BA', $now), 'CSV inválido/ambíguo não produz percentuais');
    }
    $companyDir = $directory . '/' . hash('sha256', 'mixed');
    $lock = fopen($companyDir . '/lock-BA.php', 'c+b'); flock($lock, LOCK_EX);
    try {
        ibptCheck($mixedTable->lookup('BA','101011000')['chave'] === 'ABC123', 'emissão concorrente aproveita cópia local vigente durante atualização');
        ibptReject(fn() => $mixedTable->lookup('BA','101099999'), 'NBS ausente é conferido localmente durante atualização', 'não foi encontrado');
        ibptReject(fn() => $mixedTable->lookup('BA','101011000', null, true), 'atualização concorrente não duplica requisição', 'sendo atualizada');
    }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    $brokenFile = $companyDir . '/table-BA.php'; file_put_contents($brokenFile, "<?php exit; ?>\n{broken");
    $beforeCorruption = $mixedCalls;
    ibptCheck($mixedTable->lookup('BA','101011000')['chave'] === 'ABC123' && $mixedCalls === $beforeCorruption + 1, 'cache corrompido é descartado e reconstruído');
    unlink($brokenFile); symlink(__FILE__, $brokenFile);
    ibptReject(fn() => $mixedTable->lookup('BA','101011000'), 'cache simbólico é recusado antes da leitura', 'Armazenamento');
    foreach (glob($directory . '/*/*.php') ?: [] as $file) {
        if (is_link($file)) { continue; }
        ibptCheck(str_starts_with((string) file_get_contents($file), "<?php exit; ?>\n"), 'arquivos do cache não expõem conteúdo pela execução PHP');
        ibptCheck((fileperms($file) & 0777) === 0600, 'cache tem permissão privada');
    }
    ibptReject(fn() => new IbptStore('https://example.test', hash('sha256','x')), 'cache não aceita um caminho remoto');
    ibptReject(fn() => new IbptStore($directory, '../bad'), 'cache não aceita escopo arbitrário');
    $logs = [];
    $logged = new IbptTable(ibptStore('diagnostic'), static fn(): string => ibptCsv(), 'ibptClock',
        function ($level, $message, $context) use (&$logs): void { $logs[] = compact('level','message','context'); });
    $logged->lookup('BA','101011000');
    ibptCheck(count($logs) === 1 && $logs[0]['level'] === 'info' && $logs[0]['context']['ibpt_key'] === 'ABC123'
        && $logs[0]['context']['http_status'] === 200 && $logs[0]['context']['ibpt_valid_to'] === '2026-12-31', 'diagnóstico registra chave, vigência e status da fonte');
    ibptCheck($logs[0]['context']['uf'] === 'BA' && $logs[0]['context']['nbs'] === '101011000'
        && !str_contains(json_encode($logs), 'Construção') && !str_contains(json_encode($logs), 'codigo;ex;'), 'diagnóstico não armazena o corpo da tabela');
    $logFailure = new IbptTable(ibptStore('diagnostic-fail'), static function (): string { throw new RuntimeException('corpo-secreto', 503); }, 'ibptClock',
        function ($level, $message, $context) use (&$logs): void { $logs[] = compact('level','message','context'); });
    ibptReject(fn() => $logFailure->lookup('BA','101011000'), 'falha de transporte é registrada sem corpo');
    ibptCheck(end($logs)['context']['http_status'] === 503 && !str_contains(json_encode($logs), 'corpo-secreto'), 'erro remoto não vaza conteúdo em diagnóstico');
    $logUnavailable = new IbptTable(ibptStore('diagnostic-offline'), static fn(): string => ibptCsv(), 'ibptClock',
        static function (): void { throw new RuntimeException('armazenamento cheio'); });
    ibptCheck($logUnavailable->lookup('BA','101011000')['chave'] === 'ABC123', 'falha no log opcional não impede cálculo');
    echo 'OK: ' . $checks . " verificações da tabela IBPT.\n";
} finally { ibptRemove($directory); }
