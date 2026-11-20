'use strict';
const fs=require('fs'),path=require('path'),cp=require('child_process');
const {JSDOM}=require('jsdom');
const modulePath=path.resolve(__dirname,'..');
const fixture=String.raw`
define('BASEPATH', __DIR__); require $argv[1].'/autoload.php';
function admin_url($p=''){return 'https://crm.example.test/admin/'.$p;}
function nfse_nacional_can($p){return true;}
function &get_instance(){static $ci; if(!$ci)$ci=(object)['security'=>new class{function get_csrf_token_name(){return 'csrf';}function get_csrf_hash(){return 'a';}}];return $ci;}
$settings=\NfseNacional\Config::defaults();$settings['accounting_recipients']=[];
for($i=0;$i<15;$i++)$settings['accounting_recipients'][]=['id'=>str_pad(dechex($i+1),16,'0',STR_PAD_LEFT),'name'=>'Pessoa '.$i,'email'=>'p'.$i.'@example.test'];
echo '<!doctype html><html><body><form id="settings-form">'; require $argv[1].'/views/settings.php';echo '</form></body></html>';
`;
const html=cp.execFileSync(process.env.PHP_BIN||'php',['-r',fixture,modulePath],{encoding:'utf8'});
if(/Fatal error:|Parse error:/.test(html))throw new Error(html);
let n=0;const check=(v,m)=>{if(!v)throw new Error(m);n++;};
const pause=()=>new Promise(r=>setTimeout(r,30));
(async()=>{
 const dom=new JSDOM(html,{url:'https://crm.example.test/admin/settings',runScripts:'outside-only'});const w=dom.window,d=w.document;
 w.HTMLElement.prototype.scrollIntoView=function(){};
 let rows=Array.from(d.querySelectorAll('[data-nfse-email-body] tr')).map(r=>({id:r.dataset.id,name:r.children[0].textContent,email:r.children[1].textContent}));
 let requests=[];
 w.fetch=async(url,options)=>{const data=Object.fromEntries(options.body.entries());requests.push({url,data});
  check(data.csrf==='a'||data.csrf==='b','CSRF on row mutation');
  if(url.includes('delete'))rows=rows.filter(r=>r.id!==data.id);
  else{let row=rows.find(r=>r.id===data.id);if(row)Object.assign(row,{name:data.name,email:data.email});else rows.push({id:'eeeeeeeeeeeeeeee',name:data.name,email:data.email});}
  return {ok:true,status:200,json:async()=>({ok:true,message:'Salvo',csrf:{name:'csrf',hash:'b'},data:{recipients:rows}})};
 };
 w.eval(fs.readFileSync(path.join(modulePath,'assets/nfse_nacional.js'),'utf8')); d.dispatchEvent(new w.Event('DOMContentLoaded'));await pause();
 check(d.querySelectorAll('[data-nfse-email-body] tr').length===15,'all recipients shown above ten');
 const search=d.querySelector('[data-nfse-email-search]');search.value='Pessoa 14';search.dispatchEvent(new w.Event('input',{bubbles:true}));check(d.querySelectorAll('[data-nfse-email-body] tr:not([hidden])').length===1,'grid search');search.value='';search.dispatchEvent(new w.Event('input',{bubbles:true}));
 d.querySelector('[data-nfse-email-add]').click();let row=d.querySelector('[data-nfse-email-body] tr');check(row.querySelectorAll('input').length===2,'new inline editor');
 row.querySelector('input[type=text]').value='<script>escaped</script>';row.querySelector('input[type=email]').value='new@example.test';row.querySelector('[data-email-save]').click();await pause();
 check(requests.length===1&&rows.length===16,'individual save');check(!d.querySelector('[data-nfse-email-body] script'),'names rendered as text');
 row=d.querySelector('[data-nfse-email-body] tr');row.querySelector('[data-email-edit]').click();row.querySelector('input[type=text]').value='Alterado';row.querySelector('[data-email-save]').click();await pause();check(rows[0].name==='Alterado','edit keeps existing identity');
 row=d.querySelector('[data-nfse-email-body] tr');row.querySelector('[data-email-delete]').click();await pause();check(rows.length===15&&requests.length===3,'delete individual recipient');
 d.querySelector('[data-nfse-email-add]').click();d.querySelector('[data-email-cancel]').click();check(!d.querySelector('[data-nfse-email-body] input'),'cancel new editor without saving');
 const toggle=d.querySelector('[data-nfse-variable-rate]');toggle.checked=true;toggle.dispatchEvent(new w.Event('change',{bubbles:true}));check(d.querySelector('[name="settings[tax][simple_rate]"]').readOnly,'variable percentage cannot be statically edited');
 console.log(`OK: ${n} verificações DOM da grid e alíquota variável.`);w.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
