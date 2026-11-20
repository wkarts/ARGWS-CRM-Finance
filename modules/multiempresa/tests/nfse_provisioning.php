<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,GlobalSettings,Onboarding,ImpactPlans,Modules};
use Multiempresa\Infrastructure\Files;
$app=App::environment();$actor=$app->auth->syncNative(1);$org=$app->catalog->bySlug('grupo','alfa')['organization_id'];$n=0;
function nfseCheck(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException($label);}$n++;echo 'PASS NFS-e '.$label."\n";}
function nfseCopy(string $src,string $dest):void{Files::mkdir($dest);foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $f){$to=$dest.'/'.substr($f->getPathname(),strlen($src)+1);if($f->isDir()){Files::mkdir($to);}else{copy($f->getPathname(),$to);}}}
function nfseFinish(Onboarding $wizard,array $actor,array $w):array{for($i=0;$i<20&&in_array($w['status'],['pending','running'],true);$i++){$w=$wizard->advance($actor,$w['id']);}return $w;}
$global=new GlobalSettings($app);$global->save($actor,['database'=>['operation'=>'automatic','isolation'=>'database','host'=>getenv('MULTIEMPRESA_TEST_MYSQL_HOST'),'port'=>(int)getenv('MULTIEMPRESA_TEST_MYSQL_PORT'),'username'=>getenv('MULTIEMPRESA_TEST_USER'),'password'=>getenv('MULTIEMPRESA_TEST_PASSWORD'),'database_pattern'=>getenv('MULTIEMPRESA_TEST_DATABASE').'_nfse_{company}','user_host'=>'localhost'],'dns'=>['enabled'=>false,'manual_confirmed'=>false]]);
$module=$app->config['root'].'/modules/nfse_nacional';nfseCopy(getenv('MULTIEMPRESA_TEST_NFSE_LEGACY'),$module);
$wizard=new Onboarding($app);$input=['request_key'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'name'=>'NFS-e sem prefixo','slug'=>'nfse-empty','admin_mode'=>'new','admin_name'=>'Administrador fiscal','admin_email'=>'nfse-bare@example.test','admin_password'=>'Fiscal-fixture-password-26','modules'=>['nfse_nacional'],'activate_modules'=>['nfse_nacional'],'modules_reviewed'=>true];
$w=nfseFinish($wizard,$actor,$wizard->begin($actor,$input));
nfseCheck($w['status']==='failed'&&$w['error_code']==='module_prefix','original 0.8.1 failure reproduced and diagnosed without revealing exception text');
nfseCheck($w['completed']===3&&str_contains($w['diagnosis']['diagnosis']??'','NFS-e Nacional'),'completed database, core and settings stages are preserved');
$c=$app->catalog->company($w['company_id']);$connection=$app->vault->open($c['secrets'])['connection'];$pdo=$app->storage($c['mode'])->connect($connection);
nfseCheck($c['table_prefix']===''&&!in_array('nfse_nacional_settings',$app->storage($c['mode'])->tables($pdo,''),true),'legacy scope fails before any NFS-e table write');
$pdo->exec("INSERT INTO clients(company,datecreated,active) VALUES('PRESERVE-NFSE',NOW(),1)");
$completed=array_values(array_filter($w['steps'],fn($s)=>$s['status']==='done'));
nfseCopy(getenv('MULTIEMPRESA_TEST_NFSE_ROOT'),$module);
$impact=new ImpactPlans($app);$plan=$impact->create($actor,'retry',$c['id'],['modules'=>['nfse_nacional'],'activate_modules'=>['nfse_nacional'],'modules_reviewed'=>true]);$retry=$impact->confirm($actor,$plan['plan_id'],$plan['confirmation_name'],true);
nfseCheck(array_values(array_filter($retry['steps'],fn($s)=>$s['status']==='done'))===$completed,'retry does not recreate completed stages');
$retry=nfseFinish($wizard,$actor,$retry);
nfseCheck($retry['status']==='done','updated NFS-e completes the interrupted onboarding: '.json_encode($retry['diagnosis']??[]));
nfseCheck($pdo->query('SELECT company FROM clients')->fetchColumn()==='PRESERVE-NFSE','business data survives module update and retry');
$tables=json_decode(file_get_contents($module.'/multiempresa.json'),true)['tables'];
nfseCheck(!array_diff($tables,$app->storage('database')->tables($pdo,'')),'all 15 NFS-e tables are created without an enterprise prefix');
nfseCheck($pdo->query("SELECT installed_version FROM modules WHERE module_name='nfse_nacional'")->fetchColumn()==='0.8.2','native module version is installed in the exclusive database');
(new Modules($app))->disable($c['id'],'nfse_nacional');nfseCheck($app->catalog->company($c['id'])['status']==='active','deactivation keeps the company active');
(new Modules($app))->approve($c['id'],'nfse_nacional',true);nfseCheck((int)$pdo->query("SELECT active FROM modules WHERE module_name='nfse_nacional'")->fetchColumn()===1,'reactivation works without provisioning again');
$global->save($actor,['database'=>['isolation'=>'prefix','shared_database'=>getenv('MULTIEMPRESA_TEST_DATABASE').'_beta']]);
$input=array_replace($input,['request_key'=>bin2hex(random_bytes(16)),'name'=>'NFS-e compartilhado','slug'=>'nfse-shared','prefix'=>'fiscal_','admin_email'=>'nfse-shared@example.test']);
$shared=nfseFinish($wizard,$actor,$wizard->begin($actor,$input));nfseCheck($shared['status']==='done','NFS-e activates under exact per-table grants in a shared database: '.json_encode($shared['diagnosis']??[]));
$sc=$app->catalog->company($shared['company_id']);$sp=$app->storage('prefix')->connect($app->vault->open($sc['secrets'])['connection']);
nfseCheck(count(array_intersect(array_map(fn($t)=>'fiscal_'.$t,$tables),$app->storage('prefix')->tables($sp,'fiscal_')))===count($tables),'shared NFS-e tables retain their company prefix');
nfseCheck((int)$sp->query("SELECT active FROM fiscal_modules WHERE module_name='nfse_nacional'")->fetchColumn()===1,'shared installation is active');
try{$sp->query('SELECT COUNT(*) FROM emp_clients');throw new RuntimeException('Expected scoped permission error');}catch(PDOException $e){nfseCheck(in_array($e->errorInfo[1]??0,[1142,1044],true),'NFS-e credential cannot read a neighboring company');}
// Empty, scoped database for real HTTP connection success/failure tests.
$cfg=$global->get()['database'];$dest=getenv('MULTIEMPRESA_TEST_DATABASE').'_connection';$user='connection_'.substr(bin2hex(random_bytes(6)),0,12);$password='Connection-fixture-password-26';
$app->db->pdo->exec('CREATE DATABASE `'.$dest.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$app->db->pdo->exec('CREATE USER '.$app->db->pdo->quote($user)."@'localhost' IDENTIFIED BY ".$app->db->pdo->quote($password));
$app->db->pdo->exec('GRANT ALL PRIVILEGES ON `'.str_replace('_','\\_',$dest)."`.* TO ".$app->db->pdo->quote($user)."@'localhost'");
$global->save($actor,['database'=>['operation'=>'manual','isolation'=>'database']]);
Files::write(dirname(getenv('MULTIEMPRESA_HOST')).'/connection-fixture.json',json_encode(['host'=>$cfg['host'],'port'=>$cfg['port'],'database'=>$dest,'username'=>$user,'db_password'=>$password,'organization_id'=>$org],JSON_THROW_ON_ERROR));
echo "RESULT $n native NFS-e provisioning assertions passed\n";
