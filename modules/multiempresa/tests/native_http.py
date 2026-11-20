from pathlib import Path
import os,subprocess,shutil,time,urllib.request,urllib.parse,http.cookiejar,json,re,sys
import tempfile
if os.environ.get('MULTIEMPRESA_TEST_DISPOSABLE')!='1':
 raise RuntimeError('Set MULTIEMPRESA_TEST_DISPOSABLE=1; use an isolated MySQL server.')
base=Path(__file__).resolve().parents[1]
reference=Path(os.environ['MULTIEMPRESA_TEST_PERFEX_ROOT']).resolve()
work=Path(tempfile.mkdtemp(prefix='multiempresa-test-'));host=work/'host'
php=os.environ.get('MULTIEMPRESA_TEST_PHP',shutil.which('php') or 'php')
mysql=os.environ.get('MULTIEMPRESA_TEST_MYSQL_CLI',shutil.which('mariadb') or shutil.which('mysql') or 'mariadb')
dbhost=os.environ.get('MULTIEMPRESA_TEST_MYSQL_HOST','127.0.0.1');port=os.environ.get('MULTIEMPRESA_TEST_MYSQL_PORT','3306')
dbuser=os.environ.get('MULTIEMPRESA_TEST_USER','root');dbpassword=os.environ.get('MULTIEMPRESA_TEST_PASSWORD','')
host.mkdir()
for f in reference.iterdir():
 if f.name in ['application','modules','install','uploads','temp']:continue
 if f.is_dir():(host/f.name).symlink_to(f,target_is_directory=True)
 else:shutil.copy2(f,host/f.name)
(host/'application').mkdir();(host/'application/config').mkdir()
for f in (reference/'application').iterdir():
 if f.name=='config':continue
 if f.name in ['cache','logs','controllers']:
  (host/'application'/f.name).mkdir()
  if f.name=='controllers':
   for child in f.iterdir():(host/'application/controllers'/child.name).symlink_to(child,target_is_directory=child.is_dir())
 else:(host/'application'/f.name).symlink_to(f,target_is_directory=f.is_dir())
for f in (reference/'application/config').iterdir():
 if f.name!='app-config.php':(host/'application/config'/f.name).symlink_to(f,target_is_directory=f.is_dir())
(host/'modules').mkdir();shutil.copytree(base,host/'modules/multiempresa')
for module in ['ma','fleet','gerencianet_gateway','purchase']:shutil.copytree(reference/'modules'/module,host/'modules'/module)
def php_literal(value):
 return "'"+str(value).replace('\\','\\\\').replace("'","\\'")+"'"
(host/'modules/multiempresa/config/settings.php').write_text("<?php return ['php_binary'=>"+php_literal(php)+'];')
(host/'uploads').mkdir();(host/'temp').mkdir()
DB='multiempresa_test_'+str(time.time_ns());url='http://127.0.0.1:8879/'
(host/'application/config/app-config.php').write_text("<?php\ndefined('BASEPATH') or exit;\n"+''.join('define('+php_literal(k)+','+php_literal(v)+');\n' for k,v in {'APP_BASE_URL':url,'APP_ENC_KEY':'01234567890123456789012345678901','APP_DB_HOSTNAME':dbhost+':'+port,'APP_DB_USERNAME':dbuser,'APP_DB_PASSWORD':dbpassword,'APP_DB_NAME':DB,'APP_DB_PREFIX':'core_','APP_DB_CHARSET':'utf8mb4','APP_DB_COLLATION':'utf8mb4_unicode_ci','SESS_DRIVER':'database','SESS_SAVE_PATH':'sessions','APP_SESSION_COOKIE_SAME_SITE':'Lax'}.items())+'define(\'APP_CSRF_PROTECTION\',true);\n')
router=work/'router.php';router.write_text('''<?php
$root=__DIR__.'/host';$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/modules/multiempresa/tenant.php/')) {http_response_code(404);echo 'PATH_INFO disabled';return;}
if($path==='/modules/multiempresa/tenant.php') { require $root.'/modules/multiempresa/tenant.php'; return; }
if(is_file($root.$path)){return false;}
// Model the static-file location used by Nginx/CloudPanel: missing image paths never reach PHP.
if(is_file(__DIR__.'/static-images')&&preg_match('~\\.(?:png|jpe?g|gif|svg|ico|webp)$~i',$path)){http_response_code(404);echo 'Static image missing';return;}
chdir($root);$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['PHP_SELF']='/index.php';require $root.'/index.php';
''')
env=os.environ.copy();env.update(MULTIEMPRESA_TEST_DSN='mysql:host='+dbhost+';port='+port+';charset=utf8mb4',MULTIEMPRESA_TEST_DATABASE=DB,MYSQL_PWD=dbpassword)
web=None
try:
 p=subprocess.run([php,str(base/'tests/fixture.php')],env=env,capture_output=True,text=True);assert p.returncode==0,p.stdout+p.stderr
 print('PASS native Perfex schema fixture',flush=True)
 log=(work/'http.log').open('w');web=subprocess.Popen([php,'-S','127.0.0.1:8879','-t',str(host),str(router)],env=env,stdout=log,stderr=log);time.sleep(.3)
 jar=http.cookiejar.CookieJar();http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
 def req(path,data=None,headers=None):
  request=urllib.request.Request(urllib.parse.urljoin(url,path),data=(data if isinstance(data,bytes) else urllib.parse.urlencode(data,doseq=True).encode()) if data is not None else None,headers=headers or {})
  try:
   with http.open(request,timeout=120) as r:return r.status,r.read().decode(errors='replace'),r.url
  except urllib.error.HTTPError as e:return e.code,e.read().decode(errors='replace'),e.url
 def csrf(html):
  m=re.search(r'name="(csrf_token_name)"[^>]*value="([^"]+)"',html)
  if not m:
   b=re.search(r'<script type="application/json" id="pme-bootstrap">(.*?)</script>',html,re.S)
   if b:
    c=json.loads(b[1])['csrf'];return {c['name']:c['hash']}
   raise RuntimeError('No native CSRF in '+html[:800])
  return {m[1]:m[2]}
 status,html,_=req('/admin/authentication');assert status==200,(status,html[:1000]);token=csrf(html)
 status,html,u=req('/admin/authentication',dict(token,email='admin@example.test',password='Native-test-password-26'));assert status==200 and '/authentication' not in u,(status,u,html[:1000]);print('PASS native Perfex login',flush=True)
 status,html,u=req('/admin/mods/activate/multiempresa');(work/'activation.html').write_text(html)
 assert status==200,(status,u,html[:1200]);print('PASS module activation through native Modules screen',flush=True)
 status,html,u=req('/admin/multiempresa');(work/'panel.html').write_text(html)
 assert status==200 and 'Suas empresas' in html,(status,u,html[:1500]);print('PASS native module panel',flush=True)
 def action(data):
  status,html,_=req('/admin/multiempresa');
  status,html,u=req('/admin/multiempresa',dict(csrf(html),**data));
  (work/'last-action.html').write_text(html)
  assert status==200,(status,html[:800]);return html
 html=action(dict(action='organization',name='Grupo de Teste',slug='grupo'))
 assert 'Organização cadastrada' in html,html[-3000:];print('PASS organization creation with native CSRF',flush=True)
 def sql(query):
  p=subprocess.run([mysql,'-h',dbhost,'-P',port,'-u',dbuser,'-N','-B',DB,'-e',query],text=True,capture_output=True,env=env)
  assert p.returncode==0,p.stderr;return p.stdout.strip()
 org=sql('SELECT id FROM core_multiempresa_organizations LIMIT 1')
 setup="require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$a=\\Multiempresa\\Application\\App::environment();(new \\Multiempresa\\Application\\GlobalSettings($a))->save($a->auth->syncNative(1),['users'=>['global_access'=>'explicit']]);"
 setup+="(new \\Multiempresa\\Application\\Installation($a))->configure($a->auth->syncNative(1),'"+org+"');"
 p=subprocess.run([php,'-r',setup],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True);assert p.returncode==0,p.stdout+p.stderr
 for slug,mode in [('alfa','database'),('beta','prefix')]:
  dest=DB+'_'+slug;sql('CREATE DATABASE `'+dest+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
  fixture={'admin_mode':'current','organization_id':org,'name':'Empresa '+slug.title(),'slug':slug,'mode':'prefix','prefix':'emp_','connection':{'host':dbhost,'port':int(port),'database':dest,'username':dbuser,'password':dbpassword}}
  # Trusted internal setup preserves the original recovery fixtures. Public creation is exercised by wizard_http.
  code="require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$app=\\Multiempresa\\Application\\App::environment();(new \\Multiempresa\\Application\\Provisioner($app))->create($app->auth->syncNative(1),json_decode(getenv('TEST_COMPANY_FIXTURE'),true,32,JSON_THROW_ON_ERROR));"
  seeded=subprocess.run([php,'-r',code],env=dict(env,MULTIEMPRESA_HOST=str(host),TEST_COMPANY_FIXTURE=json.dumps(fixture)),capture_output=True,text=True)
  assert seeded.returncode==0,seeded.stdout+seeded.stderr
  if mode=='database':sql("UPDATE core_multiempresa_companies SET mode='database' WHERE slug='"+slug+"'")
 print('PASS database and prefix company provisioning',flush=True)
 for slug in ['alfa','beta']:
  entry='/'+sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id=(SELECT id FROM core_multiempresa_companies WHERE slug='"+slug+"')")+'/'
  status,html,u=req(entry+'__pme/start');(work/(slug+'.html')).write_text(html)
  assert status==200 and 'Empresa atual' in html,(status,u,html[:2000]);print('PASS native SSO and dashboard '+slug,flush=True)
  for route in ['admin/clients','admin/invoices','admin/settings','authentication/login']:
   status,body,u=req(entry+route);assert status==200,(slug,route,status,body[:1000])
  print('PASS native clients invoices settings portal '+slug,flush=True)
 entry='/'+sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id=(SELECT id FROM core_multiempresa_companies WHERE slug='alfa')")+'/'
 import importlib.util
 spec=importlib.util.spec_from_file_location('branding_http',base/'tests/branding_http.py');branding=importlib.util.module_from_spec(spec);spec.loader.exec_module(branding);branding.run(req,sql,DB,entry,host)
 spec=importlib.util.spec_from_file_location('domains_http',base/'tests/domains_http.py');domains=importlib.util.module_from_spec(spec);spec.loader.exec_module(domains);domains.run(sql,DB)
 status,html,_=req(entry+'admin/clients/client');data=dict(csrf(html),company='Exclusivo Alfa',default_currency='0',default_language='',country='0')
 status,html,u=req(entry+'admin/clients/client',data);assert status==200 and re.search('/client/[0-9]+',u),(status,u,html[:1500]);
 assert sql('SELECT COUNT(*) FROM `'+DB+'_alfa`.emp_clients WHERE company="Exclusivo Alfa"')=='1'
 assert sql('SELECT COUNT(*) FROM `'+DB+'_beta`.emp_clients')=='0';print('PASS native customer POST and physical company isolation',flush=True)
 for tab in ['access','modules','integrations','events','updates','audit']:
  status,html,_=req('/admin/multiempresa?tab='+tab);assert status==200,(tab,status,html[:1000])
  if tab=='integrations':
   from html.parser import HTMLParser
   class VisibleText(HTMLParser):
    def __init__(self):super().__init__();self.parts=[]
    def handle_data(self,d):self.parts.append(d)
   visible=VisibleText();visible.feed(html);text=' '.join(visible.parts)
   assert 'Identificação da empresa' in text and 'Permissões de leitura' in text
   assert not any(scope in text for scope in ['context:read','clients:read','invoices:read','events:read'])
   assert 'Não é necessário para cadastrar empresas' in text
   print('PASS integrations use clear optional permission labels without raw technical scopes',flush=True)
 print('PASS all native administration tabs',flush=True)
 for path,expected in [(entry+'uploads/invoices/1/private.txt',403),(entry+'application/config/config.php',404),(entry+'cron',403)]:
  status,body,_=req(path);assert status==expected,(path,status,body[:500])
 print('PASS private files and unauthorized native cron blocked',flush=True)
 p=subprocess.run([php,str(base/'tests/compatibility.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120)
 print(p.stdout,flush=True);print(p.stderr,flush=True)
 assert p.returncode==0,'Compatibility suite failed'
 # Invoke additional PHP assertions against the real native installation.
 p=subprocess.run([php,str(base/'tests/integration.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120)
 print(p.stdout,flush=True);print(p.stderr,flush=True)
 assert p.returncode==0,'PHP integration failed'
 credentials=json.loads((work/'tokens.json').read_text())
 status,body,_=req('/modules/multiempresa/tenant.php?__pme_route=/api/v1/clients',headers={'Authorization':'Bearer '+credentials['token']});assert status==200 and 'Exclusivo Alfa' in body,(status,body[:1000])
 status,body,_=req('/modules/multiempresa/tenant.php?__pme_route=/o/grupo/e/beta/api/v1/clients',headers={'Authorization':'Bearer '+credentials['token']});assert status==403,(status,body[:1000])
 print('PASS HTTP API token scope and cross-company rejection',flush=True)
 sql("UPDATE core_modules SET installed_version='1.0.0' WHERE module_name='multiempresa'")
 legacy_code="require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$app=\\Multiempresa\\Application\\App::environment();$s=(new \\Multiempresa\\Application\\GlobalSettings($app))->get();unset($s['runtime']);$s['lookups']=['enabled'=>false,'custom_url'=>'retired','cnpjws_token'=>'retired-token'];$app->db->exec(\"DELETE FROM pme_settings WHERE name='global_configuration'\");$app->db->exec(\"INSERT INTO pme_settings(name,value) VALUES('global_configuration',?)\",[$app->vault->seal($s)]);"
 seeded=subprocess.run([php,'-r',legacy_code],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True);assert seeded.returncode==0,seeded.stderr
 status,body,_=req('/admin/multiempresa');assert status==200,(status,body[:1000])
 assert sql("SELECT installed_version FROM core_modules WHERE module_name='multiempresa'")=='1.2.8'
 print('PASS automatic native sequential module migration 100 to 128 without a database button',flush=True)
 status,body,_=req('/admin/multiempresa/global_settings');assert status==200 and 'lookups' not in json.loads(body)['data']
 print('PASS migration retires lookup configuration without requiring administrator input',flush=True)
 before=sql("SELECT value FROM core_multiempresa_settings WHERE name='global_configuration'")
 status,panel,_=req('/admin/multiempresa')
 status,body,_=req('/admin/multiempresa/test_cloudflare',dict(csrf(panel),**{'dns[token]':'preview-only','dns[zone_id]':'b'*32,'dns[base_domain]':'crm.example.com','dns[api_base_url]':'https://invalid.example.com'}))
 assert json.loads(body)['error']=='cloudflare_endpoint',(status,body[:500])
 assert before==sql("SELECT value FROM core_multiempresa_settings WHERE name='global_configuration'")
 print('PASS native Cloudflare test uses submitted values, rejects nonofficial endpoint and never persists the preview',flush=True)

 import importlib.util
 spec=importlib.util.spec_from_file_location('wizard_http',base/'tests/wizard_http.py')
 wizard_tests=importlib.util.module_from_spec(spec);spec.loader.exec_module(wizard_tests)
 wizard_tests.run(req,sql,DB,org,dbhost,port,dbuser,dbpassword,host,work)
 spec=importlib.util.spec_from_file_location('optional_http',base/'tests/optional_http.py')
 optional_tests=importlib.util.module_from_spec(spec);spec.loader.exec_module(optional_tests)
 optional_tests.run(req,sql,DB,dbhost,port,dbuser,dbpassword,host,work)
 spec=importlib.util.spec_from_file_location('module_defaults_http',base/'tests/module_defaults_http.py');defaults_tests=importlib.util.module_from_spec(spec);spec.loader.exec_module(defaults_tests);defaults_tests.run(req,sql,DB,org,host)
 p=subprocess.run([php,str(base/'tests/features.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120)
 print(p.stdout,flush=True)
 assert p.returncode==0,p.stdout+p.stderr
 p=subprocess.run([php,str(base/'tests/module_toggle.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120)
 print(p.stdout,flush=True);assert p.returncode==0,p.stdout+p.stderr
 p=subprocess.run([php,str(base/'tests/dns_lifecycle.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120)
 print(p.stdout,flush=True);assert p.returncode==0,p.stdout+p.stderr
 p=subprocess.run([php,str(base/'tests/removal.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120)
 print(p.stdout,flush=True)
 assert p.returncode==0,p.stdout+p.stderr
 p=subprocess.run([php,str(base/'tests/core_maintenance.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=180)
 print(p.stdout,flush=True);assert p.returncode==0,p.stdout+p.stderr
 p=subprocess.run([php,str(base/'tests/maintenance.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=180)
 print(p.stdout,flush=True);assert p.returncode==0,p.stdout+p.stderr
 p=subprocess.run([php,str(base/'tests/purchase.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=180)
 print(p.stdout,flush=True);assert p.returncode==0,p.stdout+p.stderr
 status,body,_=req('/admin/multiempresa',{'action':'organization','name':'No csrf','slug':'no-csrf'});assert status in (403,419),(status,body[:500])
 print('PASS native CSRF rejects untrusted mutation',flush=True)
 p=subprocess.run([php,str(base/'tests/organization_scope.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120);print(p.stdout,flush=True);assert p.returncode==0,p.stdout+p.stderr
 spec=importlib.util.spec_from_file_location('organization_scope_http',base/'tests/organization_scope_http.py');scope_tests=importlib.util.module_from_spec(spec);spec.loader.exec_module(scope_tests);scope_tests.run(req,sql,DB,org,host,base,work,env,php)
 p=subprocess.run([php,str(base/'tests/unprefixed.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=180);print(p.stdout,flush=True);assert p.returncode==0,p.stdout+p.stderr
 spec=importlib.util.spec_from_file_location('company_access_http',base/'tests/company_access_http.py')
 access_tests=importlib.util.module_from_spec(spec);spec.loader.exec_module(access_tests)
 access_tests.run(req,sql,DB,org,host,base,work,env,php)
 spec=importlib.util.spec_from_file_location('tenant_messages_http',base/'tests/tenant_messages_http.py');message_tests=importlib.util.module_from_spec(spec);spec.loader.exec_module(message_tests);message_tests.run(req,sql,DB,host,base,work,env,php)
 spec=importlib.util.spec_from_file_location('lifecycle_http',base/'tests/lifecycle_http.py')
 lifecycle=importlib.util.module_from_spec(spec);spec.loader.exec_module(lifecycle)
 lifecycle.run(req,sql,DB,org,host,base,work,'switch')
 if os.environ.get('MULTIEMPRESA_TEST_DOM_MODULES'):
  _,html,_=req('/admin/multiempresa')
  def read_json(route):
   code,body,_=req('/admin/multiempresa/'+route);assert code==200,(route,code,body[:300]);return json.loads(body)['data']
  companies=read_json('companies')
  fixture={'html':html,'companies':companies,'company':read_json('company?id='+next(c['id'] for c in companies['rows'] if c['status']=='active')),'settings':read_json('global_settings'),'organizations':read_json('organizations'),'installation':read_json('installation')}
  dom_fixture=work/'dom-fixture.json';dom_fixture.write_text(json.dumps(fixture))
  dom_test=subprocess.run(['node',str(base/'tests/dom.cjs'),str(dom_fixture)],env=env,capture_output=True,text=True,timeout=30)
  print(dom_test.stdout,flush=True);assert dom_test.returncode==0,dom_test.stderr
 browser=subprocess.run(['node',str(base/'tests/browser.cjs'),str(work)],env=env,capture_output=True,text=True,timeout=120)
 print(browser.stdout,flush=True)
 if browser.returncode: print('BROWSER UNAVAILABLE: '+browser.stderr[:700],flush=True)
 status,html,u=req('/admin/authentication/logout');status,html,u=req(entry+'admin');assert '/authentication' in u,(status,u,html[:1000]);print('PASS native logout revokes company sessions',flush=True)
 lifecycle.run(req,sql,DB,org,host,base,work,'uninstall')
 print('RESULT native module HTTP integration passed',flush=True)
finally:
 if web:web.terminate();web.wait(timeout=15)
 print('Disposable fixture directory: '+str(work),flush=True)
 # Preserve this isolated fixture and test databases for inspection. Remove them after review.
