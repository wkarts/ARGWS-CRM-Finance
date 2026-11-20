"""Explicit initial administration and strict operational organization boundaries."""
import json,secrets,re,subprocess,http.cookiejar,urllib.request,urllib.parse,os

def run(req,sql,DB,org,host,base,work,env,php):
 n=0
 def check(ok,label):
  nonlocal n
  assert ok,label
  n+=1;print('PASS organization '+label,flush=True)
 def get(route):
  s,b,_=req('/admin/multiempresa/'+route);assert s==200,(route,s,b[:1000]);return json.loads(b)
 def post(route,data,expected=200):
  csrf=get('companies')['csrf'];s,b,_=req('/admin/multiempresa/'+route,dict(data,**{csrf['name']:csrf['hash']}));assert s==expected,(route,s,b[:2000]);return json.loads(b)
 def call(code):
  out=subprocess.run([php,'-r',"require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$app=\\Multiempresa\\Application\\App::environment();"+code],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120);assert out.returncode==0,out.stdout+out.stderr;return out.stdout
 def finish(w):
  for _ in range(15):
   if w['status'] not in ['pending','running']:break
   w=post('company_action',{'operation':'advance','id':w['id']})['data']
  assert w['status']=='done',w
  return w
 def csrf(body,name='csrf_token_name'):
  m=re.search(r'name="'+name+r'"[^>]*value="([^"]+)"',body);assert m,(name,body[:400]);return {name:m[1]}
 # Primary without organization cannot switch even when user has membership.
 alfa=sql("SELECT id FROM core_multiempresa_companies WHERE slug='alfa'")
 post('configure_installation',{'organization_id':''})
 status,body,_=req('/admin/multiempresa');selector=re.search(r'<li id="pme-company-selector".*?</ul>',body,re.S)[0]
 check('value="'+alfa+'"' not in selector,'unaffiliated primary hides every tenant from selector')
 c=get('companies')['csrf'];status,_,_=req('/admin/multiempresa/switch_company',dict(company_id=alfa,**{c['name']:c['hash']}));check(status==403,'unaffiliated primary cannot switch via forged POST')
 post('configure_installation',{'organization_id':org})
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE u.native_staff_id=1")=='0','relinking primary never resurrects revoked company grants')
 call("$u=$app->auth->syncNative(1);foreach(['alfa','beta'] as $slug){$c=$app->catalog->bySlug('grupo',$slug);$app->catalog->grant($u,$c['id'],$u['id'],1);}")
 # Full initial-administrator flow, using a separate organization.
 other=post('save_organization',{'name':'Organização independente','slug':'independente-admin','active':'1'})['data']['id']
 fields={'operation':'begin','request_key':secrets.token_hex(16),'organization_id':other,'name':'Administração independente','slug':'independente','language':'english','timezone':'UTC','selection_present':'1','admin_name':'Administrador exclusivo','admin_email':'independent@example.test','admin_password':'Independent-fixture-26'}
 invalid=dict(fields);invalid.pop('admin_email');check(post('company_action',invalid,400)['error'] in ['invalid_email','email'],'missing administrator rejects before provisioning')
 post('save_global_settings',{'configuration':json.dumps({'users':{'grant_creator_default':True}})})
 w=finish(post('company_action',fields)['data']);cid=w['company_id'];company=get('company?id='+cid)['data'];entry=urllib.parse.urlparse(company['address']).path
 post('save_global_settings',{'configuration':json.dumps({'users':{'grant_creator_default':False}})})
 check('Independent-fixture-26' not in json.dumps(company) and 'password' not in (company['initial_administrator'] or {}),'administrator password never returned in company details')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE m.company_id='"+cid+"' AND u.native_staff_id=1")=='0','creator has no implicit company membership even with global opt-in default')
 check(sql("SELECT COUNT(*) FROM `"+company['connection']['database']+'`.`'+company['table_prefix']+"staff` WHERE email='admin@example.test'")=='0','creator is not cloned into the new company')
 check(sql("SELECT CONCAT(s.admin,':',p.principal_access) FROM core_staff s JOIN core_multiempresa_users u ON u.native_staff_id=s.staffid JOIN core_multiempresa_user_preferences p ON p.user_id=u.id WHERE s.email='independent@example.test'")=='0:0','new company admin has no global role or primary access')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE u.email='independent@example.test'")=='1','new administrator has exactly one explicit company grant')
 status,_,_=req(entry+'admin');check(status==403,'global administrator cannot enter tenant without a grant')
 jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
 def login(path,data=None):
  request=urllib.request.Request('http://127.0.0.1:8879'+path,data=urllib.parse.urlencode(data).encode() if data is not None else None)
  try:
   with client.open(request,timeout=120) as r:return r.status,r.read().decode(errors='replace'),r.url
  except urllib.error.HTTPError as e:return e.code,e.read().decode(errors='replace'),e.url
 status,body,_=login('/admin/authentication');status,body,url=login('/admin/authentication',dict(csrf(body),email=fields['admin_email'],password=fields['admin_password']))
 check(status==200 and entry in url and 'Empresa atual' in body,'initial administrator signs in and reaches only own company')
 status,body,url=login('/admin/multiempresa');check((status==403 or 'access_denied' in url or (status==200 and entry in url)) and 'multiempresa-panel' not in body,'tenant initial admin cannot manage global central panel')
 # Local management policy is enforced before the native staff write, not just in the UI.
 post('save_global_settings',{'configuration':json.dumps({'users':{'tenant_staff_management':False}})})
 status,body,_=login(entry+'admin/staff/member');status,body,_=login(entry+'admin/staff/member',dict(csrf(body),firstname='Blocked',lastname='Employee',email='policy-blocked@example.test',password='Policy-fixture-password',email_signature='',default_language='english',direction=''))
 check(status==403 and sql("SELECT COUNT(*) FROM core_staff WHERE email='policy-blocked@example.test'")=='0','disabled local staff management rejects native POST without module fields')
 post('save_global_settings',{'configuration':json.dumps({'users':{'tenant_staff_management':True,'global_access':'all'}})})
 # Native submissions without module fields still obey configured defaults.
 call("(new \\Multiempresa\\Application\\StaffAccess($app))->remember($app->auth->syncNative(1)['id'],\\Multiempresa\\Application\\Installation::ID);")
 status,body,_=req('/admin/staff/member')
 status,body,_=req('/admin/staff/member',dict(csrf(body),firstname='No cascade',lastname='Central',email='policy-central@example.test',password='Policy-fixture-password',email_signature='',default_language='english',direction=''))
 check(status==200 and sql("SELECT principal_access FROM core_multiempresa_user_preferences p JOIN core_multiempresa_users u ON u.id=p.user_id WHERE u.email='policy-central@example.test'")=='0','central employee without module fields follows denied primary default')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE u.email='policy-central@example.test'")=='0','central employee never inherits creator company grants')
 for enabled,suffix in [(True,'on'),(False,'off')]:
  post('save_global_settings',{'configuration':json.dumps({'users':{'tenant_new_staff_access':enabled}})})
  status,body,_=login(entry+'admin/staff/member');email='policy-tenant-'+suffix+'@example.test'
  status,body,_=login(entry+'admin/staff/member',dict(csrf(body),firstname='Local policy',lastname=suffix,email=email,password='Policy-fixture-password',email_signature='',default_language='english',direction=''))
  count=sql("SELECT COUNT(*) FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE u.email='"+email+"' AND m.active=1")
  check(status==200 and count==('1' if enabled else '0'),'tenant employee without module fields obeys local grant default '+suffix)
  check(sql("SELECT principal_access FROM core_multiempresa_user_preferences p JOIN core_multiempresa_users u ON u.id=p.user_id WHERE u.email='"+email+"'")=='0','tenant employee never inherits primary access '+suffix)
 post('save_global_settings',{'configuration':json.dumps({'users':{'tenant_new_staff_access':True}})})
 status,body,_=req(entry+'admin');check(status==403,'legacy global-all setting cannot bypass organization isolation')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE m.company_id='"+cid+"' AND u.native_staff_id=1")=='0','global access does not create inherited memberships')
 call("(new \\Multiempresa\\Application\\StaffAccess($app))->remember($app->auth->syncNative(1)['id'],\\Multiempresa\\Application\\Installation::ID);")
 post('save_global_settings',{'configuration':json.dumps({'users':{'global_access':'explicit'}})})
 status,_,_=req(entry+'admin');check(status==403,'changing global access to explicit immediately rejects ungranted company')
 login('/admin/authentication/logout')
 # A duplicate e-mail is not silently merged or assigned another password.
 duplicate=dict(fields,request_key=secrets.token_hex(16),slug='duplicate-admin');check(post('company_action',duplicate,400)['error']=='administrator_exists','duplicate staff identity rejected without implicit merging')
 fields.update(request_key=secrets.token_hex(16),slug='independente-optin',name='Administrador e criador',admin_email='optin@example.test',grant_creator='1')
 check(post('company_action',fields,403)['error']=='organization_scope','creator opt-in across organizations is rejected before provisioning')
 fields['grant_creator']='0'
 second=finish(post('company_action',fields)['data']);secondid=second['company_id']
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE m.company_id='"+secondid+"' AND u.native_staff_id=1 AND m.active=1")=='0','independent company never receives creator membership')
 c=get('companies')['csrf'];status,_,_=req('/admin/multiempresa/switch_company',dict(company_id=secondid,**{c['name']:c['hash']}));check(status==403,'primary cannot switch to another organization even with explicit opt-in')
 # Scoped employee lookup must return names, never credentials.
 staff=get('staff_choices?company_id='+secondid+'&search=optin')['data']['rows'];check(len(staff)==1 and staff[0]['email']=='optin@example.test' and 'password' not in staff[0],'staff selector searches active employees without passwords')
 for tab in ['access','modules','integrations','events','updates','audit','logs','settings','organizations','companies']:
  status,body,_=req('/admin/multiempresa?tab='+tab);check(status==200 and 'pme-navigation' in body,'ten-tab render '+tab)
 status,body,_=req('/admin/multiempresa?tab=audit&company_id='+cid+'&actor=admin%40example.test');check(status==200 and 'Dados técnicos' in body,'audit filters and integrity details render')
 # Availability is independent from activation, including repeated submissions.
 result=post('company_action',{'operation':'module_available','company_id':secondid,'module':'isolation_probe','reviewed':'1'})['data'];check(any(m['name']=='isolation_probe' and not int(m['active']) for m in result['company']['modules']),'module availability action leaves module inactive')
 post('company_action',{'operation':'module','company_id':secondid,'module':'isolation_probe','enabled':'1','reviewed':'1'})
 result=post('company_action',{'operation':'module_available','company_id':secondid,'module':'isolation_probe','reviewed':'1'})['data'];check(any(m['name']=='isolation_probe' and int(m['active']) for m in result['company']['modules']),'stale availability request never disables an active module')
 # A central operator can edit the owned identity, but its choices never cross the owner organization.
 independent_id=sql("SELECT id FROM core_multiempresa_users WHERE email='independent@example.test'")
 independent_staff=sql("SELECT native_staff_id FROM core_multiempresa_users WHERE id='"+independent_id+"'")
 call("(new \\Multiempresa\\Application\\StaffAccess($app))->remember($app->auth->syncNative(1)['id'],\\Multiempresa\\Application\\Installation::ID);")
 status,body,_=req('/admin/staff/member/'+independent_staff)
 check(status==200 and 'id="pme-staff-'+alfa+'"' not in body and 'id="pme-staff-installation"' not in body and 'id="pme-staff-'+secondid+'"' in body,'owned administrator form offers only peers of its organization')
 call("$u=$app->auth->refresh('"+independent_id+"');$app->auth->issueToken($u,'"+cid+"','Deletion fixture',['context:read'],1);$app->auth->createSession($u,'"+cid+"',false);")
 old_route=entry
 for company_id in [cid,secondid]:
  plan=post('impact_plan',{'operation':'delete_company','target':company_id,'delete_data':'1','delete_files':'1','delete_backups':'1','delete_dns':'0'})['data'];post('confirm_impact',{'plan_id':plan['plan_id'],'confirmation':plan['confirmation_name'],'accepted':'1'})
 check(sql("SELECT COUNT(*) FROM core_staff WHERE email IN ('independent@example.test','optin@example.test','policy-tenant-on@example.test','policy-tenant-off@example.test')")=='0','deletion removes exclusively owned native login identities even without a grant')
 for table in ['users','user_preferences','memberships','org_members','user_organizations','sessions','tokens','tickets','switch_tickets']:
  column='id' if table=='users' else 'user_id'
  check(sql("SELECT COUNT(*) FROM core_multiempresa_"+table+" WHERE "+column+"='"+independent_id+"'")=='0','deletion purges owned identity from '+table)
 check(sql("SELECT COUNT(*) FROM core_staff WHERE staffid=1 AND admin=1")=='1','company deletion preserves the pre-existing principal administrator')
 recreate=dict(fields,request_key=secrets.token_hex(16),slug='independente',name='Administração independente',admin_email='independent@example.test',grant_creator='0')
 fresh=finish(post('company_action',recreate)['data']);freshid=fresh['company_id'];freshuser=sql("SELECT id FROM core_multiempresa_users WHERE email='independent@example.test'")
 check(freshid!=cid and freshuser!=independent_id,'same name slug and email create fresh company and identity IDs')
 freshentry=urllib.parse.urlparse(get('company?id='+freshid)['data']['address']).path
 check(freshentry!=old_route and req(old_route+'admin')[0]!=200,'deleted company route cannot resolve to the recreated company')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships WHERE company_id='"+freshid+"'")=='1','recreated company receives only its selected initial administrator')
 # Simulate a legacy foreign membership before the migration cleanup.
 call("$u=$app->auth->syncNative(1);$c=$app->catalog->company('"+freshid+"');$pdo=$app->storage($c['mode'])->connect($app->vault->open($c['secrets'])['connection']);$q=$pdo->prepare('INSERT INTO `'.$c['table_prefix'].'staff` (email,firstname,lastname,password,datecreated,admin,active,is_not_staff) VALUES(?,?,?,?,?,1,1,0)');$q->execute([$u['email'],$u['name'],'',password_hash('Legacy-invalid-fixture',PASSWORD_BCRYPT),date('Y-m-d H:i:s')]);$local=(int)$pdo->lastInsertId();$app->db->exec('INSERT INTO pme_memberships(company_id,user_id,staff_id,active) VALUES(?,?,?,1)',['"+freshid+"',$u['id'],$local]);")
 status,_,_=req(freshentry+'admin');check(status==403,'legacy foreign membership is rejected before cleanup')
 orphan=call("$table=$app->config['native_prefix'].'staff';$q=$app->db->pdo->prepare('INSERT INTO `'.$table.'` (email,firstname,lastname,password,datecreated,admin,active,is_not_staff) VALUES(?,?,?,?,?,0,1,0)');$q->execute(['deleted-owner@example.test','Orphan','Fixture',password_hash('Orphan-password-26',PASSWORD_BCRYPT),date('Y-m-d H:i:s')]);$u=$app->auth->syncNative((int)$app->db->pdo->lastInsertId());(new \\Multiempresa\\Application\\StaffAccess($app))->savePreference($u['id'],['identity_company'=>str_repeat('f',32),'principal_access'=>0]);$app->db->exec('INSERT INTO pme_memberships(company_id,user_id,staff_id,active) VALUES(?,?,99999,1)',['"+alfa+"',$u['id']]);echo $u['id'];")
 call("(new \\Multiempresa\\Application\\IdentityCleanup($app))->reconcile();(new \\Multiempresa\\Application\\IdentityCleanup($app))->reconcile();")
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE m.company_id='"+freshid+"' AND u.native_staff_id=1")=='0','idempotent upgrade cleanup physically removes legacy foreign grant')
 check(sql("SELECT COUNT(*) FROM core_staff WHERE email='deleted-owner@example.test'")=='0' and sql("SELECT COUNT(*) FROM core_multiempresa_users WHERE id='"+orphan+"'")=='0','upgrade removes orphan identity without inferring ownership from a leftover foreign grant')
 shared_fields=dict(fields,request_key=secrets.token_hex(16),slug='identity-shared',name='Identidade compartilhada no grupo',admin_email='shared-admin@example.test',grant_creator='0')
 shared=finish(post('company_action',shared_fields)['data'])['company_id']
 call("$u=$app->auth->syncNative(1);$q=$app->db->pdo->prepare('SELECT * FROM `'.$app->config['native_prefix'].'staff` WHERE email=?');$q->execute(['independent@example.test']);(new \\Multiempresa\\Application\\StaffAccess($app))->apply($u,$q->fetch(\\PDO::FETCH_ASSOC),null,['"+freshid+"','"+shared+"']);")
 plan=post('impact_plan',{'operation':'delete_company','target':freshid,'delete_data':'1','delete_files':'1','delete_backups':'1','delete_dns':'0'})['data'];post('confirm_impact',{'plan_id':plan['plan_id'],'confirmation':plan['confirmation_name'],'accepted':'1'})
 check(sql("SELECT identity_company FROM core_multiempresa_user_preferences WHERE user_id='"+freshuser+"'")==shared,'deleting one company preserves explicitly shared identity in the same organization')
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships WHERE user_id='"+freshuser+"' AND company_id='"+shared+"' AND active=1")=='1','remaining authorized company keeps its own membership')
 plan=post('impact_plan',{'operation':'delete_company','target':shared,'delete_data':'1','delete_files':'1','delete_backups':'1','delete_dns':'0'})['data'];post('confirm_impact',{'plan_id':plan['plan_id'],'confirmation':plan['confirmation_name'],'accepted':'1'})
 check(sql("SELECT COUNT(*) FROM core_staff WHERE email IN ('independent@example.test','shared-admin@example.test')")=='0','last company deletion removes both exclusive and formerly shared owned identities')
 print('RESULT '+str(n)+' organization HTTP assertions passed',flush=True)
