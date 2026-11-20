"""Availability defaults and explicit activation, using real native HTTP and an isolated DB."""
import json,re,secrets

def run(req,sql,DB,org,host):
 count=0
 def check(ok,label):
  nonlocal count
  assert ok,label
  count+=1;print('PASS defaults '+label,flush=True)
 def get(route):
  status,body,_=req('/admin/multiempresa/'+route);assert status==200,(route,status,body[:1400]);return json.loads(body)
 def post(route,data,expected=200):
  csrf=get('companies')['csrf'];status,body,_=req('/admin/multiempresa/'+route,dict(data,**{csrf['name']:csrf['hash']}));assert status==expected,(route,status,body[:2400]);return json.loads(body)
 def action(op,data):return post('company_action',dict({'admin_mode':'current'} if op in ['begin','check','draft','start_draft'] else {},**dict(data,operation=op)))['data']
 def finish(w):
  for _ in range(20):
   if w['status'] not in ('pending','running'):break
   w=action('advance',{'id':w['id']})
  assert w['status']=='done',w
  return w
 path=host/'modules/available_probe';path.mkdir()
 (path/'available_probe.php').write_text("<?php\n/*\nModule Name: Available Probe\nVersion: 1.0.0\n*/\nregister_activation_hook('available_probe',static function(){update_option('available_probe_activated','1');});\n")
 old=get('global_settings')['data']['modules']['defaults']
 before=sql("SELECT CONCAT(company_id,':',name,':',active) FROM core_multiempresa_modules ORDER BY company_id,name")
 try:
  saved=post('save_global_settings',{'configuration':json.dumps({'modules':{'defaults':['isolation_probe','available_probe','isolation_probe']}})})['data']
  check(saved['modules']['defaults']==['available_probe','isolation_probe'],'one default list is normalized and saved')
  check(before==sql("SELECT CONCAT(company_id,':',name,':',active) FROM core_multiempresa_modules ORDER BY company_id,name"),'changing defaults does not alter existing company modules')
  check(post('save_global_settings',{'configuration':json.dumps({'modules':{'defaults':['multiempresa']}})},400)['error']=='central_module','central module cannot become a company default')
  status,html,_=req('/admin/multiempresa');boot=json.loads(re.search(r'id="pme-bootstrap">(.*?)</script>',html,re.S)[1])
  check(boot['provisioning']['default_modules']==saved['modules']['defaults'],'wizard receives the saved default list')
  fields=dict(request_key=secrets.token_hex(16),organization_id=org,name='Disponíveis sem ativação',slug='disponiveis',prefix='available_',language='english',timezone='UTC',modules_reviewed='1')
  w=finish(action('begin',fields));cid=w['company_id'];c=get('company?id='+cid)['data'];database=c['connection']['database'];prefix=c['table_prefix']
  grid=get('companies?search=Dispon%C3%ADveis')['data']['rows'];row=next(row for row in grid if row['id']==cid);check(int(row['module_count'])==2 and int(row['active_module_count'])==0,'company grid counts available packages separately from enabled modules')
  check(len(c['modules'])==2 and not any(int(m['active']) for m in c['modules']),'default packages are available while every module remains disabled')
  check(all(step.get('activate') is False for step in w['steps'] if step['type']=='module'),'progress distinguishes availability from activation')
  check(sql('SELECT COUNT(*) FROM `'+database+'`.`'+prefix+'modules`')=='0','availability never executes native installers or creates native activation rows')
  check(sql('SELECT COUNT(*) FROM `'+database+'`.`'+prefix+'options` WHERE name="available_probe_activated"')=='0','availability never calls the activation hook')
  status,body,_=req(c['address']+'__pme/start');assert status==200,(status,body[:500])
  status,body,_=req(c['address']+'admin/modules');check(status==200 and 'Available Probe' in body and 'modules/activate/available_probe' in body,'native Modules screen lists the provisioned disabled package')
  csrf=re.search(r'name="csrf" value="([^"]+)"',body)[1]
  status,body,_=req(c['address']+'admin/modules/activate/available_probe',{'csrf':csrf});check(status==200 and 'Situação do módulo atualizada.' in body,'company administrator can activate an available package for the first time')
  check(sql('SELECT value FROM `'+database+'`.`'+prefix+'options` WHERE name="available_probe_activated"')=='1','first manual activation runs the native hook')
  c=get('company?id='+cid)['data'];check({m['name']:int(m['active']) for m in c['modules']}=={'available_probe':1,'isolation_probe':0},'manual activation leaves the other default package disabled')
  check(get('provision?id='+w['id'])['data']['status']=='done','first manual activation keeps completed provisioning intact')
  empty=action('draft',dict(fields,request_key=secrets.token_hex(16),name='Sem padrões',slug='sem-padroes',selection_present='1'))
  check(get('company?id='+empty['company_id'])['data']['planned_modules']==[],'clearing all defaults is retained in a saved registration')
  mixed=finish(action('begin',dict(fields,request_key=secrets.token_hex(16),name='Escolha explícita',slug='escolha',prefix='choice_',selection_present='1',**{'modules[]':['available_probe','isolation_probe'],'activate_modules[]':['available_probe']})))
  c=get('company?id='+mixed['company_id'])['data'];check({m['name']:int(m['active']) for m in c['modules']}=={'available_probe':1,'isolation_probe':0},'wizard activates only the explicitly selected subset')
  check(post('company_action',dict(fields,operation='draft',request_key=secrets.token_hex(16),name='Inválida',slug='invalida',selection_present='1',**{'modules[]':['isolation_probe'],'activate_modules[]':['available_probe']}),400)['error']=='module_activation','activation outside the provisioned selection is rejected')
  plan=post('impact_plan',{'operation':'reprovision','target':mixed['company_id'],'selection_present':'1','modules_reviewed':'1','modules[]':['available_probe','isolation_probe']})['data']
  check(all(m['action']=='available_inactive' for m in plan['impact']['modules']),'review shows explicit removal of activation without removing availability')
  revised=finish(post('confirm_impact',{'plan_id':plan['plan_id'],'confirmation':plan['confirmation_name'],'accepted':'1'})['data'])
  check(not any(int(m['active']) for m in get('company?id='+mixed['company_id'])['data']['modules']),'confirmed revision can keep both packages available and disabled')
 finally:
  post('save_global_settings',{'configuration':json.dumps({'modules':{'defaults':old}})})
 print('RESULT '+str(count)+' module defaults HTTP assertions passed',flush=True)
