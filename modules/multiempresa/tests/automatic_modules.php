<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,GlobalSettings,Onboarding,ModuleMaintenance,Modules,ModuleMaintenance as Maintenance};
use Multiempresa\Infrastructure\{Files,Process};
use Multiempresa\Perfex\ModuleRuntime;
$app=App::environment();$actor=$app->auth->syncNative(1);$org=$app->catalog->bySlug('grupo','alfa')['organization_id'];$n=0;
function autoCheck(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException('FAIL '.$label);}$n++;echo 'PASS auto modules '.$label."\n";}
function autoFinish(Onboarding $wizard,array $actor,array $w):array{for($i=0;$i<20&&in_array($w['status'],['pending','running'],true);$i++){$w=$wizard->advance($actor,$w['id']);}return $w;}
function autoSource(App $app,string $name,string $version):void{
 $root=$app->config['root'].'/modules/'.$name;Files::mkdir($root);
 $sql='CREATE TABLE IF NOT EXISTS `\'.db_prefix().\''.$name.'_data` (id INT PRIMARY KEY,value VARCHAR(100),activations INT NOT NULL DEFAULT 0) ENGINE=InnoDB';
 Files::write($root.'/'.$name.'.php',"<?php\n/*\nModule Name: ".$name."\nVersion: ".$version."\n*/\nregister_activation_hook('".$name."',function(){\nget_instance()->db->query('".$sql."');\nget_instance()->db->query(\"INSERT IGNORE INTO `\".db_prefix().\"".$name."_data` VALUES(1,'ORIGINAL',0)\");\nget_instance()->db->query(\"UPDATE `\".db_prefix().\"".$name."_data` SET activations=activations+1 WHERE id=1\");\n});\n");
 Files::write($root.'/multiempresa.json',json_encode(['tables'=>[$name.'_data',$name.'_extra']],JSON_THROW_ON_ERROR));
}
function autoMigration(App $app,string $name,int $version,string $body):void{
 Files::write($app->config['root'].'/modules/'.$name.'/migrations/'.$version.'_version_'.$version.'.php',"<?php class Migration_Version_".$version." extends App_module_migration { public function up(){ ".$body." } }\n");
}
function autoConnection(App $app,string $id):array{$c=$app->catalog->company($id);return [$c,$app->storage($c['mode'])->connect($app->vault->open($c['secrets'])['connection'])];}
foreach(['auto_one','auto_two','auto_new','auto_unused'] as $name){autoSource($app,$name,'1.0.0');}
$global=new GlobalSettings($app);$original=$global->get();
$global->save($actor,['database'=>['operation'=>'automatic','isolation'=>'database','host'=>getenv('MULTIEMPRESA_TEST_MYSQL_HOST'),'port'=>(int)getenv('MULTIEMPRESA_TEST_MYSQL_PORT'),'username'=>getenv('MULTIEMPRESA_TEST_USER'),'password'=>getenv('MULTIEMPRESA_TEST_PASSWORD'),'database_pattern'=>getenv('MULTIEMPRESA_TEST_DATABASE').'_auto_{company}','user_host'=>'localhost'],'dns'=>['enabled'=>false,'manual_confirmed'=>false]]);
$wizard=new Onboarding($app);$fields=['organization_id'=>$org,'language'=>'english','timezone'=>'UTC','admin_mode'=>'current','modules'=>['auto_one','auto_two','auto_new'],'activate_modules'=>['auto_one','auto_two'],'modules_reviewed'=>true];
$a=autoFinish($wizard,$actor,$wizard->begin($actor,array_replace($fields,['request_key'=>bin2hex(random_bytes(16)),'name'=>'Atualização exclusiva','slug'=>'update-exclusive'])));autoCheck($a['status']==='done','exclusive onboarding completes');
$global->save($actor,['database'=>['isolation'=>'prefix','shared_database'=>getenv('MULTIEMPRESA_TEST_DATABASE').'_beta']]);
$b=autoFinish($wizard,$actor,$wizard->begin($actor,array_replace($fields,['request_key'=>bin2hex(random_bytes(16)),'name'=>'Atualização prefixo','slug'=>'update-prefix','prefix'=>'upd_'])));autoCheck($b['status']==='done','shared onboarding completes');
$aid=$a['company_id'];$bid=$b['company_id'];[$ac,$ap]=autoConnection($app,$aid);[$bc,$bp]=autoConnection($app,$bid);
(new Modules($app))->disable($aid,'auto_two');$app->db->exec("UPDATE pme_companies SET status='suspended' WHERE id=?",[$bid]);
$before=$app->db->all('SELECT * FROM pme_onboardings WHERE company_id IN (?,?) ORDER BY id',[$aid,$bid]);
foreach(['auto_one','auto_two'] as $name){
 autoSource($app,$name,'1.0.2');
 autoMigration($app,$name,101,"get_instance()->db->query('ALTER TABLE `'.db_prefix().'".$name."_data` ADD note VARCHAR(50) NULL');get_instance()->db->query(\"INSERT INTO `\".db_prefix().\"".$name."_data` VALUES(2,'MIGRATION101',0,NULL)\");");
 autoMigration($app,$name,102,"get_instance()->db->query('CREATE TABLE `'.db_prefix().'".$name."_extra` (id INT PRIMARY KEY) ENGINE=InnoDB');get_instance()->db->query(\"INSERT INTO `\".db_prefix().\"".$name."_data` VALUES(3,'MIGRATION102',0,NULL)\");");
}
autoSource($app,'auto_new','1.0.1');autoMigration($app,'auto_new',101,"throw new RuntimeException('An available, never installed package must not migrate');");
autoSource($app,'auto_unused','1.0.1');
$maintenance=new Maintenance($app);$maintenance->ensure($aid,true);$maintenance->ensure($bid,true);
foreach([[$aid,$ap,''],[$bid,$bp,'upd_']] as [$id,$pdo,$prefix]){
 foreach(['auto_one','auto_two'] as $name){
  $v=$pdo->query("SELECT installed_version FROM `".$prefix."modules` WHERE module_name='".$name."'")->fetchColumn();autoCheck($v==='1.0.2','native migrations reach target '.$prefix.$name.' ('.$v.')');
  autoCheck((int)$pdo->query('SELECT COUNT(*) FROM `'.$prefix.$name.'_data`')->fetchColumn()===3,'each pending migration executes once '.$prefix.$name);
  autoCheck((int)$pdo->query('SELECT activations FROM `'.$prefix.$name.'_data` WHERE id=1')->fetchColumn()===1,'upgrade never reruns activation hook '.$prefix.$name);
 }
 autoCheck((int)$pdo->query("SELECT COUNT(*) FROM `".$prefix."modules` WHERE module_name='auto_new'")->fetchColumn()===0,'never activated package remains uninstalled '.$prefix);
}
autoCheck((int)$ap->query("SELECT active FROM modules WHERE module_name='auto_two'")->fetchColumn()===0,'inactive installed module remains inactive after migrations');
autoCheck($app->catalog->company($bid)['status']==='suspended','suspended company remains suspended');
autoCheck($app->db->all('SELECT * FROM pme_onboardings WHERE company_id IN (?,?) ORDER BY id',[$aid,$bid])===$before,'no onboarding row is created, reopened or rewritten by updates');
autoCheck(!$app->db->one("SELECT name FROM pme_modules WHERE name='auto_unused'"),'unassigned package is never provisioned to a company');
autoCheck(!$app->db->one("SELECT name FROM pme_modules WHERE company_id=? AND name='auto_one'",[$app->catalog->bySlug('grupo','alfa')['id']]),'another company receives no implicit module assignment');
$backups=count(glob($app->state('backups/'.$aid.'/*')));$maintenance->ensure($aid,true);autoCheck(count(glob($app->state('backups/'.$aid.'/*')))===$backups,'unchanged packages create no new backup or migration');
// A real partial SQL migration must be rolled back before the old site is reopened.
$ap->exec("UPDATE auto_one_data SET value='CLIENT_VALUE' WHERE id=1");$allow=$app->state('tests/allow-module-update');
autoSource($app,'auto_one','1.0.3');autoMigration($app,'auto_one',103,"get_instance()->db->query(\"UPDATE `\".db_prefix().\"auto_one_data` SET value='BROKEN' WHERE id=1\");get_instance()->db->query('ALTER TABLE `'.db_prefix().'auto_one_data` ADD partial_update INT NULL');if(!is_file(".var_export($allow,true).")){throw new RuntimeException('Forced migration failure');}get_instance()->db->query(\"UPDATE `\".db_prefix().\"auto_one_data` SET value='RECOVERED' WHERE id=1\");");
$maintenance->ensure($aid,true);
autoCheck($app->catalog->company($aid)['status']==='active','failed migration restores original company availability');
autoCheck($ap->query("SELECT installed_version FROM modules WHERE module_name='auto_one'")->fetchColumn()==='1.0.2','failed migration restores the previous installed version');
autoCheck($ap->query('SELECT value FROM auto_one_data WHERE id=1')->fetchColumn()==='CLIENT_VALUE','automatic rollback preserves original business data');
autoCheck((int)$ap->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='auto_one_data' AND COLUMN_NAME='partial_update'")->fetchColumn()===0,'automatic rollback removes partial DDL');
autoCheck($ap->query("SELECT installed_version FROM modules WHERE module_name='auto_two'")->fetchColumn()==='1.0.2','completed updates of other modules are preserved');
$key=Maintenance::key($aid,'auto_one');$state=$app->vault->open($app->db->one('SELECT value FROM pme_settings WHERE name=?',[$key])['value']);autoCheck($state['phase']==='failed'&&$state['retry_after']>=time(),'failed update records an automatic retry instead of a new onboarding');
Files::write($allow,'allow');$maintenance->ensure($aid,true);
autoCheck($ap->query("SELECT installed_version FROM modules WHERE module_name='auto_one'")->fetchColumn()==='1.0.3'&&$ap->query('SELECT value FROM auto_one_data WHERE id=1')->fetchColumn()==='RECOVERED','automatic retry completes the pending migration');
autoCheck((int)$ap->query('SELECT COUNT(*) FROM auto_one_data')->fetchColumn()===3,'retry never repeats previously completed migrations');
autoCheck(!$app->db->one('SELECT name FROM pme_settings WHERE name=?',[$key]),'successful retry clears its pending state');
// Simulate a web interruption after the worker committed/applied SQL but before publication.
$release=$app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1');$fingerprint=Files::treeHash($app->config['root'].'/modules/auto_one');
$state=['company_id'=>$aid,'module'=>'auto_one','fingerprint'=>$fingerprint,'version'=>'1.0.3','release'=>$release,'status'=>'active','active'=>1,'installed'=>true,'phase'=>'applied','backup'=>null,'before'=>(new \Multiempresa\Perfex\ModuleExtensions($app))->snapshot($aid,$release)];
$app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)',[$key,$app->vault->seal($state)]);$app->db->exec("UPDATE pme_companies SET status='maintenance',error_code='module_updating' WHERE id=?",[$aid]);$app->db->exec('UPDATE pme_modules SET fingerprint=? WHERE company_id=? AND name=?',[str_repeat('0',64),$aid,'auto_one']);
$backups=count(glob($app->state('backups/'.$aid.'/*')));$maintenance->ensure($aid,true);
autoCheck($app->catalog->company($aid)['status']==='active'&&!$app->db->one('SELECT name FROM pme_settings WHERE name=?',[$key]),'interrupted publication finalizes an already applied worker result');
autoCheck(count(glob($app->state('backups/'.$aid.'/*')))===$backups&&(int)$ap->query('SELECT COUNT(*) FROM auto_one_data')->fetchColumn()===3,'finalization never repeats migrations or creates a replacement backup');
// Recover an interrupted SQL worker before considering a later core update.
$app->db->exec("UPDATE pme_companies SET status='maintenance',error_code='module_updating' WHERE id=?",[$aid]);
$snapshot=(new \Multiempresa\Application\Backups($app))->create($aid);$state['phase']='migrating';$state['backup']=$snapshot;$state['checksum']=hash_file('sha256',$snapshot);$state['reviews']=$app->db->all('SELECT * FROM pme_module_reviews WHERE company_id=? AND name=?',[$aid,'auto_one']);
$app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)',[$key,$app->vault->seal($state)]);
$ap->exec("UPDATE auto_one_data SET value='INTERRUPTED' WHERE id=1");$ap->exec('ALTER TABLE auto_one_data ADD crash_partial INT NULL');
(new \Multiempresa\Application\CoreMaintenance($app))->ensure($aid);
autoCheck($app->catalog->company($aid)['status']==='active'&&$ap->query('SELECT value FROM auto_one_data WHERE id=1')->fetchColumn()==='RECOVERED','interrupted worker restores the prior data before core maintenance');
autoCheck((int)$ap->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='auto_one_data' AND COLUMN_NAME='crash_partial'")->fetchColumn()===0,'interrupted worker recovery removes partial DDL');
$maintenance->ensure($aid,true);autoCheck(!$app->db->one('SELECT name FROM pme_settings WHERE name=?',[$key])&&(int)$ap->query('SELECT activations FROM auto_one_data WHERE id=1')->fetchColumn()===1,'recovered worker retries without reinstalling the module');
// Code-only versions do not need an artificial SQL migration and stay disabled.
autoSource($app,'auto_two','1.0.3');$maintenance->ensure($aid,true);
autoCheck($ap->query("SELECT installed_version FROM modules WHERE module_name='auto_two'")->fetchColumn()==='1.0.3'&&(int)$ap->query("SELECT active FROM modules WHERE module_name='auto_two'")->fetchColumn()===0,'code-only update advances native version and preserves disabled state');
Files::write($app->config['root'].'/modules/auto_two/new-asset.txt','same-version update');$previous=$app->db->one('SELECT fingerprint FROM pme_modules WHERE company_id=? AND name=?',[$aid,'auto_two'])['fingerprint'];$maintenance->ensure($aid,true);
autoCheck($app->db->one('SELECT fingerprint FROM pme_modules WHERE company_id=? AND name=?',[$aid,'auto_two'])['fingerprint']!==$previous,'same-version file replacements update the private copy');
autoCheck((int)$ap->query('SELECT activations FROM auto_two_data WHERE id=1')->fetchColumn()===1,'code-only changes never invoke activation hooks');
// A corrected package resumes the original failed first activation without another registration.
autoSource($app,'auto_late','1.0.0');$latePath=$app->config['root'].'/modules/auto_late/auto_late.php';Files::write($latePath,file_get_contents($latePath)."\nregister_activation_hook('auto_late',function(){throw new RuntimeException('Initial source bug');});\n");
$global->save($actor,['database'=>['isolation'=>'database']]);$late=autoFinish($wizard,$actor,$wizard->begin($actor,array_replace($fields,['request_key'=>bin2hex(random_bytes(16)),'name'=>'Retomada automática','slug'=>'update-late','modules'=>['auto_late'],'activate_modules'=>['auto_late']])));autoCheck($late['status']==='failed','first activation source error is reproduced');
$lateId=$late['id'];$done=array_values(array_filter($late['steps'],fn($s)=>$s['status']==='done'));autoSource($app,'auto_late','1.0.1');$late=autoFinish($wizard,$actor,$wizard->advance($actor,$lateId));
autoCheck($late['status']==='done'&&$late['id']===$lateId,'corrected package resumes the same failed execution automatically: '.json_encode($late['diagnosis']??[]));
autoCheck(array_slice(array_values(array_filter($late['steps'],fn($s)=>$s['status']==='done')),0,count($done))===$done,'automatic onboarding resumption preserves completed steps');
// Invalid migration sequences leave the working version available until the package is corrected.
autoSource($app,'auto_two','1.0.5');autoMigration($app,'auto_two',105,"get_instance()->db->query(\"INSERT INTO `\".db_prefix().\"auto_two_data` VALUES(5,'MIGRATION105',0,NULL)\");");
$maintenance->ensure($aid,true);autoCheck($ap->query("SELECT installed_version FROM modules WHERE module_name='auto_two'")->fetchColumn()==='1.0.3'&&$app->catalog->company($aid)['status']==='active','migration gap keeps the previous version and company available');
autoMigration($app,'auto_two',104,"get_instance()->db->query(\"INSERT INTO `\".db_prefix().\"auto_two_data` VALUES(4,'MIGRATION104',0,NULL)\");");$maintenance->ensure($aid,true);
autoCheck($ap->query("SELECT installed_version FROM modules WHERE module_name='auto_two'")->fetchColumn()==='1.0.5'&&(int)$ap->query('SELECT COUNT(*) FROM auto_two_data')->fetchColumn()===5,'corrected migration sequence is applied automatically');
autoSource($app,'auto_two','1.0.2');$maintenance->ensure($aid,true);autoCheck($ap->query("SELECT installed_version FROM modules WHERE module_name='auto_two'")->fetchColumn()==='1.0.5','automatic update never runs a downgrade');autoSource($app,'auto_two','1.0.5');
foreach($app->db->all("SELECT id FROM pme_companies WHERE status='active'") as $row){$app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['cron:'.$row['id'],(string)time()]);}
(new \Multiempresa\Application\Scheduler($app))->tick();
autoCheck($bp->query("SELECT installed_version FROM upd_modules WHERE module_name='auto_one'")->fetchColumn()==='1.0.3','native scheduler updates an idle suspended company');
autoCheck($app->catalog->company($bid)['status']==='suspended'&&(int)$bp->query("SELECT active FROM upd_modules WHERE module_name='auto_two'")->fetchColumn()===1,'background update preserves company and module states');
// Physical deletion also removes the internal module-maintenance state and scan cursor.
$lateCompany=$late['company_id'];$lateKey=Maintenance::key($lateCompany,'auto_late');
$app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)',[$lateKey,$app->vault->seal(['phase'=>'failed','company_id'=>$lateCompany])]);
$app->db->exec("INSERT INTO pme_settings(name,value) VALUES('modules-scan:cursor',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[$lateCompany]);
$impact=new \Multiempresa\Application\ImpactPlans($app);$plan=$impact->create($actor,'delete_company',$lateCompany,['delete_data'=>true,'delete_files'=>true,'delete_backups'=>true]);$impact->confirm($actor,$plan['plan_id'],$plan['confirmation_name'],true);
autoCheck(!$app->db->one('SELECT name FROM pme_settings WHERE name=?',[$lateKey]),'physical company deletion removes its pending module-update state');
autoCheck(!$app->db->one("SELECT name FROM pme_settings WHERE name='modules-scan:cursor' AND value=?",[$lateCompany]),'physical deletion removes the deleted company from the scan cursor');
// The next real HTTP access must update both an old core schema and a new module package.
$ap->exec('ALTER TABLE user_auto_login MODIFY key_id VARCHAR(40) NOT NULL');$ap->exec("DELETE FROM options WHERE name IN ('proposal_auto_convert_leads_to_client_on_client_accept','einvoice_send_as_credit_note_email_attachment')");$ap->exec('UPDATE migrations SET version=340');$app->db->exec('UPDATE pme_companies SET schema_version=340 WHERE id=?',[$aid]);
autoSource($app,'auto_one','1.0.4');autoMigration($app,'auto_one',104,"get_instance()->db->query(\"INSERT INTO `\".db_prefix().\"auto_one_data` VALUES(4,'HTTP_UPDATE',0,NULL,NULL)\");");
$global->save($actor,$original);Files::write(dirname(getenv('MULTIEMPRESA_HOST')).'/auto-companies.json',json_encode(['exclusive'=>$aid,'shared'=>$bid],JSON_THROW_ON_ERROR));
echo "RESULT $n automatic module update assertions passed\n";
