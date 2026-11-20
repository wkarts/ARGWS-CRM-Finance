<?php
declare(strict_types=1);
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,CoreMaintenance,GlobalSettings,Onboarding,ImpactPlans,CompanyManager};
use Multiempresa\Perfex\{Adapter,SchemaTemplate};
use Multiempresa\Infrastructure\Files;
use Multiempresa\Domain\Failure;
$app=App::environment();$actor=$app->auth->syncNative(1);$service=new CoreMaintenance($app);$n=0;
function coreCheck(bool $v,string $m):void{global $n;if(!$v){throw new RuntimeException($m);}++$n;echo 'PASS CORE '.$m."\n";}
function coreDenied(callable $fn,string $reason):void{try{$fn();}catch(Failure $e){coreCheck($e->reason===$reason,'failure '.$reason.' (received '.$e->reason.')');return;}throw new RuntimeException('Expected '.$reason);}
$root=$app->config['root'];$org=$app->catalog->bySlug('grupo','alfa')['organization_id'];
$global=new GlobalSettings($app);$global->save($actor,['database'=>['operation'=>'automatic','isolation'=>'prefix','shared_database'=>getenv('MULTIEMPRESA_TEST_DATABASE').'_beta'],'dns'=>['enabled'=>false,'manual_confirmed'=>false]]);
$wizard=new Onboarding($app);$before=(new CompanyManager($app))->release();
$w=$wizard->begin($actor,['admin_mode'=>'current','request_key'=>bin2hex(random_bytes(20)),'organization_id'=>$org,'name'=>'Automação do núcleo','slug'=>'core-automatic','prefix'=>'autocore_','language'=>'english','timezone'=>'UTC','modules'=>[]]);
$app->db->exec('UPDATE pme_releases SET fingerprint=? WHERE id=?',[str_repeat('0',64),$before['id']]);
$refreshed=$service->synchronize();
coreCheck($refreshed['id']!==$before['id']&&$refreshed['fingerprint']===Adapter::inspect($root)['fingerprint'],'obsolete registered fingerprint is automatically synchronized');
coreCheck($service->synchronize()['id']===$refreshed['id'],'synchronization is idempotent');
$w=$wizard->advance($actor,$w['id']);
$legacy=$w['steps'];$legacy[$w['step_index']]['status']='failed';
$app->db->exec("UPDATE pme_onboardings SET status='failed',error_code='core_changed',steps=? WHERE id=?",[json_encode($legacy),$w['id']]);
$app->db->exec("UPDATE pme_companies SET status='failed',error_code='core_changed' WHERE id=?",[$w['company_id']]);
$w=$wizard->advance($actor,$w['id']);
coreCheck($w['status']==='pending'&&$w['step_index']===2,'previous core_changed failure resumes automatically without an impact plan');

for($i=0;$i<10&&in_array($w['status'],['pending','running'],true);$i++){$w=$wizard->advance($actor,$w['id']);}
coreCheck($w['status']==='done','unfinished wizard continues after internal release registration changes: '.json_encode($w['diagnosis']));
$c=$app->catalog->company($w['company_id']);$pdo=$app->storage($c['mode'])->connect($app->vault->open($c['secrets'])['connection']);
coreCheck(is_file($app->state('companies/'.$c['id'].'/bootstrap.pme')),'bootstrap structure is persisted before DDL');
$bootstrap=$app->vault->open(file_get_contents($app->state('companies/'.$c['id'].'/bootstrap.pme')));
coreCheck($bootstrap['company_id']===$c['id']&&!str_contains(file_get_contents($app->state('companies/'.$c['id'].'/bootstrap.pme')),'CREATE TABLE'),'bootstrap template is encrypted and bound to the company');
$native=$app->config['native_prefix'];$version=(int)$refreshed['version'];
$pdo->exec("INSERT INTO autocore_clients(company,datecreated) VALUES('Cliente preservado',NOW())");$client=(int)$pdo->lastInsertId();
(new \Multiempresa\Application\DatabaseProvisioning($app))->grantTables($c['id'],['generated_probe']);
$pdo->exec('CREATE TABLE autocore_generated_probe(id INT PRIMARY KEY, doubled INT GENERATED ALWAYS AS (id*2) STORED) ENGINE=InnoDB');$pdo->exec('INSERT INTO autocore_generated_probe(id) VALUES(21)');
$app->db->pdo->exec('ALTER TABLE `'.$native.'options` ADD pme_future_field INT NULL');
try{
    SchemaTemplate::install($app,$pdo,'autocore_',$refreshed,$c['id']);
    coreCheck((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='autocore_options' AND COLUMN_NAME='pme_future_field'")->fetchColumn()===0,'resuming keeps the pinned template when the primary schema changes');
    coreCheck($pdo->query('SELECT company FROM autocore_clients WHERE userid='.$client)->fetchColumn()==='Cliente preservado','template resume never reseeds or overwrites business data');
}finally{$app->db->pdo->exec('ALTER TABLE `'.$native.'options` DROP COLUMN pme_future_field');}
$config=$root.'/application/config/migration.php';$configLink=is_link($config)?readlink($config):null;$configText=file_get_contents($config);
if($configLink){unlink($config);}Files::write($config,$configText."\n// Changed by a completed native patch.\n");
try{
    $patched=$service->synchronize();coreCheck($patched['id']!==$refreshed['id'],'completed native code patch is detected without manual release registration');
    $app->db->pdo->exec('UPDATE `'.$native.'migrations` SET version='.($version-1));
    Files::write($config,file_get_contents($config)."// Still updating\n");
    try{coreDenied(fn()=>$service->synchronize(),'schema_source_version');coreCheck((new CompanyManager($app))->release()['id']===$patched['id'],'incomplete native update cannot publish a mismatched schema');}
    finally{$app->db->pdo->exec('UPDATE `'.$native.'migrations` SET version='.$version);}
    $service->synchronize();
    // Recreate the exact database changes from native migration 3.4.1.
    coreCheck($version===341,'real native 3.4.1 migration fixture');
    $pdo->exec('ALTER TABLE autocore_user_auto_login MODIFY key_id VARCHAR(40) NOT NULL');
    $pdo->exec("DELETE FROM autocore_options WHERE name IN ('proposal_auto_convert_leads_to_client_on_client_accept','einvoice_send_as_credit_note_email_attachment')");
    $pdo->exec('UPDATE autocore_migrations SET version=340');$app->db->exec('UPDATE pme_companies SET schema_version=340 WHERE id=?',[$c['id']]);
    $workerLock=new \Multiempresa\Infrastructure\AdvisoryLock($app,'core-worker:'.$c['id']);
    try{coreDenied(fn()=>$service->ensure($c['id']),'operation_busy');coreCheck($app->catalog->company($c['id'])['status']==='active'&&(int)$pdo->query('SELECT version FROM autocore_migrations')->fetchColumn()===340,'live isolated worker blocks concurrent migration or rollback');}
    finally{$workerLock->close();}
    $service->ensure($c['id']);
    coreCheck((int)$pdo->query('SELECT version FROM autocore_migrations')->fetchColumn()===341&&$app->catalog->company($c['id'])['status']==='active','native migration runs automatically before tenant access');
    coreCheck((int)$pdo->query("SELECT COUNT(*) FROM autocore_options WHERE name='proposal_auto_convert_leads_to_client_on_client_accept'")->fetchColumn()===1,'native migration executes the actual Perfex SQL and defaults');
    coreCheck($pdo->query('SELECT company FROM autocore_clients WHERE userid='.$client)->fetchColumn()==='Cliente preservado','automatic native update preserves existing customers');
    coreCheck(count(glob($app->state('backups/'.$c['id'].'/*/snapshot.pme')))===1,'exactly one encrypted backup is taken before migration');
    $service->ensure($c['id']);coreCheck(count(glob($app->state('backups/'.$c['id'].'/*/snapshot.pme')))===1,'already current database is not migrated or backed up again');
    $currentRelease=$service->synchronize();$key='core:'.$c['id'];
    $app->db->exec("UPDATE pme_companies SET status='maintenance' WHERE id=?",[$c['id']]);
    $app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)',[$key,$app->vault->seal(['phase'=>'migrating','attempt'=>str_repeat('a',32),'release_id'=>$currentRelease['id']])]);
    try{
        coreDenied(fn()=>\Multiempresa\Infrastructure\Process::run([$app->config['php_binary'],$root.'/modules/multiempresa/scripts/tenant-worker.php'],['company_id'=>$c['id'],'operation'=>'migrate','release'=>$currentRelease,'automatic'=>true,'attempt'=>str_repeat('b',32)]),'migration_job');
        coreCheck($pdo->query('SELECT company FROM autocore_clients WHERE userid='.$client)->fetchColumn()==='Cliente preservado','late worker with an obsolete attempt cannot execute database changes');
    }finally{$app->db->exec('DELETE FROM pme_settings WHERE name=?',[$key]);$app->db->exec("UPDATE pme_companies SET status='active' WHERE id=?",[$c['id']]);}
    $app->db->exec('UPDATE pme_companies SET schema_version=342 WHERE id=?',[$c['id']]);
    try{coreDenied(fn()=>$service->ensure($c['id']),'downgrade');}finally{$app->db->exec('UPDATE pme_companies SET schema_version=341 WHERE id=?',[$c['id']]);}
    $backup=glob($app->state('backups/'.$c['id'].'/*/snapshot.pme'))[0];$backupHash=hash_file('sha256',$backup);$original=$app->catalog->company($c['id']);
    $app->db->exec("UPDATE pme_companies SET status='maintenance' WHERE id=?",[$c['id']]);
    coreDenied(fn()=>(new \Multiempresa\Application\Backups($app))->rollbackOwned($c['id'],$backup,str_repeat('0',64)),'rollback_backup');
    coreCheck($pdo->query('SELECT company FROM autocore_clients WHERE userid='.$client)->fetchColumn()==='Cliente preservado','invalid automatic backup cannot alter tenant data');
    $centralConnection=$app->vault->open($c['secrets'])['connection'];$centralConnection['database']=getenv('MULTIEMPRESA_TEST_DATABASE');$centralConnection['username']=getenv('MULTIEMPRESA_TEST_USER')?:'root';$centralConnection['password']=getenv('MULTIEMPRESA_TEST_PASSWORD')?:'';
    $secret=$app->vault->open($c['secrets']);$secret['connection']=$centralConnection;
    $app->db->exec("UPDATE pme_companies SET secrets=?,table_prefix=?,schema_version=340,status='suspended' WHERE id=?",[$app->vault->seal($secret),$native,$c['id']]);
    try{coreDenied(fn()=>$service->ensure($c['id']),'primary_protected');}
    finally{$app->db->exec('UPDATE pme_companies SET secrets=?,table_prefix=?,schema_version=?,status=? WHERE id=?',[$original['secrets'],$original['table_prefix'],$original['schema_version'],$original['status'],$c['id']]);}

    // Force a real DDL + row mutation followed by an exception; recovery must be automatic.
    $migrations=$root.'/application/migrations';$migrationLink=is_link($migrations)?readlink($migrations):null;
    if(!$migrationLink){throw new RuntimeException('Disposable migrations symlink required');}
    unlink($migrations);mkdir($migrations);
    foreach(new DirectoryIterator($migrationLink) as $f){if(!$f->isDot()){symlink($f->getPathname(),$migrations.'/'.$f->getFilename());}}
    $file=$migrations.'/341_version_341.php';unlink($file);
    Files::write($file, <<<'FIXTURE'
<?php
class Migration_Version_341 extends CI_Migration {
    public function up():void {
        $this->db->query("UPDATE `".db_prefix()."clients` SET company='BROKEN'");
        $this->db->query("ALTER TABLE `".db_prefix()."clients` ADD pme_partial INT NULL");
        throw new RuntimeException('automatic rollback fixture');
    }
}
FIXTURE);
    $pdo->exec('UPDATE autocore_migrations SET version=340');$app->db->exec('UPDATE pme_companies SET schema_version=340 WHERE id=?',[$c['id']]);
    try{
        coreDenied(fn()=>$service->ensure($c['id']),'core_update_pending');
        coreCheck($pdo->query('SELECT company FROM autocore_clients WHERE userid='.$client)->fetchColumn()==='Cliente preservado','failed migration restores customer rows automatically');
        coreCheck((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='autocore_clients' AND COLUMN_NAME='pme_partial'")->fetchColumn()===0,'failed migration rolls back completed nontransactional DDL');
        coreCheck($app->catalog->company($c['id'])['status']==='maintenance','incompatible database is not exposed while automatic retry is pending');
        coreCheck((int)$pdo->query('SELECT doubled FROM autocore_generated_probe WHERE id=21')->fetchColumn()===42,'automatic backup and rollback preserve generated columns');
    }finally{foreach(new DirectoryIterator($migrations) as $f){if(!$f->isDot()){unlink($f->getPathname());}}rmdir($migrations);symlink($migrationLink,$migrations);}
    $service->ensure($c['id']);
    coreCheck($app->catalog->company($c['id'])['status']==='active'&&(int)$pdo->query('SELECT version FROM autocore_migrations')->fetchColumn()===341,'pending automatic maintenance retries successfully after code is available');
    coreCheck(!$app->db->one('SELECT name FROM pme_settings WHERE name=?',['core:'.$c['id']]),'successful retry removes maintenance state');
    coreCheck((int)$app->db->pdo->query('SELECT version FROM `'.$native.'migrations`')->fetchColumn()===341,'primary installation schema is never migrated by tenant maintenance');
}finally{
    unlink($config);if($configLink){symlink($configLink,$config);}else{Files::write($config,$configText);}
    $service->synchronize();
}
$impact=new ImpactPlans($app);$p=$impact->create($actor,'delete_company',$c['id'],['delete_data'=>true,'delete_files'=>true,'delete_backups'=>true,'delete_dns'=>true]);$impact->confirm($actor,$p['plan_id'],$p['confirmation_name'],true);
coreCheck(!$app->db->one('SELECT id FROM pme_companies WHERE id=?',[$c['id']]),'automatic maintenance resources remain removable independently of the organization');
echo 'RESULT '.$n.' automatic core assertions passed'."\n";
