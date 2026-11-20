<?php
// Included in GLOBAL scope, before the native front controller defines its context.
if (PHP_SAPI === 'cli' || defined('PME_CONTEXT_READY')) { return; }
require_once __DIR__.'/autoload.php';
if (!is_file(\Multiempresa\Perfex\Installer::configPath())) { return; }
$pmePath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$pmeConfig=require \Multiempresa\Perfex\Installer::configPath();
$pmeMount=rtrim(parse_url($pmeConfig['native_url'],PHP_URL_PATH)??'','/');
// Custom hosts share this front controller, but may never fall through into the principal CRM.
$pmeExpected=parse_url($pmeConfig['native_url']);$pmeHost=strtolower($_SERVER['HTTP_HOST']??'');
if($pmeHost!==strtolower($pmeExpected['host'].(isset($pmeExpected['port'])?':'.$pmeExpected['port']:''))){
    try{
        $app=\Multiempresa\Application\App::environment();\Multiempresa\Perfex\Installer::assertEnabled($app);
        $pmeDomain=$app->db->one('SELECT verified FROM pme_hosts WHERE host=?',[$pmeHost])??$app->db->one('SELECT verified FROM pme_organization_hosts WHERE host=?',[$pmeHost]);
        if($pmeDomain&&$pmePath==='/__pme_domain_check'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
            header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['proof'=>(new \Multiempresa\Integration\DomainReadiness($app))->proof($pmeHost)]);exit;
        }
        if(!$pmeDomain||!(int)$pmeDomain['verified']){throw new \Multiempresa\Domain\Failure('domain_pending','Endereço ainda não disponível. Use o endereço atual da aplicação.',421);}
        require __DIR__.'/tenant.php';exit;
    }catch(\Throwable $e){http_response_code($e instanceof \Multiempresa\Domain\Failure?$e->status:503);header('Cache-Control: no-store');header('Content-Type: text/plain; charset=utf-8');echo \Multiempresa\Http\TenantError::message($e instanceof \Multiempresa\Domain\Failure?$e->status:503);exit;}
}
unset($pmeExpected,$pmeHost);

if(!is_string($pmePath)||!preg_match('~\A'.preg_quote($pmeMount,'~').'/([a-f0-9]{20})(?:/(.*))?\z~D',$pmePath,$pmeMatch)){unset($pmeConfig,$pmePath,$pmeMount,$pmeMatch);return;}
try{
    $app=\Multiempresa\Application\App::environment();
    $routes=new \Multiempresa\Application\CompanyRoutes($app);
    $target=$routes->resolve($pmeMatch[1]);
    if(!$target){throw new \Multiempresa\Domain\Failure('company_not_found','Empresa não encontrada.',404);}
    $expected=parse_url($app->config['native_url']);
    if(strtolower($_SERVER['HTTP_HOST']??'')!==strtolower($expected['host'].(isset($expected['port'])?':'.$expected['port']:''))){throw new \Multiempresa\Domain\Failure('unknown_host','Domínio não cadastrado.',421);}
    if($target!==\Multiempresa\Application\Installation::ID){require __DIR__.'/tenant.php';exit;}
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&preg_match('~\A(?:assets/|modules/[a-zA-Z0-9_]+/assets/|uploads/|media/)~',$pmeMatch[2]??'')){
        \Multiempresa\Http\Response::redirect($app->config['native_url'].$pmeMatch[2].(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:''));
    }
    $GLOBALS['pme_app']=$app;
    define('PME_PRIMARY_ROUTE',true);
    $assign_to_config['base_url']=$routes->url($target);
    $_SERVER['SCRIPT_NAME']=$pmeMount.'/'.$pmeMatch[1].'/index.php';
    $_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
    require_once __DIR__.'/libraries/Perfex/url_bridge.php';
}catch(\Throwable $e){
    http_response_code($e instanceof \Multiempresa\Domain\Failure?$e->status:503);
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
    \Multiempresa\Http\TenantError::render($e);exit;
}
unset($pmeConfig,$pmePath,$pmeMount,$pmeMatch,$expected,$target,$routes);
