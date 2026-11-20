(function(){
 'use strict';var root=document.getElementById('pme-uninstaller');if(!root)return;
 var $=function(s){return root.querySelector(s);},boot=JSON.parse($('#pme-uninstall-bootstrap').textContent),state=boot.execution,stage=0,busy=false,stopped=false;
 var h=function(v){var el=document.createElement('span');el.textContent=String(v==null?'':v);return el.innerHTML;};
 function message(text,error){var box=$('#pme-uninstall-message');box.textContent=text||'';box.hidden=!text;box.className='alert '+(error?'alert-danger':'alert-info');}
 function go(value){stage=value;root.querySelectorAll('[data-uninstall-stage]').forEach(function(el){el.hidden=Number(el.dataset.uninstallStage)!==value;});$('#pme-uninstall-stage-counter').textContent='Etapa '+(value+1)+' de 3';$('#pme-uninstall-back').disabled=busy||value===0||value===2;$('#pme-uninstall-cancel').textContent=value===2?'Voltar à lista':'Cancelar';$('#pme-uninstall-next').textContent=value===0?'Continuar':value===1?'Confirmar e executar':'Continuar';message('');}
 async function post(route,data){var body=new URLSearchParams(data);body.set(boot.csrf.name,boot.csrf.hash);var response=await fetch(boot.url+route,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:body}),result=await response.json();if(result.csrf)boot.csrf=result.csrf;if(!response.ok||!result.ok)throw new Error(result.message||'Não foi possível concluir. Revise o andamento antes de continuar.');return result.data;}
 function impact(){var text=state.warnings.map(function(w){return '<p>'+h(w)+'</p>';}).join('');if(state.shared_dns&&state.shared_dns.length){text+='<details><summary>DNS compartilhado da instalação</summary><pre>'+h(JSON.stringify(state.shared_dns,null,2))+'</pre></details>';}state.steps.forEach(function(s){text+='<details class="pme-advanced"><summary>'+h(s.label)+'</summary>';s.impact.companies.forEach(function(c){text+='<h4>'+h(c.name)+'</h4><p>'+h(c.database||'')+' · '+h(c.prefix||'')+'</p><pre>'+h([].concat(c.tables||[],c.files||[],c.backups||[]).join('\n')||(state.mode==='keep'?'Recursos preservados':'Nenhum arquivo ou tabela a excluir'))+'</pre><pre>'+h(JSON.stringify(c.resources||[],null,2))+'</pre>';});text+='<pre>'+h(JSON.stringify(s.impact.organization_resources||[],null,2))+'</pre></details>';});$('#pme-uninstall-impact').innerHTML=text;}
 function progress(){
  var ready=state.status==='ready',failed=state.status==='failed',total=state.mode==='keep'?1:state.steps.length+1,completed=ready?total:state.step,percent=Math.round(completed/total*100),bar=$('#pme-uninstall-bar');
  $('#pme-uninstall-counter').textContent=completed+' de '+total+' etapas concluídas';$('#pme-uninstall-percent').textContent=percent+'%';bar.style.width=percent+'%';bar.dataset.percent=percent;bar.setAttribute('aria-valuenow',percent);
  $('#pme-uninstall-heading').textContent=ready?'Recursos conferidos':failed?'Remoção interrompida':'Executando operação';
  $('#pme-uninstall-steps').innerHTML=state.steps.map(function(s){return '<li><span>'+h(s.label)+'</span><small>'+h({done:'Removida',preserved:'Preservada',pending:'Pendente'}[s.status]||s.status)+'</small></li>';}).join('');
  $('#pme-uninstall-outcome').textContent=ready?'A instalação principal está preservada. Clique em Finalizar remoção para excluir o código do módulo.':failed?state.diagnosis:'Ao voltar à lista, a etapa atual termina e o restante fica pausado para continuar depois.';
  $('#pme-uninstall-next').textContent=ready?'Finalizar remoção':failed?'Revisar recursos restantes':busy?'Executando…':'Continuar remoção';$('#pme-uninstall-next').disabled=busy;
 }
 async function execute(){busy=true;stopped=false;try{progress();while(state.status==='running'&&!stopped){state=await post('uninstall_step',{id:state.id});progress();}}catch(error){message(error.message,true);}finally{busy=false;progress();}}
 $('#pme-uninstall-form').onsubmit=async function(event){event.preventDefault();if(busy)return;busy=true;var button=$('#pme-uninstall-next');button.disabled=true;try{
  if(stage===0){state=await post('uninstall_plan',{mode:this.elements.mode.value});go(1);impact();}
  else if(stage===1){state=await post('uninstall_confirm',{id:state.id,confirmation:this.elements.confirmation.value,accepted:this.elements.accepted.checked?'1':'0'});go(2);await execute();}
  else if(state.status==='ready'){var finish=$('#pme-uninstall-finish');finish.elements.id.value=state.id;finish.elements[boot.csrf.name].value=boot.csrf.hash;finish.submit();}
  else if(state.status==='failed'){go(0);}
  else await execute();
 }catch(error){message(error.message,true);}finally{busy=false;button.disabled=false;}};
 $('#pme-uninstall-back').onclick=function(){if(!busy&&stage===1)go(0);};
 $('#pme-uninstall-cancel').onclick=function(){stopped=true;location.assign(boot.modules_url);};
 $('#pme-open-uninstall').onclick=function(){window.jQuery($('#pme-uninstall-modal')).modal('show');};
 window.jQuery($('#pme-uninstall-modal')).on('hidden.bs.modal',function(){stopped=true;});
 if(state){go(2);progress();}else go(0);$('#pme-open-uninstall').click();
})();
