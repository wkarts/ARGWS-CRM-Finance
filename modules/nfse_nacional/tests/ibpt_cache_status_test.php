<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\IbptStore;
use NfseNacional\IbptTable;

$checks = 0; $now = strtotime('2026-11-11T12:00:00Z');
$directory = sys_get_temp_dir() . '/nfse-ibpt-status-' . bin2hex(random_bytes(8));
function statusCheck(bool $value, string $message): void { if (!$value) { throw new RuntimeException('FALHOU: ' . $message); } ++$GLOBALS['checks']; }
function statusReject(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $error) { ++$GLOBALS['checks']; return; }
    throw new RuntimeException('FALHOU: ' . $message);
}
function statusClock(): int { return $GLOBALS['now']; }
function statusStore(string $company): IbptStore { return new IbptStore($GLOBALS['directory'], hash('sha256', $company), 'statusClock'); }
function statusFile(string $company, string $uf = 'BA'): string { return $GLOBALS['directory'] . '/' . hash('sha256', $company) . '/table-' . $uf . '.php'; }
function statusRow(array $values = []): array {
    return array_replace(['codigo'=>'101011000','ex'=>'','tipo'=>'1','descricao'=>'Serviço de teste',
        'nacionalfederal'=>'13.45','importadosfederal'=>'15.45','estadual'=>'0','municipal'=>'5',
        'vigenciainicio'=>'01/11/2026','vigenciafim'=>'30/11/2026','chave'=>'TST072','versao'=>'26.2.C','fonte'=>'IBPT/empresometro.com.br'], $values);
}
function statusCsv(array $rows): string {
    $stream = fopen('php://temp', 'w+b'); fputcsv($stream, array_keys(statusRow()), ';', '"', '');
    foreach ($rows as $row) { fputcsv($stream, $row, ';', '"', ''); }
    rewind($stream); $body = stream_get_contents($stream); fclose($stream); return $body;
}
function statusState(string $file): array { return json_decode(substr(file_get_contents($file), strlen("<?php exit; ?>\n")), true, 16, JSON_THROW_ON_ERROR); }
function statusPut(string $file, array $state): void { file_put_contents($file, "<?php exit; ?>\n" . json_encode($state)); }
function statusRemove(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (glob($path . '/*') ?: [] as $child) { statusRemove($child); }
    if (is_dir($path)) { rmdir($path); }
}
try {
    $calls = []; $failure = false;
    $body = statusCsv([statusRow(), statusRow(['codigo'=>'101012000'])]);
    $table = new IbptTable(statusStore('a'), function ($url, $timeout) use (&$calls, &$body, &$failure): string {
        $calls[] = [$url, $timeout]; if ($failure) { throw new RuntimeException('segredo-remoto', 503); } return $body;
    }, 'statusClock');
    $empty = $table->status('ba');
    statusCheck($empty['uf'] === 'BA' && $empty['status'] === 'missing' && $empty['service_count'] === 0, 'status inicial usa UF normalizada e informa ausência local');
    statusCheck($empty['can_refresh'] && $empty['next_manual_at'] === null && $empty['last_attempt_at'] === null, 'primeira atualização manual está disponível');
    statusCheck($calls === [] && !is_file(statusFile('a')), 'abrir status não baixa nem cria uma tabela');
    statusCheck(glob($directory . '/' . hash('sha256','a') . '/*') === [], 'status não cria arquivo de bloqueio');
    foreach (['ZZ','BA/../SP','https://example.test'] as $bad) {
        statusReject(fn()=>$table->status($bad), 'UF inválida no status');
        statusReject(fn()=>$table->refresh($bad), 'UF inválida na atualização');
    }
    statusCheck($calls === [], 'UF inválida nunca chega ao transporte');
    $updated = $table->refresh('BA');
    statusCheck(count($calls) === 1 && $calls[0] === ['https://ibpt.wwsoftwares.com.br/tabela/ibpt/BA',12000], 'atualização usa só UF e o destino fixo');
    statusCheck($updated['status'] === 'current' && $updated['refresh_state'] === 'updated' && $updated['service_count'] === 2, 'atualização sem NBS armazena todos os serviços da UF');
    statusCheck($updated['version'] === '26.2.C' && $updated['key'] === 'TST072' && $updated['source'] === 'IBPT/empresometro.com.br', 'status expõe versão chave e fonte reais');
    statusCheck($updated['valid_from'] === '2026-11-01' && $updated['valid_to'] === '2026-11-30', 'período vem da tabela');
    statusCheck($updated['fetched_at'] === '2026-11-11T12:00:00Z' && $updated['last_attempt_at'] === $updated['fetched_at'], 'datas de obtenção e tentativa são explícitas');
    statusCheck(!$updated['can_refresh'] && $updated['next_manual_at'] === '2026-11-11T12:01:00Z', 'atualização manual tem intervalo de um minuto');
    statusCheck($updated['next_auto_check_at'] === '2026-12-01T03:00:00Z' && $updated['next_retry_at'] === null, 'renovação automática prevista após fim de vigência no horário brasileiro');
    $file = statusFile('a'); clearstatcache(true,$file); $inode = fileinode($file); $before = file_get_contents($file);
    $sameNow = $table->refresh('BA');
    statusCheck($sameNow['refresh_state'] === 'throttled' && count($calls) === 1, 'duplo clique não duplica transferência');
    statusCheck(file_get_contents($file) === $before, 'clique limitado não modifica a tabela');
    for ($i=0;$i<10;$i++) { $table->lookup('BA', $i%2 ? '101012000' : '101011000'); $table->status('BA'); }
    clearstatcache(true,$file);
    statusCheck(count($calls) === 1 && fileinode($file) === $inode && file_get_contents($file) === $before, 'notas e status reutilizam cache sem rede nem regravação');
    $GLOBALS['now'] += 86400;
    $table->lookup('BA','101011000');
    statusCheck(count($calls) === 1 && $table->status('BA')['can_refresh'], 'passar um dia não baixa tabela vigente');
    statusReject(fn()=>$table->lookup('BA','101099999'), 'NBS ausente não é inventado');
    statusCheck(count($calls) === 1, 'NBS ausente em tabela vigente não desencadeia downloads repetidos');
    $same = $table->refresh('BA');
    statusCheck($same['refresh_state'] === 'unchanged' && count($calls) === 2 && $same['fetched_at'] === $updated['fetched_at'], 'retorno idêntico preserva data da versão local e atualiza tentativa');
    statusCheck($same['last_attempt_at'] === '2026-11-12T12:00:00Z', 'última consulta é diferente da instalação da tabela');
    $GLOBALS['now'] += 61; $failure = true;
    $failed = $table->refresh('BA');
    statusCheck($failed['status'] === 'current' && $failed['refresh_state'] === 'failed' && $failed['key'] === 'TST072', 'falha manual preserva tabela local vigente');
    statusCheck($failed['next_retry_at'] === '2026-11-12T13:01:01Z' && $failed['next_manual_at'] === '2026-11-12T12:02:01Z', 'intervalos automático e manual são independentes');
    statusCheck(!str_contains(json_encode($failed),'segredo-remoto'), 'status não expõe corpo ou exceção de transporte');
    $table->lookup('BA','101011000');
    statusCheck(count($calls) === 3, 'cache vigente segue disponível sem insistir na fonte');
    $GLOBALS['now'] += 61; $failure = false;
    $recovered = $table->refresh('BA');
    statusCheck($recovered['refresh_state'] === 'unchanged' && count($calls) === 4, 'manual após um minuto permite corrigir falha antes da próxima tentativa automática');
    foreach ([['vigenciainicio'=>'01/10/2026','vigenciafim'=>'31/10/2026','versao'=>'26.2.B'],
        ['vigenciainicio'=>'01/12/2026','vigenciafim'=>'31/12/2026','versao'=>'26.2.D'],
        ['vigenciainicio'=>'01/10/2026','versao'=>'26.2.B'], ['versao'=>'26.2.B']] as $old) {
        $GLOBALS['now'] += 61; $body = statusCsv([statusRow($old)]); $preserved = $table->refresh('BA');
        statusCheck($preserved['status'] === 'current' && $preserved['key'] === 'TST072' && $preserved['service_count'] === 2 && $preserved['refresh_state'] === 'failed', 'fonte anterior vencida futura ou regressiva não apaga cópia vigente');
        statusCheck($preserved['fetched_at'] === $updated['fetched_at'] && str_contains($preserved['refresh_message'],'preservada'), 'preservação da versão local é informada');
    }
    $other = new IbptTable(statusStore('b'), static fn():string=>statusCsv([statusRow(['chave'=>'OUTRA72'])]), 'statusClock');
    statusCheck($other->status('BA')['status'] === 'missing', 'contexto de outra empresa não vê tabela da empresa anterior');
    statusCheck($other->refresh('SP')['uf'] === 'SP' && $other->status('BA')['status'] === 'missing', 'cache de SP não é confundido com BA');
    statusCheck($table->status('BA')['key'] === 'TST072', 'outra empresa não altera chave anterior');

    $GLOBALS['now'] = strtotime('2026-11-11T12:00:00Z'); $expiredCalls=0;
    $expiredBody = statusCsv([statusRow(['vigenciainicio'=>'20/09/2026','vigenciafim'=>'31/10/2026','chave'=>'C44399','versao'=>'26.2.B'])]);
    $expired = new IbptTable(statusStore('expired'), function()use(&$expiredCalls,&$expiredBody):string{++$expiredCalls;return $expiredBody;},'statusClock');
    $s=$expired->refresh('BA');
    statusCheck($s['status']==='expired' && $s['key']==='C44399' && $s['refresh_state']==='failed', 'fonte vencida fica identificada sem fingir atualização utilizável');
    statusCheck($s['valid_to']==='2026-10-31' && $s['next_retry_at']==='2026-11-11T13:00:00Z', 'vigência vencida e próxima tentativa automática visíveis');
    statusCheck($expired->lookup('BA','101011000')['chave']==='C44399','cache vencido ainda calcula');
    $GLOBALS['now']+=3599; statusCheck($expired->lookup('BA','101011000')['chave']==='C44399','espera automática preservada sem bloquear cálculo');
    statusCheck($expiredCalls===1, 'vencimento não baixa a tabela em cada emissão');
    $expiredBody=statusCsv([statusRow()]); $GLOBALS['now']++;
    statusCheck($expired->lookup('BA','101011000')['chave']==='TST072' && $expiredCalls===2, 'ao vencer intervalo renova automaticamente e usa retorno vigente');

    $future = new IbptTable(statusStore('future'), static fn():string=>statusCsv([statusRow(['vigenciainicio'=>'01/12/2026','vigenciafim'=>'31/12/2026'])]), 'statusClock');
    statusCheck($future->refresh('BA')['status']==='future', 'tabela futura não aparece como vigente');
    $GLOBALS['now']=strtotime('2026-12-01T03:00:00Z');
    statusCheck($future->status('BA')['status']==='current', 'vigência muda automaticamente na data de início sem download');
    statusCheck($future->lookup('BA','101011000')['chave']==='TST072', 'tabela futura torna-se utilizável quando começa vigência');
    statusCheck($table->status('BA')['status']==='expired', 'status local reconhece vencimento sem consultar a rede');
    $GLOBALS['now']=strtotime('2026-11-11T12:00:00Z');
    $mixed = new IbptTable(statusStore('mixed'), static fn():string=>statusCsv([statusRow(),statusRow(['codigo'=>'101012000','chave'=>'OUTRA72'])]), 'statusClock');
    $m=$mixed->refresh('BA');
    statusCheck($m['status']==='mixed' && $m['version']===null && $m['key']===null && $m['valid_to']===null && $m['service_count']===2, 'metadados divergentes não mostram chave ou vigência arbitrárias');
    $state=statusState(statusFile('mixed')); $state['rows']['101011000']['municipal']='segredo'; $state['last_outcome']='corpo-secreto'; statusPut(statusFile('mixed'),$state);
    $bad=$mixed->status('BA');
    statusCheck($bad['status']==='invalid' && $bad['key']===null && !str_contains(json_encode($bad),'segredo'), 'status valida cópia local antes de expor metadados');
    $lock=fopen(dirname($file).'/lock-BA.php','r+b');flock($lock,LOCK_EX);
    try { statusCheck($table->status('BA')['status']==='current','status somente leitura permanece disponível durante atualização concorrente'); }
    finally {flock($lock,LOCK_UN);fclose($lock);}
    $state=statusState($file);unset($state['last_attempt_at'],$state['last_outcome']);statusPut($file,$state);
    statusCheck($table->status('BA')['version']==='26.2.C', 'cache v0.7.1 sem metadados de tentativa continua legível');
    echo 'OK: ' . $checks . " verificações de atualização e status local IBPT.\n";
} finally { statusRemove($directory); }
