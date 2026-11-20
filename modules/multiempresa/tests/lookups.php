<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require dirname(__DIR__).'/autoload.php';
use Multiempresa\Application\{App,Lookups,GlobalSettings};
use Multiempresa\Infrastructure\{Schema,Files,HttpClient};
use Multiempresa\Domain\{Id,Failure};
$n=0;$calls=[];$state=sys_get_temp_dir().'/multiempresa-lookups-'.Id::new();Files::mkdir($state);
$app=new App(['central'=>['dsn'=>'sqlite::memory:','table_prefix'=>'test_'],'native_prefix'=>'core_','master_key'=>base64_encode(random_bytes(32)),'state_dir'=>$state]);
Schema::install($app->db,112);Schema::install($app->db,112);$actor=['id'=>Id::new(),'platform_admin'=>true];
function check(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException($label);}echo 'PASS lookup '.$label."\n";$n++;}
function resetLookup():void{global $app,$calls;$app->db->exec('DELETE FROM pme_lookup_cache');$app->db->exec('DELETE FROM pme_rate_limits');$calls=[];}
function service(callable $fn):Lookups{global $app,$calls;return new Lookups($app,new HttpClient(function($method,$url,$headers,$payload,$timeout)use($fn,&$calls){$calls[]=$url;if($method!=='GET'||$headers!==[]||$payload!==null||$timeout>4){throw new RuntimeException('Unexpected public request contract');}return $fn($url);}));}
function complete():array{return ['cnpj'=>'11222333000181','razao_social'=>'Empresa Pública','nome_fantasia'=>'Nome Fantasia','logradouro'=>'Praça da Sé','numero'=>0,'bairro'=>'Sé','municipio'=>'São Paulo','uf'=>'SP','cep'=>'01.001-000','email'=>'contato@example.test; segundo@example.test','ddd_telefone_1'=>'1130000000','codigo_municipio_ibge'=>3550308,'cnae_fiscal'=>6201501,'natureza_juridica'=>'Sociedade limitada','descricao_situacao_cadastral'=>'ATIVA','data_inicio_atividade'=>'07/11/2000'];}
check(count($app->db->all('SELECT * FROM pme_lookup_cache'))===0,'fresh install creates the internal cache idempotently');
$lookups=service(fn()=>complete());$r=$lookups->lookup($actor,'cnpj','11.222.333/0001-81');
check(count($calls)===1&&str_starts_with($calls[0],'https://brasilapi.com.br/api/cnpj/v1/'),'fresh install consults a fixed public service without configuration');
check($r['fields']['legal_name']==='Empresa Pública'&&$r['fields']['trade_name']==='Nome Fantasia'&&$r['fields']['city']==='São Paulo','CNPJ maps company and address fields');
check($r['fields']['cep']==='01001000'&&$r['fields']['number']==='0'&&$r['fields']['email']==='contato@example.test'&&$r['fields']['opening_date']==='2000-11-07','returned fields normalize without making the form invalid');
check(!isset($r['providers'])&&array_keys($r)===['fields','message'],'technical provider choices are not exposed in the lookup response');
$again=$lookups->lookup($actor,'cnpj','11222333000181');check($r===$again&&count($calls)===1,'repeated lookup reuses recent data without another request');
$cache=$app->db->one('SELECT data FROM pme_lookup_cache');check(!str_contains($cache['data'],'Empresa Pública'),'cached public response is encrypted');
$app->db->exec('UPDATE pme_lookup_cache SET expires_at=0');$lookups->lookup($actor,'cnpj','11222333000181');check(count($calls)===2&&count($app->db->all('SELECT * FROM pme_lookup_cache'))===1,'expired lookup is refreshed and old cache removed');

$legacy=['database'=>['password'=>'db-keep','isolation'=>'prefix'],'dns'=>['token'=>'dns-keep','enabled'=>false],'lookups'=>['enabled'=>false,'cnpj_order'=>['custom'],'cep_order'=>[],'cnpjws_token'=>'retired-token','custom_url'=>'https://obsolete.example.test','custom_mapping'=>'invalid']];
$app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)',['global_configuration',$app->vault->seal($legacy)]);resetLookup();
$r=service(fn()=>complete())->lookup($actor,'cnpj','11222333000181');check($r['fields']['legal_name']==='Empresa Pública'&&str_contains($calls[0],'brasilapi.com.br'),'legacy disabled flag, custom URL and token do not control automatic lookup');
$settings=new GlobalSettings($app);check(!isset($settings->get(true)['lookups']),'settings API no longer exposes lookup parameters');
$settings->removeLegacyLookupSettings();$settings->removeLegacyLookupSettings();unset($legacy['lookups']);
check($app->vault->open($app->db->one("SELECT value FROM pme_settings WHERE name='global_configuration'")['value'])===$legacy,'upgrade removes obsolete parameters and preserves database/DNS secrets');
$settings->save($actor,['lookups'=>['enabled'=>false,'custom_url'=>'invalid','timeout'=>0]]);
check(!isset($settings->get()['lookups']),'old settings submissions cannot restore the retired configuration');

resetLookup();$r=service(function($url){if(str_contains($url,'brasilapi')){throw new Failure('http_503','Unavailable');}if(str_contains($url,'cnpj.ws')){throw new Failure('http_429','Quota');}return complete();})->lookup($actor,'cnpj','11222333000181');
check(count($calls)===3&&str_contains($calls[1],'publica.cnpj.ws')&&$calls[2]==='https://receitaws.com.br/v1/cnpj/11222333000181','unavailable services fall back to public CNPJWS and public ReceitaWS without credentials');
check($r['fields']['legal_name']==='Empresa Pública','last public alternative can complete the lookup');
resetLookup();$r=service(function($url){if(str_contains($url,'brasilapi'))return ['cnpj'=>'11222333000181','razao_social'=>'Prioridade interna'];if(str_contains($url,'cnpj.ws'))return ['razao_social'=>'Não substituir','estabelecimento'=>['cnpj'=>'11222333000181','nome_fantasia'=>'Complementado','logradouro'=>'Rua teste','numero'=>'1']];return ['cnpj'=>'11222333000181','nome'=>'Também preservar','municipio'=>'Salvador','uf'=>'BA','cep'=>'40020000'];})->lookup($actor,'cnpj','11222333000181');
check(count($calls)===3&&$r['fields']['legal_name']==='Prioridade interna'&&$r['fields']['trade_name']==='Complementado'&&$r['fields']['city']==='Salvador','incomplete responses combine matching fields without replacing higher priority data');

resetLookup();$svc=service(function($url){if(str_contains($url,'viacep'))return ['erro'=>'true'];return ['cep'=>'01001000','street'=>'Praça da Sé','neighborhood'=>'Sé','city'=>'São Paulo','state'=>'SP'];});$r=$svc->lookup($actor,'cep','01001-000');
check(count($calls)===2&&$r['fields']['street']==='Praça da Sé'&&$r['fields']['district']==='Sé','CEP not found uses the next public service automatically');
resetLookup();$r=service(fn($url)=>str_contains($url,'viacep')?['cep'=>'01001-001','logradouro'=>'Wrong address','localidade'=>'São Paulo','uf'=>'SP']:['cep'=>'01001000','street'=>'Correct address','city'=>'São Paulo','state'=>'SP'])->lookup($actor,'cep','01001000');
check($r['fields']['street']==='Correct address'&&count($calls)===2,'response for another CEP is rejected before filling fields');
resetLookup();$r=service(fn($url)=>str_contains($url,'brasilapi')?array_replace(complete(),['cnpj'=>'00000000000191']):['razao_social'=>'Empresa correta','estabelecimento'=>['cnpj'=>'11222333000181','logradouro'=>'Rua correta','cidade'=>['nome'=>'Salvador'],'estado'=>['sigla'=>'BA'],'cep'=>'40020000','inscricoes_estaduais'=>[['ativo'=>false,'estado'=>['sigla'=>'BA'],'inscricao_estadual'=>'old'],['ativo'=>true,'estado'=>['sigla'=>'BA'],'inscricao_estadual'=>'active']]]])->lookup($actor,'cnpj','11222333000181');
check($r['fields']['legal_name']==='Empresa correta'&&$r['fields']['state_registration']==='active','foreign CNPJ rejected; active state registration matches the establishment UF');

resetLookup();$svc=service(fn()=>throw new Failure('http_503','Raw technical failure'));$r=$svc->lookup($actor,'cnpj','11222333000181');
check($r['fields']===[]&&str_contains($r['message'],'manualmente')&&!str_contains($r['message'],'503'),'total outage returns a simple message and allows manual registration');
$svc->lookup($actor,'cnpj','11222333000181');check(count($calls)===6&&$app->db->all('SELECT * FROM pme_lookup_cache')===[],'failed responses are not cached and a later retry is possible');
$svc->lookup($actor,'cnpj','11222333000181');$svc->lookup($actor,'cnpj','11222333000181');check(count($calls)===10,'public provider quota prevents repeated calls after three attempts in the minute');
resetLookup();$r=service(fn()=>['cnpj'=>'11222333000181','status'=>'OK'])->lookup($actor,'cnpj','11222333000181');check($r['fields']===[],'identifier-only responses are not reported as successful');
resetLookup();$r=service(fn()=>['razao_social'=>'Dados parciais'])->lookup($actor,'cnpj','11222333000181');$cache=$app->db->one('SELECT expires_at FROM pme_lookup_cache');check($r['fields']['legal_name']==='Dados parciais'&&$cache['expires_at']<=time()+300,'useful partial data is retained with a short cache lifetime');
resetLookup();$svc=service(fn()=>complete());foreach([['cnpj','11222333000180'],['cnpj','00000000000000'],['cep','123']] as [$type,$value]){try{$svc->lookup($actor,$type,$value);throw new RuntimeException('Invalid value accepted');}catch(Failure $e){check($e->reason===$type,'invalid '.$type.' rejected before provider request');}}
check($calls===[],'invalid identifiers make no external requests');
echo 'RESULT '.$n.' automatic lookup assertions passed'."\n";
