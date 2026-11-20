<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require dirname(__DIR__).'/autoload.php';
use Multiempresa\Domain\Failure;
use Multiempresa\Http\TenantError;
$n=0;
function userCheck(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException($label);}$n++;}
$technical='Multiempresa PHP SQL MySQL prefixo provisionamento /private/TenantModules.php';
foreach([400,401,403,404,405,409,421,429,503] as $status){
 $e=new Failure('technical_failure',$technical,$status);
 $_SERVER['HTTP_ACCEPT']='text/html';unset($_SERVER['HTTP_X_REQUESTED_WITH']);$_GET=[];
 ob_start();TenantError::render($e);$html=ob_get_clean();
 userCheck(str_contains($html,'<!doctype html>')&&!str_contains($html,$technical)&&!str_contains($html,'technical_failure'),'navigation is a safe HTML error '.$status);
 $_SERVER['HTTP_ACCEPT']='application/json';ob_start();TenantError::render($e);$json=json_decode(ob_get_clean(),true,32,JSON_THROW_ON_ERROR);
 userCheck($json['error']==='technical_failure'&&$json['message']===TenantError::message($status)&&!str_contains($json['message'],$technical),'API retains code without internal diagnosis '.$status);
}
$_SERVER['HTTP_ACCEPT']='text/html';$_SERVER['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';ob_start();TenantError::render(new RuntimeException($technical));$json=json_decode(ob_get_clean(),true,32,JSON_THROW_ON_ERROR);userCheck($json['error']==='module_unavailable'&&str_contains($json['message'],'suporte'),'AJAX receives a generic support message');
unset($_SERVER['HTTP_X_REQUESTED_WITH']);$_GET['__pme_route']='/o/group/e/company/api/v1/clients';ob_start();TenantError::render(new Failure('api_token',$technical,403));$json=json_decode(ob_get_clean(),true,32,JSON_THROW_ON_ERROR);userCheck($json['error']==='api_token','API requests keep JSON even when Accept contains HTML');
echo "RESULT $n tenant error assertions passed\n";
