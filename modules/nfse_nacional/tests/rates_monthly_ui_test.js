'use strict';
const fs=require('fs'),path=require('path'),cp=require('child_process'),{JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BIN||'php';
const fixture=String.raw`
define('BASEPATH',__DIR__);require $argv[1].'/autoload.php';
function nfse_nacional_can($cap){return true;}function admin_url($p=''){return 'https://crm.example.test/admin/'.$p;}
function init_head(){}function init_tail(){}function form_open($url,$attributes=[]){$s='<form action="'.$url.'" method="post"';foreach($attributes as $k=>$v)$s.=' '.$k.'="'.$v.'"';return $s.'><input name="csrf" type="hidden" value="one">';}function form_close(){return '</form>';}
function &get_instance(){static $ci;return $ci??=(object)['security'=>new class{function get_csrf_token_name(){return 'csrf';}function get_csrf_hash(){return 'one';}}];}
echo '<!doctype html><html><body>';
if($argv[2]==='settings'){$settings=\NfseNacional\Config::defaults();$settings['variable_simple_rate']='1';$settings['tax']['simple_rate']='2.00';$settings['tax']['iss_rate']='2.50';$settings['tax']['total_tax_mode']='ibpt';$rate_month='2026-11';$monthly_rate=['rate'=>'11.41'];$monthly_iss_rate=['rate'=>'3.25'];echo '<form>';require $argv[1].'/views/settings.php';echo '</form>';}
else{$rate_type=$argv[2]==='rates-iss'?'iss':'simple';$rates=[['competence'=>'2026-11','rate'=>$rate_type==='iss'?'3.25':'11.41','updated_at'=>'2026-11-16 22:30:00']];require $argv[1].'/views/competence_rates.php';}
echo '</body></html>';
`;
let n=0;const check=(ok,msg)=>{if(!ok)throw Error(msg);n++;};const pause=()=>new Promise(r=>setTimeout(r,20));
function create(kind){let html=cp.execFileSync(php,['-r',fixture,root,kind],{encoding:'utf8'});let dom=new JSDOM(html,{url:'https://crm.example.test/admin/nfse_nacional/competence_rates',runScripts:'outside-only'});dom.window.HTMLElement.prototype.scrollIntoView=function(){};return dom;}
(async()=>{
 let dom=create('settings'),w=dom.window,d=w.document;w.fetch=async()=>({ok:true,json:async()=>({ok:true,data:{rate:'12.55'}})});
 w.eval(fs.readFileSync(path.join(root,'assets/nfse_nacional.js'),'utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));await pause();
 const percent=d.querySelector('[name="settings[tax][simple_rate]"]'),mode=d.querySelector('[name="settings[tax][total_tax_mode]"]');
 check(percent.value==='11.41'&&percent.readOnly,'saved monthly rate appears in Simples field');check(mode.value==='ibpt'&&!mode.disabled,'variable rate does not force or disable IBPT mode');
 for(const value of ['none','amount','simple','ibpt']){mode.value=value;mode.dispatchEvent(new w.Event('change',{bubbles:true}));check(mode.value===value&&!mode.disabled,'estimation method remains selectable: '+value);}
 for(const name of ['iss_taxation','retention','iss_rate']){let field=d.querySelector('[name="settings[tax]['+name+']"]');check(!field.disabled&&!field.readOnly,'other tax control stays editable: '+name);}
 const toggle=d.querySelector('[data-nfse-variable-rate]');toggle.checked=false;toggle.dispatchEvent(new w.Event('change',{bubbles:true}));check(percent.value==='2.00'&&!percent.readOnly,'disabling restores fixed percentage');toggle.checked=true;toggle.dispatchEvent(new w.Event('change',{bubbles:true}));check(percent.value==='11.41'&&mode.value==='ibpt','re-enabling restores month without changing method');
 const issToggle=d.querySelector('[data-nfse-variable-iss-rate]'),shared=d.querySelector('[data-nfse-shared-rate]'),iss=d.querySelector('[name="settings[tax][iss_rate]"]');
 const change=field=>field.dispatchEvent(new w.Event('change',{bubbles:true}));
 issToggle.checked=true;change(issToggle);check(iss.value==='3.25'&&iss.readOnly&&percent.value==='11.41','both monthly percentages can be separate');
 shared.checked=true;change(shared);check(iss.value==='11.41'&&percent.value==='11.41','explicit sharing copies the integral percentage');check(d.querySelector('[data-nfse-settings-rate-status]').textContent.includes('superior a 5%'),'shared rate exceeding ISS limit is explained without conversion');
 toggle.checked=false;change(toggle);check(percent.value==='2.00'&&!percent.readOnly&&iss.value==='11.41'&&iss.readOnly,'ISS alone can reuse the monthly Simples register');
 shared.checked=false;change(shared);check(iss.value==='3.25'&&percent.value==='2.00','ISS alone can use its independent register');
 issToggle.checked=false;change(issToggle);check(iss.value==='2.50'&&!iss.readOnly,'disabling ISS monthly restores its own fixed percentage');
 toggle.checked=true;change(toggle);issToggle.checked=true;change(issToggle);w.fetch=async()=>({ok:true,json:async()=>({ok:true,data:{rate:'12.55',iss_rate:'4.50'}})});
 let month=d.querySelector('[data-nfse-settings-rate-month]');month.value='2026-12';month.dispatchEvent(new w.Event('change',{bubbles:true}));await pause();check(percent.value==='12.55'&&iss.value==='4.50'&&mode.value==='ibpt','another competence independently updates both percentages');w.fetch=async()=>({ok:true,json:async()=>({ok:true,data:{rate:null}})});month.value='2027-01';month.dispatchEvent(new w.Event('change',{bubbles:true}));await pause();check(percent.value===''&&iss.value===''&&percent.readOnly&&mode.value==='ibpt','missing month never falls back to fixed rate or changes method');check(d.querySelector('[data-nfse-settings-rate-status]').textContent.includes('Sem alíquota'),'missing competence explains the required registration without disabling settings');dom.window.close();
 dom=create('rates');w=dom.window;d=w.document;let posts=[],dataTableCalls=0;
 const jq=function(){return{each(){dataTableCalls++;},on(){},data(){}}};jq.fn={DataTable(){dataTableCalls++;},dataTable:{isDataTable(){return false;}}};w.jQuery=jq;
 w.fetch=async(url,options)=>{posts.push(Object.fromEntries(options.body.entries()));return{ok:true,status:200,json:async()=>({ok:true,message:'Salvo',data:{},csrf:{name:'csrf',hash:'two'}})};};
 w.eval(fs.readFileSync(path.join(root,'assets/nfse_nacional.js'),'utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));await pause();
 check(dataTableCalls===0,'rates never initialize a DataTable with inherited CRM AJAX/skeleton defaults');check(d.querySelector('[data-nfse-rates-grid]').textContent.includes('11,41%'),'existing month remains visible before AJAX');
 d.querySelector('[data-nfse-rate-edit]').click();let form=d.querySelector('[data-nfse-rate-form]');check(form.querySelector('[name=competence]').value==='11/2026'&&form.querySelector('[name=original_competence]').value==='11/2026','Edit selects original month');check(form.querySelector('[name=competence]').readOnly&&form.querySelector('[name=rate]').value==='11.41','edit keeps month fixed and percentage editable');
 form.querySelector('[name=rate]').value='11.55';form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await pause();check(posts.length===1&&posts[0].original_competence==='11/2026'&&posts[0].rate==='11.55'&&posts[0].csrf==='one','edit submits existing row and CSRF rather than creating duplicate');
 d.querySelector('[data-nfse-rate-new]').click();check(form.querySelector('[name=original_competence]').value===''&&!form.querySelector('[name=competence]').readOnly,'New clears edit identity without touching stored rows');dom.window.close();
 dom=create('rates-iss');w=dom.window;d=w.document;let issPosts=[];w.fetch=async(url,options)=>{issPosts.push(Object.fromEntries(options.body.entries()));return{ok:true,status:200,json:async()=>({ok:true,message:'Salvo',data:{},csrf:{name:'csrf',hash:'two'}})};};w.eval(fs.readFileSync(path.join(root,'assets/nfse_nacional.js'),'utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));await pause();check(d.querySelector('[data-nfse-rates-grid]').textContent.includes('3,25%')&&d.querySelector('.nav-tabs .active').textContent.includes('ISSQN'),'ISS grid displays independent register and active tab');d.querySelector('[data-nfse-rate-edit]').click();form=d.querySelector('[data-nfse-rate-form]');form.querySelector('[name=rate]').value='3.50';form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await pause();check(issPosts.length===1&&issPosts[0].rate_type==='iss'&&issPosts[0].original_competence==='11/2026'&&issPosts[0].rate==='3.50','editing ISS submits its type without overwriting Simples');dom.window.close();
 console.log(`OK: ${n} verificações DOM de grade sem skeleton, edição mensal e controles fiscais independentes.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
