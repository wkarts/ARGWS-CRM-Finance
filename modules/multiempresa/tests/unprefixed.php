<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,Provisioner,Backups,Recovery,Modules,ImpactPlans};
use Multiempresa\Domain\Failure;
$app=App::environment();$actor=$app->auth->syncNative(1);$org=$app->catalog->bySlug('grupo','alfa')['organization_id'];$n=0;
function bareTest(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException('FAIL '.$label);}$n++;echo 'PASS unprefixed '.$label."\n";}
$run=bin2hex(random_bytes(3));$email='bare-'.$run.'@example.test';
$connection=['host'=>getenv('MULTIEMPRESA_TEST_MYSQL_HOST'),'port'=>(int)getenv('MULTIEMPRESA_TEST_MYSQL_PORT'),'database'=>getenv('MULTIEMPRESA_TEST_DATABASE').'_bare'.$run,'username'=>getenv('MULTIEMPRESA_TEST_USER'),'password'=>getenv('MULTIEMPRESA_TEST_PASSWORD')];
$app->db->pdo->exec('CREATE DATABASE `'.$connection['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$input=['organization_id'=>$org,'name'=>'Sem prefixo','slug'=>'sem-prefixo-'.$run,'mode'=>'database','prefix'=>'ignored_','connection'=>$connection,'admin_mode'=>'new','admin_name'=>'Admin isolado','admin_email'=>$email,'admin_password'=>'Bare-private-password-26'];
$id=(new Provisioner($app))->create($actor,$input);$c=$app->catalog->company($id);$pdo=$app->storage('database')->connect($connection);
bareTest($c['table_prefix']==='', 'direct provisioning ignores a prefix for a new exclusive database');
$tables=$app->storage('database')->tables($pdo,'');
bareTest(in_array('staff',$tables,true)&&in_array('clients',$tables,true)&&!array_filter($tables,fn($t)=>str_starts_with($t,'ignored_')||str_starts_with($t,'tbl')||str_starts_with($t,'core_')), 'physical core tables have bare names');
bareTest($pdo->query('SELECT email FROM staff')->fetchColumn()===$email,'initial administrator uses unprefixed staff table');
try{$app->storage('prefix')->tables($pdo,'');throw new RuntimeException('Expected invalid_identifier');}catch(Failure $e){bareTest($e->reason==='invalid_identifier','shared storage cannot enumerate a whole database with an empty prefix');}
(new Modules($app))->approve($id,'isolation_probe',true);
$pdo->exec('INSERT INTO probe(id) VALUES(125)');
(new Modules($app))->disable($id,'isolation_probe');
bareTest((int)$pdo->query("SELECT active FROM modules WHERE module_name='isolation_probe'")->fetchColumn()===0&&(int)$pdo->query('SELECT id FROM probe')->fetchColumn()===125,'module deactivation preserves unprefixed data');
(new Modules($app))->approve($id,'isolation_probe',true);
bareTest((int)$pdo->query("SELECT active FROM modules WHERE module_name='isolation_probe'")->fetchColumn()===1,'module reactivation works without reprovisioning');
$pdo->exec('CREATE TABLE bare_parent(id INT PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE bare_child(id INT PRIMARY KEY,parent_id INT, label VARCHAR(100), FOREIGN KEY(parent_id) REFERENCES bare_parent(id)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO bare_parent VALUES(1)");$pdo->exec("INSERT INTO bare_child VALUES(2,1,'clients tblclients unchanged')");
$app->db->exec("UPDATE pme_companies SET status='suspended' WHERE id=?",[$id]);
$backup=(new Backups($app))->create($id);
bareTest(is_file($backup),'encrypted backup includes all unprefixed company tables');
foreach([''=>'_empty','rest_'=>'_named'] as $prefix=>$suffix){
 $target=$connection;$target['database'].=$suffix;$app->db->pdo->exec('CREATE DATABASE `'.$target['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 (new Recovery($app))->restore($id,$backup,$target,$prefix);
 $restored=$app->catalog->company($id);$db=$app->storage('database')->connect($target);
 bareTest($restored['table_prefix']===$prefix&&$restored['status']==='suspended','restore keeps declared destination prefix '.($prefix?:'(empty)'));
 bareTest($db->query('SELECT label FROM `'.$prefix.'bare_child`')->fetchColumn()==='clients tblclients unchanged','restore preserves column names and literal values');
 bareTest((int)$db->query('SELECT COUNT(*) FROM `'.$prefix.'bare_child` c JOIN `'.$prefix.'bare_parent` p ON p.id=c.parent_id')->fetchColumn()===1,'restore verifies foreign keys within destination');
}
// Compatibility: a previously prefixed exclusive backup can also be restored without a prefix.
$namedBackup=(new Backups($app))->create($id);$target=$connection;$target['database'].='_converted';$app->db->pdo->exec('CREATE DATABASE `'.$target['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
(new Recovery($app))->restore($id,$namedBackup,$target,'');$restoredPdo=$app->storage('database')->connect($target);
bareTest((int)$restoredPdo->query('SELECT id FROM probe')->fetchColumn()===125,'legacy prefixed backup restores into a new unprefixed database');
bareTest((int)$pdo->query('SELECT id FROM probe')->fetchColumn()===125,'restore never renames or overwrites source tables');
$impact=new ImpactPlans($app);
// Even forged metadata cannot turn an unprefixed scope into deletion of the primary installation.
$original=$app->catalog->company($id);$forged=$app->vault->open($original['secrets']);$forged['connection']=$connection;$forged['connection']['database']=getenv('MULTIEMPRESA_TEST_DATABASE');
$app->db->exec('UPDATE pme_companies SET secrets=? WHERE id=?',[$app->vault->seal($forged),$id]);
try {
 try {$impact->create($actor,'delete_company',$id,['delete_data'=>true]);throw new RuntimeException('Primary deletion scope was accepted');}
 catch(Failure $e){bareTest($e->reason==='primary_protected','empty prefix never permits deletion of the primary database');}
} finally {$app->db->exec('UPDATE pme_companies SET secrets=? WHERE id=?',[$original['secrets'],$id]);}
$plan=$impact->create($actor,'delete_company',$id,['delete_data'=>true,'delete_files'=>true,'delete_backups'=>true,'delete_dns'=>true]);
bareTest(in_array('clients',$plan['impact']['companies'][0]['tables'],true),'deletion plan inventories bare table names');
$impact->confirm($actor,$plan['plan_id'],$plan['confirmation_name'],true);
bareTest(!$app->db->one('SELECT id FROM pme_companies WHERE id=?',[$id])&&$restoredPdo->query('SHOW TABLES')->fetchAll()===[],'confirmed deletion removes unprefixed owned tables and registration');
bareTest((int)$pdo->query('SELECT id FROM probe')->fetchColumn()===125,'deletion preserves the original restore source database');
echo "RESULT $n unprefixed assertions passed\n";
