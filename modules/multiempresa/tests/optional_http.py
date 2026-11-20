"""Regression: no DNS, no PATH_INFO, registration without infrastructure and native UI."""
import json,secrets,re,urllib.parse,os,subprocess
from pathlib import Path

def run(req,sql,DB,dbhost,port,dbuser,dbpassword,host,work):
 n=0
 def check(ok,label):
  nonlocal n
  assert ok,label
  n+=1;print('PASS optional '+label,flush=True)
 def get(r):
  s,b,_=req('/admin/multiempresa/'+r);assert s==200,(r,s,b[:1200]);return json.loads(b)
 def post(r,d,expected=200):
  c=get('companies')['csrf'];s,b,_=req('/admin/multiempresa/'+r,dict(d,**{c['name']:c['hash']}));assert s==expected,(r,s,b[:1800]);return json.loads(b)
 def action(op,d,expected=200):return post('company_action',dict({'admin_mode':'current'} if op in ['begin','check','draft','start_draft'] else {},**dict(d,operation=op)),expected)['data']
 def config(c):return post('save_global_settings',{'configuration':json.dumps(c)})['data']
 def execute(w):
  for _ in range(20):
   if w['status'] not in ['pending','running']:break
   w=action('advance',{'id':w['id']})
  assert w['status']=='done',w
  return w
 def plan(op,target,**options):return post('impact_plan',dict(operation=op,target=target,**options))['data']
 def confirm(p):return post('confirm_impact',dict(plan_id=p['plan_id'],confirmation=p['confirmation_name'],accepted='1'))['data']
 s,b,_=req('/modules/multiempresa/tenant.php/__health');check(s==404,'server actually rejects PATH_INFO')
 s,b,_=req('/modules/multiempresa/tenant.php?__pme_route=/__health');check(s==200 and json.loads(b)['ready'],'direct entry health works without server changes')
 settings=config({'database':{'operation':'manual','isolation':'database'},'dns':{'enabled':False,'manual_confirmed':False,'base_domain':'','target':''}})
 check('lookups' not in get('global_settings')['data'],'lookup parameters are absent from global settings')
 lookup_seed=r"require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$a=\Multiempresa\Application\App::environment();$l=new \Multiempresa\Application\Lookups($a,new \Multiempresa\Infrastructure\HttpClient(static fn($m,$url)=>str_contains($url,'/ws/')?['cep'=>'01001-000','logradouro'=>'Praça da Sé','localidade'=>'São Paulo','uf'=>'SP']:['cnpj'=>'11222333000181','razao_social'=>'Empresa pública teste','logradouro'=>'Praça da Sé','municipio'=>'São Paulo','uf'=>'SP','cep'=>'01001000']));$l->lookup($a->auth->syncNative(1),'cep','01001000');$l->lookup($a->auth->syncNative(1),'cnpj','11222333000181');"
 seeded=subprocess.run([os.environ['MULTIEMPRESA_TEST_PHP'],'-r',lookup_seed],env=dict(os.environ,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True);assert seeded.returncode==0,seeded.stdout+seeded.stderr
 cep=get('lookup?type=cep&value=01001000')['data'];check(cep['fields']['city']=='São Paulo' and 'providers' not in cep,'native CEP endpoint returns internal lookup data without provider parameters')
 cnpj=get('lookup?type=cnpj&value=11222333000181')['data'];check(cnpj['fields']['legal_name']=='Empresa pública teste','native CNPJ endpoint fills company data without configuration')
 code,body,_=req('/admin/multiempresa/lookup?type=cnpj&value=11222333000180');check(code==400 and json.loads(body)['error']=='cnpj','invalid CNPJ rejected through the native endpoint')
 code,body,_=req('/admin/multiempresa?tab=settings');check(code==200 and 'name="lookups[' not in body and 'custom_mapping' not in body,'native settings page contains no technical lookup controls')

 check(not settings['dns']['enabled'] and not settings['dns']['manual_confirmed'],'all DNS integrations can be disabled')
 org=post('save_organization',{'name':'Organização sem integrações','slug':'opcional','active':'1'})['data'];oid=org['id']
 original_org=get('installation')['data']['organization_id']
 post('configure_installation',{'organization_id':oid})
 fields=dict(request_key=secrets.token_hex(16),organization_id=oid,name='Cadastro sem ambiente',slug='cadastro',language='english',timezone='UTC')
 d=action('draft',fields);cid=d['company_id'];check(d['status']=='draft' and d['total']==0,'save registration without database credentials or DNS')
 check(action('draft',fields)['company_id']==cid,'duplicate save request reuses the same registration')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_resources WHERE company_id='"+cid+"'")=='0','draft does not allocate external resources')
 check(action('advance',{'id':d['id']})['status']=='draft','advance cannot provision a draft implicitly')
 action('save',dict(company_id=cid,name='Cadastro editado',language='english',timezone='UTC',**{'profile[city]':'Salvador'}))
 check(get('company?id='+cid)['data']['profile']['city']=='Salvador','draft registration remains editable')
 config({'database':{'operation':'automatic','isolation':'database','host':dbhost,'port':int(port),'username':dbuser,'password':dbpassword,'database_pattern':DB+'_optional_{company}','user_host':'localhost'}})
 start=dict(fields,company_id=cid,request_key=secrets.token_hex(16),name='Cadastro editado')
 check(action('check',start)['database_operation']=='automatic','draft connection can be checked before provisioning')
 w=execute(action('start_draft',start));check(w['company_id']==cid,'later initial provisioning uses the same draft record')
 check(get('company?id='+cid)['data']['profile']['city']=='Salvador','starting a draft preserves the manually edited profile')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_organization_hosts WHERE organization_id='"+oid+"'")=='0','no organization DNS record is required or created')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_hosts WHERE company_id='"+cid+"'")=='0','no company DNS record is required or created')
 check(any('endereço atual' in s.get('result','') for s in w['steps']),'completion explains that current application address is used')
 check(action('start_draft',start)['id']==w['id'],'initial provisioning request is idempotent after completion')
 post('company_action',dict(start,operation='start_draft',name='Changed replay'),409)
 check(True,'changed draft provisioning replay is rejected')
 detail=get('company?id='+cid)['data'];address=detail['address'];route=urllib.parse.urlsplit(address).path+('?' + urllib.parse.urlsplit(address).query if urllib.parse.urlsplit(address).query else '')
 s,b,u=req(route+'__pme/start');check(s==200 and 'pme-company' in b,'native SSO and selector work on generated entry')
 css=re.findall(r'<link[^>]+href="([^"]+\.css[^\"]*)"',b)
 check(css and all('__pme_route' not in x for x in css),'native public styles retain physical URLs for fonts and images')
 s,b,u=req(route+'admin/clients?group=active&search=manual');check(s==200,'native routes preserve concatenated query parameters')
 # Simulates an execution left by 1.1.0 at the exact diagnostic sent by the customer.
 fields2=dict(fields,request_key=secrets.token_hex(16),name='Retomar sem DNS',slug='retomar')
 failed=action('begin',fields2);fid=failed['company_id']
 sql("UPDATE core_multiempresa_onboardings SET status='failed',error_code='dns_configuration' WHERE id='"+failed['id']+"'")
 sql("UPDATE core_multiempresa_companies SET status='failed',error_code='dns_configuration' WHERE id='"+fid+"'")
 p=plan('retry',fid);check(p['impact']['dns_records']==[],'retry impact has no DNS changes when disabled')
 resumed=execute(confirm(p));check(resumed['status']=='done','legacy dns_configuration failure can be resumed without DNS')
 d2=action('draft',dict(fields,request_key=secrets.token_hex(16),name='Cadastro a remover',slug='apagar'))
 p=plan('delete_company',d2['company_id'],delete_data='1',delete_files='1',delete_dns='1');confirm(p)
 check(req('/admin/multiempresa/company?id='+d2['company_id'])[0]==404,'draft removal does not require a database connection')
 # Change the global isolation after registration; only unprovisioned drafts may change destination.
 planned=action('draft',dict(fields,request_key=secrets.token_hex(16),name='Rascunho flexível',slug='flexivel',**{'modules[]':['isolation_probe']}))
 check(planned['status']=='draft','planned modules do not require activation consent while only saving')
 config({'database':{'operation':'automatic','isolation':'prefix','shared_database':DB+'_beta'}})
 changed=execute(action('start_draft',dict(fields,request_key=secrets.token_hex(16),company_id=planned['company_id'],name='Rascunho flexível',prefix='flex_',modules_reviewed='1',**{'modules[]':['isolation_probe']})))
 cd=get('company?id='+planned['company_id'])['data']
 check(cd['mode']=='prefix' and cd['table_prefix']=='flex_','unprovisioned draft applies revised isolation and prefix')
 check(sql('SELECT COUNT(*) FROM `'+DB+'_beta`.flex_modules WHERE module_name="multiempresa"')=='0','draft module selection still excludes central module')
 current=get('global_settings')['data']['runtime'];settings=config({'runtime':{'php_binary':current['php_binary'],'trusted_proxies':[]}})
 check(get('global_settings')['data']['runtime']['php_available'],'runtime configuration saves and validates through the module interface')
 post('save_global_settings',{'configuration':json.dumps({'runtime':{'php_binary':'/missing/php','trusted_proxies':[]}})},400)
 check(get('global_settings')['data']['runtime']['php_available'],'invalid runtime choice preserves the working executable')
 s,b,_=req('/admin/multiempresa');check(s==200 and '<dialog' not in b and 'modal-dialog modal-lg' in b and 'pme-navigation' in b,'wizard and editor inherit native Bootstrap modal and navigation')
 check('Salvar cadastro' in b and 'pme-save-draft' in b,'UI offers registration separately from provisioning')
 for tab in ['access','modules','integrations','events','updates','audit']:
  s,b,_=req('/admin/multiempresa?tab='+tab);check(s==200 and 'pme-navigation' in b,'native navigation is consistent in '+tab)
 s,b,_=req('/admin/multiempresa?tab=modules');check('Nome da pasta do módulo' not in b and 'pme-edit-modules' in b and 'Módulos por empresa' in b,'module configuration uses installed module choices')
 check(get('logs?company_id='+cid)['data']['rows'][0]['company_name']=='Cadastro editado','logs display company names along with technical identifiers')
 post('configure_installation',{'organization_id':original_org})
 code=r"require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$a=\Multiempresa\Application\App::environment();$u=$a->auth->syncNative(1);foreach(['alfa','beta'] as $slug){$c=$a->catalog->bySlug('grupo',$slug);$a->catalog->grant($u,$c['id'],$u['id'],1);}"
 restored=subprocess.run([os.environ['MULTIEMPRESA_TEST_PHP'],'-r',code],env=dict(os.environ,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True);assert restored.returncode==0,restored.stdout+restored.stderr
 p=plan('delete_organization',oid,delete_data='1',delete_files='1',delete_dns='1');confirm(p)
 check(req('/admin/multiempresa/organization?id='+oid)[0]==404,'organization without DNS can be fully removed')
 check(sql("SELECT COUNT(*) FROM `"+DB+"_alfa`.emp_clients WHERE company='Exclusivo Alfa'")=='1','optional lifecycle preserves unrelated organization data')
 print('RESULT '+str(n)+' optional HTTP assertions passed',flush=True)
