"""Native installation switching and both reviewed uninstall modes (disposable fixtures only)."""
import json,re,shutil,subprocess,os,secrets
from pathlib import Path

def run(req,sql,DB,org,host,base,work,phase):
 n=0
 def check(ok,label):
  nonlocal n
  assert ok,label
  n+=1;print('PASS lifecycle '+label,flush=True)
 def get(route):
  status,body,_=req('/admin/multiempresa/'+route);assert status==200,(status,body[:1000]);return json.loads(body)
 def token():
  status,body,_=req('/admin/multiempresa/uninstall');assert status==200,(status,body[:1000])
  boot=json.loads(re.search(r'<script type="application/json" id="pme-uninstall-bootstrap">(.*?)</script>',body,re.S)[1]);return boot['csrf']
 def post(route,data,expected=200):
  c=token();status,body,url=req('/admin/multiempresa/'+route,dict(data,**{c['name']:c['hash']}));assert status==expected,(route,status,url,body[:1500]);return json.loads(body)
 if phase=='switch':
  primary=get('installation')['data'];check(primary['organization_id']==org and primary['protected'],'principal belongs to chosen organization and remains protected')
  cid=sql("SELECT id FROM core_multiempresa_companies WHERE slug='alfa'")
  entry='/'+sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id='"+cid+"'")+'/'
  c=token();status,body,where=req('/admin/multiempresa/switch_company',dict(company_id=cid,**{c['name']:c['hash']}));check(status==200 and entry in where and 'Empresa atual' in body,'native card switches to company without signing in again')
  status,stale,where=req('/admin/clients');check(status==200 and entry in where,'old principal page follows the newly active company')
  status,stale,_=req('/admin/clients',headers={'X-Requested-With':'XMLHttpRequest'});check(status==409,'stale principal AJAX requests cannot read previous company data')
  cross=sql("SELECT c.id FROM core_multiempresa_companies c WHERE c.organization_id<>'"+org+"' AND NOT EXISTS(SELECT 1 FROM core_multiempresa_memberships m JOIN core_multiempresa_users u ON u.id=m.user_id WHERE m.company_id=c.id AND m.active=1 AND u.native_staff_id=1) LIMIT 1")
  if not cross:
   other={'id':sql("SELECT id FROM core_multiempresa_organizations WHERE slug='outra-seletor'")}
   if not other['id']:other=post('save_organization',{'name':'Outra organização do seletor','slug':'outra-seletor','active':'1'})['data']
   post('company_action',{'operation':'draft','request_key':secrets.token_hex(20),'organization_id':other['id'],'name':'Empresa de outra organização','slug':'outra-seletor','language':'english','timezone':'UTC'})
   cross=sql("SELECT id FROM core_multiempresa_companies WHERE organization_id='"+other['id']+"' LIMIT 1")
  assert cross,'Cross-organization switch fixture is missing'
  c=token();status,body,_=req('/admin/multiempresa/switch_company',dict(company_id=cross,**{c['name']:c['hash']}));assert status==403,(status,body[:200]);check(True,'ungranted company ID is rejected by native switch backend')
  status,body,_=req(entry+'admin');csrf=re.search(r'name="csrf" value="([^"]+)"',body)[1]
  status,body,where=req(entry+'__pme/switch',{'csrf':csrf,'company_id':'installation'});check(status==200 and '__pme_route' not in where and 'Empresa atual' in body,'company card returns to the principal installation without new login')
  status,body,_=req(entry+'admin',headers={'X-Requested-With':'XMLHttpRequest'});check(status in (401,403,302) or 'authentication' in body.lower(),'return to principal revokes previous tenant session')
  print('RESULT '+str(n)+' native switch HTTP assertions passed',flush=True);return
 # All test databases/files are disposable. First test preserve + native removal + reinstall.
 status,html,where=req('/admin/authentication');csrf=re.search(r'name="(csrf_token_name)"[^>]*value="([^"]+)"',html)
 if csrf:req('/admin/authentication',{csrf[1]:csrf[2],'email':'admin@example.test','password':'Native-test-password-26'})
 core_before=sql('SELECT CONCAT(COUNT(*),":",GROUP_CONCAT(company ORDER BY userid)) FROM core_clients')
 tenant_before=sql('SELECT COUNT(*) FROM `'+DB+'_alfa`.emp_clients')
 count_before=sql('SELECT COUNT(*) FROM core_multiempresa_companies')
 primary_key=sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id='installation'")
 keep=post('uninstall_plan',{'mode':'keep'})['data']
 post('uninstall_confirm',{'id':keep['id'],'confirmation':'Multiempresa','accepted':'0'},400)
 check(sql("SELECT active FROM core_modules WHERE module_name='multiempresa'")=='1','uninstall requires explicit acceptance before deactivating')
 keep=post('uninstall_confirm',{'id':keep['id'],'confirmation':'Multiempresa','accepted':'1'})['data'];check(keep['status']=='ready','preserve mode finishes resource check without deleting tenant data')
 status,body,where=req('/admin/mods/uninstall/multiempresa');check(status==200 and '/multiempresa/uninstall' in where and (host/'modules/multiempresa').is_dir(),'inactive native uninstall is intercepted for review')
 c=token();status,body,where=req('/'+primary_key+'/admin/multiempresa/uninstall_finish',dict(id=keep['id'],**{c['name']:c['hash']}));(work/'uninstall-result.html').write_text(body);assert status==200,(status,where,re.sub('<[^>]+>',' ',body)[-1200:]);check(not (host/'modules/multiempresa').exists(),'native module code removal completes after confirmation')
 check(sql('SELECT COUNT(*) FROM core_multiempresa_companies')==count_before and sql('SELECT COUNT(*) FROM `'+DB+'_alfa`.emp_clients')==tenant_before,'preserve mode retains all company registrations and business data')
 check(sql("SELECT COUNT(*) FROM core_staff s JOIN core_multiempresa_users u ON u.native_staff_id=s.staffid JOIN core_multiempresa_user_preferences p ON p.user_id=u.id WHERE p.principal_access=0 AND u.platform_admin=0 AND s.active=1")=='0','uninstall leaves no active principal login for tenant-only identities')
 check('MULTIEMPRESA BOOTSTRAP' not in (host/'index.php').read_text() and '/admin/modules' in where and primary_key not in where,'uninstall restores native entry and redirects outside removed alias')
 shutil.copytree(base,host/'modules/multiempresa');(host/'modules/multiempresa/config/settings.php').write_text("<?php return ['php_binary'=>"+repr(os.environ['MULTIEMPRESA_TEST_PHP'])+"];")
 status,body,where=req('/admin/mods/activate/multiempresa');check(status==200 and sql('SELECT COUNT(*) FROM core_multiempresa_companies')==count_before,'reinstall reuses preserved registry and environments')
 check(sql("SELECT active FROM core_staff WHERE email='access@example.test'")=='1','reinstall restores only logins disabled during reviewed uninstall')
 check(sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id='installation'")==primary_key,'preserved reinstall keeps principal short address')
 # Remove all owned company resources. Foreign and native resources must survive.
 remove=post('uninstall_plan',{'mode':'remove'})['data'];check(remove['installation']['protected'] and remove['steps'],'remove mode inventories organizations and companies while protecting principal')
 remove=post('uninstall_confirm',{'id':remove['id'],'confirmation':'Multiempresa','accepted':'1'})['data']
 for _ in range(len(remove['steps'])+3):
  if remove['status']!='running':break
  remove=post('uninstall_step',{'id':remove['id']})['data']
 check(remove['status']=='ready','reviewed removal of all organizations and companies completes: '+str(remove.get('diagnosis','')))
 check(sql('SELECT COUNT(*) FROM core_multiempresa_companies')=='0' and sql('SELECT COUNT(*) FROM core_multiempresa_organizations')=='0','removal clears module company and organization catalog')
 check(sql('SELECT CONCAT(COUNT(*),":",GROUP_CONCAT(company ORDER BY userid)) FROM core_clients')==core_before and sql('SELECT COUNT(*) FROM core_staff')!='0','principal customers and staff remain untouched')
 check(sql('SELECT COUNT(*) FROM core_multiempresa_execution_logs')!='0','execution diagnosis remains available until removal finishes')
 c=token();status,body,where=req('/admin/multiempresa/uninstall_finish',dict(id=remove['id'],**{c['name']:c['hash']}));check(status==200 and not (host/'modules/multiempresa').exists(),'final native uninstall removes only module code')
 check(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,18)='core_multiempresa_'")=='0','remove mode physically drops all module tables after finishing')
 check(sql('SELECT CONCAT(COUNT(*),":",GROUP_CONCAT(company ORDER BY userid)) FROM core_clients')==core_before,'full removal still preserves principal business data')
 check(not any(work.glob('.multiempresa-*/installation.php')),'full removal deletes the module private configuration and encryption key')
 check(all((host/'modules'/name).is_dir() for name in ['ma','fleet','gerencianet_gateway']),'other installed module packages remain untouched')
 print('RESULT '+str(n)+' uninstall HTTP assertions passed',flush=True)
