(function(){
'use strict';
var root=document.querySelector('.pme-legacy');if(!root)return;
var script=document.currentScript,endpoint=script&&script.dataset.panelApi;
var grant=root.querySelector('input[name="action"][value="grant"]');
if(grant&&endpoint){
 var form=grant.form,select=form.elements.staff_id,company=form.elements.company_id,search=form.querySelector('[data-staff-search]'),message=form.querySelector('[data-staff-message]'),serial=0,timer;
 async function load(){
  var request=++serial;select.disabled=true;select.replaceChildren(new Option('Carregando colaboradores…',''));form.elements.email.value='';
  try{var url=new URL(endpoint,location.href);url.searchParams.set('company_id',company.value);url.searchParams.set('search',search.value);var response=await fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}),payload=await response.json();if(request!==serial)return;if(!response.ok||payload.error)throw new Error(payload.message||payload.error?.message||'Não foi possível consultar os colaboradores.');
   var data=payload.data;select.replaceChildren(new Option('Selecione um colaborador',''));data.rows.forEach(function(staff){var option=new Option((staff.firstname+' '+staff.lastname).trim()+' · '+staff.email,staff.staffid);option.dataset.email=staff.email;select.add(option);});select.disabled=!data.rows.length;message.textContent=data.rows.length?'Até 50 resultados. Pesquise para localizar outros colaboradores. A identidade central deve ter o mesmo e-mail.':'Nenhum colaborador ativo encontrado nesta empresa.';
  }catch(error){if(request!==serial)return;select.replaceChildren(new Option('Consulta indisponível',''));message.textContent=error.message;}
 }
 company.addEventListener('change',function(){search.value='';load();});search.addEventListener('input',function(){serial++;clearTimeout(timer);timer=setTimeout(load,250);});select.addEventListener('change',function(){form.elements.email.value=select.selectedOptions[0]?.dataset.email||'';});form.addEventListener('submit',function(event){if(select.disabled||!select.value||!form.elements.email.value){event.preventDefault();message.textContent='Selecione um colaborador ativo antes de autorizar.';}});load();
}
var restore=root.querySelector('input[name="action"][value="restore"]');
if(restore){var restoreForm=restore.form,choices=Array.from(restoreForm.querySelectorAll('[data-backup-choice]')),catalogs=choices.map(function(s){return Array.from(s.options).map(function(o){return o.cloneNode(true);});});function refresh(){choices.forEach(function(s,i){s.replaceChildren.apply(s,catalogs[i].filter(function(o){return !o.dataset.company||o.dataset.company===restoreForm.elements.company_id.value;}).map(function(o){return o.cloneNode(true);}));});}restoreForm.elements.company_id.addEventListener('change',refresh);choices.forEach(function(s){s.addEventListener('change',function(){if(s.value)choices.filter(function(other){return other!==s;}).forEach(function(other){other.value='';});});});refresh();}
})();
