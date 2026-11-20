<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
if (!getenv('NFSE_TEST_MYSQL_PORT')) { echo "PENDENTE: requer MariaDB descartável.\n"; exit(2); }
require __DIR__ . '/sync_queue_database_test.php';
function staff_can($cap,$feature){ return $feature === 'nfse_nacional' && in_array($cap,$GLOBALS['syncGrants'] ?? [],true); }
function admin_url($p=''){ return 'https://crm.example.test/admin/'.$p; }
function log_message(...$args){}
#[AllowDynamicProperties] class AdminController {}
require dirname(__DIR__).'/controllers/Nfse_nacional.php';
class SyncInput { function __construct(public string $verb,public array $values=[]){} function method(){return $this->verb;}function post($k,$filter=false){return $this->values[$k]??null;}function get($k){return $this->values[$k]??null;} }
class SyncOutput {public int $status=200;public string $body='';function set_status_header($s){$this->status=$s;return $this;}function set_content_type(...$a){return $this;}function set_output($s){$this->body=$s;return $this;}}
$syncConfig=new class{public bool $csrf=true;function item($k){return $k==='csrf_protection'?$this->csrf:null;}};
$syncSecurity=new class{function get_csrf_token_name(){return 'csrf';}function get_csrf_hash(){return 'current';}};
get_instance()->config=$syncConfig;get_instance()->security=$syncSecurity;
$apiCalls=[];$contexts=[];
$apiFactory=function($environment,$certificate)use(&$apiCalls,&$contexts,$tax){return new class($apiCalls,$contexts,$tax){
 function __construct(private array &$calls,private array &$contexts,private string $tax){}
 function distribute($nsu,$issuer){
  $this->calls[]=$nsu;$this->contexts[]=NfseNacional\CompanyContext::current()->descriptor($issuer)['runtime'];$n=(int)$nsu+1;
  if($n>6)return ['items'=>[],'next_nsu'=>$nsu,'has_more'=>false,'empty'=>true,'retry_after'=>3600];
  $year=[4=>2025,5=>2026,6=>2024][$n];$note=$n===5?nationalNote($n,'11144477735',$this->tax,'2',"$year-11-10","$year-11-10T12:00:00-03:00"):nationalNote($n,$this->tax,'11144477735','2',"$year-11-10","$year-11-10T12:00:00-03:00");
  return ['items'=>[$note],'next_nsu'=>(string)$n,'has_more'=>true,'empty'=>false,'retry_after'=>0];
 }
};};
$makeService=fn()=>new NfseNacional\NationalService($model,$apiFactory);
function syncController($verb,$data){
 $c=(new ReflectionClass(Nfse_nacional::class))->newInstanceWithoutConstructor();$c->input=new SyncInput($verb,$data);$c->output=new SyncOutput();$c->config=$GLOBALS['syncConfig'];$c->security=$GLOBALS['syncSecurity'];
 (new ReflectionProperty(Nfse_nacional::class,'fiscal'))->setValue($c,$GLOBALS['model']);
 (new ReflectionProperty(Nfse_nacional::class,'nationalFactory'))->setValue($c,fn()=>($GLOBALS['makeService'])());return $c;
}
// Simulate the observed active queue with zero work, not an artificial NSU restart.
$db->where('issuer_tax_id',$tax)->where('environment','2')->update('nfse_nacional_national_sync',['next_sync_at'=>null]);
$db->where('issuer_tax_id',$tax)->where('environment','2')->update('nfse_nacional_sync_runs',['status'=>'pending','mode'=>'period','date_from'=>'2024-01-01','date_to'=>'2024-12-31','next_run_at'=>gmdate('Y-m-d H:i:s',time()-600),'last_worker_at'=>null]);
$q=new NfseNacional\SyncQueue($model,$makeService());verify($q->state('2')['worker_stalled']===true&&$apiCalls===[],'stale pending queue diagnosed without a remote GET');
$cursorBeforeResume=$repo->state('2')['last_nsu'];$jobBeforeResume=$q->state('2');
$c=syncController('POST',['environment'=>'2','sync_mode'=>'full']);$c->national_sync();$reply=json_decode($c->output->body,true);
if($c->output->status!==200){echo 'Reprodução do diagnóstico: HTTP '.$c->output->status.' — '.($reply['message']??'')."\n";}
verify($c->output->status===200&&$reply['ok']&&$apiCalls===['3']&&$repo->state('2')['last_nsu']==='4','real start endpoint imports immediately from confirmed NSU instead of only scheduling');
verify($q->state('2')['processed']===1&&!empty($q->state('2')['last_worker_at'])&&$contexts===['native'],'interactive processing keeps company authentication and records actual worker activity');
verify($cursorBeforeResume==='3'&&$q->state('2')['mode']==='full'&&$q->state('2')['date_from']===null&&$q->state('2')['date_to']===null,'active period becomes full history without the reported 409');
verify($q->state('2')['baseline_processed']===$jobBeforeResume['baseline_processed']&&$q->state('2')['started_at']===$jobBeforeResume['started_at'],'selection changes preserve original run baselines and start time');
$q->start('2','period','2026-01-01','2026-12-31');verify($q->state('2')['mode']==='period'&&$q->state('2')['date_from']==='2026-01-01'&&$repo->state('2')['last_nsu']==='4','full to period changes selection only, never the confirmed cursor');
$q->start('2','period','2025-01-01','2025-12-31');verify($q->state('2')['date_to']==='2025-12-31'&&$q->state('2')['processed']===1&&count($apiCalls)===1,'changing active periods retains progress and does not consume a page');
$db->where('issuer_tax_id',$tax)->where('environment','2')->update('nfse_nacional_sync_runs',['mode'=>'full','date_from'=>'2024-01-01','date_to'=>'2024-12-31']);
$q->start('2','full');verify($q->state('2')['date_from']===null&&$q->state('2')['date_to']===null&&$repo->state('2')['last_nsu']==='4','legacy dates attached to full history normalize without blocking');
$c=syncController('POST',['environment'=>'2','sync_mode'=>'period','sync_date_from'=>'invalid','sync_date_to'=>'2026-12-31']);$c->national_sync();verify($c->output->status===422&&count($apiCalls)===1&&$q->state('2')['mode']==='full','invalid new selection is rejected without damaging the existing run');
$c=syncController('GET',['environment'=>'2']);$c->national_sync_progress();verify($apiCalls===['3'],'GET progress remains read-only');
$c=syncController('POST',['environment'=>'2']);$c->national_sync_process();verify($c->output->status===200&&$apiCalls===['3','4']&&$repo->list('received',['environment'=>'2'])['total']===1,'CSRF POST continuation recovers received note after 2024');
// The new report permissions do not require the deprecated literal national_view grant.
$db->query('CREATE TABLE IF NOT EXISTS staffpermissions(staff_id INT,feature VARCHAR(100),capability VARCHAR(100))');
$db->query('DELETE FROM staffpermissions');$db->query('UPDATE staff SET admin=0 WHERE staffid=7');
$syncGrants=['view','national_sync','report_issued'];foreach($syncGrants as $cap){$db->insert('staffpermissions',['staff_id'=>7,'feature'=>'nfse_nacional','capability'=>$cap]);}
$syncAdmin=false;$syncLoggedIn=false;
$q->run(3);$syncLoggedIn=true;verify($q->state('2')['status']==='completed'&&$repo->state('2')['last_nsu']==='6','cron without staff session completes with current synchronization grants');
verify(array_slice($contexts,2)===['automation','automation']&&$apiCalls===['3','4','5','6'],'screen closure continues through background delegation, without restart or skipped NSU');
$syncLoggedIn=true;$syncAdmin=true;
verify($repo->list('both',['environment'=>'2'])['total']===6&&$repo->list('issued',['environment'=>'2','date_from'=>'2025-01-01','date_to'=>'2025-12-31'])['total']===2,'archive contains prior notes plus 2025/2026 and later-arriving 2024 without duplicates');
$before=count($apiCalls);$q->start('2','full');$q->run(1,true);$allowed=$q->state('2')['next_attempt_at'];$q->start('2','full');$q->run(1,true);
verify(count($apiCalls)===$before&&$q->state('2')['status']==='waiting'&&$q->state('2')['next_attempt_at']===$allowed,'manual resume never bypasses confirmed-empty cooldown');
$q->start('2','period','2026-01-01','2026-12-31');verify($q->state('2')['next_attempt_at']===$allowed&&$q->state('2')['status']==='waiting'&&count($apiCalls)===$before,'switching a waiting run to period preserves the service cooldown');
// A stale worker selection must recheck postponement under its lock.
$db->where('issuer_tax_id',$tax)->where('environment','2')->update('nfse_nacional_sync_runs',['status'=>'pending','next_run_at'=>gmdate('Y-m-d H:i:s',time()-1)]);
class RecheckModel extends Nfse_nacional_model {public $beforeLock;function lock(string $name,callable $operation){if($this->beforeLock){$callback=$this->beforeLock;$this->beforeLock=null;$callback();}return parent::lock($name,$operation);}}
$recheck=new RecheckModel();$recheck->beforeLock=fn()=>$db->where('issuer_tax_id',$tax)->where('environment','2')->update('nfse_nacional_sync_runs',['status'=>'waiting','next_run_at'=>gmdate('Y-m-d H:i:s',time()+600)]);
(new NfseNacional\SyncQueue($recheck,$makeService()))->run(1,true);verify(count($apiCalls)===$before&&$q->state('2')['status']==='waiting','worker selected before a postponement cannot consume another page');
// 087 resumes after interrupted DDL without rewriting cursor, archive or pending queue.
class App_module_migration {}
require dirname(__DIR__).'/migrations/087_version_087.php';$table='nfse_nacional_sync_runs';$db->query('ALTER TABLE `'.$table.'` DROP COLUMN last_worker_at');$db->failOn='ADD COLUMN `last_worker_at`';
try{(new Migration_Version_087())->up();throw new LogicException('DDL failure ignored');}catch(RuntimeException $e){verify(!$db->field_exists('last_worker_at',$table),'interrupted migration reports the missing heartbeat column');}
(new Migration_Version_087())->up();(new Migration_Version_087())->up();verify($db->field_exists('last_worker_at',$table)&&$repo->state('2')['last_nsu']==='6'&&$repo->list('both',['environment'=>'2'])['total']===6,'migration retry preserves confirmed NSU and all documents');
// Revoked permission blocks background processing without transmitting or changing cursor.
$db->query("DELETE FROM staffpermissions WHERE capability='national_sync'");$db->where('issuer_tax_id',$tax)->where('environment','2')->update($table,['status'=>'pending','next_run_at'=>gmdate('Y-m-d H:i:s',time()-1)]);$q->run(1);
verify($q->state('2')['status']==='failed'&&count($apiCalls)===$before&&$repo->state('2')['last_nsu']==='6','revoked synchronization access stops worker safely');
echo "OK: regressões 083/085, botão real, cron sem sessão, emitidas/recebidas, pausas e migração retomável; $n verificações acumuladas em MariaDB.\n";
