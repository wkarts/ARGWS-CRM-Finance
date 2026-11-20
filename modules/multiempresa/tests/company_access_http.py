"""Opaque routing, native module state and native staff grants against real Perfex."""
import re,json,subprocess,os,urllib.request,urllib.parse,http.cookiejar,time

def run(req,sql,DB,org,host,base,work,env,php):
 n=0
 def check(ok,label):
  nonlocal n
  assert ok,label
  n+=1;print('PASS access '+label,flush=True)
 def call(code):
  p=subprocess.run([php,'-r',"require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$app=\\Multiempresa\\Application\\App::environment();"+code],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=120)
  assert p.returncode==0,p.stdout+p.stderr
  return p.stdout
 def csrf(body,name='csrf_token_name'):
  m=re.search(r'name="'+name+r'"[^>]*value="([^"]+)"',body);assert m,(name,body[:400]);return {name:m[1]}
 def principal():
  # Exercise the authenticated card's return ticket, not a fabricated native session.
  last=sql("SELECT last_company_id FROM core_multiempresa_user_preferences p JOIN core_multiempresa_users u ON u.id=p.user_id WHERE native_staff_id=1")
  if last and last not in ('NULL','installation'):
   key=sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id='"+last+"'")
   status,body,_=req('/'+key+'/admin');assert status==200,(status,body[:300])
   status,body,url=req('/'+key+'/__pme/switch',dict(csrf(body,'csrf'),company_id='installation'));assert status==200,(status,body[:500])
  return '/'+sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id='installation'")+'/'
 entries={slug:'/'+sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id=(SELECT id FROM core_multiempresa_companies WHERE slug='"+slug+"')")+'/' for slug in ['alfa','beta']}
 main=principal()
 check(all(re.fullmatch('/[a-f0-9]{20}/',e) for e in [main,*entries.values()]) and len(set([main,*entries.values()]))==3,'unique short random addresses include principal')
 original_beta_status=sql("SELECT status FROM core_multiempresa_companies WHERE slug='beta'")
 sql("UPDATE core_multiempresa_companies SET status='active' WHERE slug='beta'")
 for slug,entry in entries.items():
  status,body,where=req('/modules/multiempresa/tenant.php?__pme_route=/o/grupo/e/'+slug+'/admin/staff')
  check(status==200 and entry+'admin/staff' in where and '__pme_route' not in where,'legacy staff link canonicalized '+slug+' '+str(status)+' '+where)
  for route in ['admin/staff/member','admin/roles','admin/clients','admin/invoices','admin/tasks','admin/settings','admin/modules','admin/projects']:
   status,body,where=req(entry+route);check(status==200,slug+' native route '+route+((': '+str(status)+' '+re.sub('<[^>]+>',' ',body)[-400:]) if status!=200 else ''))
  status,body,_=req(entry+'admin/staff')
  status,body,_=req(entry+'admin/staff',dict(csrf(body),draw='1',start='0',length='10'),headers={'X-Requested-With':'XMLHttpRequest'})
  check(status==200 and 'aaData' in json.loads(body),'native employee table AJAX '+slug)
  status,body,_=req(entry+'admin/staff/member');check('Acesso às empresas' in body,'native employee company field '+slug)
 main=principal();status,body,where=req(main+'admin/staff/member');check(status==200 and 'Acesso às empresas' in body,'primary native employee form has company grants')
 alfa=sql("SELECT id FROM core_multiempresa_companies WHERE slug='alfa'");beta=sql("SELECT id FROM core_multiempresa_companies WHERE slug='beta'")
 alfa_storage=json.loads(call("$c=$app->catalog->company('"+alfa+"');echo json_encode(['database'=>$app->vault->open($c['secrets'])['connection']['database'],'prefix'=>$c['table_prefix']]);"))
 alfa_table='`'+alfa_storage['database']+'`.'+alfa_storage['prefix']
 # Native staff creation propagates only the identity and chosen company; no role/admin escalation.
 data=dict(csrf(body),firstname='Access',lastname='Employee',email='access@example.test',password='Access-test-password-26',email_signature='',default_language='english',direction='',multiempresa_access_present='1')
 data['multiempresa_companies[]']=[alfa,beta]
 status,body,where=req(main+'admin/staff/member',data);check(status==200 and re.search('/member/[0-9]+',where),'native employee created with two explicit company grants'+((' '+str(status)+' '+body[-250:]) if status!=200 else ''))
 uid=sql("SELECT id FROM core_multiempresa_users WHERE email='access@example.test'");staffid=sql("SELECT staffid FROM core_staff WHERE email='access@example.test'")
 check(sql("SELECT COUNT(*) FROM core_multiempresa_memberships WHERE user_id='"+uid+"' AND active=1")=='2','both grants saved without manual staff IDs')
 check(sql("SELECT principal_access FROM core_multiempresa_user_preferences WHERE user_id='"+uid+"'")=='0','unselected principal is denied')
 check(sql("SELECT admin FROM "+alfa_table+"staff WHERE email='access@example.test'")=='0','new local employee is not implicitly administrator')
 # Same native employee save with only company permission changed must still apply.
 status,body,_=req(main+'admin/staff/member/'+staffid)
 data.update(csrf(body));data['password']='';data['multiempresa_companies[]']=[alfa]
 status,body,_=req(main+'admin/staff/member/'+staffid,data)
 check(status==200 and sql("SELECT active FROM core_multiempresa_memberships WHERE user_id='"+uid+"' AND company_id='"+beta+"'")=='0','permission-only native save revokes access')
 # Local native creation creates a global login without exposing the principal.
 status,body,_=req(entries['alfa']+'admin/staff/member')
 local=dict(csrf(body),firstname='Tenant',lastname='Employee',email='tenant-created@example.test',password='Tenant-test-password-26',email_signature='',default_language='english',direction='',multiempresa_access_present='1')
 local['multiempresa_companies[]']=[beta]
 status,body,_=req(entries['alfa']+'admin/staff/member',local)
 check(status==403 and sql("SELECT COUNT(*) FROM core_staff WHERE email='tenant-created@example.test'")=='0','tenant form rejects forged grant to another company before creation')
 status,body,_=req(entries['alfa']+'admin/staff/member');local.update(csrf(body));local['multiempresa_companies[]']=[alfa]
 status,body,where=req(entries['alfa']+'admin/staff/member',local)
 check(status==200 and re.search('/member/[0-9]+',where),'native tenant employee creation completes')
 check(sql("SELECT CONCAT(s.admin,':',p.principal_access) FROM core_staff s JOIN core_multiempresa_users u ON u.native_staff_id=s.staffid JOIN core_multiempresa_user_preferences p ON p.user_id=u.id WHERE s.email='tenant-created@example.test'")=='0:0','tenant-created login has neither global admin nor principal access')
 main=principal()
 # Approve a real supplied module centrally, then toggle from native company page.
 call("(new \\Multiempresa\\Application\\Modules($app))->approve('"+alfa+"','purchase',true);")
 status,body,_=req(entries['alfa']+'__pme/start');assert status==200,(status,body[:600])
 backups_before=call("echo count(glob($app->state('backups/"+alfa+"/*')));")
 onboarding_before=sql("SELECT COALESCE(CONCAT(status,':',step_index),'') FROM core_multiempresa_onboardings WHERE company_id='"+alfa+"'")
 for desired,action in [('0','deactivate'),('1','activate')]:
  status,body,_=req(entries['alfa']+'admin/modules');check(status==200 and '/'+action+'/purchase' in body,'native module page offers '+action);check(body.find('id="header"')<body.find('id="wrapper"') and body.find('id="header"')>=0,'native header precedes module content '+action)
  check('type="file"' not in body and '/uninstall/' not in body and '/update_version/' not in body,'tenant module screen hides package mutations')
  status,_,_=req(entries['alfa']+'admin/modules/'+action+'/purchase');check(status==200 and sql("SELECT active FROM core_multiempresa_modules WHERE company_id='"+alfa+"' AND name='purchase'")!=desired,'GET does not mutate module')
  status,body,_=req(entries['alfa']+'admin/modules');started=time.monotonic();status,body,_=req(entries['alfa']+'admin/modules/'+action+'/purchase',csrf(body,'csrf'));print('MEASURE native '+action+' %.3fs'%(time.monotonic()-started),flush=True)
  check(status==200 and sql("SELECT active FROM core_multiempresa_modules WHERE company_id='"+alfa+"' AND name='purchase'")==desired,'native '+action+' succeeds and stays listed')
  check(sql("SELECT active FROM "+alfa_table+"modules WHERE module_name='purchase'")==desired,'native registry reflects '+action)
  check(call("echo count(glob($app->state('backups/"+alfa+"/*')));")==backups_before,'state toggle does not create full company backup '+action)
  check(sql("SELECT status FROM core_multiempresa_companies WHERE id='"+alfa+"'")=='active' and sql("SELECT COALESCE(CONCAT(status,':',step_index),'') FROM core_multiempresa_onboardings WHERE company_id='"+alfa+"'")==onboarding_before,'toggle preserves provisioned status and workflow '+action)
 for action in ['uninstall/purchase','upload','upgrade_database/purchase','update_version/purchase']:
  status,_,_=req(entries['alfa']+'admin/modules/'+action);check(status==403,'tenant rejects '+action)
 status,_,_=req(entries['alfa']+'admin/modules/deactivate/purchase',{'csrf':'wrong'});check(status==403,'native module toggle requires CSRF')
 # Separate browser identity proves authorization is enforced beyond the selector.
 jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar));origin='http://127.0.0.1:8879'
 def employee(path,data=None,headers=None):
  r=urllib.request.Request(origin+path,data=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None,headers=headers or {})
  try:
   with client.open(r,timeout=120) as response:return response.status,response.read().decode(errors='replace'),response.url
  except urllib.error.HTTPError as e:return e.code,e.read().decode(errors='replace'),e.url
 status,body,_=employee('/admin/authentication');status,body,where=employee('/admin/authentication',dict(csrf(body),email='access@example.test',password='Access-test-password-26'))
 check(status==200 and entries['alfa'] in where,'restricted employee login opens first permitted company')
 check('value="installation"' not in body and 'value="'+beta+'"' not in body,'selector excludes unauthorized principal and revoked company')
 status,body,where=employee(main+'admin/clients');check(status==200 and entries['alfa'] in where,'direct principal URL cannot expose its data')
 status,body,_=employee(entries['beta']+'admin');check(status==403,'known hash does not grant company access')
 status,body,where=employee(entries['alfa']+'admin/modules');check('access_denied' in where and '/deactivate/purchase' not in body,'non-admin receives native access-denied page')
 status,body,_=employee(entries['alfa']+'admin');status,body,_=employee(entries['alfa']+'admin/modules/deactivate/purchase',csrf(body,'csrf'));check(status==403,'non-admin direct module POST denied by backend')
 # Cross-organization grants are rejected even when submitted by the global administrator.
 other=call("$actor=$app->auth->syncNative(1);$org=$app->catalog->createOrganization($actor,'Access Other','access-other');$app->db->exec('UPDATE pme_companies SET organization_id=? WHERE id=?',[$org,'"+beta+"']);echo $org;")
 main=principal();status,body,_=req(main+'admin/staff/member/'+staffid);data.update(csrf(body));data['multiempresa_companies[]']=[alfa,beta]
 status,body,_=req(main+'admin/staff/member/'+staffid,data);check(status==403,'principal cannot grant access across organizations')
 status,body,_=employee(entries['alfa']+'admin');check('value="'+beta+'"' not in body,'selector hides authorized company in other organization')
 status,body,where=employee(entries['alfa']+'__pme/switch',dict(csrf(body,'csrf'),company_id=beta));check(status==403,'cross-organization switch rejected despite explicit membership')
 status,body,where=employee(entries['beta']+'admin');check(status==403,'direct hash entry cannot bypass the identity organization')
 employee('/admin/authentication/logout');status,body,_=employee('/admin/authentication');status,body,where=employee('/admin/authentication',dict(csrf(body),email='access@example.test',password='Access-test-password-26'))
 check(status==200 and entries['alfa'] in where,'login restores last company within the identity organization')
 # Disable Multiempresa centrally. Restricted native identities cannot fall into primary.
 main=principal();status,body,_=req(main+'admin/modules/deactivate/multiempresa');check(status==200 and sql("SELECT active FROM core_modules WHERE module_name='multiempresa'")=='0','Multiempresa can be disabled normally')
 status,body,_=employee('/admin/clients');check(status==403,'disabled module does not leak principal data to tenant-only identities')
 call("(new \Multiempresa\Application\StaffAccess($app))->remember($app->auth->syncNative(1)['id'],'"+alfa+"');")
 status,body,where=req('/admin/clients');check(status==200 and main in where,'disabled module keeps principal access despite last company preference')
 employee('/admin/authentication/logout');status,body,_=employee('/admin/authentication');status,body,where=employee('/admin/authentication',dict(csrf(body),email='admin@example.test',password='Native-test-password-26'))
 check(status==200 and main in where,'administrator login remains available while module is disabled')
 status,body,_=req(main+'admin/modules/activate/multiempresa');check(status==200,'principal can reactivate Multiempresa')
 employee('/admin/authentication/logout');status,body,_=employee('/admin/authentication');status,body,where=employee('/admin/authentication',dict(csrf(body),email='tenant-created@example.test',password='Tenant-test-password-26'))
 check(status==200 and entries['alfa'] in where,'tenant-created employee can sign in through central login')
 # Restore fixture organization and native principal state for the remaining regression suite.
 sql("UPDATE core_multiempresa_companies SET organization_id='"+org+"',status='"+original_beta_status+"' WHERE id='"+beta+"'")
 call("(new \\Multiempresa\\Application\\StaffAccess($app))->remember($app->auth->syncNative(1)['id'],\\Multiempresa\\Application\\Installation::ID);")
 print('RESULT '+str(n)+' company access HTTP assertions passed',flush=True)
