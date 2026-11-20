<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,GlobalSettings,Resources,ExecutionLog,ImpactPlans,Organizations};
use Multiempresa\Integration\{Cloudflare,CustomHostnames};
use Multiempresa\Infrastructure\HttpClient;
use Multiempresa\Domain\{Failure,Id};
$app=App::environment();$actor=$app->auth->syncNative(1);$ledger=new Resources($app);$n=0;
function dnscheck(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException('FAIL DNS '.$label);}$n++;echo 'PASS DNS '.$label."\n";}
function dnsdeny(callable $fn,string $reason):Failure{try{$fn();}catch(Failure $e){dnscheck($e->reason===$reason,$reason);return $e;}throw new RuntimeException('Expected '.$reason);}
$cfg=array_replace(GlobalSettings::defaults()['dns'],['enabled'=>true,'token'=>'  Bearer private-dns-test  ','zone_id'=>str_repeat('a',32),'base_domain'=>'crm.example.com','zone_name'=>'example.com','type'=>'A','target'=>'198.51.100.9']);
$records=[];$certs=[];$calls=[];$blocked=false;$sslActive=false;
$http=new HttpClient(function($method,$url,$headers,$body)use(&$records,&$certs,&$calls,&$blocked,&$sslActive){
    $calls[]=[$method,$url,$body];dnscheck($headers['Authorization']==='Bearer private-dns-test','normalized token sent only in Authorization');
    if($blocked){throw new Failure('http_403','Upstream',503,['http_status'=>403,'provider_codes'=>[10000]]);}
    $path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);$id=basename($path);
    if(str_contains($path,'/custom_hostnames')){
        if($method==='GET'&&$id==='custom_hostnames'){return ['success'=>true,'result'=>array_values(array_filter($certs,fn($r)=>$r['hostname']===($q['hostname']??'')))];}
        if($method==='POST'){$id='11111111-2222-4333-8444-'.str_pad((string)(count($certs)+1),12,'0',STR_PAD_LEFT);$certs[$id]=$body+['id'=>$id,'status'=>'active'];$certs[$id]['ssl']['status']='pending_validation';}
        if($method==='PATCH'){$certs[$id]=array_replace($certs[$id],$body);}
        if($method==='DELETE'){unset($certs[$id]);return ['success'=>true,'result'=>['id'=>$id]];}
        if(!isset($certs[$id])){throw new Failure('http_404','Missing',503,['http_status'=>404]);}
        $r=$certs[$id];if($sslActive){$r['ssl']['status']='active';}return ['success'=>true,'result'=>$r];
    }
    if(!str_contains($path,'/dns_records')){return ['success'=>true,'result'=>['id'=>str_repeat('a',32),'name'=>'example.com']];}
    if($method==='GET'){return ['success'=>true,'result'=>array_values(array_filter($records,fn($r)=>$r['name']===($q['name']??'')))];}
    if($method==='POST'){$id=Id::new();$records[$id]=$body+['id'=>$id];}
    if($method==='PATCH'){$records[$id]=$body+['id'=>$id];}
    if($method==='DELETE'){unset($records[$id]);return ['success'=>true,'result'=>['id'=>$id]];}
    return ['success'=>true,'result'=>$records[$id]];
});$cf=new Cloudflare($app,$http);$org=$app->catalog->createOrganization($actor,'DNS lifecycle','dns-lifecycle');
foreach(['A'=>'198.51.100.10','AAAA'=>'2001:db8::10','CNAME'=>'origin.example.com'] as $type=>$target){$data=$cf->ensure(array_replace($cfg,['type'=>$type]),$org,null,'test.crm.example.com',$target);dnscheck($data['type']===$type&&$data['target']===$target,'native payload '.$type);}
dnscheck(count($records)===1,'changing type updates the owned record instead of duplicating');
$other=$app->catalog->createOrganization($actor,'DNS other','dns-other');dnsdeny(fn()=>$cf->ensure($cfg,$other,null,'test.crm.example.com','198.51.100.1'),'resource_owner');
$e=dnsdeny(fn()=>$cf->api(array_replace($cfg,['api_base_url'=>'https://evil.example.com']),'GET',''),'cloudflare_endpoint');
$blocked=true;$e=dnsdeny(fn()=>$cf->ensure($cfg,$org,null,'blocked.crm.example.com','198.51.100.2'),'http_403');dnscheck(str_contains($e->getMessage(),'10000')&&!str_contains(json_encode($e->diagnostic),'private-dns-test'),'403 preserves provider code without credentials');
$r=$ledger->find('dns:'.$cfg['zone_id'].':blocked.crm.example.com');$before=count($calls);$cf->remove($cfg,$r);dnscheck(count($calls)===$before&&$ledger->find($r['resource_key'])['status']==='deleted','failed initial GET leaves a removable reservation without provider access');
// Old versions did not record whether a write was attempted. Recover only with exact logged evidence.
$key='dns:'.$cfg['zone_id'].':legacy.crm.example.com';$r=$ledger->reserve($org,null,'dns',$key,['host'=>'legacy.crm.example.com','zone_id'=>$cfg['zone_id'],'owner'=>'test']);$execution=Id::new();$log=new ExecutionLog($app);
$log->record('impact.confirmed','running',$actor['id'],null,$org,$execution,['operation'=>'dns','impact'=>['dns_records'=>[['name'=>'legacy.crm.example.com']]]]);
$log->record('impact.failed','failed',$actor['id'],null,$org,$execution,['reason'=>'http_403','trace'=>[['class'=>Cloudflare::class,'function'=>'records']]]);
$before=count($calls);$cf->remove($cfg,$r);dnscheck(count($calls)===$before,'legacy failed GET reservation is released using logged proof');
$blocked=false;$r=$ledger->find('dns:'.$cfg['zone_id'].':test.crm.example.com');$cf->remove(array_replace($cfg,['zone_id'=>str_repeat('b',32)]),$r);dnscheck(str_contains($calls[count($calls)-1][1],$cfg['zone_id']),'removal uses the resource zone after global zone change');
$wild=array_replace($cfg,['managed_wildcard_dns'=>true,'managed_wildcard_target'=>'198.51.100.8']);$before=count(array_filter($calls,fn($c)=>$c[0]==='POST'));
$cf->ensure($wild,$org,null,'one.crm.example.com',$cfg['target']);$cf->ensure($wild,$other,null,'two.crm.example.com',$cfg['target']);
dnscheck(count(array_filter($calls,fn($c)=>$c[0]==='POST'))===$before+1,'wildcard shared across organizations creates only one record');
dnscheck(count($ledger->list(Cloudflare::SHARED))===1,'wildcard belongs to installation rather than deletable organization');
$before=count($calls);$count=(int)$app->db->one('SELECT COUNT(*) AS n FROM pme_resources')['n'];$cf->ensure(array_replace($cfg,['dry_run'=>true]),$org,null,'dry.crm.example.com',$cfg['target']);dnscheck(count($calls)===$before&&(int)$app->db->one('SELECT COUNT(*) AS n FROM pme_resources')['n']===$count,'dry run makes no calls or resource reservations');
$tls=new CustomHostnames($app,$cf);$sslCfg=array_replace($cfg,['custom_hostname_enabled'=>true,'custom_hostname_origin'=>'origin.example.com']);$result=$tls->ensure($sslCfg,$org,null,'ssl.crm.example.com');dnscheck(!$result['ready'],'active hostname with pending SSL is not ready');
$posts=array_values(array_filter($calls,fn($c)=>$c[0]==='POST'&&str_ends_with($c[1],'/custom_hostnames')));dnscheck(!isset($posts[0][2]['ssl']['certificate_authority'])&&$posts[0][2]['custom_origin_server']==='origin.example.com','default CA omitted and configured custom origin sent');
$sslActive=true;dnscheck($tls->ensure($sslCfg,$org,null,'ssl.crm.example.com')['ready'],'ready only after both hostname and certificate are active');
$tls->ensure(array_replace($sslCfg,['custom_hostname_ca'=>'lets_encrypt']),$org,null,'ssl.crm.example.com');dnscheck($calls[count($calls)-1][2]['ssl']['certificate_authority']==='lets_encrypt','explicit Lets Encrypt sent to provider without silently changing authority');
$r=$ledger->find('tls:'.$cfg['zone_id'].':ssl.crm.example.com');$certs[$r['data']['hostname_id']]['hostname']='foreign.example.com';dnsdeny(fn()=>$tls->remove($cfg,$r),'hostname_owner');$certs[$r['data']['hostname_id']]['hostname']=$r['data']['host'];$tls->remove($cfg,$r);dnscheck(!$certs,'owned TLS hostname removed');$tls->remove($cfg,$r);dnscheck(!$certs,'already missing TLS hostname removal is idempotent');
$decode=new ReflectionMethod(HttpClient::class,'decode');$e=dnsdeny(fn()=>$decode->invoke(null,403,'{"success":false,"errors":[{"code":10000,"message":"token private-dns-test"}]}'),'http_403');dnscheck($e->diagnostic['provider_codes']===[10000]&&!str_contains(json_encode($e->diagnostic),'private-dns-test'),'real HTTP decoder retains only numeric error codes');
$readiness=new \Multiempresa\Integration\DomainReadiness($app,new HttpClient(fn()=>['proof'=>$app->vault->mac('domain-ready:valid.example.com')]));
dnscheck($readiness->ready('valid.example.com'),'HTTPS proof accepts only the exact installation and hostname');dnscheck(!$readiness->ready('other.example.com'),'HTTPS proof rejects wrong hostname');
$unreachable=new \Multiempresa\Integration\DomainReadiness($app,new HttpClient(static fn()=>throw new Failure('http_transport','TLS unavailable')));dnscheck(!$unreachable->ready('valid.example.com'),'TLS failure keeps current address usable');
$sample=$app->catalog->bySlug('grupo','alfa');$sibling=$app->catalog->bySlug('grupo','beta');$scope=$sample['organization_id'];$oldConfig=$app->db->one('SELECT * FROM pme_organization_config WHERE organization_id=?',[$scope]);
$probeHosts=['blocked-probe.example.com','alpha-probe.example.com','beta-probe.example.com'];
$app->db->exec('INSERT INTO pme_organization_hosts(host,organization_id,verified) VALUES(?,?,0)',[$probeHosts[0],$scope]);
foreach([$sample,$sibling] as $i=>$company){$app->db->exec('INSERT INTO pme_hosts(host,company_id,verified) VALUES(?,?,0)',[$probeHosts[$i+1],$company['id']]);}
$config=['host'=>$probeHosts[0],'applied_dns'=>$cfg,'pending_company_hosts'=>[$sample['id']=>$probeHosts[1],$sibling['id']=>$probeHosts[2]]];unset($config['applied_dns']['token']);
$app->db->exec("INSERT INTO pme_organization_config(organization_id,data,status,updated_at) VALUES(?,?,'pending_domain',0) ON DUPLICATE KEY UPDATE data=VALUES(data),status=VALUES(status),updated_at=0",[$scope,json_encode($config)]);
$attempts=[];$rotating=new \Multiempresa\Integration\DomainReadiness($app,new HttpClient(function($method,$url)use($app,&$attempts,$probeHosts){$host=parse_url($url,PHP_URL_HOST);$attempts[]=$host;return ['proof'=>$host===$probeHosts[0]?'wrong':$app->vault->mac('domain-ready:'.$host)];}));
for($i=0;$i<3;$i++){$rotating->refreshPending(1);}
dnscheck($attempts===$probeHosts,'bounded HTTPS polling advances past an unavailable first host');
dnscheck((int)$app->db->one('SELECT verified FROM pme_hosts WHERE host=?',[$probeHosts[2]])['verified']===1,'later company becomes ready independently of the failed organization hostname');
$app->db->exec('DELETE FROM pme_organization_hosts WHERE host=?',[$probeHosts[0]]);foreach(array_slice($probeHosts,1) as $host){$app->db->exec('DELETE FROM pme_hosts WHERE host=?',[$host]);}
$app->db->exec('DELETE FROM pme_organization_config WHERE organization_id=?',[$scope]);if($oldConfig){$app->db->exec('INSERT INTO pme_organization_config(organization_id,data,status,updated_at) VALUES(?,?,?,?)',[$scope,$oldConfig['data'],$oldConfig['status'],$oldConfig['updated_at']]);}
$settings=new GlobalSettings($app);$original=$settings->get();$saved=$settings->save($actor,['dns'=>array_replace($cfg,['dry_run'=>true,'managed_wildcard_dns'=>true,'managed_wildcard_target'=>'198.51.100.9'])]);dnscheck($saved['dns']['dry_run']&&$saved['dns']['managed_wildcard_dns']&&!isset($saved['dns']['token']),'new settings saved encrypted with no token exposed');
$polled=count($attempts);$rotating->refreshPending();dnscheck(count($attempts)===$polled,'simulation also suspends automatic hostname promotion');
$before=$app->db->all('SELECT * FROM pme_organization_hosts');$result=(new Organizations($app))->provisionDns($actor,$org);dnscheck($result['simulated']&&$before===$app->db->all('SELECT * FROM pme_organization_hosts'),'simulation does not change active routing');
dnsdeny(fn()=>$settings->save($actor,['dns'=>['zone_name'=>'different.example.com']]),'dns_zone_name');

$settingsBefore=$app->db->one("SELECT value FROM pme_settings WHERE name='global_configuration'");
$preview=$settings->dnsForTest(['token'=>' Bearer preview-token ','zone_id'=>str_repeat('B',32),'zone_name'=>'Example.COM.','base_domain'=>'New.Example.COM.']);
dnscheck($preview['token']==='Bearer preview-token'&&$preview['zone_id']===str_repeat('b',32)&&$preview['zone_name']==='example.com'&&$preview['base_domain']==='new.example.com','connection test uses unsaved form values after normalization');
dnscheck($settings->dnsForTest(['token'=>'   '])['token']===$settings->get()['dns']['token'],'empty preview token retains the encrypted saved credential');
dnscheck($settingsBefore===$app->db->one("SELECT value FROM pme_settings WHERE name='global_configuration'"),'connection preview does not persist changed credentials or addresses');
dnsdeny(fn()=>$settings->dnsForTest(['token'=>['unexpected']]),'cloudflare_configuration');
foreach(['active','expired','unsupported'] as $tokenState){
    $checks=[];
    $diagnostic=new Cloudflare($app,new HttpClient(function($method,$url,$headers,$body)use(&$checks,$tokenState){
        $checks[]=[$method,$url,$headers,$body];
        if(str_ends_with($url,'/user/tokens/verify')&&$tokenState!=='unsupported'){return ['success'=>true,'result'=>['status'=>$tokenState]];}
        throw new Failure('http_403','Provider refusal',503,['http_status'=>403,'provider_codes'=>[10000]]);
    }));
    $e=dnsdeny(fn()=>$diagnostic->diagnose($cfg),'http_403');
    dnscheck(($e->diagnostic['credential_status']??'')===match($tokenState){'active'=>'active','expired'=>'inactive',default=>'unknown'},'DNS refusal reports supported token verification: '.$tokenState);
    dnscheck(count($checks)===2&&array_column($checks,0)===['GET','GET']&&$checks[1][2]['Authorization']==='Bearer private-dns-test'&&$checks[1][3]===null,'diagnostic only performs authenticated reads at official endpoints');
    dnscheck(!str_contains($e->getMessage().json_encode($e->diagnostic),'private-dns-test'),'diagnostic never returns the API token');
}
$settings->save($actor,$original);
// Clean mock resources before subsequent real-native tests; no real provider is ever contacted.
foreach([$org,$other,Cloudflare::SHARED] as $scope){foreach($ledger->list($scope) as $r){if($r['kind']==='dns'&&$r['status']!=='deleted'){$cf->remove($cfg,$r);}}}
foreach([$org,$other] as $scope){$plan=(new ImpactPlans($app))->create($actor,'delete_organization',$scope,['delete_dns'=>true]);(new ImpactPlans($app))->confirm($actor,$plan['plan_id'],$plan['confirmation_name'],true);dnscheck(!$app->db->one('SELECT id FROM pme_organizations WHERE id=?',[$scope]),'physical organization deletion after failed DNS is usable');}
$app->db->exec('DELETE FROM pme_resources WHERE organization_id=?',[Cloudflare::SHARED]);
echo 'RESULT '.$n." DNS lifecycle assertions passed (mock provider; no external mutations)\n";
