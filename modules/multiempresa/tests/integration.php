<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,Provisioner,Events,Backups,Recovery,ControlJobs,Modules,Updates};
use Multiempresa\Infrastructure\{Files,Process};
use Multiempresa\Domain\{Id,Failure};
use Multiempresa\Perfex\Adapter;
use Multiempresa\Http\Resolver;
$n=0;
function check(bool $ok,string $label): void { global $n; if (!$ok) { throw new RuntimeException('FAIL '.$label); } $n++; echo "PASS $label\n"; }
function rejected(callable $fn,string $reason): void { try {$fn();} catch(Failure $e) {check($e->reason===$reason,$reason);return;} throw new RuntimeException('Expected '.$reason); }
$app=App::environment();$actor=$app->auth->syncNative(1);$a=$app->catalog->bySlug('grupo','alfa');$b=$app->catalog->bySlug('grupo','beta');
check(count($app->db->all('SELECT * FROM pme_companies'))===2,'central prefixed metadata');
$bc=$app->vault->open($b['secrets'])['connection'];
$gamma=(new Provisioner($app))->create($actor,['admin_mode'=>'current','organization_id'=>$b['organization_id'],'name'=>'Empresa Gama','slug'=>'gama','mode'=>'prefix','prefix'=>'gama_','connection'=>$bc]);
$pdo=$app->storage('prefix')->connect($bc);
$pdo->exec("INSERT INTO gama_clients(company,datecreated,active) VALUES('Exclusivo Gama',NOW(),1)");
check((int)$pdo->query('SELECT COUNT(*) FROM emp_clients')->fetchColumn()===0,'two companies in one database stay isolated');
rejected(fn()=>(new Provisioner($app))->create($actor,['admin_mode'=>'current','organization_id'=>$b['organization_id'],'name'=>'Sobreposição','slug'=>'overlap','mode'=>'prefix','prefix'=>'emp_','connection'=>$bc]),'storage_owned');
$verifier=Id::token();$ticket=$app->auth->issueTicket($actor,$a['id'],hash('sha256',$verifier));
$s=$app->auth->consumeTicket($ticket,$a['id'],$verifier);check($app->auth->session($s['token'],$a['id'])!==null,'SSO ticket binds company');
rejected(fn()=>$app->auth->consumeTicket($ticket,$a['id'],$verifier),'sso_ticket');
check($app->auth->session($s['token'],$b['id'])===null,'SSO cookie cannot select another company');
$app->catalog->revoke($actor,$a['id'],$actor['id']);
rejected(fn()=>$app->auth->issueTicket($actor,$a['id'],hash('sha256',$verifier)),'membership_required');
$app->catalog->grant($actor,$a['id'],$actor['id'],1);
$token=$app->auth->issueToken($actor,$a['id'],'Teste',['context:read','clients:read'],1);
rejected(fn()=>$app->auth->token($token,'clients:read',$b['id']),'api_token');
$events=new Events($app);$ep=$events->endpoint($actor,$a['id'],'hmac','durable_inbox');
$body='{"id":"evt-native-test","entity_type":"example","entity_id":"1"}';$stamp=(string)time();$headers=['x-pme-timestamp'=>$stamp,'x-pme-signature'=>hash_hmac('sha256',$stamp.'.'.$body,$ep['secret'])];
$e=$events->receive('hmac',$ep['id'],$headers,$body);check(!$e['duplicate'],'webhook persisted before acknowledgement');
check($events->receive('hmac',$ep['id'],$headers,$body)['duplicate'],'webhook retry is idempotent');
$other=str_replace('example','changed',$body);$h2=['x-pme-timestamp'=>$stamp,'x-pme-signature'=>hash_hmac('sha256',$stamp.'.'.$other,$ep['secret'])];
rejected(fn()=>$events->receive('hmac',$ep['id'],$h2,$other),'event_conflict');
check($events->runOne(),'event worker processes queued item');
check($app->db->one('SELECT status FROM pme_events WHERE id=?',[$e['id']])['status']==='done','generic inbox delivery completed');
$ca=$app->vault->open($a['secrets'])['connection'];$pa=$app->storage('database')->connect($ca);
check((int)$pa->query('SELECT COUNT(*) FROM emp_pme_inbox')->fetchColumn()===1,'inbox belongs to resolved company');
check((int)$pdo->query('SELECT COUNT(*) FROM emp_pme_inbox')->fetchColumn()===0,'other company inbox unchanged');
Files::write($app->state('companies/'.$a['id'].'/uploads/clients/1/private.txt'),'private alfa');
$job=Id::new();$app->db->exec("INSERT INTO pme_jobs(id,type,target,actor_id,status,created_at) VALUES(?,'backup',?,?,'pending',?)",[$job,$a['id'],$actor['id'],time()]);
(new ControlJobs($app))->runOne();$jr=$app->db->one('SELECT * FROM pme_jobs WHERE id=?',[$job]);check($jr['status']==='done','backup job completed');
$backup=json_decode($jr['result'],true)['backup'];check(is_file($backup),'encrypted backup exists');
$target=$ca;$target['database'].='_restore';$app->db->pdo->exec('CREATE DATABASE `'.$target['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$app->db->exec("UPDATE pme_companies SET status='suspended' WHERE id=?",[$a['id']]);
(new Recovery($app))->restore($a['id'],$backup,$target,'rest_');
$restored=$app->catalog->company($a['id']);check($restored['status']==='suspended' && $restored['table_prefix']==='rest_','restore requires inspection before reopening');
$pr=$app->storage('database')->connect($target);check($pr->query('SELECT company FROM rest_clients')->fetchColumn()==='Exclusivo Alfa','native customer restored');
check(file_get_contents($app->state('companies/'.$a['id'].'/uploads/clients/1/private.txt'))==='private alfa','attachment restored');
check((int)$pa->query('SELECT COUNT(*) FROM emp_clients')->fetchColumn()===1,'original database preserved on restore');
$app->db->exec("UPDATE pme_companies SET status='active' WHERE id=?",[$a['id']]);
$r=Process::run([$app->config['php_binary'],$app->config['root'].'/modules/multiempresa/scripts/tenant-worker.php'],['company_id'=>$b['id'],'operation'=>'cron']);check($r['ok'],'native cron in isolated process');
$mod=$app->config['root'].'/modules/isolation_probe';Files::mkdir($mod);
Files::write($mod.'/isolation_probe.php',"<?php\n/*\nModule Name: Isolation Probe\nVersion: 1.0.0\n*/\nregister_activation_hook('isolation_probe',function(){ get_instance()->db->query('CREATE TABLE IF NOT EXISTS `'.db_prefix().'probe` (id INT PRIMARY KEY) ENGINE=InnoDB'); });\n");
(new Modules($app))->approve($b['id'],'isolation_probe',true);check((int)$pdo->query("SELECT active FROM emp_modules WHERE module_name='isolation_probe'")->fetchColumn()===1,'native third-party module activation in selected company');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gama_probe'")->fetchColumn()===0,'module DDL does not leak to adjacent prefix');
(new Modules($app))->disable($b['id'],'isolation_probe');check((int)$pdo->query("SELECT active FROM emp_modules WHERE module_name='isolation_probe'")->fetchColumn()===0,'native module deactivation preserves data');
$release=$app->db->one('SELECT release_id FROM pme_gate WHERE id=1')['release_id'];
$allow=$app->state('tests/allow-migration');
foreach (['probe_one','probe_two'] as $name) {
    $path=$app->config['root'].'/modules/'.$name; Files::mkdir($path.'/migrations');
    Files::write($path.'/'.$name.'.php',"<?php\n/*\nModule Name: ".$name."\nVersion: 1.0.0\n*/\n");
    (new Modules($app))->approve($b['id'],$name,true);
    Files::write($path.'/'.$name.'.php',"<?php\n/*\nModule Name: ".$name."\nVersion: 1.0.1\n*/\n");
    $fail=$name==='probe_two' ? "if (!is_file(".var_export($allow,true).")) { throw new RuntimeException('Forced migration interruption'); }" : '';
    Files::write($path.'/migrations/101_version_101.php',"<?php class Migration_Version_101 extends App_module_migration { public function up() { get_instance()->db->query('CREATE TABLE `'.db_prefix().'".$name."_upgrade` (id INT PRIMARY KEY) ENGINE=InnoDB'); ".$fail." } }");
    (new Modules($app))->approve($b['id'],$name,true,$release);
}
$updater=new Updates($app,new Adapter($app)); $plan=$updater->plan($actor,$release);
try { $updater->execute($plan); throw new RuntimeException('Expected DDL interruption'); }
catch(Failure $e) { check($app->db->one('SELECT status FROM pme_updates WHERE id=?',[$plan])['status']==='failed','partial DDL marks update failed'); }
check($app->db->one('SELECT state FROM pme_gate WHERE id=1')['state']==='maintenance','partial DDL keeps global access blocked');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emp_probe_two_upgrade'")->fetchColumn()===1,'failure occurred after actual MySQL DDL');
$step=$app->db->one('SELECT * FROM pme_update_steps WHERE update_id=? AND company_id=?',[$plan,$b['id']]);
(new Recovery($app))->restore($b['id'],$step['backup'],$bc,'recovered_',$plan);
Files::write($allow,'allowed');
$updater->execute($plan);
check($app->db->one('SELECT status FROM pme_updates WHERE id=?',[$plan])['status']==='done','restored DDL update resumes and verifies all companies');
check((int)$pdo->query("SELECT COUNT(*) FROM recovered_modules WHERE module_name IN ('probe_one','probe_two') AND installed_version='1.0.1'")->fetchColumn()===2,'same migration class name works in separate module processes');
$token=$app->auth->issueToken($actor,$a['id'],'HTTP integration',['context:read','clients:read'],1);
$app->db->exec('UPDATE pme_organizations SET active=0 WHERE id=?',[$a['organization_id']]);
rejected(fn()=>(new \Multiempresa\Application\ReleaseGate($app))->enter($a['id']),'maintenance');
$app->db->exec('UPDATE pme_organizations SET active=1 WHERE id=?',[$a['organization_id']]);
(new \Multiempresa\Application\Scheduler($app))->tick();
check(count($app->db->all("SELECT name FROM pme_settings WHERE name LIKE 'cron:%'"))>=1,'native cron scheduler runs without manual configuration');
foreach($app->db->all('SELECT * FROM pme_audit') as $row) { check($app->audit->verify($row),'audit integrity '.$row['action']); }
Files::write(dirname(getenv('MULTIEMPRESA_HOST')).'/tokens.json',json_encode(['token'=>$token,'company'=>$a['id'],'endpoint'=>$ep],JSON_THROW_ON_ERROR));
echo "RESULT $n native integration assertions passed\n";
