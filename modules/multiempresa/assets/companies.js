(function () {
  'use strict';
  var root = document.getElementById('pme-companies');
  if (!root) return;
  var boot = JSON.parse(document.getElementById('pme-bootstrap').textContent);
  var $ = function (s, node) { return (node || root).querySelector(s); };
  var $$ = function (s, node) { return Array.from((node || root).querySelectorAll(s)); };
  var h = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var labels = {draft:'Cadastro pendente',active:'Ativa',suspended:'Suspensa',provisioning:'Provisionando',maintenance:'Em manutenção',failed:'Requer atenção',removed:'Removida',interrupted:'Interrompida'};
  var classes = {draft:'neutral',active:'success',suspended:'neutral',provisioning:'info',maintenance:'warning',failed:'danger',removed:'neutral',interrupted:'danger'};
  var query = {page:1,size:20,sort:'name',direction:'asc'}, gridSeq = 0, rows = [], currentEdit = null, removeId = null;
  var wizard = $('#pme-wizard'), form = $('#pme-wizard-form'), stage = 0, requestKey = '', workflow = null, running = false, stop = false, slugEdited = false;
  var mutation = Promise.resolve(), lastFocus = null, moduleBatch = false, revision = null, visited = 0, adminChoiceTouched = false, creatorChoiceTouched = false;

  function message(node, text, error) { node.textContent = text || ''; node.hidden = !text; node.classList.toggle('pme-alert-error', !!error);node.classList.toggle('alert-danger',!!error);node.classList.toggle('alert-info',!error); }
  function show(dialog) {
    var previous=$$('.modal').find(function(el){return el!==dialog&&(el.classList.contains('in')||el.dataset.pmeClosing==='1');});
    if(previous){window.jQuery(previous).one('hidden.bs.modal',function(){show(dialog);});if(previous.dataset.pmeClosing!=='1')close(previous);return;}
    lastFocus=document.activeElement;if(!dialog.classList.contains('in'))window.jQuery(dialog).modal({show:true,keyboard:true,backdrop:true});
  }
  function close(dialog) {
    if(!dialog)return;if(dialog===wizard){stop=true;var current=new URL(location.href);current.searchParams.delete('provision');history.replaceState(null,'',current);}
    if(dialog.classList.contains('in')){dialog.dataset.pmeClosing='1';window.jQuery(dialog).modal('hide');}
  }
  function url(name, params) { var u = new URL(boot.url + name, location.href); Object.keys(params || {}).forEach(function(k) { if (params[k] != null) u.searchParams.set(k, params[k]); }); return u; }
  async function request(name, params, post) {
    var settings = {credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}};
    var address;
    if (post) {
      var body = params instanceof FormData ? params : new URLSearchParams();
      function append(k,v) { if(Array.isArray(v))v.forEach(function(x){append(k+'[]',x);});else if(v&&typeof v==='object')Object.keys(v).forEach(function(x){append(k+'['+x+']',v[x]);});else body.append(k,v==null?'':v); }
      if(!(params instanceof FormData))Object.keys(params).forEach(function(k){append(k,params[k]);});
      body.set(boot.csrf.name, boot.csrf.hash); settings.method='POST'; settings.body=body; address=url(name);
    } else address=url(name, params);
    var response = await fetch(address, settings), result;
    try { result=await response.json(); } catch (_) { var invalid=new Error('A sessão expirou ou o servidor não respondeu. Atualize a página e consulte o andamento antes de reenviar.'); invalid.code='network'; throw invalid; }
    if (result.csrf) {
      boot.csrf=result.csrf;
      $$('input[name="'+boot.csrf.name+'"]').forEach(function(el) { el.value=boot.csrf.hash; });
      if (window.csrfData) window.csrfData.hash=boot.csrf.hash;
    }
    if (!response.ok || !result.ok) { var error=new Error(result.message || 'Não foi possível concluir a operação.'); error.code=result.error; throw error; }
    return result.data;
  }
  function post(operation, data) {
    var task=mutation.then(function() { return request('company_action', Object.assign({}, data, {operation:operation}), true); });
    mutation=task.catch(function() {}); return task;
  }
  var connectionGeneration=0;
  function connectionResult(state,text){
    var node=$('#pme-connection-result');node.hidden=!text;node.textContent=text||'';
    ['alert-info','alert-success','alert-danger'].forEach(function(c){node.classList.remove(c);});
    if(text)node.classList.add(state==='success'?'alert-success':state==='error'?'alert-danger':'alert-info');
    node.dataset.state=state;node.setAttribute('aria-busy',state==='loading'?'true':'false');
  }
  function resetConnection(){connectionGeneration++;connectionResult('idle','');}
  form.addEventListener('input',function(e){if(e.target.closest('[data-step="1"]'))resetConnection();});
  form.addEventListener('change',function(e){if(e.target.closest('[data-step="1"]')||e.target.name==='adopt'||e.target.name==='organization_id')resetConnection();});
  function fieldData(f) { var data={}; new FormData(f).forEach(function(v,k) { if(!(v instanceof File)&&k!=='certificate_password')data[k]=v; }); return data; }
  function wizardData() { var p=fieldData(form); p.modules=$$('input[name="modules[]"]:checked',form).map(function(el) { return el.value; }); delete p['modules[]'];p.activate_modules=$$('input[name="activate_modules[]"]:checked:not(:disabled)',form).map(function(el){return el.value;});delete p['activate_modules[]'];p.grant_creator=form.elements.grant_creator.checked?'1':'0';p.selection_present='1'; p.request_key=requestKey;p.slug=form.elements.slug.value;p.organization_id=form.elements.organization_id.value;p.certificate_required=form.elements.certificate_file.files.length?'1':'0'; return p; }

  async function loadGrid() {
    var seq=++gridSeq; $('#pme-grid').setAttribute('aria-busy','true');
    try {
      var data=await request('companies',query);
      if(seq!==gridSeq)return;
      rows=data.rows;query.page=data.page;
      var counts=data.summary, total=Object.keys(counts).filter(function(k){return k!=='removed';}).reduce(function(n,k){return n+Number(counts[k]);},0);
      $('[data-metric="total"]').textContent=total;
      $('[data-metric="active"]').textContent=counts.active || 0;
      $('[data-metric="suspended"]').textContent=counts.suspended || 0;
      $('[data-metric="attention"]').textContent=Number(counts.provisioning||0)+Number(counts.maintenance||0)+Number(counts.failed||0)+Number(counts.draft||0);
      $('#pme-grid-body').innerHTML=rows.map(function(c) {
        var progress=c.onboarding_id && !['draft','done','cancelled'].includes(c.onboarding_status);
        var status=c.onboarding_status==='interrupted'?'interrupted':(progress && !['failed','draft'].includes(c.status)?'provisioning':c.status);
        var actions=[],advanced=[];
        if(c.managed)actions.push('<button type="button" class="btn btn-link pme-text-button" data-company-logs="'+h(c.id)+'">Logs</button>');
        if(c.managed&&boot.canCreate&&c.onboarding_status==='draft')actions.push('<button type="button" class="btn btn-link pme-text-button" data-start-draft="'+h(c.id)+'">Provisionar</button>');
        if(c.managed&&boot.canEdit&&c.status!=='removed'&&c.onboarding_status!=='draft')advanced.push('<button type="button" class="btn btn-link pme-text-button" data-impact="'+(c.onboarding_status&&c.onboarding_status!=='done'?'retry':'reprovision')+'" data-target="'+h(c.id)+'">'+(c.onboarding_status&&c.onboarding_status!=='done'?'Retomar':'Reprovisionar')+'</button>');
        if(c.open_url)actions.push('<a class="btn btn-default pme-btn pme-btn-small pme-btn-secondary" href="'+h(c.open_url)+'">Abrir <i class="fa fa-external-link" aria-hidden="true"></i></a>');
        if(c.managed && progress)actions.push('<button type="button" class="btn btn-link pme-text-button" data-resume="'+h(c.onboarding_id)+'">Andamento</button>');
        if(c.managed && c.status!=='removed')actions.push('<button type="button" class="btn btn-link pme-text-button" data-edit="'+h(c.id)+'">'+(boot.page==='modules'?'Gerenciar módulos':(boot.canEdit?'Editar':'Consultar'))+'</button>');
        if(c.managed && !progress && ['active','suspended'].includes(c.status) && boot.canEdit)actions.push('<button type="button" class="btn btn-link pme-text-button" data-backup="'+h(c.id)+'" aria-label="Agendar backup de '+h(c.name)+'" title="Agendar backup"><i class="fa fa-archive" aria-hidden="true"></i></button>');
        if(c.managed && boot.canDelete)advanced.push('<button type="button" class="btn btn-link pme-text-button pme-danger-text" data-impact="delete_company" data-target="'+h(c.id)+'" aria-label="Remover '+h(c.name)+'"><i class="fa fa-trash-o" aria-hidden="true"></i> Remover</button>');
        return '<tr><td><div class="pme-company-cell"><span class="pme-company-avatar" aria-hidden="true">'+h(c.name.slice(0,2).toUpperCase())+'</span><div><strong>'+h(c.name)+'</strong><small>'+h(c.slug)+'</small></div></div></td><td>'+h(c.organization_name)+'</td><td><span>'+h(c.mode==='database'?'Banco exclusivo':c.mode==='prefix'?'Banco compartilhado':c.mode)+'</span><small>'+h(c.table_prefix||(c.mode==='database'?'Sem prefixo':''))+'</small></td><td><span class="pme-module-count">'+Number(c.module_count)+'</span> disponíveis<small>'+Number(c.active_module_count||0)+' ativos</small></td><td><span class="pme-chip pme-chip-'+(classes[status]||'neutral')+'"><span aria-hidden="true">●</span> '+h(labels[status]||status)+'</span></td><td><div class="pme-row-actions">'+actions.join('')+(advanced.length?'<details class="pme-actions-menu"><summary>Mais ações</summary>'+advanced.join('')+'</details>':'')+'</div></td></tr>';
      }).join('') || '<tr><td colspan="6"><div class="pme-empty"><i class="fa fa-building-o" aria-hidden="true"></i><strong>'+(query.search||query.status||query.organization_id?'Nenhuma empresa encontrada':'Seu primeiro ambiente começa aqui')+'</strong><p>'+(query.search||query.status||query.organization_id?'Ajuste a pesquisa ou os filtros.':'Clique em Nova empresa para iniciar o assistente de implantação.')+'</p></div></td></tr>';
      $('#pme-grid-count').textContent=data.total?((data.page-1)*data.size+1)+'–'+Math.min(data.page*data.size,data.total)+' de '+data.total+' empresas':'Nenhuma empresa';
      $('#pme-page').textContent=data.page+' / '+data.pages;$('#pme-prev').disabled=data.page<=1;$('#pme-next').disabled=data.page>=data.pages;
    } catch(e) { if(seq===gridSeq)message($('#pme-message'),e.message,true); }
    finally { if(seq===gridSeq)$('#pme-grid').setAttribute('aria-busy','false'); }
  }
  var searchTimer;
  $('#pme-search').addEventListener('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(function(){query.search=$('#pme-search').value;query.page=1;loadGrid();},250);});
  [['#pme-org-filter','organization_id'],['#pme-status-filter','status'],['#pme-size','size']].forEach(function(pair){$(pair[0]).addEventListener('change',function(){query[pair[1]]=this.value;query.page=1;loadGrid();});});
  $('#pme-refresh').onclick=function(){message($('#pme-message'),'');loadGrid();};
  $('#pme-prev').onclick=function(){query.page--;loadGrid();};$('#pme-next').onclick=function(){query.page++;loadGrid();};
  $$('[data-sort]').forEach(function(button){button.onclick=function(){var key=button.dataset.sort;query.direction=query.sort===key&&query.direction==='asc'?'desc':'asc';query.sort=key;query.page=1;$$('th[aria-sort]').forEach(function(th){th.removeAttribute('aria-sort');});button.closest('th').setAttribute('aria-sort',query.direction==='asc'?'ascending':'descending');loadGrid();};});

  function stageTo(value) {
    stage=value;visited=Math.max(visited,value);$$('[data-step]').forEach(function(el){el.hidden=Number(el.dataset.step)!==value;});
    $$('[data-step-label]').forEach(function(el){var n=Number(el.dataset.stepLabel);el.classList.toggle('is-current',n===value);el.classList.toggle('is-complete',n<value);if(n===value)el.setAttribute('aria-current','step');else el.removeAttribute('aria-current');});
    $('#pme-save-draft').hidden=value===4||!!form.elements.company_id.value;
    $('#pme-wizard-navigation').hidden=value===4;$('#pme-completion-actions').hidden=value!==4;
    $('#pme-back').disabled=value===0||value===4;
    $('#pme-step-counter').textContent='Etapa '+(value+1)+' de 5';
    $('#pme-forward').innerHTML=value===3?(revision?'Revisar impacto':'Provisionar empresa')+' <i class="fa fa-check" aria-hidden="true"></i>':'Continuar <i class="fa fa-arrow-right" aria-hidden="true"></i>';
    $$('[data-go-step]').forEach(function(button){button.disabled=Number(button.dataset.goStep)>visited||running;});
    message($('#pme-wizard-error'),'');
    if(value===4){updateProgress(0,0,false,false);$('#pme-progress-title').textContent='Preparando o ambiente';$('#pme-progress-current').textContent='Consultando etapas…';$('#pme-progress-steps').innerHTML='';}
    if(value===3)review();
    var heading=$('[data-step="'+value+'"] h3');heading.tabIndex=-1;heading.focus();
  }
  function validateStage(value,draft) {
    var inputs=$$('input,select,textarea',$('[data-step="'+value+'"]'));
    for(var input of inputs){if(!input.disabled && !input.closest('[hidden]') && !input.checkValidity()){input.reportValidity();return false;}}
    if(value===0&&!draft&&!form.elements.admin_mode.disabled&&form.elements.admin_mode.value==='new'){var missing=['admin_name','admin_email'].some(function(k){return !form.elements[k].value.trim();})||(!form.elements.admin_password.value&&!form.dataset.adminPasswordSaved);if(missing){message($('#pme-wizard-error'),'Informe nome, e-mail e senha do administrador inicial ou autorize o vínculo do seu usuário.',true);return false;}}
    if(value===2 && $$('input[name="modules[]"]:checked',form).length && !form.elements.modules_reviewed.checked){message($('#pme-wizard-error'),'Confirme a compatibilidade dos módulos selecionados.',true);return false;}
    return true;
  }
  function moduleOptions() {
    $('#pme-module-options').innerHTML=boot.modules.map(function(m){return '<div class="pme-module-option" data-module-search="'+h((m.label+' '+m.description).toLowerCase())+'"><label class="pme-module-pick"><input type="checkbox" name="modules[]" value="'+h(m.name)+'" '+(!m.compatible?'disabled':((boot.provisioning.default_modules||[]).includes(m.name)?'checked':''))+'><span><strong>'+h(m.label)+' <small>v'+h(m.version||'—')+'</small></strong><small>'+h(m.description||'Módulo adicional do Perfex')+'</small>'+(!m.compatible?'<small class="pme-danger-text">Requer Perfex '+h(m.minimum)+'</small>':'')+'</span></label><label class="pme-module-enable"><input type="checkbox" name="activate_modules[]" value="'+h(m.name)+'" disabled><span>Ativar agora<span class="sr-only"> '+h(m.label)+'</span></span></label></div>';}).join('') || '<div class="pme-module-empty">Nenhum módulo adicional disponível. Envie os módulos na instalação principal para selecioná-los aqui. Você pode continuar apenas com os recursos do CRM.</div>';

  }
  function review() {
    var p=wizardData();p.mode=boot.provisioning.isolation;p.host=p.host||'Automático';p.port=p.port||'';p.database=p.database||'Gerado pela regra global';var names=boot.modules.filter(function(m){return p.modules.includes(m.name);}).map(function(m){return m.label;});
    var values=[['Empresa',p.name],['Organização',form.elements.organization_id.selectedOptions[0].textContent],['Identificador',p.slug],['Operação',revision?(revision.operation==='retry'?'Retomar após revisão':'Reprovisionar após revisão'):(p.adopt?'Vincular instalação existente':'Criar empresa')],['Armazenamento',form.elements.mode.selectedOptions[0].textContent],['Servidor / porta',p.host+':'+p.port],['Banco / prefixo',p.database+' / '+(form.elements.prefix.value||(form.elements.mode.value==='database'?'Sem prefixo':'Automático'))],['Idioma / fuso',p.language+' / '+p.timezone],['Administrador inicial',(revision&&revision.preserveAdministrator)||p.adopt?'Preservado':(p.admin_mode==='current'?'Meu usuário (vínculo autorizado)':p.admin_name+' · '+p.admin_email)],['Acesso do meu usuário',(revision&&revision.preserveAdministrator)||p.adopt?'Preservado':(p.admin_mode==='current'||p.grant_creator==='1'?'Autorizado explicitamente':'Não será vinculado')],['Módulos disponíveis',names.join(', ')||'Nenhum'],['Ativar agora',boot.modules.filter(function(m){return p.activate_modules.includes(m.name);}).map(function(m){return m.label;}).join(', ')||'Nenhum — ativação manual depois']];
    $('#pme-review').innerHTML=values.map(function(row){return '<div><dt>'+h(row[0])+'</dt><dd>'+h(row[1])+'</dd></div>';}).join('');
  }
  function key() { var bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);return Array.from(bytes).map(function(b){return b.toString(16).padStart(2,'0');}).join(''); }
  function newWizard() {
    if(running){message($('#pme-message'),'Aguarde a etapa atual terminar antes de abrir outro cadastro.',true);return;}
    if(form.elements.organization_id.options.length<2){if(boot.canCreateOrganization)location.assign('?tab=organizations');else message($('#pme-message'),'Você precisa administrar uma organização ativa para cadastrar uma empresa. Solicite o vínculo ao administrador.',true);return;}
    revision=null;visited=0;adminChoiceTouched=false;creatorChoiceTouched=false;form.reset();delete form.dataset.adminPasswordSaved;form.elements.company_id.value='';form.elements.slug.disabled=false;form.elements.organization_id.disabled=false;form.elements.adopt.disabled=false;form.elements.prefix.disabled=false;form.elements.certificate_file.disabled=false;form.elements.certificate_password.disabled=false;requestKey=key();workflow=null;stop=false;slugEdited=false;form.elements.admin_mode.value=(boot.userPolicies||{}).initial_admin||'new';form.elements.grant_creator.checked=!!(boot.userPolicies||{}).grant_creator_default;moduleOptions();selectedCount();
    if(form.elements.organization_id.options.length===2)form.elements.organization_id.selectedIndex=1;
    applyGlobal();adoptFields();resetConnection();$('#pme-completion-actions').innerHTML='';stageTo(0);show(wizard);
  }
  function administratorFields(){
    var disabled=form.elements.adopt.checked||!!(revision&&revision.preserveAdministrator),same=!!boot.actorOrganization&&form.elements.organization_id.value===boot.actorOrganization;
    $('#pme-initial-administrator').hidden=disabled;form.elements.admin_mode.disabled=disabled;
    form.elements.admin_mode.querySelector('option[value="current"]').disabled=!same;
    if(!same){form.elements.admin_mode.value='new';}else if(!adminChoiceTouched){form.elements.admin_mode.value=(boot.userPolicies||{}).initial_admin||'new';}
    var fresh=!disabled&&form.elements.admin_mode.value==='new';$('#pme-new-administrator').hidden=!fresh;
    ['admin_name','admin_email','admin_password','grant_creator'].forEach(function(name){form.elements[name].disabled=!fresh;});
    if(!same){form.elements.grant_creator.checked=false;form.elements.grant_creator.disabled=true;}
    else if(!creatorChoiceTouched){form.elements.grant_creator.checked=!!(boot.userPolicies||{}).grant_creator_default;}
    $('#pme-admin-scope-note').hidden=same||!form.elements.organization_id.value;
  }
  form.elements.organization_id.addEventListener('change',administratorFields);
  form.elements.admin_mode.onchange=function(){adminChoiceTouched=true;administratorFields();};
  form.elements.grant_creator.onchange=function(){creatorChoiceTouched=true;};
  function adoptFields() { administratorFields();var adopt=form.elements.adopt.checked;applyGlobal();$('#pme-adopt-fields').hidden=!adopt;form.elements.prefix.required=boot.provisioning.isolation==='prefix'&&(adopt||boot.provisioning.operation==='manual');form.elements.encryption_key.required=adopt;form.elements.local_staff_id.required=adopt; }
  if($('#pme-new'))$('#pme-new').onclick=newWizard;
  form.noValidate=true;form.elements.adopt.onchange=adoptFields;
  form.elements.slug.oninput=function(){slugEdited=true;};
  form.elements.name.oninput=function(){if(!slugEdited)form.elements.slug.value=this.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,63);};
  $('#pme-module-search').oninput=function(){var term=this.value.toLowerCase();$$('[data-module-search]').forEach(function(el){el.hidden=!el.dataset.moduleSearch.includes(term);});};
  $('#pme-back').onclick=function(){if(!running&&stage>0&&stage<4)stageTo(stage-1);};
  function applyGlobal(){
    if(revision){
      form.elements.mode.value=revision.mode;form.elements.mode.disabled=true;
      ['host','port','database','username','ssl_ca'].forEach(function(k){var el=form.elements[k];el.disabled=true;el.closest('label').hidden=false;});
      form.elements.db_password.disabled=false;form.elements.db_password.closest('label').hidden=false;form.elements.prefix.disabled=true;form.elements.prefix.required=false;form.elements.prefix.closest('label').hidden=revision.mode==='database'&&form.elements.prefix.value==='';
      $('#pme-global-summary').textContent='Destino já reservado para esta empresa. Revise os dados e os módulos; mantenha a senha em branco para conservar a conexão. Alterações só serão aplicadas após a revisão de impacto.';
      $('#pme-adopt-fields').hidden=true;$('#pme-check-connection').hidden=true;return;
    }
    $('#pme-check-connection').hidden=false;
    form.elements.mode.value=boot.provisioning.isolation;form.elements.mode.disabled=true;
    var unprefixed=boot.provisioning.isolation==='database'&&!form.elements.adopt.checked;
    form.elements.prefix.disabled=unprefixed;form.elements.prefix.closest('label').hidden=unprefixed;
    if(unprefixed)form.elements.prefix.value='';
    var automatic=boot.provisioning.operation==='automatic'&&!form.elements.adopt.checked;
    $('.pme-tls-settings',form).hidden=automatic;
    ['host','port','database','username','db_password','ssl_ca'].forEach(function(k){var el=form.elements[k];el.disabled=automatic;el.closest('label').hidden=automatic;});
    $('#pme-global-summary').textContent=(automatic?'Criação automática dos recursos MySQL':'Conexão manual com uma base existente')+' · '+(boot.provisioning.isolation==='database'?'Banco exclusivo':'Prefixo exclusivo')+' · '+(!boot.provisioning.dns_enabled?'Endereço atual da aplicação':'Endereço '+(boot.provisioning.address_mode==='organization'?'da organização':'individual por empresa'));
  }
  function selectedCount(){
    $$('input[name="activate_modules[]"]',form).forEach(function(el){var selected=$$('input[name="modules[]"]:checked:not(:disabled)',form).some(function(pick){return pick.value===el.value;});el.disabled=!selected;if(!selected)el.checked=false;});
    var count=$$('input[name="activate_modules[]"]:checked',form).length;$('#pme-selected-count').textContent=$$('input[name="modules[]"]:checked',form).length+' módulos selecionados'+(count?' · '+count+' para ativar':'');
  }
  $('#pme-activate-selected').onclick=function(){$$('input[name="activate_modules[]"]:not(:disabled)',form).forEach(function(el){el.checked=true;});selectedCount();};
  $('#pme-keep-inactive').onclick=function(){$$('input[name="activate_modules[]"]',form).forEach(function(el){el.checked=false;});selectedCount();};
  $('#pme-select-all').onclick=function(){$$('input[name="modules[]"]:not(:disabled)',form).forEach(function(el){el.checked=el.value.toLowerCase()!=='multiempresa';});selectedCount();};
  $('#pme-unselect-all').onclick=function(){$$('input[name="modules[]"]',form).forEach(function(el){el.checked=false;});selectedCount();};
  form.addEventListener('change',selectedCount);
  $('#pme-check-connection').onclick=async function(){
    if(!validateStage(1)){connectionResult('error','Teste não realizado. Confira os campos obrigatórios da conexão.');return;}
    var button=this,generation=++connectionGeneration;button.disabled=true;connectionResult('loading','Testando conexão…');
    try{var result=await post('check',wizardData());if(generation===connectionGeneration)connectionResult('success','Teste concluído com sucesso. '+result.message);}
    catch(e){if(generation===connectionGeneration)connectionResult('error','Falha no teste de conexão. '+e.message);}
    finally{button.disabled=false;}
  };
  form.addEventListener('submit',async function(event){
    event.preventDefault();if(running||stage===4||!validateStage(stage))return;
    if(stage<3){stageTo(stage+1);return;}
    if(revision){var changes=wizardData();changes.selection_present='1';if(!changes.db_password)delete changes.db_password;window.MultiempresaUI.reviewRevision(revision.operation,revision.id,changes);return;}
    var data=wizardData();running=true;stop=false;stageTo(4);$('#pme-progress-title').textContent='Preparando o ambiente';$('#pme-progress-subtitle').textContent='Validando e registrando a solicitação…';$('#pme-progress-steps').innerHTML='';$('#pme-completion-actions').innerHTML='';
    try{
      workflow=await post(form.elements.company_id.value?'start_draft':'begin',data);if(form.elements.certificate_file.files.length){await uploadCertificate(form,workflow.company_id);}
      form.elements.db_password.value='';form.elements.encryption_key.value='';
      var current=new URL(location.href);current.searchParams.set('provision',workflow.id);history.replaceState(null,'',current);
      renderProgress(workflow);running=false;await execute();
    }catch(e){running=false;if(workflow){renderProgress(workflow);message($('#pme-wizard-error'),e.message+' Corrija o certificado na edição da empresa e continue.',true);}else{stageTo(3);message($('#pme-wizard-error'),e.message,true);}}
  });
  $('#pme-save-draft').onclick=async function(){
    if(running||!validateStage(0,true))return;var button=this;button.disabled=true;running=true;
    try{var saved=await post('draft',wizardData());if(form.elements.certificate_file.files.length)await uploadCertificate(form,saved.company_id);close(wizard);message($('#pme-message'),'Cadastro salvo. O ambiente e o DNS não foram provisionados. Use Provisionar quando desejar.');loadGrid();}
    catch(e){message($('#pme-wizard-error'),e.message,true);}finally{button.disabled=false;running=false;}
  };
  function restoreAdministrator(data){adminChoiceTouched=true;creatorChoiceTouched=true;if(data.administrator_password_set)form.dataset.adminPasswordSaved='1';else delete form.dataset.adminPasswordSaved;var a=data.initial_administrator||{};form.elements.admin_mode.value=a.mode||'new';form.elements.admin_name.value=a.name||'';form.elements.admin_email.value=a.email||'';form.elements.admin_password.value='';form.elements.grant_creator.checked=!!a.grant_creator;administratorFields();}
  async function startDraft(id){
    try{var d=await request('company',{id:id});newWizard();form.elements.company_id.value=id;
      ['name','slug','organization_id','language','timezone'].forEach(function(k){if(d[k])form.elements[k].value=d[k];});
      ['host','port','database','username'].forEach(function(k){if(d.connection[k])form.elements[k].value=d.connection[k];});
      form.elements.prefix.value=d.table_prefix;slugEdited=true;form.elements.slug.disabled=true;form.elements.organization_id.disabled=true;
      $$('[data-profile]',form).forEach(function(el){el.value=(d.profile||{})[el.dataset.profile]||'';});
      $$('input[name="modules[]"]',form).forEach(function(el){el.checked=(d.planned_modules||[]).includes(el.value);});selectedCount();$$('input[name="activate_modules[]"]',form).forEach(function(el){el.checked=!el.disabled&&(d.planned_activate_modules||[]).includes(el.value);});
      restoreAdministrator(d);stageTo(0);selectedCount();
    }catch(e){message($('#pme-message'),e.message,true);}
  }
  async function reviseExecution(targetStep) {
    if(running||!workflow||!boot.canEdit){message($('#pme-wizard-error'),'Aguarde a etapa em execução terminar antes de revisar.',true);return;}
    var previous=workflow;
    try{
      var data=await request('company',{id:previous.company_id});newWizard();
      slugEdited=true;revision={id:data.id,mode:data.mode,operation:previous.status==='done'?'reprovision':'retry'};workflow=previous;visited=3;
      form.elements.company_id.value=data.id;
      ['name','organization_id','slug','language','timezone'].forEach(function(k){if(data[k])form.elements[k].value=data[k];});
      ['host','port','database','username'].forEach(function(k){form.elements[k].value=data.connection[k]||'';});form.elements.prefix.value=data.table_prefix;
      form.elements.slug.disabled=true;form.elements.organization_id.disabled=true;form.elements.adopt.checked=false;form.elements.adopt.disabled=true;
      form.elements.encryption_key.required=false;form.elements.local_staff_id.required=false;
      form.elements.certificate_file.disabled=true;form.elements.certificate_password.disabled=true;
      $$('[data-profile]',form).forEach(function(el){el.value=(data.pending_profile||data.profile||{})[el.dataset.profile]||'';});
      $$('input[name="modules[]"]',form).forEach(function(el){el.checked=(data.planned_modules||[]).includes(el.value);});selectedCount();$$('input[name="activate_modules[]"]',form).forEach(function(el){el.checked=!el.disabled&&(data.planned_activate_modules||[]).includes(el.value);});
      revision.preserveAdministrator=Number(data.schema_version)>0;restoreAdministrator(data);applyGlobal();selectedCount();stageTo(Math.min(3,Math.max(0,targetStep||0)));
    }catch(error){message($('#pme-wizard-error'),error.message,true);}
  }
  function updateProgress(completed,total,done,failed) {
    var percent=total?Math.max(0,Math.min(100,Math.round(completed/total*100))):0,fill=$('#pme-progress-fill');
    fill.style.width=percent+'%';fill.dataset.percent=String(percent);fill.textContent='';
    fill.setAttribute('aria-valuenow',percent);fill.setAttribute('aria-valuetext',completed+' de '+total+' etapas concluídas');
    fill.classList.toggle('progress-bar-success',done);fill.classList.toggle('progress-bar-danger',failed);
    $('#pme-progress-percent').textContent=percent+'%';
  }
  function renderProgress(data) {
    workflow=data;var done=data.status==='done',failed=['failed','interrupted','cancelled'].includes(data.status);
    var automatic=failed&&['core_changed','release_changed','core_updating','core_update_pending','schema_source_version'].includes(data.error_code);
    $('#pme-progress-title').textContent=done?'Empresa pronta para uso':failed?'Provisionamento interrompido':'Implantação em andamento';
    $('#pme-progress-icon').innerHTML='<i class="fa '+(done?'fa-check':failed?'fa-exclamation':'fa-cog')+'" aria-hidden="true"></i>';
    $('#pme-progress-icon').classList.toggle('is-success',done);
    $('#pme-progress-subtitle').textContent=done?'Todas as etapas foram concluídas. Você já pode abrir o CRM ou editar a empresa.':automatic?'O módulo está preparando a estrutura do banco automaticamente. Você pode voltar à lista ou continuar a tentativa. Os dados são preservados.':failed?'A execução foi interrompida. Consulte o diagnóstico, corrija a causa e use Retomar após revisar o impacto.':'Ao voltar à lista, a etapa atual termina e as próximas ficam pausadas. O progresso é preservado para continuar depois.';
    updateProgress(data.completed,data.total,done,failed);
    var current=data.steps.find(function(step){return step.status==='running'||step.status==='failed';})||data.steps[data.step_index];
    $('#pme-progress-current').textContent=done?'Implantação concluída':current?current.label:'Preparando etapas…';
    $('#pme-progress-steps').innerHTML=data.steps.map(function(step){var icon=step.status==='done'?'fa-check-circle':step.status==='failed'?'fa-exclamation-circle':step.status==='running'?'fa-circle-o-notch fa-spin':'fa-circle-o';return '<li class="'+h(step.status)+'"><i class="fa '+icon+'" aria-hidden="true"></i><span>'+h(step.label)+(step.result?'<small class="text-muted">'+h(step.result)+'</small>':'')+'</span><small class="pme-step-status">'+({pending:'Aguardando',running:'Executando',done:'Concluído',failed:'Interrompido'}[step.status]||'')+'</small></li>';}).join('');
    if(data.error_code)message($('#pme-wizard-error'),'Diagnóstico: '+data.error_code+'. '+(data.diagnosis&&data.diagnosis.diagnosis||'Consulte os logs desta execução.'),true);
    var actions='<button type="button" class="btn btn-default pme-btn pme-btn-secondary" data-close>Voltar à lista</button>';
    if(done && data.company && boot.canEdit)actions+='<button type="button" class="btn btn-default pme-btn pme-btn-secondary" data-edit="'+h(data.company.id)+'">Editar empresa</button>';
    if(done && data.open_url)actions+='<a class="btn btn-primary pme-btn" href="'+h(data.open_url)+'">Abrir empresa <i class="fa fa-arrow-right" aria-hidden="true"></i></a>';
    if(failed&&!automatic&&data.company_id&&boot.canEdit)actions+='<button type="button" class="btn btn-primary pme-btn" data-impact="retry" data-target="'+h(data.company_id)+'">Revisar e retomar</button>';
    if(!running&&data.company_id&&boot.canEdit)actions+='<button type="button" class="btn btn-default" id="pme-revise">Revisar etapas anteriores</button>';
    if(data.company_id)actions+='<button type="button" class="btn btn-default pme-btn pme-btn-secondary" data-company-logs="'+h(data.company_id)+'" data-execution="'+h(data.id)+'">Ver logs</button>';
    if(!done&&(!failed||automatic)&&boot.canCreate)actions+='<button type="button" class="btn btn-primary pme-btn" id="pme-continue" '+(running?'disabled':'')+'>'+(running?'Executando…':'Continuar etapas pendentes')+'</button>';
    $('#pme-completion-actions').innerHTML=actions;
    $('#pme-step-counter').textContent=data.completed+' de '+data.total+' etapas concluídas';
    $$('[data-go-step]').forEach(function(button){button.disabled=running;});
  }
  async function execute() {
    if(running||!workflow||workflow.status==='draft')return;
    if(['core_changed','release_changed','core_updating','core_update_pending','schema_source_version'].includes(workflow.error_code)&&['failed','interrupted'].includes(workflow.status)){workflow.status='pending';}
    running=true;stop=false;
    try{
      while(workflow && !['done','failed','interrupted','cancelled'].includes(workflow.status) && !stop){
        renderProgress(workflow);
        var next=$$('#pme-progress-steps li')[workflow.step_index];
        if(next){next.classList.add('running');$('i',next).className='fa fa-circle-o-notch fa-spin';$('.pme-step-status',next).textContent='Executando';}
        workflow=await post('advance',{id:workflow.id});renderProgress(workflow);loadGrid();
      }
    }catch(e){message($('#pme-wizard-error'),e.message+' Consulte o andamento antes de continuar.',true);}
    finally{running=false;if(workflow)renderProgress(workflow);loadGrid();}
  }
  async function resume(id) {
    if(running && workflow && workflow.id===id){stop=false;show(wizard);return;}
    if(running)return;
    show(wizard);stageTo(4);$('#pme-progress-subtitle').textContent='Consultando o andamento…';$('#pme-completion-actions').innerHTML='';
    try{workflow=await request('provision',{id:id});if(workflow.status==='draft'){close(wizard);await startDraft(workflow.company_id);return;}renderProgress(workflow);}catch(e){message($('#pme-wizard-error'),e.message,true);}
  }

  function editTab(name){$$('[data-edit-panel]').forEach(function(el){el.hidden=el.dataset.editPanel!==name;});$$('[data-edit-tab]').forEach(function(el){el.classList.toggle('is-active',el.dataset.editTab===name);});}
  function fillEditor(data) {
    currentEdit=data;$('#pme-editor-title').textContent=data.name;$('#pme-edit-form').dispatchEvent(new Event('pme:profile-reset')); 
    var f=$('#pme-edit-form'),editable=boot.canEdit&&(['draft','failed'].includes(data.status)||(data.configuration_available&&(!data.onboarding||data.onboarding.status==='done')));
    ['name','language','timezone','status'].forEach(function(name){f.elements[name].value=data[name]||'';f.elements[name].disabled=!editable;});$('#pme-save').disabled=!editable;
    $$('[data-profile]',f).forEach(function(el){el.value=(data.profile||{})[el.dataset.profile]||'';el.dataset.manual='';});
    $('.pme-remove-certificate',f).hidden=!data.certificate||!editable;
    $('.pme-certificate-summary',f).textContent=data.certificate?'Titular: '+data.certificate.holder+' · Válido até '+new Date(data.certificate.valid_until*1000).toLocaleDateString('pt-BR'):'Nenhum certificado cadastrado';
    f.elements.certificate_file.value='';f.elements.certificate_password.value='';$$('.pme-profile input,.pme-profile select',f).forEach(function(el){el.disabled=!editable;});
    if(['draft','failed'].includes(data.status)){f.elements.language.value=data.language||'portuguese_br';f.elements.timezone.value=data.timezone||'America/Bahia';f.elements.status.disabled=true;}
    if(!editable)message($('#pme-editor-message'),data.configuration_error||'As configurações ficam disponíveis após a conclusão do provisionamento e fora de manutenção.',true);
    var connection=data.connection, values=[['Endereço',data.address],['Organização',data.organization_name||data.organization_slug],['Identificador',data.slug],['Armazenamento',data.mode==='database'?'Banco exclusivo':'Banco compartilhado'],['Servidor',connection.host+':'+(connection.port||3306)],['Banco',connection.database],['Prefixo',data.table_prefix||'Sem prefixo'],['Usuário do banco',connection.username],['Esquema nativo',data.schema_version]];
    $('#pme-edit-connection').innerHTML=values.map(function(row){return '<div><dt>'+h(row[0])+'</dt><dd>'+h(row[1])+'</dd></div>';}).join('')+'<div class="pme-span-2"><dt>Recursos administrados</dt><dd><pre>'+h(JSON.stringify(data.resources||[],null,2))+'</pre></dd></div>';
    var moduleEditable=editable&&data.configuration_available&&(!data.onboarding||data.onboarding.status==='done');var active={};data.modules.forEach(function(m){active[m.name]=Number(m.active)===1;});
    var modules=boot.modules.slice();data.modules.filter(function(m){return m.name.toLowerCase()!=='multiempresa';}).forEach(function(m){if(!modules.some(function(item){return item.name===m.name;}))modules.push({name:m.name,label:m.name,description:'Código não encontrado no núcleo publicado.',compatible:false});});
    $('#pme-edit-modules').innerHTML=modules.map(function(m){var available=Object.prototype.hasOwnProperty.call(active,m.name),enabled=!!active[m.name];return '<div class="pme-module-edit"><div><strong>'+h(m.label)+'</strong><small>'+h(m.description||'Módulo adicional')+'</small></div><span class="pme-chip pme-chip-'+(enabled?'success':'neutral')+'">'+(enabled?'Ativo':(available?'Disponível · desativado':'Não disponibilizado'))+'</span><button type="button" class="btn btn-default pme-btn pme-btn-small pme-btn-secondary" '+(available?'data-module="'+h(m.name)+'" data-enabled="'+(enabled?'0':'1')+'"':'data-make-available="'+h(m.name)+'"')+' '+(!moduleEditable||(!enabled&&!m.compatible)?'disabled':'')+'>'+(enabled?'Desativar':(available?'Ativar':'Disponibilizar'))+'</button></div>';}).join('')||'<p class="pme-module-empty">Nenhum módulo adicional disponível.</p>';
    $('#pme-edit-module-reviewed').disabled=!moduleEditable;
    $('#pme-edit-activate-all').disabled=moduleBatch||!moduleEditable||!$('#pme-edit-modules [data-enabled="1"]:not(:disabled)');
    $('#pme-edit-deactivate-all').disabled=moduleBatch||!moduleEditable||!$('#pme-edit-modules [data-enabled="0"]:not(:disabled)');
  }
  async function edit(id) {
    if(wizard.classList.contains('in'))close(wizard);show($('#pme-editor'));message($('#pme-editor-message'),'Carregando configurações…');currentEdit=null;$('#pme-edit-batch-status').textContent='';$('#pme-edit-form').dispatchEvent(new Event('pme:profile-reset'));$('#pme-save').disabled=true;editTab(boot.page==='modules'?'modules':'general');
    try{var data=await request('company',{id:id});message($('#pme-editor-message'),'');fillEditor(data);}catch(e){message($('#pme-editor-message'),e.message,true);}
  }
  $('#pme-edit-form').onsubmit=async function(e){e.preventDefault();if(!currentEdit)return;var button=$('#pme-save');button.disabled=true;message($('#pme-editor-message'),'Salvando alterações…');try{var data=await post('save',Object.assign(fieldData(this),{company_id:currentEdit.id}));if(this.elements.certificate_file.files.length){await uploadCertificate(this,currentEdit.id);data.company=await request('company',{id:currentEdit.id});}fillEditor(data.company);message($('#pme-editor-message'),data.message);loadGrid();}catch(error){message($('#pme-editor-message'),error.message,true);}finally{button.disabled=false;}};
  root.addEventListener('click',async function(event){
    var button=event.target.closest('button,a');if(!button)return;
    if(button.hasAttribute('data-close')){close(button.closest('.modal'));loadGrid();}
    if(button.dataset.open)show($('#'+button.dataset.open));
    if(button.dataset.resume)resume(button.dataset.resume);
    if(button.id==='pme-revise')reviseExecution(0);
    if(button.hasAttribute('data-go-step')){var target=Number(button.dataset.goStep);if(stage===4){if(target<4)reviseExecution(target);}else if(target<=visited&&target<4&&!running){stageTo(target);}}
    if(button.id==='pme-continue'){
      try{workflow=await request('provision',{id:workflow.id});renderProgress(workflow);await execute();}catch(e){message($('#pme-wizard-error'),e.message,true);}
    }
    if(button.dataset.edit)edit(button.dataset.edit);if(button.dataset.startDraft)startDraft(button.dataset.startDraft);
    if(button.dataset.backup){button.disabled=true;try{var backup=await post('backup',{company_id:button.dataset.backup});message($('#pme-message'),backup.message);}catch(e){message($('#pme-message'),e.message,true);}finally{button.disabled=false;}}
    if(button.dataset.editTab)editTab(button.dataset.editTab);

    if(button.dataset.makeAvailable && currentEdit&&!moduleBatch){
      if(!$('#pme-edit-module-reviewed').checked){message($('#pme-editor-message'),'Confirme a compatibilidade antes de disponibilizar este módulo.',true);return;}
      var availableCompany=currentEdit.id;button.disabled=true;
      try{var availableResult=await post('module_available',{company_id:availableCompany,module:button.dataset.makeAvailable,reviewed:'1'});if(currentEdit&&currentEdit.id===availableCompany){fillEditor(availableResult.company);message($('#pme-editor-message'),availableResult.message);}loadGrid();}catch(error){message($('#pme-editor-message'),error.message,true);button.disabled=false;}
    }
    if(button.dataset.module && currentEdit&&!moduleBatch){
      var enabled=button.dataset.enabled==='1';if(enabled&&!$('#pme-edit-module-reviewed').checked){message($('#pme-editor-message'),'Confirme a compatibilidade antes de ativar este módulo.',true);return;}
      var id=currentEdit.id;button.disabled=true;message($('#pme-editor-message'),'Aplicando alteração nesta empresa…');
      try{var result=await post('module',{company_id:id,module:button.dataset.module,enabled:enabled?'1':'0',reviewed:$('#pme-edit-module-reviewed').checked?'1':'0'});if(currentEdit&&currentEdit.id===id){fillEditor(result.company);message($('#pme-editor-message'),result.message);}loadGrid();}catch(e){message($('#pme-editor-message'),e.message,true);button.disabled=false;}
    }
  });
  async function setAllModules(enabled) {
    if(moduleBatch||!currentEdit)return;
    if(enabled&&!$('#pme-edit-module-reviewed').checked){message($('#pme-editor-message'),'Confirme a compatibilidade dos módulos antes de ativar todos.',true);return;}
    var company=currentEdit.id,targets=$$('#pme-edit-modules button[data-enabled="'+(enabled?'1':'0')+'"]:not(:disabled)').map(function(button){return button.dataset.module;}).filter(function(name){return name.toLowerCase()!=='multiempresa';}),completed=0;
    if(!targets.length)return;moduleBatch=true;
    $$('#pme-edit-modules button,#pme-edit-activate-all,#pme-edit-deactivate-all').forEach(function(button){button.disabled=true;});
    try{
      for(var name of targets){
        if(!currentEdit||currentEdit.id!==company||!$('#pme-editor').classList.contains('in'))break;
        $('#pme-edit-batch-status').textContent=completed+' de '+targets.length+' · '+(enabled?'Ativando ':'Desativando ')+name+'…';
        var result=await post('module',{company_id:company,module:name,enabled:enabled?'1':'0',reviewed:enabled?'1':'0'});completed++;
        if(currentEdit&&currentEdit.id===company)currentEdit=result.company;
      }
      if(currentEdit&&currentEdit.id===company)message($('#pme-editor-message'),completed===targets.length?'Módulos '+(enabled?'ativados':'desativados')+'.':'Operação pausada. Os módulos já processados foram preservados.');
    }catch(error){if(currentEdit&&currentEdit.id===company)message($('#pme-editor-message'),'Operação interrompida no módulo '+name+': '+error.message+' Os módulos anteriores foram preservados.',true);}
    finally{
      moduleBatch=false;
      if(currentEdit&&currentEdit.id===company){try{fillEditor(await request('company',{id:company}));}catch(error){message($('#pme-editor-message'),error.message,true);}$('#pme-edit-batch-status').textContent=completed+' de '+targets.length+' módulos concluídos';}
      loadGrid();
    }
  }
  $('#pme-edit-activate-all').onclick=function(){setAllModules(true);};
  $('#pme-edit-deactivate-all').onclick=function(){setAllModules(false);};
  $$('.modal').forEach(function(dialog){window.jQuery(dialog).on('hidden.bs.modal',function(){delete dialog.dataset.pmeClosing;if(dialog===wizard){stop=true;}loadGrid();if(lastFocus&&lastFocus.isConnected)lastFocus.focus();});});
  async function mutate(route,data){var task=mutation.then(function(){return request(route,data,true);});mutation=task.catch(function(){});return task;}
  $$('.pme-remove-certificate').forEach(function(button){button.onclick=async function(){if(!currentEdit||!confirm('Remover o certificado A1 desta empresa? Esta ação não altera os demais dados da empresa.'))return;this.disabled=true;try{await mutate('certificate',{company_id:currentEdit.id,remove:'1'});fillEditor(await request('company',{id:currentEdit.id}));message($('#pme-editor-message'),'Certificado removido.');}catch(e){message($('#pme-editor-message'),e.message,true);}finally{this.disabled=false;}};});
  async function uploadCertificate(f,company){var body=new FormData();body.set('company_id',company);body.set('certificate',f.elements.certificate_file.files[0]);body.set('certificate_password',f.elements.certificate_password.value);return mutate('certificate',body);}
  window.MultiempresaUI={root:root,boot:boot,$:$,$$:$$,h:h,message:message,show:show,close:close,url:url,request:request,mutate:mutate,fieldData:fieldData,loadGrid:loadGrid,edit:edit,resume:resume,execute:execute,startExecution:async function(data){if($('#pme-impact').classList.contains('in'))close($('#pme-impact'));show(wizard);stageTo(4);workflow=data;renderProgress(data);await execute();}};
  moduleOptions();selectedCount();loadGrid();
  var pending=new URL(location.href).searchParams.get('provision');if(pending)resume(pending);
})();
