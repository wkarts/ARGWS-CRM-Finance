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

 p=subprocess.run([php,str(base/'tests/automatic_modules.php')],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=240)
 print(p.stdout,flush=True);assert p.returncode==0,p.stdout+p.stderr
 companies=json.loads((work/'auto-companies.json').read_text());cid=companies['exclusive'];key=sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id='"+cid+"'");entry='/'+key+'/'
 status,body,where=req(entry+'__pme/start');assert status==200,(status,body[:2000]);print('PASS HTTP first access completes core and module maintenance',flush=True)
 probe="require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$a=\\Multiempresa\\Application\\App::environment();$c=$a->catalog->company('"+cid+"');$p=$a->storage($c['mode'])->connect($a->vault->open($c['secrets'])['connection']);echo json_encode([$p->query(\"SELECT installed_version FROM modules WHERE module_name='auto_one'\")->fetchColumn(),$p->query('SELECT version FROM migrations')->fetchColumn(),$p->query('SELECT COUNT(*) FROM auto_one_data')->fetchColumn(),$p->query('SELECT activations FROM auto_one_data WHERE id=1')->fetchColumn()]);"
 r=subprocess.run([php,'-r',probe],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True);assert r.returncode==0,r.stdout+r.stderr;values=json.loads(r.stdout);assert values==['1.0.4',341,4,1],values;print('PASS HTTP native core migration precedes module migration without reinstalling',flush=True)
 source=host/'modules/auto_one';main=source/'auto_one.php';main.write_text(main.read_text().replace('Version: 1.0.4','Version: 1.0.5'))
 marker=work/'concurrent-migration-started'
 (source/'migrations/105_version_105.php').write_text("<?php class Migration_Version_105 extends App_module_migration {public function up(){file_put_contents("+php_literal(str(marker))+",'started');usleep(350000);get_instance()->db->query(\"INSERT INTO `\".db_prefix().\"auto_one_data` VALUES(5,'CONCURRENT',0,NULL,NULL)\");}}")
 code="require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$a=\\Multiempresa\\Application\\App::environment();(new \\Multiempresa\\Application\\ModuleMaintenance($a))->ensure('"+cid+"',true);"
 children=[subprocess.Popen([php,'-r',code],env=dict(env,MULTIEMPRESA_HOST=str(host)),stdout=subprocess.PIPE,stderr=subprocess.PIPE)]
 for _ in range(200):
  if marker.exists() or children[0].poll() is not None:break
  time.sleep(.01)
 children.append(subprocess.Popen([php,'-r',code],env=dict(env,MULTIEMPRESA_HOST=str(host)),stdout=subprocess.PIPE,stderr=subprocess.PIPE))
 for child in children:
  stdout,stderr=child.communicate(timeout=120);assert child.returncode==0,(stdout,stderr)
 r=subprocess.run([php,'-r',probe],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True);values=json.loads(r.stdout);assert values==['1.0.5',341,5,1],values;print('PASS concurrent workers execute the pending migration once',flush=True)
 assert sql("SELECT status FROM core_multiempresa_companies WHERE id='"+cid+"'")=='active'
 print('RESULT automatic module native integration passed',flush=True)
finally:
 if web:web.terminate();web.wait(timeout=15)
 print('Disposable fixture directory: '+str(work),flush=True)
