'use strict';
const fs=require('fs'),path=require('path'),cp=require('child_process'),{JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'..'),php=process.env.PHP_BIN||'php',source=fs.readFileSync(path.join(root,'assets/nfse_nacional.js'),'utf8');
const fixture=String.raw`
define('BASEPATH',__DIR__);require $argv[1].'/autoload.php';$readOnly=$argv[2]==='readonly';
function nfse_nacional_can($c){return !$GLOBALS['readOnly']||$c!=='national_sync';}function admin_url($p=''){return 'https://crm.example.test/admin/'.$p;}
function init_head(){}function init_tail(){}function form_open($url,$attributes=[]){$s='<form action="'.$url.'" method="post"';foreach($attributes as $k=>$v)$s.=' '.$k.'="'.$v.'"';return $s.'><input name="csrf" type="hidden" value="one">';}function form_close(){return '</form>';}
function &get_instance(){static $ci;return $ci??=(object)['security'=>new class{function get_csrf_token_name(){return 'csrf';}function get_csrf_hash(){return 'one';}}];}
$direction='issued';$filters=['environment'=>'2'];$configuration_ready=true;$can_sync=!$readOnly;$sync_run=['status'=>'pending','processed'=>0,'batches'=>0,'last_error'=>null,'next_run_at'=>'2020-01-01 00:00:00'];echo '<!doctype html><html><body>';require $argv[1].'/views/national.php';echo '</body></html>';
`;
let checks=0;function check(x,msg){if(!x)throw Error(msg);checks++;}
async function create(kind='writer',state={status:'pending',processed:0,batches:0,next_attempt_at:'2020-01-01 00:00:00'}){
 const html=cp.execFileSync(php,['-r',fixture,root,kind],{encoding:'utf8'}),dom=new JSDOM(html,{url:'https://crm.example.test/admin/nfse_nacional/national/issued',runScripts:'outside-only'});let timers=new Map(),id=0,calls=[];const w=dom.window;
 w.setTimeout=(fn,ms)=>{timers.set(++id,{fn,ms});return id;};w.clearTimeout=id=>timers.delete(id);
 w.fetch=async(url,options={})=>{calls.push({url:String(url),method:options.method||'GET',body:options.body?Object.fromEntries(options.body.entries()):null});return{ok:true,status:200,json:async()=>({ok:true,data:{can_process:kind!=='readonly'&&kind!=='other-owner',run:(options.method==='POST'?{status:'pending',processed:50,batches:1,last_worker_at:'2026-11-17 21:00:00',next_attempt_at:'2020-01-01 00:00:00'}:state)},csrf:{name:'csrf',hash:'two'}})};};
 w.eval(source);w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
 async function tick(){let entry=[...timers].find(([,v])=>v.ms===1000||v.ms===10000);if(!entry)throw Error('no progress timer');timers.delete(entry[0]);await entry[1].fn();await Promise.resolve();}
 return{dom,w,d:w.document,calls,timers,tick};
}
(async()=>{
 let p=await create();check(p.d.querySelector('[data-sync-status]').textContent==='Agendada','SSR status is human-readable before polling');await p.tick();
 check(p.calls.length===2&&p.calls[0].method==='GET'&&p.calls[1].method==='POST','progress GET is read-only and continuation uses separate POST');
 check(p.calls[1].url.endsWith('/national_sync_process')&&p.calls[1].body.environment==='2'&&p.calls[1].body.csrf==='two','continuation posts only environment with native CSRF');
 check(p.d.querySelector('[data-sync-count]').textContent==='50'&&p.d.querySelector('[data-sync-batches]').textContent==='1','first continuation updates actual committed progress');
 check(p.d.querySelector('[data-sync-worker]').textContent!=='Nenhum lote processado nesta execução','last worker activity is visible');
 check([...p.timers.values()].some(t=>t.ms===10000),'next batch continues automatically without another click');
 check(p.d.querySelector('[data-nfse-form="national-sync"] button[type=submit]').disabled===false,'form is released after bounded processing');p.w.close();
 p=await create('writer',{status:'waiting',processed:50,batches:1,next_attempt_at:'2099-01-01 00:00:00'});await p.tick();check(p.calls.length===1&&p.calls[0].method==='GET','authority cooldown never causes an early processing POST');check(p.d.querySelector('[data-sync-status]').textContent==='Aguardando próxima consulta','waiting is distinguished from completion');p.w.close();
 p=await create('other-owner');await p.tick();check(p.calls.length===1,'another authorized user reads progress without repeatedly posting to an execution they have not resumed');p.w.close();
 p=await create('readonly');await p.tick();check(p.calls.length===1&&p.calls[0].method==='GET','report-only user cannot trigger automatic POST');p.w.close();
 p=await create('writer',{status:'completed',processed:150,batches:4,next_attempt_at:'2020-01-01 00:00:00'});await p.tick();check(p.calls.length===1&&p.d.querySelector('[data-sync-status]').textContent==='Concluída','completed run never processes again');check(![...p.timers.values()].some(t=>t.ms===10000),'completed run stops polling');p.w.close();
 p=await create('writer',{status:'pending',processed:0,batches:0,worker_stalled:true,next_attempt_at:'2099-01-01 00:00:00'});await p.tick();check(p.d.querySelector('[data-sync-message]').textContent.includes('cron do CRM'),'stalled queue explains the missing background execution');p.w.close();
 console.log(`OK: ${checks} verificações DOM de sincronização: continuidade POST/CSRF, progresso, pausa, leitura e conclusão.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
