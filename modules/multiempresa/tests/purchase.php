<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,GlobalSettings,Onboarding,ImpactPlans,Modules,DatabaseProvisioning};
use Multiempresa\Infrastructure\Files;
use Multiempresa\Perfex\ModuleRuntime;
$app=App::environment();$actor=$app->auth->syncNative(1);$org=$app->catalog->bySlug('grupo','alfa')['organization_id'];$n=0;
function purchaseTest(bool $ok,string $message):void{global $n;if(!$ok){throw new RuntimeException('FAIL '.$message);}$n++;echo 'PASS purchase '.$message."\n";}
function purchaseFinish(Onboarding $wizard,array $actor,array $w):array{for($i=0;$i<15&&in_array($w['status'],['pending','running'],true);$i++){$w=$wizard->advance($actor,$w['id']);}return $w;}
$source=$app->config['root'].'/modules/purchase';$sourceHash=Files::treeHash($source);$installer=file_get_contents($source.'/install.php');
purchaseTest(substr_count($installer,'INSERT INTO `tblpurchase_option`')===10,'licensed purchase installer reproduces all ten fixed-prefix INSERT statements');
$global=new GlobalSettings($app);$originalSettings=$global->get();
$global->save($actor,['database'=>['operation'=>'automatic','isolation'=>'prefix','shared_database'=>getenv('MULTIEMPRESA_TEST_DATABASE').'_beta'],'dns'=>['enabled'=>false,'manual_confirmed'=>false]]);
$wizard=new Onboarding($app);$impact=new ImpactPlans($app);
$w=$wizard->begin($actor,['admin_mode'=>'current','request_key'=>bin2hex(random_bytes(20)),'organization_id'=>$org,'name'=>'Compras retomada','slug'=>'compras-retomada','prefix'=>'buy_','language'=>'english','timezone'=>'UTC','modules'=>['isolation_probe','purchase'],'activate_modules'=>['isolation_probe','purchase'],'modules_reviewed'=>true]);
for($i=0;$i<10&&in_array($w['status'],['pending','running'],true)&&($w['steps'][$w['step_index']]['name']??'')!=='purchase';$i++){$w=$wizard->advance($actor,$w['id']);}
purchaseTest($w['status']==='pending'&&($w['steps'][$w['step_index']]['name']??'')==='purchase','previous core and module steps finish before purchase activation');
$c=$app->catalog->company($w['company_id']);$pdo=$app->storage($c['mode'])->connect($app->vault->open($c['secrets'])['connection']);
$private=$app->state('companies/'.$c['id'].'/modules/purchase');$runtime=new ModuleRuntime($app);
$runtime->mount($source,$private,'buy_',$sourceHash);
// Reproduce the unadapted installer from 1.1.3 in an otherwise identified private runtime.
Files::write($private.'/install.php',$installer);$marker=$private.'/.pme-overlay.json';$state=json_decode(file_get_contents($marker),true,32,JSON_THROW_ON_ERROR);$state['files']['install.php']=hash('sha256',$installer);Files::write($marker,json_encode($state,JSON_THROW_ON_ERROR));
$w=$wizard->advance($actor,$w['id']);
purchaseTest($w['status']==='failed'&&(int)($w['diagnosis']['worker']['driver_code']??0)===1142&&($w['diagnosis']['worker']['trace'][3]['file']??null)==='install.php'&&(int)($w['diagnosis']['worker']['trace'][3]['line']??0)===339,'restricted shared-prefix user rejects the unadapted purchase INSERT at line 339');
purchaseTest((int)$pdo->query('SELECT COUNT(*) FROM buy_purchase_option')->fetchColumn()===0,'failed installer has already created the correctly prefixed partial table');
$pdo->exec("INSERT INTO buy_purchase_option(option_name,option_val,auto) VALUES ('pur_order_prefix','PRESERVED-PO',1),('tenant_manual_value','preserved business value',0)");
$pdo->exec('INSERT INTO buy_probe(id) VALUES(741)');
$completed=array_values(array_filter($w['steps'],fn($s)=>$s['status']==='done'));
$state['signature']['adapter']=3;Files::write($marker,json_encode($state,JSON_THROW_ON_ERROR));
purchaseTest(!ModuleRuntime::ready($private,'buy_',$sourceHash),'old private cache is recognized as requiring automatic rebuild');
$adminCfg=(new DatabaseProvisioning($app))->administrativeConnection($c);$admin=$app->storage('prefix')->connect($adminCfg);
$admin->exec('CREATE TABLE tblpurchase_option(option_id INT PRIMARY KEY,option_name VARCHAR(100),option_val TEXT,auto INT)');
$admin->exec("INSERT INTO tblpurchase_option VALUES(1,'other_owner','never change',1)");
$plan=$impact->create($actor,'retry',$c['id'],['modules'=>['isolation_probe','purchase'],'activate_modules'=>['isolation_probe','purchase'],'modules_reviewed'=>true]);$w=$impact->confirm($actor,$plan['plan_id'],$plan['confirmation_name'],true);
purchaseTest(array_values(array_filter($w['steps'],fn($s)=>$s['status']==='done'))===$completed,'retry retains the completed structure and module stages');
$w=purchaseFinish($wizard,$actor,$w);
purchaseTest($w['status']==='done','real purchase resumes to completion: '.json_encode($w['diagnosis']??[]));
purchaseTest((int)$pdo->query("SELECT active FROM buy_modules WHERE module_name='purchase'")->fetchColumn()===1,'native purchase activation is complete');
purchaseTest($pdo->query("SELECT option_val FROM buy_purchase_option WHERE option_name='pur_order_prefix'")->fetchColumn()==='PRESERVED-PO','resumption preserves existing purchase settings');
purchaseTest($pdo->query("SELECT option_val FROM buy_purchase_option WHERE option_name='tenant_manual_value'")->fetchColumn()==='preserved business value'&&(int)$pdo->query('SELECT id FROM buy_probe')->fetchColumn()===741,'partial company data and completed module data survive');
$expected=['purchase_order_setting','pur_order_prefix','next_po_number','date_reset_number','pur_request_prefix','next_pr_number','date_reset_pr_number','pur_inv_prefix','next_inv_number','create_invoice_by'];
$rows=$pdo->query('SELECT option_name,COUNT(*) AS n FROM buy_purchase_option GROUP BY option_name')->fetchAll(PDO::FETCH_KEY_PAIR);
purchaseTest(count(array_intersect($expected,array_keys($rows)))===10&&!array_filter($rows,fn($count)=>(int)$count>1),'all ten affected defaults exist exactly once after retry');
purchaseTest((int)$admin->query('SELECT COUNT(*) FROM tblpurchase_option')->fetchColumn()===1&&$admin->query('SELECT option_val FROM tblpurchase_option')->fetchColumn()==='never change','legacy table owned outside the company is untouched');
purchaseTest(ModuleRuntime::ready($private,'buy_',$sourceHash)&&!str_contains(file_get_contents($private.'/install.php'),'INSERT INTO `tblpurchase_option`'),'private runtime is rebuilt without changing source fingerprints');
foreach(['101','107','108'] as $version){purchaseTest(!str_contains(file_get_contents($private.'/migrations/'.$version.'_version_'.$version.'.php'),'INSERT INTO `tblpurchase_option`'),'native purchase migration '.$version.' uses the company prefix');}
$pdo->exec("DELETE FROM buy_purchase_option WHERE option_name='pur_order_prefix'");
$probe=$app->config['root'].'/modules/purchase_migration_probe';Files::mkdir($probe);
Files::write($probe.'/purchase_migration_probe.php', <<<'PHP'
<?php
/*
Module Name: Purchase migration regression
Version: 1.0.0
*/
register_activation_hook('purchase_migration_probe',function(){
    require module_dir_path('purchase','migrations/101_version_101.php');
    (new Migration_Version_101('purchase'))->up();
});
PHP);
(new Modules($app))->approve($c['id'],'purchase_migration_probe',true);
purchaseTest($pdo->query("SELECT option_val FROM buy_purchase_option WHERE option_name='pur_order_prefix'")->fetchColumn()==='#PO','actual purchase migration 101 executes its adapted SQL in native Perfex');
purchaseTest($admin->query('SELECT option_val FROM tblpurchase_option')->fetchColumn()==='never change','migration also leaves the unrelated legacy table untouched');
$admin->exec('DROP TABLE tblpurchase_option');
// Reproduce the exact 1146 with an exclusive database (shared-prefix grants reject it earlier with 1142).
$global->save($actor,['database'=>['operation'=>'automatic','isolation'=>'database']]);
$exclusive=$wizard->begin($actor,['admin_mode'=>'current','request_key'=>bin2hex(random_bytes(20)),'organization_id'=>$org,'name'=>'Compras exclusiva retomada','slug'=>'compras-exclusiva','prefix'=>'exclusive_','language'=>'english','timezone'=>'UTC','modules'=>['purchase'],'activate_modules'=>['purchase'],'modules_reviewed'=>true]);
for($i=0;$i<10&&in_array($exclusive['status'],['pending','running'],true)&&($exclusive['steps'][$exclusive['step_index']]['name']??'')!=='purchase';$i++){$exclusive=$wizard->advance($actor,$exclusive['id']);}
purchaseTest($exclusive['status']==='pending'&&($exclusive['steps'][$exclusive['step_index']]['name']??'')==='purchase','exclusive database reaches purchase with completed core stages');
$exclusivePath=$app->state('companies/'.$exclusive['company_id'].'/modules/purchase');$runtime->mount($source,$exclusivePath,'',$sourceHash);
Files::write($exclusivePath.'/install.php',$installer);$exclusiveMarker=$exclusivePath.'/.pme-overlay.json';$exclusiveState=json_decode(file_get_contents($exclusiveMarker),true,32,JSON_THROW_ON_ERROR);$exclusiveState['files']['install.php']=hash('sha256',$installer);Files::write($exclusiveMarker,json_encode($exclusiveState,JSON_THROW_ON_ERROR));
$exclusive=$wizard->advance($actor,$exclusive['id']);
purchaseTest($exclusive['status']==='failed'&&($exclusive['diagnosis']['worker']['missing_table']??null)==='tblpurchase_option'&&(int)($exclusive['diagnosis']['worker']['driver_code']??0)===1146,'exclusive database reproduces the exact reported MySQL 1146 on tblpurchase_option');
$exclusiveState['signature']['adapter']=3;Files::write($exclusiveMarker,json_encode($exclusiveState,JSON_THROW_ON_ERROR));
$plan=$impact->create($actor,'retry',$exclusive['company_id'],['modules'=>['purchase'],'activate_modules'=>['purchase'],'modules_reviewed'=>true]);$exclusive=$impact->confirm($actor,$plan['plan_id'],$plan['confirmation_name'],true);$exclusive=purchaseFinish($wizard,$actor,$exclusive);
purchaseTest($exclusive['status']==='done','reported exclusive-database failure recovers without recreating company or database');
$exclusiveCompany=$app->catalog->company($exclusive['company_id']);purchaseTest($exclusiveCompany['table_prefix']==='', 'exclusive core and real purchase module use no table prefix');$exclusiveConnection=$app->vault->open($exclusiveCompany['secrets'])['connection'];
$admin->exec('FLUSH PRIVILEGES');
$exclusivePdo=$app->storage('database')->connect($exclusiveConnection);
purchaseTest((int)$exclusivePdo->query('SELECT COUNT(*) FROM purchase_option')->fetchColumn()>=10,'automatic exclusive grants survive privilege-cache reload after retry');
purchaseTest(strlen(str_replace('_','\\_',$exclusiveConnection['database']))<=64,'generated database names fit the persisted escaped privilege scope');
// Also exercise a clean install on its first attempt.
$fresh=$wizard->begin($actor,['admin_mode'=>'current','request_key'=>bin2hex(random_bytes(20)),'organization_id'=>$org,'name'=>'Compras nova','slug'=>'compras-nova','prefix'=>'fresh_','language'=>'english','timezone'=>'UTC','modules'=>['purchase'],'activate_modules'=>['purchase'],'modules_reviewed'=>true]);
$fresh=purchaseFinish($wizard,$actor,$fresh);purchaseTest($fresh['status']==='done','fresh purchase provisions automatically in an exclusive database: '.json_encode($fresh['diagnosis']??[]));
$other=$app->catalog->company($fresh['company_id']);$otherDb=$app->storage($other['mode'])->connect($app->vault->open($other['secrets'])['connection']);
purchaseTest($otherDb->query("SELECT option_val FROM purchase_option WHERE option_name='pur_order_prefix'")->fetchColumn()==='#PO','exclusive company receives its own defaults');
purchaseTest((int)$otherDb->query("SELECT COUNT(*) FROM purchase_option WHERE option_name='tenant_manual_value'")->fetchColumn()===0,'business data is never copied between companies');
purchaseTest(Files::treeHash($source)===$sourceHash&&file_get_contents($source.'/install.php')===$installer,'shared purchase source and original migrations remain byte-identical');
$global->save($actor,$originalSettings);echo "RESULT $n purchase assertions passed\n";
