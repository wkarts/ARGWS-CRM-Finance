<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('BASEPATH',__DIR__); require dirname(__DIR__).'/autoload.php';
class App_Model {}
require dirname(__DIR__).'/models/Nfse_nacional_model.php';
use NfseNacional\{Automation,Config,CompetenceRate};
function nfse_nacional_scope(){return str_repeat('a',64);}
function db_prefix(){return 'test_';}
function get_staff_user_id(){return 7;}
function &get_instance(){return $GLOBALS['ci'];}
function log_message(...$args){}
class Result {
 function __construct(private array $rows){}
 function row_array(){return $this->rows[0]??[];}
 function result_array(){return $this->rows;}
}
class Db {
 public array $tables=[];private array $conditions=[];private ?int $limit=null;
 function select($s){return $this;}
 function where($key,$value){$this->conditions[]=[$key,$value,false];return $this;}
 function where_in($key,$value){$this->conditions[]=[$key,$value,true];return $this;}
 function order_by(...$x){return $this;}
 function limit($n){$this->limit=$n;return $this;}
 function matches($row){foreach($this->conditions as [$key,$val,$in]){if(str_ends_with($key,' <=')){if(($row[substr($key,0,-3)]??null)>$val)return false;}
  elseif($in ? !in_array($row[$key]??null,$val) : ($row[$key]??null)!=$val)return false;}return true;}
 function reset(){ $this->conditions=[];$this->limit=null; }
 function get($table){$rows=array_values(array_filter($this->tables[$table]??[],fn($r)=>$this->matches($r)));if($this->limit!==null)$rows=array_slice($rows,0,$this->limit);$this->reset();return new Result($rows);}
 function update($table,$changes){foreach($this->tables[$table] as &$row){if($this->matches($row))$row=array_replace($row,$changes);}unset($row);$this->reset();return true;}
 function query($sql,$p){if(str_contains($sql,'INSERT IGNORE')&&str_contains($sql,'`jobs`')){
   foreach($this->tables['jobs'] as $j)if($j['invoice_id']===$p[2]&&$j['environment']===$p[4]&&$j['trigger_event']===$p[5])return true;
   $cols=['scope','issuer','invoice_id','client_id','environment','trigger_event','recipient_selection','explicit_selection','invoice_fingerprint','status','due_at','created_at','updated_at'];
   $this->tables['jobs'][]=['id'=>count($this->tables['jobs'])+1,'document_id'=>null,'last_error'=>null]+array_combine($cols,$p);return true;
  }throw new RuntimeException('unexpected query');}
}
class Model {
 public Db $db; public array $config;public array $rates=[];public array $issRates=[];public int $prepared=0;
 function __construct(){ $this->db=new Db();$this->config=Config::defaults();unset($this->config['automations']);$this->config['provider']['tax_id']='11222333000181';$this->config['automation']=['mode'=>'all','trigger'=>'created','delay_minutes'=>'30','staff_id'=>'7'];
  $this->db->tables=['jobs'=>[],'documents'=>[],'profiles'=>[],'targets'=>[],'test_staff'=>[['staffid'=>7,'active'=>1,'admin'=>1]]];}
 function table($s){return ['automation_jobs'=>'jobs','automation_targets'=>'targets','profiles'=>'profiles','documents'=>'documents'][$s]??$s;}
 function settings(){return $this->config;}
 function invoiceRecipientSelection(...$x){return '';}
 function lock($n,$f){return $f();}
 function applyCompetenceRate($d,$required=false){return CompetenceRate::apply($d,$this->config,fn($m,$type='simple')=>($type==='iss'?$this->issRates:$this->rates)[$m]??null,$required);}
 function prepare($id){$this->prepared++;$i=$GLOBALS['invoices'][$id];$this->db->tables['documents'][]=['id'=>$this->prepared,'invoice_id'=>$id,'environment'=>'2','status'=>'draft','attempts'=>0];return $this->prepared;}
}
class FakeService {
 public int $calls=0;public int $queries=0;public string $result='authorized';
 function __construct(private $model){}
 function reconcile($id){$this->queries++;return ['status'=>$this->result];}
 function transmit($id){$this->calls++;$this->model->db->where('id',$id)->update('documents',['status'=>$this->result,'attempts'=>1]);return ['status'=>$this->result];}
}
$n=0;function qcheck($ok,$msg){global $n;if(!$ok)throw new RuntimeException('FALHOU: '.$msg);$n++;}
function setup(){
 $m=new Model();$GLOBALS['invoices']=[41=>(object)['id'=>41,'clientid'=>9,'date'=>'2026-11-01','datecreated'=>date('Y-m-d H:i:s'),'currency'=>1,'subtotal'=>'100.00','discount_total'=>'0','total'=>'100.00','status'=>1,'items'=>[]]];
 $GLOBALS['ci']=(object)['load'=>new class{function model($n){}},'invoices_model'=>new class{function get($id){return $GLOBALS['invoices'][$id]??false;}}];
 $s=new FakeService($m);return [$m,$s,new Automation($m,$s)];
}
function makeDue($m){foreach($m->db->tables['jobs'] as &$j)$j['due_at']='2020-01-01 00:00:00';}
[$m,$s,$a]=setup();$a->enqueue(41,'created');$a->enqueue(41,'created');qcheck(count($m->db->tables['jobs'])===1,'idempotent scheduling');$a->run();qcheck($s->calls===0,'delay respected');makeDue($m);$a->run();$a->run();qcheck($s->calls===1&&$m->db->tables['jobs'][0]['status']==='issued','exactly one transmission');
[$m,$s,$a]=setup();$m->config['variable_simple_rate']='1';$a->enqueue(41,'created');makeDue($m);$a->run();qcheck($s->calls===0&&$m->db->tables['jobs'][0]['status']==='blocked','missing competence blocks before I/O');$m->rates['2026-11']=['rate'=>'6.25'];makeDue($m);$a->run();qcheck($s->calls===1,'rate registration releases waiting job');
foreach(['authorized','cancelled','processing','uncertain'] as $status){[$m,$s,$a]=setup();$a->enqueue(41,'created');$m->db->tables['documents'][]=['id'=>99,'invoice_id'=>41,'environment'=>'2','status'=>$status,'attempts'=>1];makeDue($m);$a->run();qcheck($s->calls===0&&$m->db->tables['jobs'][0]['status']==='skipped','manual or uncertain document suppresses automation: '.$status);}
[$m,$s,$a]=setup();$a->enqueue(41,'created');$GLOBALS['invoices'][41]->status=5;makeDue($m);$a->run();qcheck($s->calls===0,'cancelled invoice never transmitted');
[$m,$s,$a]=setup();$a->enqueue(41,'created');unset($GLOBALS['invoices'][41]);makeDue($m);$a->run();qcheck($s->calls===0&&$m->db->tables['jobs'][0]['status']==='cancelled','deleted invoice removed from automation');
[$m,$s,$a]=setup();$m->config['automation']['mode']='selected';$a->enqueue(41,'created');qcheck(!$m->db->tables['jobs'],'unselected client ignored');$a->enqueue(41,'created',true);makeDue($m);$a->run();qcheck($s->calls===1,'explicit invoice selection accepted');
[$m,$s,$a]=setup();$m->config['automation']['trigger']='paid';$a->enqueue(41,'paid');qcheck(!$m->db->tables['jobs'],'partial/open invoice ignored');$GLOBALS['invoices'][41]->status=2;$a->enqueue(41,'paid');makeDue($m);$a->run();qcheck($s->calls===1,'paid invoice issued');
[$m,$s,$a]=setup();$a->enqueue(41,'created');$m->config['environment']='1';makeDue($m);$a->run();qcheck($s->calls===0,'environment immutable');
[$m,$s,$a]=setup();$a->enqueue(41,'created');$m->config['provider']['tax_id']='00000000000000';makeDue($m);$a->run();qcheck($s->calls===0,'issuer isolation');
[$m,$s,$a]=setup();$a->enqueue(41,'created');$m->db->tables['test_staff'][0]['active']=0;makeDue($m);$a->run();qcheck($s->calls===0,'revoked delegating admin stops jobs');
[$m,$s,$a]=setup();$a->enqueue(41,'created');$s->result='uncertain';makeDue($m);$a->run();$a->run();qcheck($s->calls===1&&$m->db->tables['jobs'][0]['status']==='checking','uncertain response is checked without retransmission');
[$m,$s,$a]=setup();$a->enqueue(41,'created');$GLOBALS['invoices'][41]->total='200.00';makeDue($m);$a->run();qcheck($s->calls===0,'changed invoice requires review');
[$m,$s,$a]=setup();$m->config['automation']['mode']='selected';$m->config['automation']['trigger']='paid';$a->enqueue(41,'created',true);makeDue($m);$a->run();qcheck($s->calls===0,'explicit selection cannot enable a disabled creation rule');
[$m,$s,$a]=setup();$m->config['automation']['mode']='disabled';$a->enqueue(41,'created',true);qcheck(!$m->db->tables['jobs'],'explicit selection cannot bypass disabled mode');
[$m,$s,$a]=setup();$m->config['automations']=['created'=>['mode'=>'selected','delay_minutes'=>'30','staff_id'=>'7'],'paid'=>['mode'=>'selected','delay_minutes'=>'0','staff_id'=>'7']];
$m->db->tables['targets'][]=['client_id'=>9,'recipient_selection'=>'*','trigger_event'=>'created'];
$a->enqueue(41,'created');$GLOBALS['invoices'][41]->status=2;$a->enqueue(41,'paid');
qcheck(count($m->db->tables['jobs'])===1&&$m->db->tables['jobs'][0]['trigger_event']==='created','selected recipients are independent per automation type');
[$m,$s,$a]=setup();$a->enqueue(41,'created');$s->result='uncertain';makeDue($m);$a->run();makeDue($m);$a->run();
qcheck($s->calls===1&&$s->queries===1&&$m->db->tables['jobs'][0]['status']==='checking','inconclusive operation is queried before any future resend');
$s->result='authorized';makeDue($m);$a->run();qcheck($s->calls===1&&$m->db->tables['jobs'][0]['status']==='issued','confirmation closes original pending job without a second POST');
foreach (['iss', 'simple'] as $source) {
 [$m,$s,$a]=setup();$m->config['variable_iss_rate']='1';$m->config['variable_iss_rate_source']=$source;
 $a->enqueue(41,'created');makeDue($m);$a->run();qcheck($s->calls===0&&$m->prepared===0&&$m->db->tables['jobs'][0]['status']==='blocked','ISS mensal ausente bloqueia worker antes da preparação: '.$source);
 $m->rates['2026-11']=['rate'=>'3.25'];$m->issRates['2026-11']=['rate'=>'3.25'];makeDue($m);$a->run();qcheck($s->calls===1,'Cadastro necessário libera worker: '.$source);
}
[$m,$s,$a]=setup();$m->config['variable_iss_rate']='1';$m->config['variable_iss_rate_source']='simple';$m->rates['2026-11']=['rate'=>'11.41'];$a->enqueue(41,'created');makeDue($m);$a->run();qcheck($s->calls===0&&$m->prepared===0&&str_contains($m->db->tables['jobs'][0]['last_error'],'superior a 5%'),'Compartilhamento inválido não reduz ou transmite percentual silenciosamente');
echo "OK: $n verificações da fila com o worker real, sem chamadas externas.\n";
