<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('MULTIEMPRESA_TEST_DISPOSABLE')!=='1'){exit(1);}
require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';
use Multiempresa\Application\{App,CompanyProfile,Certificates,GlobalSettings,Lookups,Resources,ExecutionLog,CompanySwitch,ImpactPlans};
use Multiempresa\Infrastructure\HttpClient;
use Multiempresa\Integration\Cloudflare;
use Multiempresa\Domain\{Failure,Id};
$app=App::environment();$actor=$app->auth->syncNative(1);$a=$app->catalog->bySlug('grupo','alfa');$b=$app->catalog->bySlug('grupo','beta');$n=0;
function feature(bool $ok,string $label):void{global $n;if(!$ok){throw new RuntimeException('FAIL '.$label);}$n++;echo 'PASS feature '.$label."\n";}
function refuses(callable $f,string $reason):void{try{$f();}catch(Failure $e){feature($e->reason===$reason,$reason);return;}throw new RuntimeException('Expected '.$reason);}
feature(CompanyProfile::cnpj('11.222.333/0001-81')==='11222333000181','numeric CNPJ checksum and normalization');
$base='12ABC34501DE';foreach([[5,4,3,2,9,8,7,6,5,4,3,2],[6,5,4,3,2,9,8,7,6,5,4,3,2]] as $weights){$sum=0;foreach($weights as $i=>$w){$sum+=(ord($base[$i])-48)*$w;}$r=$sum%11;$base.=($r<2?0:11-$r);}
feature(CompanyProfile::cnpj($base)===$base,'alphanumeric CNPJ accepts the documented ASCII checksum');
refuses(fn()=>CompanyProfile::cnpj('11.222.333/0001-80'),'cnpj');
refuses(fn()=>CompanyProfile::cnpj('00.000.000/0000-00'),'cnpj');
refuses(fn()=>CompanyProfile::normalize(['cep'=>'123','state'=>'XX']),'cep');
refuses(fn()=>HttpClient::validateUrl('http://localhost/secret'),'http_url');refuses(fn()=>HttpClient::validateUrl('https://127.0.0.1/secret'),'http_url');

$records=[];$calls=[];$transport=new HttpClient(function($method,$url,$headers,$body)use(&$records,&$calls){
    $calls[]=$method;feature(($headers['Authorization']??'')==='Bearer cf-private','Cloudflare token transmitted only in authorization header');
    $path=parse_url($url,PHP_URL_PATH);parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);$id=basename($path);
    if($method==='GET'){return ['success'=>true,'result'=>array_values(array_filter($records,static fn($r)=>$r['name']===($q['name']??'')))];}
    if($method==='POST'){$id=Id::new();$records[$id]=$body+['id'=>$id];return ['success'=>true,'result'=>$records[$id]];}
    if($method==='PATCH'){$records[$id]=array_replace($records[$id],$body);return ['success'=>true,'result'=>$records[$id]];}
    if($method==='DELETE'){unset($records[$id]);return ['success'=>true,'result'=>['id'=>$id]];}
    throw new RuntimeException('Unexpected mock method');
});
$cf=new Cloudflare($app,$transport);$cfg=['zone_id'=>str_repeat('a',32),'token'=>'cf-private','type'=>'A','ttl'=>1,'proxied'=>false];$hostname='alfa.example.com';
$one=$cf->ensure($cfg,$a['organization_id'],$a['id'],$hostname,'198.51.100.10',$actor['id']);$two=$cf->ensure($cfg,$a['organization_id'],$a['id'],$hostname,'198.51.100.11',$actor['id']);
feature(count($records)===1&&$one['record_id']===$two['record_id']&&$two['target']==='198.51.100.11','DNS create then update reuses owned record ID');
refuses(fn()=>$cf->ensure($cfg,$b['organization_id'],$b['id'],$hostname,'198.51.100.12'),'resource_owner');
$txt=Id::new();$records[$txt]=['id'=>$txt,'name'=>$hostname,'comment'=>'external TXT','type'=>'TXT','content'=>'unrelated'];$cf->ensure($cfg,$a['organization_id'],$a['id'],$hostname,'198.51.100.11');
$foreign=Id::new();$records[$foreign]=['id'=>$foreign,'name'=>'external.example.com','comment'=>'belongs to another tool','type'=>'A','content'=>'198.51.100.9'];
refuses(fn()=>$cf->ensure($cfg,$a['organization_id'],$a['id'],'external.example.com','198.51.100.13'),'dns_owned_elsewhere');
$resource=(new Resources($app))->find('dns:'.$cfg['zone_id'].':'.$hostname);$records[$two['record_id']]['comment']='changed';refuses(fn()=>$cf->remove($cfg,$resource),'dns_owner');$records[$two['record_id']]['comment']=$resource['data']['owner'];$cf->remove($cfg,$resource,$actor['id']);
feature(!isset($records[$two['record_id']])&&isset($records[$foreign])&&isset($records[$txt]),'DNS deletion preserves unrelated records including TXT on same host');
feature((new Resources($app))->find($resource['resource_key'])['status']==='deleted','DNS resource lifecycle is durable');

$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$csr=openssl_csr_new(['commonName'=>'Empresa Teste:11222333000181','organizationName'=>'Empresa Teste'],$key,['digest_alg'=>'sha256']);$cert=openssl_csr_sign($csr,null,$key,30,['digest_alg'=>'sha256']);openssl_pkcs12_export($cert,$pfx,$key,'certificate-private');
$certificates=new Certificates($app);$meta=$certificates->import($actor,$a['id'],'empresa.pfx',$pfx,'certificate-private');feature(str_contains($meta['holder'],'Empresa Teste')&&$meta['valid_until']>time(),'A1 owner and validity extracted from PKCS12');
$sealed=$app->db->one('SELECT secret FROM pme_certificates WHERE company_id=?',[$a['id']])['secret'];feature(!str_contains($sealed,'certificate-private')&&!str_contains($sealed,base64_encode($pfx)),'certificate contents and password stored encrypted');
$read=$certificates->credentials($actor,$a['id']);feature($read['pkcs12']===$pfx&&$read['password']==='certificate-private','authorized backend integration accesses company certificate');
refuses(fn()=>$certificates->import($actor,$a['id'],'empresa.pfx',$pfx,'wrong'),'certificate_password');
refuses(fn()=>$certificates->import($actor,$a['id'],'empresa.txt',$pfx,'certificate-private'),'certificate_file');
$outsider=['id'=>Id::new(),'platform_admin'=>0];$app->db->exec('INSERT INTO pme_users(id,email,name,password_hash,created_at) VALUES(?,?,?,?,?)',[$outsider['id'],'outsider@example.test','Outside','native-only',time()]);refuses(fn()=>$certificates->credentials($outsider,$a['id']),'organization_scope');
$org2=$app->catalog->createOrganization($actor,'Outra organização','outra-org');$app->db->exec('INSERT INTO pme_user_organizations(user_id,organization_id) VALUES(?,?)',[$outsider['id'],$org2]);$orgCompany=Id::new();$app->db->exec('INSERT INTO pme_companies(id,organization_id,slug,name,mode,table_prefix,storage_identity,secrets,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$orgCompany,$org2,'other','Other','prefix','other_',hash('sha256','other-scope'),$a['secrets'],'active',time()]);
(new \Multiempresa\Application\StaffAccess($app))->savePreference($outsider['id'],['identity_company'=>$orgCompany,'principal_access'=>0]);refuses(fn()=>$app->catalog->assertUserOrganization($outsider['id'],$a['organization_id']),'organization_scope');
$app->db->exec("INSERT INTO pme_org_members(organization_id,user_id,role) VALUES(?,?,'admin')",[$org2,$outsider['id']]);refuses(fn()=>$certificates->metadata($a['id']) && $app->catalog->membership($outsider['id'],$a['id']),'organization_scope');

$s=$app->auth->createSession($actor,$a['id'],false);$session=$app->auth->session($s['token'],$a['id']);refuses(fn()=>(new CompanySwitch($app))->issue($session,$a['id'],$orgCompany),'organization_scope');

$logger=new ExecutionLog($app);$execution=Id::new();$logger->record('redaction.test','failed',$actor['id'],$a['id'],null,$execution,['password'=>'LEAK','nested'=>['Authorization'=>'LEAK','certificate'=>'LEAK','reason'=>'visible'],'sql_operations'=>['CREATE TABLE']]);
$logs=$logger->query($actor,['execution_id'=>$execution]);feature(!str_contains(json_encode($logs),'LEAK')&&str_contains(json_encode($logs),'visible'),'structured logs redact nested secrets while preserving diagnosis');
feature($logger->query($outsider,['company_id'=>$a['id']])['total']===0,'organization administrator cannot query another organization logs');
$error=ExecutionLog::exception(new PDOException('password=LEAK'));feature(!str_contains(json_encode($error),'LEAK'),'database errors omit raw SQL values and credentials');
refuses(fn()=>(new GlobalSettings($app))->save($outsider,['database'=>['username'=>'root']]),'platform_admin');
refuses(fn()=>(new ImpactPlans($app))->create($outsider,'delete_company',$a['id']),'organization_scope');
$app->db->exec("INSERT INTO pme_org_members(organization_id,user_id,role) VALUES(?,?,'admin')",[$a['organization_id'],$outsider['id']]);
feature(array_column((new \Multiempresa\Application\CompanyManager($app))->grid($outsider,[])['rows'],'id')===[$orgCompany],'legacy cross-organization membership cannot leak company grid');
feature((new \Multiempresa\Application\Organizations($app))->grid($outsider,[])['total']===1,'organization grid is restricted to assigned organization');
feature($logger->query($outsider,['company_id'=>$a['id']])['total']===0,'legacy organizational grant cannot bypass audit scope');
$app->db->exec('DELETE FROM pme_companies WHERE id=?',[$orgCompany]);
$settings=new GlobalSettings($app);$orgService=new \Multiempresa\Application\Organizations($app);$dns=$settings->get()['dns'];$saved=['configuration'=>['target'=>'198.51.100.2','applied_dns'=>array_replace($dns,['target'=>'198.51.100.1'])]];
feature($orgService->effectiveDns($saved,array_replace($dns,['target'=>'198.51.100.3']))['target']==='198.51.100.1','new company keeps previously applied organization destination');
feature($orgService->effectiveDns($saved,array_replace($dns,['target'=>'198.51.100.3']),true)['target']==='198.51.100.3','organization DNS plan can explicitly apply new destination');
// Release mock-only reservations before the later real HTTP uninstall lifecycle.
foreach((new Resources($app))->list($a['organization_id']) as $reserved){if($reserved['kind']==='dns'&&$reserved['status']!=='deleted'){$cf->remove($cfg,$reserved,$actor['id']);}}
echo 'RESULT '.$n." extended feature assertions passed (HTTP integrations use deterministic transports)\n";
