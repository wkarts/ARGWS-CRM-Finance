<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,Installation,Onboarding,ImpactPlans,Uninstallation,GlobalSettings,CompanyManager,Modules};
use Multiempresa\Infrastructure\Files;
use Multiempresa\Perfex\ModuleRuntime;
use Multiempresa\Domain\{Failure,Id};
$app=App::environment();$actor=$app->auth->syncNative(1);$org=$app->catalog->bySlug('grupo','alfa')['organization_id'];$n=0;
function maintenance(bool $value,string $label):void{global $n;if(!$value){throw new RuntimeException('FAIL '.$label);}$n++;echo 'PASS maintenance '.$label."\n";}
function denial(callable $fn,string $reason):void{try{$fn();}catch(Failure $e){maintenance($e->reason===$reason,$reason);return;}throw new RuntimeException('Expected '.$reason);}
$install=new Installation($app);$primary=$install->configure($actor,$org);$outsider=['id'=>Id::new(),'platform_admin'=>0];(new \Multiempresa\Application\StaffAccess($app))->savePreference($outsider['id'],['principal_access'=>0]);
maintenance($primary['protected']&&$primary['organization_id']===$org,'principal installation links to an organization without becoming a provisioned company');
maintenance(!$install->canAccess($outsider),'identity without primary authorization cannot access principal data');
denial(fn()=>$install->configure($outsider,$org),'platform_admin');
denial(fn()=>(new Uninstallation($app))->prepare($outsider,'remove'),'platform_admin');
$source="-- INSERT INTO tblitems\nINSERT INTO `tblitems` VALUES ('tblitems', 'JOIN tblitems'); UPDATE tblitems SET name='x'; /* FROM tblitems */";
$expected="-- INSERT INTO tblitems\nINSERT INTO `test_items` VALUES ('tblitems', 'JOIN tblitems'); UPDATE `test_items` SET name='x'; /* FROM tblitems */";
maintenance(ModuleRuntime::rewrite($source,['tblitems'=>'test_items'])===$expected,'SQL projection changes table identifiers without altering strings and comments');
$root=$app->config['root'];$ma=$root.'/modules/ma';$maHash=Files::treeHash($ma);$fleetHash=Files::treeHash($root.'/modules/fleet');$nativeHash=Files::treeHash(realpath($root.'/application/libraries'));$seed=file_get_contents($ma.'/database/email_template_default.sql');
maintenance(str_contains($seed,'`tblma_email_templates`'),'real reported marketing module contains its legacy table prefix');
$bad=$root.'/modules/zz_failure';Files::mkdir($bad);
Files::write($bad.'/zz_failure.php',"<?php\n/*\nModule Name: Failure fixture\nVersion: 1.0.0\n*/\nregister_activation_hook('zz_failure', function(){get_instance()->db->query('CREATE TABLE IF NOT EXISTS `'.db_prefix().'zz_partial` (id INT PRIMARY KEY) ENGINE=InnoDB');throw new RuntimeException('Fixture interruption');});\n");
$global=new GlobalSettings($app);$global->save($actor,['database'=>['operation'=>'automatic','isolation'=>'prefix','shared_database'=>getenv('MULTIEMPRESA_TEST_DATABASE').'_beta'],'dns'=>['enabled'=>false,'manual_confirmed'=>false]]);
$wizard=new Onboarding($app);$impact=new ImpactPlans($app);
$w=$wizard->begin($actor,['admin_mode'=>'current','request_key'=>bin2hex(random_bytes(20)),'organization_id'=>$org,'name'=>'Revisão de etapas','slug'=>'revisao','prefix'=>'review_','language'=>'english','timezone'=>'UTC','modules'=>['fleet','gerencianet_gateway','isolation_probe','ma','zz_failure'],'activate_modules'=>['fleet','gerencianet_gateway','isolation_probe','ma','zz_failure'],'modules_reviewed'=>true]);
for($i=0;$i<20&&in_array($w['status'],['pending','running'],true);$i++){$w=$wizard->advance($actor,$w['id']);}
maintenance($w['status']==='failed'&&$w['steps'][$w['step_index']]['name']==='zz_failure','real ma activation completes before the deliberate subsequent failure: '.json_encode($w['diagnosis']??[]));
$c=$app->catalog->company($w['company_id']);$pdo=$app->storage($c['mode'])->connect($app->vault->open($c['secrets'])['connection']);
maintenance((int)$pdo->query('SELECT COUNT(*) FROM review_ma_email_templates')->fetchColumn()>0,'real marketing SQL seed populates the company prefix');
maintenance(Files::treeHash($ma)===$maHash&&file_get_contents($ma.'/database/email_template_default.sql')===$seed,'original external module source and SQL remain byte-identical');
maintenance(Files::treeHash($root.'/modules/fleet')===$fleetHash&&Files::treeHash(realpath($root.'/application/libraries'))===$nativeHash,'fleet-generated files and gateway extension installation preserve central source');
$log=new \Multiempresa\Application\ExecutionLog($app);$e=new mysqli_sql_exception("Table 'db.tblmissing' doesn't exist",1146);$diagnostic=$log::exception($e);
maintenance(($diagnostic['missing_table']??null)==='tblmissing','missing table name is available without exposing raw SQL');
$p=$impact->create($actor,'retry',$c['id'],['name'=>'Empresa revisada','profile'=>['city'=>'Salvador'],'modules'=>['fleet','gerencianet_gateway','isolation_probe','ma'],'modules_reviewed'=>true]);
maintenance(in_array('deactivate_or_skip',array_column($p['impact']['modules'],'action'),true),'review explicitly identifies the failed module removed from selection');
$w=$impact->confirm($actor,$p['plan_id'],$p['confirmation_name'],true);
maintenance(count(array_filter($w['steps'],fn($s)=>$s['type']==='module'&&$s['status']==='done'))===4&&$w['completed']>=6,'retry preserves completed database structure and modules');
$changedFiles=[];foreach(['isolation_probe','ma'] as $name){$file=$root.'/modules/'.$name.'/review-change.txt';Files::write($file,'Deliberate source revision for retry regression');$changedFiles[]=$file;}
for($i=0;$i<6&&in_array($w['status'],['pending','running'],true);$i++){$w=$wizard->advance($actor,$w['id']);}maintenance($w['status']==='done','changed packages update automatically before the company is released');
maintenance(count(array_filter($w['steps'],fn($s)=>$s['type']==='module'&&$s['status']==='done'))===4,'automatic package refresh retains every completed module step');
for($i=0;$i<15&&in_array($w['status'],['pending','running'],true);$i++){$w=$wizard->advance($actor,$w['id']);}
maintenance($w['status']==='done'&&$w['completed']===$w['total'],'revised failed execution completes only after all remaining mandatory steps');
$d=(new CompanyManager($app))->detail($actor,$c['id']);maintenance($d['name']==='Empresa revisada'&&$d['profile']['city']==='Salvador','returning to registration reapplies revised fields');
maintenance((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='review_zz_partial'")->fetchColumn()===1,'skipping interrupted module preserves its partial tables');
(new Modules($app))->disable($c['id'],'gerencianet_gateway');
(new Modules($app))->disable($c['id'],'isolation_probe');maintenance((int)$pdo->query("SELECT active FROM review_modules WHERE module_name='isolation_probe'")->fetchColumn()===0,'individual deactivation preserves the company');
(new Modules($app))->approve($c['id'],'isolation_probe',true);maintenance((int)$pdo->query("SELECT active FROM review_modules WHERE module_name='isolation_probe'")->fetchColumn()===1,'individual reactivation remains available after recovery');
maintenance((int)$pdo->query("SELECT active FROM review_modules WHERE module_name='gerencianet_gateway'")->fetchColumn()===0,'deactivating legacy gateway removes its private native loader without breaking the next module');
$probeFile=$root.'/modules/isolation_probe/isolation_probe.php';$probeSource=file_get_contents($probeFile);
Files::write($probeFile,str_replace(['Version: 1.0.0','function(){'],['Version: 1.0.1',"function(){ throw new RuntimeException('Activation must not repeat on upgrade');"],$probeSource));
$probeMigration=$root.'/modules/isolation_probe/migrations/101_version_101.php';Files::write($probeMigration,"<?php class Migration_Version_101 extends App_module_migration { public function up() { get_instance()->db->query('CREATE TABLE IF NOT EXISTS `'.db_prefix().'probe_updated` (id INT PRIMARY KEY) ENGINE=InnoDB'); } }");
(new Modules($app))->approve($c['id'],'isolation_probe',true);
maintenance($pdo->query("SELECT installed_version FROM review_modules WHERE module_name='isolation_probe'")->fetchColumn()==='1.0.1','updating an active module runs its native migration without repeating activation');
$before=$app->db->all('SELECT id,name,active FROM pme_organizations ORDER BY id');$sibling=$app->catalog->bySlug('grupo','alfa');
$p=$impact->create($actor,'delete_company',$c['id'],['delete_data'=>true,'delete_files'=>true,'delete_backups'=>true]);
maintenance(count($p['impact']['companies'])===1&&$p['impact']['companies'][0]['id']===$c['id']&&str_contains(implode(' ',$p['impact']['warnings']),'organização e todas as outras empresas'),'company deletion review identifies only the selected company and preserves organization');
$impact->confirm($actor,$p['plan_id'],$p['confirmation_name'],true);
foreach($changedFiles as $file){unlink($file);}
Files::write($probeFile,$probeSource);unlink($probeMigration);
maintenance($app->db->all('SELECT id,name,active FROM pme_organizations ORDER BY id')===$before&&$app->catalog->company($sibling['id'])['status']===$sibling['status'],'provisioned company deletion leaves every organization and sibling company unchanged');
maintenance(!$app->db->one('SELECT id FROM pme_audit WHERE company_id=?',[$c['id']])&&!$app->db->one('SELECT id FROM pme_execution_logs WHERE company_id=? OR details LIKE ?',[$c['id'],'%'.$c['id'].'%']),'physical company deletion also removes scoped history and identifiers');
maintenance(!is_dir($app->state('backups/'.$c['id']))&&!is_dir($app->state('companies/'.$c['id'])),'confirmed company files and backups are removed');
$onlyOrg=$app->catalog->createOrganization($actor,'Organização mantida','mantida');$d=$wizard->begin($actor,['admin_mode'=>'current','request_key'=>bin2hex(random_bytes(20)),'organization_id'=>$onlyOrg,'name'=>'Única empresa','slug'=>'unica','language'=>'english','timezone'=>'UTC','save_only'=>true]);$p=$impact->create($actor,'delete_company',$d['company_id']);$impact->confirm($actor,$p['plan_id'],$p['confirmation_name'],true);
maintenance((int)$app->db->one('SELECT active FROM pme_organizations WHERE id=?',[$onlyOrg])['active']===1,'removing the last company does not remove or disable its organization');
maintenance(!$app->db->one('SELECT id FROM pme_companies WHERE id=?',[$d['company_id']]),'deleted company has no logical tombstone');
$p=$impact->create($actor,'delete_organization',$onlyOrg);$impact->confirm($actor,$p['plan_id'],$p['confirmation_name'],true);
maintenance(!$app->db->one('SELECT id FROM pme_organizations WHERE id=?',[$onlyOrg]),'organization is physically deleted');
$replacement=$app->catalog->createOrganization($actor,'Organização mantida','mantida');maintenance($replacement!==$onlyOrg,'deleted organization identifier can be reused for a new registration');
$legacyOrg=$app->catalog->createOrganization($actor,'Excluída na versão anterior','excluida-legada');$legacy=$wizard->begin($actor,['admin_mode'=>'current','request_key'=>bin2hex(random_bytes(20)),'organization_id'=>$legacyOrg,'name'=>'Empresa removida','slug'=>'removida','language'=>'english','timezone'=>'UTC','save_only'=>true]);
$app->db->exec("UPDATE pme_companies SET status='removed' WHERE id=?",[$legacy['company_id']]);$app->db->exec("INSERT INTO pme_organization_config(organization_id,data,status,updated_at) VALUES(?,?,'removed',?) ON DUPLICATE KEY UPDATE status='removed'",[$legacyOrg,$app->vault->seal([]),time()]);
(new \Multiempresa\Application\CatalogRemoval($app))->retireLegacyTombstones();
maintenance(!$app->db->one('SELECT id FROM pme_companies WHERE id=?',[$legacy['company_id']])&&!$app->db->one('SELECT id FROM pme_organizations WHERE id=?',[$legacyOrg]),'update removes legacy completed logical deletions');
maintenance($app->catalog->createOrganization($actor,'Novo cadastro','excluida-legada')!==$legacyOrg,'legacy deleted organization identifier is available after update');
$u=new Uninstallation($app);$keep=$u->prepare($actor,'keep');maintenance($keep['mode']==='keep'&&$keep['installation']['protected'],'uninstall preserve option inventories companies and protects principal');
denial(fn()=>$u->confirm($actor,$keep['id'],'Multiempresa',false),'confirmation');
maintenance((int)$app->db->pdo->query("SELECT active FROM core_modules WHERE module_name='multiempresa'")->fetchColumn()===1,'unconfirmed removal cannot deactivate the module');
$app->catalog->createOrganization($actor,'Inventário alterado','alterado');denial(fn()=>$u->confirm($actor,$keep['id'],'Multiempresa',true),'plan_changed');
// A forged catalog row still cannot authorize removing the principal's tables.
$forged=Id::new();$cn=['host'=>getenv('MULTIEMPRESA_TEST_MYSQL_HOST'),'port'=>(int)getenv('MULTIEMPRESA_TEST_MYSQL_PORT'),'database'=>getenv('MULTIEMPRESA_TEST_DATABASE'),'username'=>getenv('MULTIEMPRESA_TEST_USER'),'password'=>getenv('MULTIEMPRESA_TEST_PASSWORD')];
$app->db->exec('INSERT INTO pme_companies(id,organization_id,slug,name,mode,table_prefix,storage_identity,secrets,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$forged,$org,'forged','Forged principal','prefix','core_',hash('sha256','forged'),$app->vault->seal(['connection'=>$cn]),'draft',time()]);
try{denial(fn()=>$impact->create($actor,'delete_company',$forged,['delete_data'=>true]),'primary_protected');}finally{$app->db->exec('DELETE FROM pme_companies WHERE id=?',[$forged]);}
echo 'RESULT '.$n." maintenance assertions passed\n";
