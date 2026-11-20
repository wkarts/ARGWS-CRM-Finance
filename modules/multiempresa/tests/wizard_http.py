"""Native HTTP acceptance of the 1.1 organization/company lifecycle in disposable MySQL."""
import json,secrets,urllib.parse,os,subprocess

def run(req,sql,DB,org,dbhost,port,dbuser,dbpassword,host,work):
 count=0
 def check(ok,label):
  nonlocal count
  assert ok,label
  count+=1;print('PASS wizard '+label,flush=True)
 def get(route,expected=200):
  status,body,_=req('/admin/multiempresa/'+route)
  assert status==expected,(route,status,body[:2200])
  return json.loads(body)
 def post(route,data,expected=200):
  csrf=get('companies')['csrf'];status,body,_=req('/admin/multiempresa/'+route,dict(data,**{csrf['name']:csrf['hash']}))
  assert status==expected,(route,status,body[:2500])
  return json.loads(body)
 def action(op,fields,expected=200):return post('company_action',dict({'admin_mode':'current'} if op in ['begin','check','draft','start_draft'] else {},**dict(fields,operation=op)),expected)
 def configure(database,**extra):
  data={'database':database};data.update(extra)
  return post('save_global_settings',{'configuration':json.dumps(data)})['data']
 def execute(w):
  for _ in range(30):
   if w['status'] not in ('pending','running'):break
   w=action('advance',{'id':w['id']})['data']
  return w
 def plan(op,target,**options):return post('impact_plan',dict(operation=op,target=target,**options))['data']
 def confirm(p,expected=200):return post('confirm_impact',{'plan_id':p['plan_id'],'confirmation':p['confirmation_name'],'accepted':'1'},expected)
 status,html,_=req('/admin/multiempresa');(work/'companies.html').write_text(html)
 check(status==200 and all(x in html for x in ['pme-grid-body','pme-wizard-form','pme-select-all','pme-unselect-all','profile[cnpj]','certificate_file','pme-impact']), 'complete wizard profile certificate bulk modules and impact review rendered')
 for page in ['organizations','settings','logs']:
  status,body,_=req('/admin/multiempresa?tab='+page);check(status==200 and 'pme-bootstrap' in body,'native '+page+' administration rendered')
 check(get('companies?search=%27%20OR%201%3D1')['data']['total']==0,'search is bound SQL data')
 check(get('companies?status=untrusted',400)['error']=='filter','invalid status rejected')
 global_settings=configure({'operation':'automatic','isolation':'database','host':dbhost,'port':int(port),'username':dbuser,'password':dbpassword,'database_pattern':DB+'_auto_{company}','user_host':'localhost'},dns={'base_domain':'example.test','target':'198.51.100.10','manual_confirmed':True})
 check('password' not in global_settings['database'],'global credentials never returned')
 check(post('test_global_database',{})['data']['connected'],'administrative connection verified')
 neworg=post('save_organization',{'name':'Organização Automática','slug':'automatica','active':'1'})['data'];oid=neworg['id']
 post('configure_installation',{'organization_id':oid})
 check(get('organizations?search=Autom%C3%A1tica')['data']['total']==1,'organization grid searches and paginates')
 fields=dict(request_key=secrets.token_hex(16),organization_id=oid,name='Primeira Empresa',slug='primeira',prefix='ignored_',language='english',timezone='UTC',**{'modules[]':['isolation_probe'],'activate_modules[]':['isolation_probe'],'modules_reviewed':'1','profile[cnpj]':'11.222.333/0001-81','profile[legal_name]':'Empresa Exemplo Ltda','profile[cep]':'01001-000','profile[street]':'Praça da Sé','profile[city]':'São Paulo','profile[state]':'SP'})
 check(action('begin',dict(fields,**{'modules[]':['multiempresa']}),400)['error']=='central_module','Multiempresa cannot enter a tenant even through direct API')
 check(action('begin',dict(fields,**{'profile[cnpj]':'11.222.333/0001-82'}),400)['error']=='cnpj','CNPJ check digits validated in backend')
 check(action('check',fields)['ok'],'automatic preflight succeeds without per-company administrative credentials')
 w=action('begin',fields)['data'];cid=w['company_id'];wid=w['id']
 check(w['company']['status']=='draft' and w['completed']==0,'registration is distinct from completed provisioning')
 check(action('begin',fields)['data']['id']==wid,'duplicate initial submit reuses same execution')
 check(action('begin',dict(fields,name='Alterada'),409)['error']=='request_conflict','reused request key rejects changed data')
 check(action('begin',dict(fields,company_id=cid),409)['error']=='impact_required','reprovision requires an impact plan')
 w=action('advance',{'id':wid})['data'];check(w['step_index']==1 and w['status']=='pending','database resources complete before schema stage')
 w=execute(w);check(w['status']=='done' and w['completed']==w['total'],'first company completes every mandatory stage: '+str(w.get('diagnosis')))
 check(action('advance',{'id':wid})['data']['status']=='done','completed initial execution cannot run twice')
 detail=get('company?id='+cid)['data'];dest=detail['connection']['database'];prefix=detail['table_prefix']
 check(prefix=='' and sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='"+dest+"' AND TABLE_NAME IN ('staff','clients','options','probe')")=='4','automatic exclusive database and module tables have no prefix')
 check(detail['profile']['cnpj']=='11222333000181' and detail['profile']['city']=='São Paulo','normalized company profile persisted')
 check(detail['connection']['username']!=dbuser and 'password' not in detail['connection'],'tenant uses a separate application credential')
 check(sql("SELECT COUNT(*) FROM `"+dest+"`.`"+prefix+"modules` WHERE module_name='multiempresa'")=='0','Multiempresa never installed or activated in tenant')
 sql("INSERT INTO `"+dest+"`.`"+prefix+"clients`(company,datecreated,active) VALUES('PRESERVE',NOW(),1)")
 edited=action('save',dict(company_id=cid,name='Empresa Editada',language='portuguese_br',timezone='America/Sao_Paulo',status='active',**{'profile[trade_name]':'Nome fantasia manual'}))['data']['company']
 check(edited['profile']['trade_name']=='Nome fantasia manual','company registration can be edited after provisioning')
 check(sql('SELECT COUNT(*) FROM `'+dest+'`.`'+prefix+'clients`')=='1','editing preserves native data')
 p=plan('reprovision',cid)
 check(p['impact']['configuration_replaced'] and not p['impact']['delete_data'],'reprovision review declares reuse and configuration replacement')
 check(post('confirm_impact',{'plan_id':p['plan_id'],'confirmation':p['confirmation_name'],'accepted':'0'},400)['error']=='confirmation','explicit impact acceptance required')
 repro=confirm(p)['data'];repro=execute(repro)
 check(repro['status']=='done' and repro['id']!=wid,'confirmed reprovision runs a new tracked execution')
 check(sql('SELECT COUNT(*) FROM `'+dest+'`.`'+prefix+'clients`')=='1','reprovision preserves existing business data')
 check(confirm(p,409)['error']=='plan_expired','impact confirmation is single use')
 second=dict(fields,request_key=secrets.token_hex(16),name='Segunda Empresa',slug='segunda');second=execute(action('begin',second)['data']);check(second['status']=='done','second company in same organization provisioned')
 # Automatic shared database with exact per-table permissions and a native external module.
 shared=DB+'_beta';before=sql('SELECT COUNT(*) FROM `'+shared+'`.gama_clients')
 configure({'isolation':'prefix','shared_database':shared})
 shared_fields=dict(fields,request_key=secrets.token_hex(16),name='Empresa Wizard',slug='wizard',prefix='wiz_')
 sharedw=execute(action('begin',shared_fields)['data']);sharedid=sharedw['company_id'];check(sharedw['status']=='done','automatic prefix mode and module installation complete')
 check(sql('SELECT COUNT(*) FROM `'+shared+'`.gama_clients')==before,'other prefix data preserved')
 # Exercise the actual generated credential against a known neighboring table.
 probe="require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$app=\\Multiempresa\\Application\\App::environment();$c=$app->catalog->company(getenv('PROBE_COMPANY'));$pdo=$app->storage($c['mode'])->connect($app->vault->open($c['secrets'])['connection']);try{$pdo->query('SELECT COUNT(*) FROM gama_clients');exit(9);}catch(PDOException $e){exit(in_array($e->errorInfo[1]??0,[1142,1044],true)?0:8);}"
 result=subprocess.run([os.environ['MULTIEMPRESA_TEST_PHP'],'-r',probe],env=dict(os.environ,MULTIEMPRESA_HOST=str(host),PROBE_COMPANY=sharedid),capture_output=True,text=True)
 check(result.returncode==0,'generated prefix credential cannot query another company tables')
 # Manual dedicated base, credential limited to that base.
 manualdb=DB+'_manual';manualuser='t_'+secrets.token_hex(7);manualpass=secrets.token_hex(20)
 sql('CREATE DATABASE `'+manualdb+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
 sql("CREATE USER '"+manualuser+"'@'localhost' IDENTIFIED BY '"+manualpass+"'")
 sql("GRANT ALL PRIVILEGES ON `"+manualdb.replace("_",chr(92)+"_")+"`.* TO '"+manualuser+"'@'localhost'")
 configure({'operation':'manual','isolation':'database'})
 manual=dict(request_key=secrets.token_hex(16),organization_id=oid,name='Empresa Manual',slug='manual',host=dbhost,port=port,database=manualdb,username=manualuser,db_password=manualpass,language='english',timezone='UTC')
 check(action('check',dict(manual,username=dbuser,db_password=dbpassword),400)['error'] in ('database_privilege_scope','database_grant_option'),'manual root/global privileges rejected before changes')
 manualw=execute(action('begin',manual)['data']);check(manualw['status']=='done','manual existing database with scoped credentials provisioned')
 check(get('company?id='+manualw['company_id'])['data']['table_prefix']=='' and sql("SELECT COUNT(*) FROM `"+manualdb+"`.staff")=='1','manual exclusive database also has no prefix')
 # Manual shared base: all privileges are explicit table grants, never database-wide.
 manualprefixuser='p_'+secrets.token_hex(7);manualprefixpass=secrets.token_hex(20)
 sql("CREATE USER '"+manualprefixuser+"'@'localhost' IDENTIFIED BY '"+manualprefixpass+"'")
 sql("GRANT SELECT, CREATE ON `"+shared+"`.`manualp_clients` TO '"+manualprefixuser+"'@'localhost'")
 configure({'operation':'manual','isolation':'prefix'})
 manualprefix=dict(manual,request_key=secrets.token_hex(16),name='Manual Prefixo',slug='manual-prefixo',database=shared,prefix='manualp_',username=manualprefixuser,db_password=manualprefixpass)
 check(action('check',manualprefix,400)['error']=='database_permissions','insufficient manual permissions detected before DDL')
 core=json.loads((host/'modules/multiempresa/resources/core-defaults.json').read_text())['tables'];existing=set(sql('SHOW TABLES').splitlines());tables=[t for t in core if 'core_'+t in existing]+['pme_owner','pme_inbox','pme_bootstrap']
 sql(';'.join("GRANT ALL PRIVILEGES ON `"+shared+"`.`manualp_"+table+"` TO '"+manualprefixuser+"'@'localhost'" for table in tables))
 manualpw=execute(action('begin',manualprefix)['data']);check(manualpw['status']=='done','manual prefix isolation with scoped table grants provisioned')
 # Changed resources invalidate a prepared impact plan.
 stale=plan('reprovision',cid);post('save_organization',{'id':oid,'name':'Organização Automática Revisada','active':'1'})
 check(confirm(stale,409)['error']=='plan_changed','organization changes invalidate a prior impact confirmation')
 # Interrupt an execution after the durable reservation and confirm retry.
 configure({'operation':'automatic','isolation':'database'})
 retry=dict(fields,request_key=secrets.token_hex(16),name='Retomada',slug='retomada');rw=action('begin',retry)['data']
 sql("UPDATE core_multiempresa_onboardings SET status='running' WHERE id='"+rw['id']+"'")
 rw=action('advance',{'id':rw['id']})['data'];check(rw['status']=='interrupted' and rw['can_retry'],'abandoned execution is identified without confusing initial creation with reprovision')
 rp=plan('retry',rw['company_id']);rw=execute(confirm(rp)['data']);check(rw['status']=='done','confirmed retry resumes interrupted execution')
 # A requested certificate is a mandatory step, so missing A1 never reports success.
 certfields=dict(fields,request_key=secrets.token_hex(16),name='Certificado pendente',slug='certificado-pendente',certificate_required='1')
 certw=execute(action('begin',certfields)['data']);check(certw['status']=='failed' and certw['error_code']=='certificate_required','missing required A1 blocks success at certificate stage')
 # Upload PKCS#12 through the real multipart controller, then resume the held certificate step.
 pfx=work/'fixture-a1.pfx'
 code="$k=openssl_pkey_new(['private_key_bits'=>2048]);$r=openssl_csr_new(['commonName'=>'Empresa HTTP'],$k);$c=openssl_csr_sign($r,null,$k,30);openssl_pkcs12_export($c,$b,$k,'fixture-a1-password');file_put_contents(getenv('PFX_FIXTURE'),$b);"
 generated=subprocess.run([os.environ['MULTIEMPRESA_TEST_PHP'],'-r',code],env=dict(os.environ,PFX_FIXTURE=str(pfx)),capture_output=True,text=True);assert generated.returncode==0
 token=get('companies')['csrf'];boundary='multipart'+secrets.token_hex(16);body=bytearray()
 for name,value in {token['name']:token['hash'],'company_id':certw['company_id'],'certificate_password':'fixture-a1-password'}.items():body.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+value+'\r\n').encode())
 body.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="certificate"; filename="fixture.pfx"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+pfx.read_bytes()+('\r\n--'+boundary+'--\r\n').encode())
 status,response,_=req('/admin/multiempresa/certificate',bytes(body),{'Content-Type':'multipart/form-data; boundary='+boundary})
 check(status==200 and json.loads(response)['data']['holder']=='Empresa HTTP','A1 upload works through native multipart endpoint')
 certw=execute(confirm(plan('retry',certw['company_id']))['data']);check(certw['status']=='done','certificate correction resumes and completes mandatory stages')
 # Module exception followed by repaired source: completed stages are retained.
 badmodule=host/'modules/retry_probe';badmodule.mkdir();(badmodule/'retry_probe.php').write_text("<?php\n/*\nModule Name: Retry Probe\nVersion: 1.0.0\n*/\nregister_activation_hook('retry_probe',function(){throw new RuntimeException('fixture failure');});\n")
 broken=dict(fields,request_key=secrets.token_hex(16),name='Falha Corrigida',slug='falha-corrigida',**{'modules[]':['retry_probe'],'activate_modules[]':['retry_probe']});fw=execute(action('begin',broken)['data'])
 check(fw['status']=='failed' and fw['completed']>=3,'module failure does not report success and retains completed steps')
 (badmodule/'retry_probe.php').write_text("<?php\n/*\nModule Name: Retry Probe\nVersion: 1.0.0\n*/\nregister_activation_hook('retry_probe',function(){});\n")
 rp=plan('retry',fw['company_id']);fixed=execute(confirm(rp)['data']);check(fixed['status']=='done','failed module can resume after correction without recreating completed schema')
 check(get('logs?company_id='+fw['company_id']+'&status=failed')['data']['total']>0,'failure logs filter by company and status')
 logs=get('logs?execution_id='+fixed['id'])['data'];check(logs['total']>=2,'new execution logs identify resumed stages')
 status,download,_=req('/admin/multiempresa/download_logs?company_id='+fw['company_id']);check(status==200 and all(json.loads(line) for line in download.splitlines()),'filtered logs downloadable as JSONL')
 # Native selector renders only organization peers, then PKCE switch uses current session.
 # DNS API contracts are tested separately; native HTTP fixture uses the central path on loopback.
 sql("UPDATE core_multiempresa_organization_hosts SET verified=0 WHERE organization_id='"+oid+"'")
 entry='/'+sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id=(SELECT id FROM core_multiempresa_companies WHERE slug='primeira')")+'/'
 status,body,where=req(entry+'__pme/start');(work/'wizard-sso.html').write_text(body);assert status==200,(status,where,body[:1600]);check(status==200 and 'pme-company' in body and 'Empresa Editada' in body,'persistent selector shows active company')
 check('Empresa Alfa' not in body,'selector hides explicitly authorized companies of another organization')
 import re
 csrf=re.search(r'name="csrf" value="([^"]+)"',body)[1]
 status,body,where=req(entry+'__pme/switch',{'csrf':csrf,'company_id':second['company_id']})
 check(status==200 and ('/'+sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id='"+second['company_id']+"'")+'/') in where and 'Segunda Empresa' in body,'same-organization switch completes without another login')
 # Native central session remains available; editing is independent from switching.
 status,body,where=req('/admin/multiempresa');check(status==200,'central session remains available')
 d=plan('delete_company',sharedid,delete_data='1',delete_files='1',delete_dns='1')
 check(d['impact']['companies'][0]['tables'] and d['impact']['delete_data'],'deletion plan inventories exact tables')
 confirm(d);check(req('/admin/multiempresa/company?id='+sharedid)[0]==404,'confirmed deletion physically removes company')
 check(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='"+shared+"' AND TABLE_NAME LIKE 'wiz\\_%'")=='0','prefix deletion removes only confirmed company tables')
 check(sql('SELECT COUNT(*) FROM `'+shared+'`.gama_clients')==before,'prefix deletion preserves other companies')
 post('configure_installation',{'organization_id':org})
 code=r"require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$a=\Multiempresa\Application\App::environment();$u=$a->auth->syncNative(1);foreach(['alfa','beta'] as $slug){$c=$a->catalog->bySlug('grupo',$slug);$a->catalog->grant($u,$c['id'],$u['id'],1);}"
 restored=subprocess.run([os.environ['MULTIEMPRESA_TEST_PHP'],'-r',code],env=dict(os.environ,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True);assert restored.returncode==0,restored.stdout+restored.stderr
 d=plan('delete_organization',oid,delete_data='1',delete_files='1',delete_dns='1');check(len(d['impact']['companies'])>=4,'organization deletion explicitly includes linked companies')
 confirm(d);check(req('/admin/multiempresa/organization?id='+oid)[0]==404,'organization deletion physically removes linked companies and registration')
 check(sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='"+manualdb+"'")=='1','manual database preserved after deleting owned tables')
 check(sql('SELECT COUNT(*) FROM `'+DB+'_beta`.gama_clients')==before,'organization deletion preserves unrelated organizations')
 check(get('logs?organization_id='+oid)['data']['total']==0,'physical deletion also purges organization history')
 # Regression fixture expected by removal.php.
 (work/'removed-company.json').write_text(json.dumps({'id':sharedid,'organization_id':org,'name':'Removed','slug':'wizard'}))
 print('RESULT '+str(count)+' wizard HTTP assertions passed',flush=True)
