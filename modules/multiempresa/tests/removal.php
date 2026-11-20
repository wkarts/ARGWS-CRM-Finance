<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,Events,Provisioner};
use Multiempresa\Domain\Failure;
$app=App::environment();$actor=$app->auth->syncNative(1);$company=json_decode(file_get_contents(dirname(getenv('MULTIEMPRESA_HOST')).'/removed-company.json'),true);$n=0;
function removal_reject(callable $fn,string $reason):void{global $n;try{$fn();}catch(Failure $e){if($e->reason!==$reason){throw $e;}$n++;echo 'PASS removed-company '.$reason."\n";return;}throw new RuntimeException('Expected '.$reason);}
if($app->db->one('SELECT id FROM pme_companies WHERE id=?',[$company['id']])){throw new RuntimeException('Deletion must be physical');}
removal_reject(fn()=>$app->catalog->grant($actor,$company['id'],$actor['id'],1),'company_not_found');
removal_reject(fn()=>(new Events($app))->endpoint($actor,$company['id'],'hmac','durable_inbox'),'company_not_found');
$_POST=['company_id'=>$company['id']];
removal_reject(fn()=>(new \Multiempresa\Http\Panel($app,$actor))->action('backup'),'company_not_found');
// Even an accidentally active historical membership cannot bypass the removal marker.
$app->db->exec('UPDATE pme_memberships SET active=1 WHERE company_id=?',[$company['id']]);
try{removal_reject(fn()=>$app->catalog->membership($actor['id'],$company['id']),'company_not_found');}
finally{$app->db->exec('UPDATE pme_memberships SET active=0 WHERE company_id=?',[$company['id']]);}
removal_reject(fn()=>(new Provisioner($app))->create($actor,['admin_mode'=>'current','organization_id'=>$company['organization_id'],'name'=>$company['name'],'slug'=>$company['slug']],false,$company['id']),'company_not_found');
echo 'RESULT '.$n." removed-company regression assertions passed\n";
