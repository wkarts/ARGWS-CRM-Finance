<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require dirname(__DIR__).'/autoload.php';
use Multiempresa\Application\App;
use Multiempresa\Domain\{Id,Failure};
use Multiempresa\Infrastructure\Files;
use Multiempresa\Perfex\ModuleRuntime;
$n=0;
function runtimeTest(bool $ok,string $message):void{global $n;if(!$ok){throw new RuntimeException($message);}$n++;echo 'PASS module runtime '.$message."\n";}
$directory=sys_get_temp_dir().'/multiempresa-runtime-'.Id::new();Files::mkdir($directory);
$source= <<<'PHP'
<?php
// INSERT INTO tblpurchase_option must stay a comment.
/* UPDATE tblpurchase_option SET x='example'; */
return [
 'single'=>'INSERT INTO `tblpurchase_option` VALUES (\'tblpurchase_option\', \'JOIN tblpurchase_option\', \'C:\\temp\');',
 'double'=>"INSERT INTO tblpurchase_option VALUES ('\"quotes\"', '\$amount', '\n\r\t\v\e\f', '\8\9\q', '\x41\101', '\u{1F3E2}', '\0');",
 'binary'=>b'SELECT * FROM tblpurchase_option WHERE name=\'tblpurchase_option\'',
 'fragment'=>'SELECT * FROM tblpurchase_option WHERE name = \'' . 'tblpurchase_option' . '\'',
 'value'=>'SELECT \'INSERT INTO tblpurchase_option\', \'FROM tblpurchase_option\' FROM tblpurchase_option',
 'unmapped'=>'SELECT * FROM tblunknown',
 'plain'=>'The table tblpurchase_option is mentioned here.',
 'identifier'=>'tblpurchase_option',
 'comment'=>"/* FROM tblpurchase_option */\n SELECT * FROM tblpurchase_option",
 'qualified'=>'SELECT * FROM central.tblpurchase_option',
 'dynamic'=>'SELECT * FROM ' . 'tenant_purchase_option',
 'interpolated'=>"SELECT * FROM {$GLOBALS['runtime_test_table']}",
];
PHP;
$map=['tblpurchase_option'=>'tenant_purchase_option'];$GLOBALS['runtime_test_table']='tenant_purchase_option';
Files::write($directory.'/before.php',$source);$before=require $directory.'/before.php';
$adapted=ModuleRuntime::phpSql($source,$map);Files::write($directory.'/after.php',$adapted);$after=require $directory.'/after.php';
foreach(['single','double','binary','fragment','value','comment'] as $key){
    $expected=ModuleRuntime::rewrite($before[$key],$map);
    runtimeTest($after[$key]===$expected,'PHP literal semantics and scoped SQL: '.$key);
}
foreach(['unmapped','plain','identifier','qualified','dynamic','interpolated'] as $key){runtimeTest($after[$key]===$before[$key],'preserves unrelated PHP content: '.$key);}
runtimeTest(str_contains($adapted,'// INSERT INTO tblpurchase_option must stay a comment.')&&str_contains($adapted,"/* UPDATE tblpurchase_option SET x='example'; */"),'PHP comments remain byte-identical');
runtimeTest(str_contains($after['value'],"'INSERT INTO tblpurchase_option'")&&str_contains($after['single'],"'JOIN tblpurchase_option'"),'SQL payload text is not rewritten');
runtimeTest(ModuleRuntime::phpSql($adapted,$map)===$adapted,'repeated adaptation is idempotent');
try{ModuleRuntime::rewrite("INSERT INTO tblpurchase_option VALUES ('unfinished",$map);throw new RuntimeException('Expected invalid SQL');}catch(Failure $e){runtimeTest($e->reason==='module_sql_string','standalone SQL retains strict validation');}
$app=new App(['central'=>['dsn'=>'sqlite::memory:','table_prefix'=>'core_multiempresa_'],'native_prefix'=>'core_','master_key'=>base64_encode(random_bytes(32)),'state_dir'=>$directory.'/state','base_url'=>'https://crm.example.test/tenant.php','native_url'=>'https://crm.example.test/']);
$root=$directory.'/source/purchase';Files::mkdir($root);
Files::write($root.'/purchase.php',"<?php /* fixture */ function runtime_test_declared_table(){return db_prefix().'purchase_option';}");
Files::write($root.'/install.php',$source);Files::write($root.'/generated.php','<?php return "distributed";');
$hash=Files::treeHash($root);$target=$directory.'/private/purchase';$runtime=new ModuleRuntime($app);
$runtime->mount($root,$target,'tenant_',$hash);
runtimeTest(file_get_contents($target.'/install.php')===$adapted,'mount adapts PHP using only known module tables');
$marker=$target.'/.pme-overlay.json';$state=json_decode(file_get_contents($marker),true,32,JSON_THROW_ON_ERROR);
Files::write($target.'/install.php',$source);$state['signature']['adapter']=3;$state['files']['install.php']=hash('sha256',$source);Files::write($marker,json_encode($state,JSON_THROW_ON_ERROR));
Files::write($target.'/generated.php','<?php return "company generated";');
runtimeTest(!ModuleRuntime::ready($target,'tenant_',$hash),'old private adapter is invalidated even with identical source hash');
$runtime->mount($root,$target,'tenant_',$hash);
runtimeTest(ModuleRuntime::ready($target,'tenant_',$hash)&&file_get_contents($target.'/install.php')===$adapted,'upgrade rebuilds the old private installer');
runtimeTest(str_contains(file_get_contents($target.'/generated.php'),'company generated'),'upgrade preserves generated state of unchanged files');
runtimeTest(Files::treeHash($root)===$hash&&file_get_contents($root.'/install.php')===$source,'shared package source remains byte-identical');
ModuleRuntime::remove($directory);echo "RESULT $n module runtime assertions passed\n";
