<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
if (!getenv('NFSE_TEST_MYSQL_PORT')) { echo "PENDENTE: configure MariaDB descartável.\n"; exit(2); }
$argv[1]='native_empty'; $grants=['view','settings','rates_view','rates_create','rates_edit','rates_delete'];
function is_staff_logged_in(){return true;} function is_admin(){return false;}
function staff_can($cap,$feature){return $feature==='nfse_nacional'&&in_array($cap,$GLOBALS['grants'],true);}
require __DIR__.'/database_matrix_test.php';
#[AllowDynamicProperties] class AdminController {}
require dirname(__DIR__).'/controllers/Nfse_nacional.php';
function access_denied($name){throw new RuntimeException('Denied',403);} function show_404(){throw new RuntimeException('404',404);}
function admin_url($p=''){return 'https://crm.example.test/admin/'.$p;}
function html_escape($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function log_message(...$args){}
function init_head(){} function init_tail(){}
function form_open($action,$attrs=[]){$html='<form method="post" action="'.html_escape($action).'"';foreach($attrs as $k=>$v)$html.=' '.html_escape($k).'="'.html_escape($v).'"';return $html.'>';}
function form_close(){return '</form>';}
class RatesInput {public function __construct(public string $verb,public array $values){} function method(){return $this->verb;}function post($k,$filter=false){return $this->values[$k]??null;}function get($k){return $this->values[$k]??null;}}
class RatesOutput {public int $status=200; public string $body='';function set_status_header($s){$this->status=$s;return $this;}function set_content_type(...$a){return $this;}function set_output($s){$this->body=$s;return $this;}}
$config=new class {function item($k){return $k==='csrf_protection';}};
$security=new class {function get_csrf_token_name(){return 'csrf';}function get_csrf_hash(){return 'local';}};
get_instance()->config=$config;get_instance()->security=$security;
function ratesController($verb,$data){
 $c=(new ReflectionClass(Nfse_nacional::class))->newInstanceWithoutConstructor();
 $c->input=new RatesInput($verb,$data);$c->output=new RatesOutput();$c->config=$GLOBALS['config'];$c->security=$GLOBALS['security'];
 $c->load=new class {public array $data=[];public string $view='';function view($v,$data){$this->view=$v;$this->data=$data;}};
 (new ReflectionProperty(Nfse_nacional::class,'fiscal'))->setValue($c,$GLOBALS['model']);return $c;
}
// Existing November rate is listed and edited explicitly, never inserted as a duplicate.
$c=ratesController('GET',[]);$c->competence_rates();verify(count($c->load->data['rates'])===1,'real controller lists existing rate');
$render=$c->load->data;extract($render);ob_start();require dirname(__DIR__).'/views/competence_rates.php';$html=ob_get_clean();
verify(str_contains($html,'11/2026')&&str_contains($html,'6,25%')&&str_contains($html,'data-nfse-rate-edit'),'server-rendered row and Edit are visible without DataTables');
$c=ratesController('POST',['competence'=>'11/2026','rate'=>'11,41','original_competence'=>'11/2026']);$c->competence_rate_save();verify($c->output->status===200&&$model->competenceRate('2026-11')['rate']==='11.41','edit saves existing November rate');
$c=ratesController('GET',['competence'=>'2026-11']);$c->competence_rate_lookup();verify(json_decode($c->output->body,true)['data']['rate']==='11.41','settings lookup returns monthly percentage');
$c=ratesController('POST',['competence'=>'11/2026','rate'=>'12.00']);$c->competence_rate_save();verify($c->output->status===422,'new entry does not silently overwrite existing month');
$grants=['view','rates_view'];$c=ratesController('POST',['competence'=>'11/2026','rate'=>'12.00','original_competence'=>'11/2026']);$c->competence_rate_save();verify($c->output->status===403&&$model->competenceRate('2026-11')['rate']==='11.41','view-only staff cannot edit by direct POST');
$c=ratesController('POST',['competence'=>'11/2026']);$c->competence_rate_delete();verify($c->output->status===403,'view-only staff cannot delete by direct POST');
$c=ratesController('GET',[]);$c->competence_rates();extract($c->load->data);ob_start();require dirname(__DIR__).'/views/competence_rates.php';$html=ob_get_clean();verify(!str_contains($html,'data-nfse-rate-edit')&&!str_contains($html,'competence_rate_save')&&!str_contains($html,'competence_rate_delete'),'read-only grid hides mutation controls');
$grants=['view','rates_view','rates_edit'];$c=ratesController('POST',['competence'=>'11/2026','rate'=>'11.55','original_competence'=>'11/2026']);$c->competence_rate_save();verify($c->output->status===200,'rates editor works without global settings permission');
$c=ratesController('POST',['competence'=>'12/2026','rate'=>'12.00']);$c->competence_rate_save();verify($c->output->status===403,'edit permission does not grant create');
$grants=['view','report_issued'];verify(nfse_nacional_can('national_view')&&!nfse_nacional_can('report_received')&&!nfse_nacional_can('report_package'),'report grants are independently selectable');
$c=ratesController('GET',[]);$c->reports();verify($c->load->view==='nfse_nacional/reports','authorized staff can open report hub');
try{NfseNacional\Permissions::assertReport('received');throw new LogicException('unexpected');}catch(RuntimeException $e){verify($e->getCode()===403,'unselected report is denied');}
$c=ratesController('GET',[]);try{$c->national('received');throw new LogicException('unexpected');}catch(RuntimeException $e){verify($e->getCode()===403,'national route denies unselected report before reading documents');}
$grants=['view','settings'];$fixed=$model->settings();$fixed['tax']['simple_rate']='2.00';$fixed['tax']['total_tax_mode']='simple';
$s=NfseNacional\Config::sanitize(['variable_simple_rate'=>'1','tax'=>['simple_rate'=>'11.41','total_tax_mode'=>'ibpt','iss_rate'=>'3.00']],$fixed);$model->saveSettings($s);
verify($s['tax']['total_tax_mode']==='ibpt'&&$s['tax']['iss_rate']==='3.00'&&$s['tax']['simple_rate']==='2.00','settings save accepts IBPT and ISS changes while preserving static percentage');
$data=$model->settingsData();verify($data['monthly_rate']['rate']===$model->competenceRate($data['rate_month'])['rate'],'settings receives current monthly rate separately from fixed setting');
extract($data);ob_start();require dirname(__DIR__).'/views/settings.php';$html=ob_get_clean();verify(str_contains($html,'data-nfse-monthly-rate="11.55"')&&str_contains($html,'data-nfse-fixed-rate="2.00"'),'monthly and fixed values remain separate in settings HTML');
// 086 is additive/restartable; existing Simples register is never rewritten.
$grants=['view','settings','rates_view','rates_create','rates_edit','rates_delete'];
if (!class_exists('App_module_migration')) { class App_module_migration {} }
require dirname(__DIR__).'/migrations/086_version_086.php';
$table=$model->table('competence_iss_rates');$db->query('DROP TABLE `'.$table.'`');$db->failOn='CREATE TABLE IF NOT EXISTS `'.$table.'`';
try{(new Migration_Version_086())->up();throw new LogicException('DDL failure ignored');}catch(RuntimeException $e){verify(!$db->table_exists($table),'interrupted 086 reports missing ISS table');}
(new Migration_Version_086())->up();(new Migration_Version_086())->up();verify($db->table_exists($table)&&$model->competenceRate('2026-11')['rate']==='11.55','migration retry creates only ISS structure and preserves Simples');
$db->insert($table,['issuer'=>'99888777000166','competence'=>'2026-11','rate'=>'4.90','updated_by'=>7,'updated_at'=>date('Y-m-d H:i:s')]);
$c=ratesController('POST',['rate_type'=>'iss','competence'=>'11/2026','rate'=>'3.25']);$c->competence_rate_save();verify($c->output->status===200&&$model->competenceRate('2026-11','iss')['rate']==='3.25'&&$model->competenceRate('2026-11')['rate']==='11.55','ISS creation isolated from Simples same month');
$c=ratesController('POST',['rate_type'=>'iss','competence'=>'11/2026','rate'=>'3.75','original_competence'=>'11/2026']);$c->competence_rate_save();verify($c->output->status===200&&$model->competenceRate('2026-11','iss')['rate']==='3.75','ISS edit updates original month');
$c=ratesController('POST',['rate_type'=>'iss','competence'=>'11/2026','rate'=>'3.25']);$c->competence_rate_save();verify($c->output->status===422,'ISS duplicates rejected independently');
$c=ratesController('POST',['rate_type'=>'iss','competence'=>'12/2026','rate'=>'11.41']);$c->competence_rate_save();verify($c->output->status===422&&$model->competenceRate('2026-12','iss')===null,'ISS existing fiscal limit preserved at registration');
$s=$model->settings();$s['variable_simple_rate']='0';$s['variable_iss_rate']='1';$s['variable_iss_rate_source']='iss';$s['tax']['iss_rate']='2.50';$model->saveSettings($s);
$c=ratesController('GET',['rate_type'=>'iss']);$c->competence_rates();verify(count($c->load->data['rates'])===1&&$c->load->data['rate_type']==='iss','ISS-only mode exposes correct grid');
$r=$model->applyCompetenceRate(['competence'=>'2026-11-12','values'=>['simple_rate'=>'2.00']],true);verify($r['values']['simple_rate']==='2.00'&&$r['values']['iss_rate']==='3.75','real DB resolves ISS alone by fiscal competence');
$c=ratesController('GET',['competence'=>'2026-11']);$c->competence_rate_lookup();$data=json_decode($c->output->body,true)['data'];verify($data['rate']==='11.55'&&$data['iss_rate']==='3.75','lookup returns both independent registers');
$s['variable_simple_rate']='1';$s['variable_iss_rate_source']='simple';$model->saveSettings($s);
try{$model->applyCompetenceRate(['competence'=>'2026-11-12','values'=>[]],true);throw new LogicException('shared over-limit allowed');}catch(\NfseNacional\ValidationException $e){verify(str_contains($e->getMessage(),'superior a 5%'),'over-limit shared rate blocks real model without conversion');}
$grants=['view','rates_view'];$c=ratesController('POST',['rate_type'=>'iss','competence'=>'11/2026']);$c->competence_rate_delete();verify($c->output->status===403&&$model->competenceRate('2026-11','iss')!==null,'ISS permission boundary matches Simples');
$grants=['view','rates_view','rates_delete'];$c=ratesController('POST',['rate_type'=>'iss','competence'=>'11/2026']);$c->competence_rate_delete();verify($c->output->status===200&&$model->competenceRate('2026-11','iss')===null&&$model->competenceRate('2026-11')['rate']==='11.55','ISS removal preserves Simples register');
verify($db->where('issuer','99888777000166')->where('competence','2026-11')->get($table)->row_array()['rate']==='4.90','CRUD cannot change or remove another issuer ISS rate');
echo "OK: ISSQN separado/compartilhado, CRUD, limites e migração retomável; $n verificações acumuladas em MariaDB.\n";
