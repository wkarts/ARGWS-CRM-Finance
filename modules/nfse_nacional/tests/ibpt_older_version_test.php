<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
use NfseNacional\Config;
use NfseNacional\IbptStore;
use NfseNacional\IbptTable;

$checks = 0;
$t076_now = strtotime('2026-11-11T12:00:00Z');
$dir = sys_get_temp_dir() . '/nfse-ibpt-076-' . bin2hex(random_bytes(8));
function olderCheck(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    ++$GLOBALS['checks'];
}
function olderReject(callable $fn, string $message): void
{
    try { $fn(); }
    catch (InvalidArgumentException $e) { ++$GLOBALS['checks']; return; }
    throw new RuntimeException('FAIL (should reject): ' . $message);
}
function olderClock(): int { return $GLOBALS['t076_now']; }
function olderStore(string $scope): IbptStore
{
    return new IbptStore($GLOBALS['dir'], hash('sha256', $scope), 'olderClock');
}
function olderRow(array $changes = []): array
{
    return array_replace(['codigo'=>'101011000', 'ex'=>'', 'tipo'=>'1', 'descricao'=>'Test service',
        'nacionalfederal'=>'13.45', 'importadosfederal'=>'15.45', 'estadual'=>'0', 'municipal'=>'5',
        'vigenciainicio'=>'01/11/2026', 'vigenciafim'=>'30/11/2026', 'chave'=>'NEW076',
        'versao'=>'26.2.C', 'fonte'=>'IBPT'], $changes);
}
function olderCsv(array $rows): string
{
    $file = fopen('php://temp', 'w+b');
    fputcsv($file, array_keys($rows[0]), ';', '"', '');
    foreach ($rows as $row) { fputcsv($file, array_values($row), ';', '"', ''); }
    rewind($file); $raw = stream_get_contents($file); fclose($file);
    return $raw;
}
function olderAlternative(array $changes = []): string
{
    $row = array_replace(['Codigo'=>'101011000', 'Tipo'=>'nbs', 'UF'=>'BA', 'Tabela'=>'26.2.B',
        'Descricao'=>'Test service old', 'AliquotaNacionalFederal'=>'12.00',
        'AliquotaEstadual'=>'0', 'AliquotaMunicipal'=>'4',
        'VigenciaInicio'=>'20/09/2026', 'VigenciaFim'=>'31/10/2026'], $changes);
    return olderCsv([$row]);
}
function olderClean(string $p): void
{
    if (is_file($p) || is_link($p)) { unlink($p); return; }
    foreach (glob($p . '/*') ?: [] as $child) { olderClean($child); }
    if (is_dir($p)) { rmdir($p); }
}
try {
    olderCheck(Config::defaults()['ibpt_allow_older_table']==='0', 'opt in defaults off');
    $on=Config::sanitize(['ibpt_allow_older_table'=>'1']);
    olderCheck($on['ibpt_allow_older_table']==='1', 'saved on');
    olderCheck(Config::merge($on)['ibpt_allow_older_table']==='1', 'stored on reload');
    olderCheck(Config::sanitize([], $on)['ibpt_allow_older_table']==='1', 'unrelated save preserves setting');
    olderCheck(Config::sanitize(['ibpt_allow_older_table'=>'0'], $on)['ibpt_allow_older_table']==='0', 'toggle off');
    olderReject(fn()=>Config::sanitize(['ibpt_allow_older_table'=>'yes']), 'invalid toggle rejected');

    $fresh = olderCsv([olderRow()]);
    $old = olderCsv([olderRow(['chave'=>'OLD076', 'versao'=>'26.2.B',
        'vigenciainicio'=>'20/09/2026', 'vigenciafim'=>'31/10/2026', 'municipal'=>'4'])]);
    $remoteBody=$old; $calls=0;
    $service=new IbptTable(olderStore('company-a'), function($url,$timeout) use (&$remoteBody,&$calls): string {
        olderCheck($url==='https://ibpt.wwsoftwares.com.br/tabela/ibpt/BA', 'remote URL uses issuer UF');
        ++$calls; return $remoteBody;
    }, 'olderClock');
    $installed=$service->import('BA',$fresh,'BA.csv');
    olderCheck($installed['version']==='26.2.C'&&$installed['key_origin']==='file', 'new version seeded from CSV');
    $GLOBALS['t076_now']+=61;
    $blocked=$service->refresh('BA');
    olderCheck($blocked['version']==='26.2.C'&&$blocked['key']==='NEW076', 'API regressive version blocked by default');
    olderCheck($blocked['auto_refresh_paused']===false&&$calls===1, 'default does not freeze automatic refresh');
    $GLOBALS['t076_now']+=61;
    $chosen=$service->refresh('BA',true);
    olderCheck($chosen['version']==='26.2.B'&&$chosen['key']==='OLD076'&&$chosen['status']==='expired', 'older expired version can be explicitly chosen from API');
    olderCheck($chosen['refresh_state']==='updated'&&$chosen['auto_refresh_paused']===true, 'API downgrade recorded and pauses auto renewal');
    olderCheck($chosen['key_origin']==='api'&&$chosen['key_origin_label']!=='', 'API key provenance preserved');
    olderCheck($chosen['next_auto_check_at']===null, 'auto renewal has no next scheduled attempt');
    $callsBefore=$calls; $GLOBALS['t076_now']+=7200;
    for ($i=0;$i<4;++$i) {
        $quote=$service->lookup('BA','101011000');
        olderCheck($quote['chave']==='OLD076'&&$quote['municipal']==='4.0000', 'selected old rates stay active for fiscal quote');
    }
    olderCheck($calls===$callsBefore, 'emission with old version never replaces it automatically');
    try { $service->lookup('BA','101012000'); throw new LogicException('FAIL: missing NBS was accepted'); }
    catch (RuntimeException $error) { olderCheck(str_contains($error->getMessage(),'NBS 101012000'), 'unknown NBS is not invented'); }
    olderCheck($calls===$callsBefore, 'unknown NBS does not trigger hidden download');

    $remoteBody=$fresh; $GLOBALS['t076_now']+=61;
    $restored=$service->refresh('BA',true);
    olderCheck($restored['version']==='26.2.C'&&$restored['key']==='NEW076', 'explicit API refresh restores newer version');
    olderCheck($restored['auto_refresh_paused']===false&&$restored['key_origin']==='api', 'successful newer API refresh resumes auto policy');

    $alternate=olderAlternative();
    $originalStatus=$service->status('BA');
    olderReject(fn()=> $service->import('BA',$alternate,'ibpt-consulta.csv','MYKEY076'), 'CSV downgrade needs setting');
    olderCheck($service->status('BA')['key']===$originalStatus['key'], 'default rejection does not replace existing table');
    olderReject(fn()=> $service->import('BA',$alternate,'ibpt-consulta.csv','',true), 'missing key cannot be guessed');
    olderCheck($service->status('BA')['key']===$originalStatus['key'], 'missing key keeps existing version');
    $manual=$service->import('BA',$alternate,'ibpt-consulta.csv','MYKEY076',true);
    olderCheck($manual['version']==='26.2.B'&&$manual['key']==='MYKEY076'&&$manual['status']==='expired', 'older CSV and missing key manually registered');
    olderCheck($manual['key_origin']==='manual'&&$manual['acquisition']==='upload', 'metadata identifies manually registered key');
    olderCheck($manual['auto_refresh_paused']===true&&$manual['next_auto_check_at']===null, 'manual older selection preserved');
    $quote=$service->lookup('BA','101011000');
    olderCheck($quote['chave']==='MYKEY076' && $quote['versao']==='26.2.B', 'saved key correlates with original table version');
    olderCheck($quote['source_hash']===hash('sha256',$alternate), 'original uploaded bytes identifiable with manual key');
    olderCheck($service->status('BA')['key_origin_label']==='Cadastrada manualmente no envio', 'registered key provenance visible after reopening');
    $callsBefore=$calls; $GLOBALS['t076_now']+=86400;
    olderCheck($service->lookup('BA','101011000')['chave']==='MYKEY076'&&$calls===$callsBefore, 'manual selection remains after a day without API calls');

    // Integrity rules still apply even with the opt-in enabled.
    olderReject(fn()=> $service->import('BA',olderAlternative(['UF'=>'SP']),'ibpt-consulta.csv','MYKEY076',true), 'other issuer UF rejected');
    olderReject(fn()=> $service->import('BA',olderAlternative(['VigenciaInicio'=>'01/12/2026','VigenciaFim'=>'31/12/2026']),'ibpt-consulta.csv','MYKEY076',true), 'future table rejected');
    olderReject(fn()=> $service->import('BA',olderAlternative(['Chave'=>'FROMFILE']),'ibpt-consulta.csv','NOTSAME',true), 'file key mismatch rejected');
    olderCheck($service->status('BA')['key']==='MYKEY076', 'failed imports cannot modify selected table');
    $second=new IbptTable(olderStore('company-b'), static fn():string=> $fresh, 'olderClock');
    olderCheck($second->status('BA')['status']==='missing', 'manual key not shared with other company');
    olderCheck($second->import('BA',$fresh,'BA.csv')['key']==='NEW076', 'second company independent installation');
    olderCheck($service->status('BA')['key']==='MYKEY076', 'second company did not mutate first key');

    $template=(string) file_get_contents(dirname(__DIR__) . '/views/ibpt_table.php');
    $script=(string) file_get_contents(dirname(__DIR__) . '/assets/nfse_nacional.js');
    olderCheck(str_contains($template,'settings[ibpt_allow_older_table]')&&str_contains($template,'data-nfse-ibpt-allow-older'), 'setting exposed in native IBPT form');
    olderCheck(str_contains($template,'data-nfse-ibpt-import-key')&&str_contains($template,'key_origin_label'), 'key registration and provenance in IBPT form');
    olderCheck(str_contains($script,"older.checked !== state.allowOlder"), 'unsaved choice blocks manual import and update');
    olderCheck(str_contains($script,"data.auto_refresh_paused === true"), 'UI exposes auto renewal suspension');
    echo 'OK: ' . $checks . " checks for older-version API/CSV and manual key registration.\n";
} finally { olderClean($dir); }
