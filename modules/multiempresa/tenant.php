<?php
// Entrada interna do módulo: define o contexto ANTES de inicializar o Perfex compartilhado.
declare(strict_types=1);
require_once __DIR__.'/autoload.php';
try {
    $app=\Multiempresa\Application\App::environment();
    \Multiempresa\Http\Entry::normalize($app);
    (new \Multiempresa\Http\Gateway($app))->handle();
    if (!empty($GLOBALS['pme_bootstrap_ready'])) {
        $assign_to_config=$GLOBALS['pme_assign_to_config'];
        require BASEPATH.'core/CodeIgniter.php';
    }
} catch (Throwable $e) {
    $status=$e instanceof \Multiempresa\Domain\Failure ? $e->status : 503;
    $reason=$e instanceof \Multiempresa\Domain\Failure ? $e->reason : 'module_unavailable';
    error_log('Multiempresa '.$reason.' '.get_class($e));
    $ctx=$GLOBALS['pme_context']??null;
    if($ctx&&isset($app)){
        try{(new \Multiempresa\Application\ExecutionLog($app))->record('runtime.request','failed',$ctx->principal['user_id']??null,$ctx->companyId,$ctx->organizationId,null,\Multiempresa\Application\ExecutionLog::exception($e)+['route'=>explode('?',$ctx->route,2)[0]]);}catch(Throwable){}
    }
    \Multiempresa\Http\TenantError::render($e);
}
