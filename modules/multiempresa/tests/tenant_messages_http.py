"""Tenant-facing language is neutral; central diagnosis remains available."""
import json,re,os,subprocess,html

def run(req,sql,DB,host,base,work,env,php):
 count=0
 def check(ok,label):
  nonlocal count
  assert ok,label
  count+=1;print('PASS tenant messages '+label,flush=True)
 def call(code):
  r=subprocess.run([php,'-r',"require getenv('MULTIEMPRESA_HOST').'/modules/multiempresa/autoload.php';$app=\\Multiempresa\\Application\\App::environment();"+code],env=dict(env,MULTIEMPRESA_HOST=str(host)),capture_output=True,text=True,timeout=90)
  assert r.returncode==0,r.stdout+r.stderr
  return r.stdout.strip()
 def visible(body):
  body=re.sub(r'<(?:script|style)\b[^>]*>.*?</(?:script|style)>','',body,flags=re.S|re.I)
  return html.unescape(re.sub('<[^>]*>',' ',body))
 cid=sql("SELECT id FROM core_multiempresa_companies WHERE slug='alfa'")
 entry='/'+sql("SELECT route_key FROM core_multiempresa_routes WHERE target_id='"+cid+"'")+'/'
 status,body,_=req(entry+'__pme/start');check(status==200,'authorized tenant session opens')
 # Build the approved inactive private copy before simulating a central package update.
 module=host/'modules/public_message_probe';module.mkdir()
 source=module/'public_message_probe.php';source.write_text("<?php\n/*\nModule Name: Recurso de teste\nVersion: 1.0.0\n*/\nregister_activation_hook('public_message_probe',function(){});\n")
 call("(new \\Multiempresa\\Application\\Modules($app))->makeAvailable('"+cid+"','public_message_probe',true);")
 status,body,_=req(entry+'admin/modules');check(status==200 and 'Ative ou desative os módulos disponíveis para sua empresa.' in visible(body),'module page has a simple user instruction')
 check(all(x not in visible(body) for x in ['painel Multiempresa','primeira ativação prepara','empresa principal','Envio de pacotes']),'technical onboarding instructions are absent from tenant page')
 saved_source=source.read_text();source.unlink()
 csrf=re.search(r'name="csrf" value="([^"]+)"',body)[1]
 status,body,where=req(entry+'admin/modules/activate/public_message_probe',{'csrf':csrf})
 check(status==200 and 'failed=1' in where and 'entre em contato com o suporte' in visible(body),'failed toggle returns ordinary support guidance')
 check('painel Multiempresa' not in visible(body) and 'instalação principal' not in visible(body) and 'module_update' not in visible(body),'failed toggle exposes no administrative diagnosis')
 check(sql("SELECT status FROM core_multiempresa_companies WHERE id='"+cid+"'")=='active','failed action keeps the company active')
 status,body,_=req('/admin/multiempresa/logs?company_id='+cid+'&status=failed');data=json.loads(body)['data']
 rows=[r for r in data['rows'] if r['stage']=='module.toggle']
 check(status==200 and any(r['details'].get('reason')=='module_update' and 'principal' in r['details'].get('diagnosis','') and r['details'].get('file')=='TenantModules.php' for r in rows),'principal retains the technical reason and source in administrative logs')
 status,body,_=req(entry+'admin/modules/uninstall/public_message_probe',headers={'Accept':'text/html'})
 check(status==403 and '<!doctype html>' in body.lower() and 'suporte' in visible(body),'browser denial is a readable page')
 check(all(x not in visible(body) for x in ['Multiempresa','central_operation','principal','PHP','SQL']),'browser denial hides module name, internal code and technology')
 status,body,_=req(entry+'admin/modules/uninstall/public_message_probe',headers={'Accept':'application/json'});data=json.loads(body)
 check(status==403 and data['error']=='central_operation' and 'suporte' in data['message'] and 'principal' not in data['message'],'JSON retains API code and sanitizes user message')
 sql("UPDATE core_multiempresa_companies SET status='suspended' WHERE id='"+cid+"'")
 status,body,_=req(entry+'admin/modules',headers={'Accept':'text/html'});check(status==503 and 'suporte' in visible(body) and 'Multiempresa' not in visible(body),'temporary unavailable screen gives generic guidance')
 sql("UPDATE core_multiempresa_companies SET status='active' WHERE id='"+cid+"'")
 source.write_text(saved_source)
 call("(new \\Multiempresa\\Application\\Modules($app))->makeAvailable('"+cid+"','public_message_probe',true);")
 status,body,_=req(entry+'admin/modules');csrf=re.search(r'name="csrf" value="([^"]+)"',body)[1]
 status,body,where=req(entry+'admin/modules/activate/public_message_probe',{'csrf':csrf})
 check(status==200 and 'changed=1' in where and 'Situação do módulo atualizada.' in visible(body),'authorized activation keeps its ordinary success message')
 status,body,_=req(entry+'admin/staff/member');check(status==200 and 'instalação principal' not in visible(body) and 'política do módulo' not in visible(body),'tenant staff screen avoids internal administration instructions')
 status,body,_=req('/admin/multiempresa');check(status==200 and 'Multiempresa' in visible(body),'principal administrative panel remains available')
 print('RESULT '+str(count)+' tenant message HTTP assertions passed',flush=True)
