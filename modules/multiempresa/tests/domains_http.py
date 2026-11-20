"""Custom-host front controller mapping and pending HTTPS fallback, on local HTTP only."""
import http.client,json
from urllib.parse import urlencode

def run(sql,DB):
 n=0
 def check(ok,label):
  nonlocal n
  assert ok,label
  n+=1;print('PASS domains '+label,flush=True)
 def local(host,path):
  conn=http.client.HTTPConnection('127.0.0.1',8879,timeout=20)
  try:
   conn.request('GET',path,headers={'Host':host});r=conn.getresponse();return r.status,r.read().decode(errors='replace'),dict(r.getheaders())
  finally:conn.close()
 company=sql("SELECT id FROM core_multiempresa_companies WHERE slug='alfa'");org=sql("SELECT organization_id FROM core_multiempresa_companies WHERE id='"+company+"'")
 sql("INSERT INTO core_multiempresa_hosts(host,company_id,verified) VALUES('alpha.example.com','"+company+"',0)")
 try:
  code,body,_=local('alpha.example.com','/__pme_domain_check');proof=json.loads(body)
  check(code==200 and len(proof['proof'])==64,'pending host answers installation proof without entering native principal')
  code,body,_=local('alpha.example.com','/admin');check(code==421 and 'Este endereço não está disponível' in body,'unverified domain cannot enter company or principal')
  code,body,_=local('unknown.example.com','/admin');check(code==421,'unknown wildcard hostname rejected before principal bootstrap')
  sql("UPDATE core_multiempresa_hosts SET verified=1 WHERE host='alpha.example.com'")
  code,body,_=local('alpha.example.com','/authentication/login');check(code==200 and 'alpha.example.com' in body,'verified domain reaches native tenant without server rewrite changes')
  code,body,_=local('alpha.example.com','/o/grupo/e/beta/admin');check(code==403,'custom domain cannot select another company')
  filename=sql("SELECT value FROM `"+DB+"_alfa`.emp_options WHERE name='company_logo'")
  sql("UPDATE core_multiempresa_companies SET status='maintenance' WHERE id='"+company+"'")
  code,body,headers=local('alpha.example.com','/uploads/company/'+filename);check(code==200 and '<svg' in body and 'sandbox' in headers.get('Content-Security-Policy',''),'public logo remains available during module maintenance with SVG sandbox')
  code,body,headers=local('alpha.example.com','/__pme/branding?'+urlencode({'file':filename}))
  check(code==200 and '<svg' in body and headers.get('Content-Type')=='image/svg+xml','extensionless branding endpoint works on a verified custom domain during maintenance')
 finally:
  sql("DELETE FROM core_multiempresa_hosts WHERE host='alpha.example.com'")
  sql("UPDATE core_multiempresa_companies SET status='active' WHERE id='"+company+"'")
 print('RESULT %d native domain assertions passed'%n,flush=True)
