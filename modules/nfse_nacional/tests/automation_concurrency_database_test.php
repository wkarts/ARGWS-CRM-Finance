<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
if (!getenv('NFSE_TEST_MYSQL_PORT')) { echo "PENDENTE: concorrência requer MariaDB local descartável.\n"; exit(2); }
define('BASEPATH', __DIR__);define('APP_ENC_KEY',str_repeat('concurrency-fixture-key',3));
require dirname(__DIR__).'/autoload.php';require __DIR__.'/fixtures/PdoCrm.php';
function &get_instance(){return $GLOBALS['ci'];}
function db_prefix(){return 'cc_';}
function nfse_nacional_scope(){return hash('sha256','database-concurrency-test');}
function get_staff_user_id(){return 7;}
class App_Model {public $db; public function __construct(){$this->db=get_instance()->db;}}
require dirname(__DIR__).'/models/Nfse_nacional_model.php';
class CcModel extends Nfse_nacional_model {
 public function table(string $suffix):string{return 'cc_'.$suffix;}
 public function settings():array{return json_decode($this->db->query('SELECT settings_json FROM cc_config')->row_array()['settings_json'],true);}
 public function invoiceRecipientSelection(int $id,int $client):string{return '';}
 public function prepare(int $id,?string $selection=null):int{
  $doc=$this->db->where('invoice_id',$id)->get('cc_documents')->row_array();if($doc)return (int)$doc['id'];
  $ok=$this->db->insert('cc_documents',['invoice_id'=>$id,'environment'=>'2','status'=>'draft','attempts'=>0]);
  if(!$ok)throw new RuntimeException('Cannot create fixture document');return $this->db->insert_id();
 }
}
class CcService {
 public function __construct(private $m){}
 public function transmit($id){
  $row=$this->m->db->where('id',$id)->get('cc_documents')->row_array();
  if($row['status']==='authorized')return ['status'=>'authorized'];
  $this->m->db->insert('cc_calls',['document_id'=>$id]);usleep(100000);
  $this->m->db->where('id',$id)->update('cc_documents',['status'=>'authorized','attempts'=>1]);return ['status'=>'authorized'];
 }
 public function reconcile($id){return ['status'=>'uncertain'];}
}
function ccSetup():CcModel{
 $db=PdoCrm::connect('nfse_test_concurrency');
 $GLOBALS['ci']=(object)['db'=>$db,'load'=>new class{function model($name){}},'invoices_model'=>new class{
  function get($id){$row=get_instance()->db->where('id',$id)->get('cc_invoices')->row_array();if(!$row)return null;$row['items']=[];return (object)$row;}
 }];
 return new CcModel();
}
$m=ccSetup();$db=$m->db;
foreach($db->query('SHOW TABLES')->result_array() as $row){$db->query('DROP TABLE `'.array_values($row)[0].'`');}
foreach(NfseNacional\Automation::schema()as $name=>$ddl){if(!$db->query('CREATE TABLE `cc_'.$name.'` ('.$ddl.') ENGINE=InnoDB'))throw new RuntimeException('DDL');}
foreach([
 'config'=>'settings_json LONGTEXT NOT NULL',
 'staff'=>'staffid INT PRIMARY KEY, active INT, admin INT',
 'profiles'=>'client_id INT PRIMARY KEY,profile_json TEXT',
 'invoices'=>'id INT PRIMARY KEY,clientid INT,date DATE,datecreated DATETIME,currency INT,subtotal DECIMAL(12,2),discount_total DECIMAL(12,2),total DECIMAL(12,2),status INT',
 'documents'=>"id BIGINT AUTO_INCREMENT PRIMARY KEY,invoice_id INT UNIQUE,environment CHAR(1),status VARCHAR(20),attempts INT",
 'calls'=>'id INT AUTO_INCREMENT PRIMARY KEY,document_id BIGINT'
]as $name=>$ddl){if(!$db->query('CREATE TABLE `cc_'.$name.'` ('.$ddl.') ENGINE=InnoDB'))throw new RuntimeException('DDL');}
$settings=NfseNacional\Config::defaults();$settings['provider']['tax_id']='11222333000181';
foreach(['created','paid']as $type){$settings['automations'][$type]=['mode'=>'all','staff_id'=>'7','delay_minutes'=>'0'];}
$db->insert('cc_config',['settings_json'=>json_encode($settings)]);$db->insert('cc_staff',['staffid'=>7,'active'=>1,'admin'=>1]);
$db->insert('cc_invoices',['id'=>41,'clientid'=>9,'date'=>'2026-11-01','datecreated'=>'2026-11-01 12:00:00','currency'=>1,'subtotal'=>'100.00','discount_total'=>'0.00','total'=>'100.00','status'=>1]);
$a=new NfseNacional\Automation($m,new CcService($m));$a->enqueue(41,'created');$a->enqueue(41,'paid');
if($db->count_all_results('cc_automation_jobs')!==1)throw new RuntimeException('Partial invoice must not schedule paid rule');
$db->where('id',41)->update('cc_invoices',['status'=>2]);$a->enqueue(41,'paid');$a->enqueue(41,'created');
if($db->count_all_results('cc_automation_jobs')!==2)throw new RuntimeException('Independent idempotent rules');
// Separate workers and separate PDO connections compete for the real MySQL locks.
$pids=[];
for($i=0;$i<3;$i++){
 $pid=pcntl_fork();if($pid===0){$child=ccSetup();(new NfseNacional\Automation($child,new CcService($child)))->run();exit(0);}$pids[]=$pid;
}
foreach($pids as $pid){pcntl_waitpid($pid,$status);if(pcntl_wexitstatus($status)!==0)throw new RuntimeException('Worker failure');}
$m=ccSetup();$db=$m->db;
if($db->count_all_results('cc_calls')!==1)throw new RuntimeException('Exactly one external transmission under concurrent rules/workers');
$states=array_column($db->get('cc_automation_jobs')->result_array(),'status');
if(!in_array('issued',$states,true)||count(array_intersect($states,['issued','skipped']))!==2)throw new RuntimeException('Other rule must close after authorization');
(new NfseNacional\Automation($m,new CcService($m)))->run();
if($db->count_all_results('cc_calls')!==1)throw new RuntimeException('Repeated worker must not retransmit');
echo "OK: dois tipos ativos, pagamento parcial, três workers concorrentes, uma transmissão simulada e pendências encerradas; bloqueios e índices em MariaDB real.\n";
