<?php

declare(strict_types=1);
// This process receives one company and one operation over stdin. No credentials in argv.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/autoload.php';
use Multiempresa\Application\{App,ReleaseGate};
use Multiempresa\Domain\Failure;
use Multiempresa\Http\Resolver;
use Multiempresa\Perfex\{Runtime,Adapter};

ob_start();
try {
    $app = App::environment();
    \Multiempresa\Perfex\Installer::assertEnabled($app);
    $job = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($job) || !in_array($job['operation'] ?? '', ['migrate','cron','module_activate','module_migrate','module_deactivate','module_update'], true)) {
        throw new Failure('worker_job', 'Rotina inválida.');
    }
    $ctx = (new Resolver($app))->forCompany($job['company_id']);
    if ($job['operation'] === 'migrate') {
        $state = $app->db->one('SELECT state FROM pme_gate WHERE id=1');
        $automatic=!empty($job['automatic'])&&$state['state']==='active'&&$app->catalog->company($ctx->companyId)['status']==='maintenance';
        if ($state['state'] !== 'maintenance' && !$automatic) {
            throw new Failure('migration_gate', 'Migrações exigem manutenção global.');
        }
        $release = $job['release'];
        if($automatic){
            $workerLock=new \Multiempresa\Infrastructure\AdvisoryLock($app,'core-worker:'.$ctx->companyId);
            $stateRow=$app->db->one('SELECT value FROM pme_settings WHERE name=?',['core:'.$ctx->companyId]);
            $attempt=$stateRow?$app->vault->open($stateRow['value']):[];
            if(($attempt['phase']??'')!=='migrating'||empty($job['attempt'])||($attempt['attempt']??null)!==$job['attempt']||($attempt['release_id']??null)!==$release['id']){throw new Failure('migration_job','A tentativa de atualização foi substituída.');}
        }
        if($automatic){$published=$app->db->one('SELECT release_id FROM pme_gate WHERE id=1');if($release['id']!==$published['release_id']){throw new Failure('migration_gate','A versão da manutenção mudou.');}}
    } elseif (in_array($job['operation'], ['module_activate','module_migrate','module_deactivate','module_update'], true)) {
        $company = $app->catalog->company($ctx->companyId);
        $global=$app->db->one('SELECT state FROM pme_gate WHERE id=1');
        if ($job['operation']==='module_migrate' && $global['state']==='maintenance' && !empty($job['release'])) {
            $release=$job['release'];
        } else {
            if ($company['status'] !== 'maintenance' || $global['state'] !== 'active') { throw new Failure('module_gate','Alterar módulos exige manutenção da empresa.'); }
            $release=$app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1');
        }
    } else {
        $gate = new ReleaseGate($app);
        $release = $gate->enter($ctx->companyId);
    }
    if($job['operation']==='module_update'){
        $workerLock=new \Multiempresa\Infrastructure\AdvisoryLock($app,'module-worker:'.$ctx->companyId);
        $key=\Multiempresa\Application\ModuleMaintenance::key($ctx->companyId,$job['module']);
        $record=$app->db->one('SELECT value FROM pme_settings WHERE name=?',[$key]);$state=$record?$app->vault->open($record['value']):[];
        if(($state['phase']??'')!=='migrating'||($state['attempt']??null)!==($job['attempt']??null)||($state['module']??null)!==$job['module']||($state['release']['id']??null)!==$release['id']){throw new Failure('module_update_job','Tentativa de atualização substituída.');}
    }
    if (!$release || !hash_equals($release['fingerprint'], Adapter::inspect($release['root'])['fingerprint'])) {
        throw new Failure('core_changed', 'O núcleo foi alterado fora do gerenciador de versões.', 503);
    }
    $GLOBALS['pme_job'] = $job;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING'] = '';
    $_SERVER['HTTP_HOST'] = parse_url($app->config['base_url'], PHP_URL_HOST);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    (new Runtime($app))->run($ctx, $release, true);
    $assign_to_config = $GLOBALS['pme_assign_to_config'];
    require BASEPATH.'core/CodeIgniter.php';
    $output = ob_get_clean();
    $result = $GLOBALS['pme_job_result'] ?? null;
    if (!$result) {
        throw new Failure('worker_bootstrap', 'O núcleo não concluiu a rotina.');
    }
    $job=$GLOBALS['pme_job'];
    if($job['operation']==='module_update'){
        $app=$GLOBALS['pme_app'];$key=\Multiempresa\Application\ModuleMaintenance::key($GLOBALS['pme_context']->companyId,$job['module']);
        $record=$app->db->one('SELECT value FROM pme_settings WHERE name=?',[$key]);$state=$record?$app->vault->open($record['value']):[];
        if(($state['attempt']??null)!==$job['attempt']){throw new Failure('module_update_job','Tentativa de atualização substituída.');}
        $state['phase']='applied';$app->db->exec('UPDATE pme_settings SET value=? WHERE name=?',[$app->vault->seal($state),$key]);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode(['ok' => false,'reason' => $e instanceof Failure ? $e->reason : 'tenant_failed','diagnostic'=>\Multiempresa\Application\ExecutionLog::exception($e)]);
    fwrite(STDERR, get_class($e).': isolated operation failed'."\n");
    exit(1);
}
