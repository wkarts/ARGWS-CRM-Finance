/* Native settings view and module JavaScript, with a mocked HTTP boundary. */
'use strict';
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const {JSDOM} = require('jsdom');
const modulePath = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN || 'php';
const source = fs.readFileSync(path.join(modulePath, 'assets/nfse_nacional.js'), 'utf8');
let checks = 0;
const passed = [];
const pause = () => new Promise(resolve => setTimeout(resolve, 5));
function check(value, message) { if (!value) throw new Error(message); checks++; passed.push(message); }
const fixture = String.raw`
error_reporting(E_ALL); set_error_handler(static function($n,$m,$f,$l){ throw new ErrorException($m,0,$n,$f,$l); });
define('BASEPATH', __DIR__); require $argv[1].'/autoload.php';
function admin_url($path=''){return 'https://crm.example.invalid/empresa-a/admin/'.$path;}
function nfse_nacional_can($cap){return true;}
function &get_instance(){static $ci;if(!$ci){$ci=(object)['security'=>new class{function get_csrf_token_name(){return 'fixture_csrf';}function get_csrf_hash(){return 'token-inicial';}}];}return $ci;}
$input=json_decode($argv[2],true,32,JSON_THROW_ON_ERROR);
$settings=\NfseNacional\Config::defaults();
$settings['provider']=array_replace($settings['provider'],['state'=>'BA','municipality_code'=>'2927408','name'=>'Emitente sintético','tax_id'=>'11222333000181'],$input['provider']??[]);
$ibpt_table=$input['table'];
echo '<!doctype html><html><head><meta charset="UTF-8"></head><body><nav><i class="fa fa-users"></i>Menu nativo</nav><form id="settings-form" action="'.admin_url('settings').'" method="post"><input name="native_setting" value="preservar">';
require $argv[1].'/views/settings.php';
echo '<button type="submit" id="native-save">Salvar configurações</button></form></body></html>';
`;
function table(overrides={}) {
    return {...{uf:'BA', status:'current', status_label:'Vigente', version:'26.2.TEST', key:'TEST072', source:'IBPT/empresometro.com.br',
        valid_from:'2026-11-01', valid_to:'2026-11-30', fetched_at:'2026-11-11T12:00:00Z', last_attempt_at:'2026-11-11T12:00:00Z',
        next_retry_at:null, next_auto_check_at:'2026-12-01T03:00:00Z', next_manual_at:null, service_count:860,
        acquisition:'download', acquisition_label:'Consulta automática',
        can_refresh:true, message:'Tabela vigente disponível localmente.', auto_policy:'Renovação automática quando a tabela local estiver ausente ou vencida.',
        refresh_state:'idle', refresh_message:''}, ...overrides};
}
async function page(options={}) {
    const input = {...options,table:table(options.table)};
    const rendered = cp.spawnSync(php,['-r',fixture,modulePath,JSON.stringify(input)],{encoding:'utf8'});
    if(rendered.status!==0 || rendered.stderr) throw new Error(rendered.stderr || rendered.stdout);
    const dom = new JSDOM(rendered.stdout,{url:'https://crm.example.invalid/empresa-a/admin/settings?group=nfse_nacional',runScripts:'outside-only',pretendToBeVisual:true});
    await new Promise(resolve=>dom.window.document.addEventListener('DOMContentLoaded',resolve,{once:true}));
    dom.window.Element.prototype.scrollIntoView=function(){};
    dom.window.confirm=()=>true;
    dom.window.__unexpectedFetches=0;
    dom.window.fetch=async()=>{dom.window.__unexpectedFetches++;throw new Error('Requisição não configurada no teste');};
    dom.window.eval(source);
    return dom;
}
function manager(dom){return dom.window.document.querySelector('[data-nfse-ibpt-manager]');}
function button(dom){return manager(dom).querySelector('[data-nfse-ibpt-refresh]');}
function importButton(dom){return manager(dom).querySelector('[data-nfse-ibpt-import]');}
function fileInput(dom){return manager(dom).querySelector('[data-nfse-ibpt-file]');}
function keyInput(dom){return manager(dom).querySelector('[data-nfse-ibpt-import-key]');}
function setKey(dom,value){keyInput(dom).value=value;keyInput(dom).dispatchEvent(new dom.window.Event('input',{bubbles:true}));}
function selectFiles(dom,names=['TabelaIBPTaxBA26.2.B.csv'],size){
    const input=fileInput(dom);
    let files=names.map(name=>new dom.window.File(['csv de teste'],name,{type:'text/csv'}));
    if(size!==undefined&&files[0])Object.defineProperty(files[0],'size',{value:size});
    Object.defineProperty(input,'files',{configurable:true,get:()=>files});
    Object.defineProperty(input,'value',{configurable:true,get:()=>files.length?'C:\\fakepath\\'+files[0].name:'',set:value=>{if(value==='')files=[];}});
    input.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    return files[0];
}
function text(dom,key){return manager(dom).querySelector('[data-nfse-ibpt-table-field="'+key+'"]').textContent;}
function feedback(dom){return manager(dom).querySelector('[data-nfse-ibpt-update-feedback]');}
function field(dom,key){return dom.window.document.querySelector('[name="settings[provider]['+key+']"]');}
function change(dom,key,value,type='input'){const f=field(dom,key);f.value=value;f.dispatchEvent(new dom.window.Event(type,{bubbles:true}));}
function response(data=table({refresh_state:'updated',refresh_message:'Tabela atualizada.'}),opts={}){
    return {ok:opts.ok!==false,status:opts.status||200,json:async()=>({ok:opts.ok!==false,message:opts.message||'Consulta concluída.',data:{ibpt_table:data},csrf:{name:'fixture_csrf',hash:opts.csrf||'token-novo'}})};
}
async function settled(dom){for(let n=0;n<200;n++){await pause();if(!dom.window.document.querySelector('[aria-busy="true"]'))return;}throw new Error('Requisição não terminou.');}
async function deferred(dom){let finish;const requests=[];dom.window.fetch=(url,options)=>{requests.push({url,options});return new Promise(resolve=>{finish=resolve;});};return{requests,resolve:r=>finish(r),started:async()=>{for(let i=0;i<100&&!finish;i++)await pause();if(!finish)throw new Error('Requisição não iniciou.');}};}

(async()=>{
    let dom=await page(), d=dom.window.document;
    let calls=0;dom.window.fetch=async()=>{calls++;return response();};
    check(d.querySelector('[role="tab"][aria-controls="nfse-settings-ibpt"]').textContent==='Tabela IBPT','Configurações possuem guia nativa Tabela IBPT');
    check(d.querySelector('#nfse-settings-ibpt').getAttribute('aria-labelledby')==='nfse-settings-ibpt-tab','Guia e painel têm associação acessível');
    check(text(dom,'uf')==='BA'&&text(dom,'version')==='26.2.TEST'&&text(dom,'key')==='TEST072','UF, versão e chave da tabela salva são exibidas');
    check(text(dom,'valid_from')==='01/11/2026'&&text(dom,'valid_to')==='30/11/2026','Início e fim da vigência usam datas brasileiras sem deslocar o dia');
    check(text(dom,'fetched_at')==='11/11/2026 09:00:00'&&text(dom,'last_attempt_at')==='11/11/2026 09:00:00','Datas UTC são apresentadas no horário de Brasília');
    check(text(dom,'next_auto_check_at')==='01/12/2026 00:00:00'&&text(dom,'service_count')==='860','Renovação e quantidade de serviços são visíveis');
    check(manager(dom).querySelectorAll('select,input:not([type="file"]):not([type="checkbox"]):not([type="hidden"]):not([data-nfse-ibpt-import-key])').length===0&&button(dom).type==='button'&&importButton(dom).type==='button','Metadados são somente leitura e botões da tabela não submetem configurações');
    d.querySelector('[role="tab"][aria-controls="nfse-settings-ibpt"]').click();
    await pause();check(calls===0&&dom.window.__unexpectedFetches===0,'Renderização e abertura do painel não baixam a tabela');
    check(manager(dom).textContent.includes('reutilizada nas emissões')&&manager(dom).textContent.includes('após uma hora'),'Política automática e reaproveitamento local são explicados');
    check(d.querySelector('nav').innerHTML==='<i class="fa fa-users"></i>Menu nativo','Painel não modifica ícones ou menus nativos');
    let captured=[];dom.window.fetch=async(url,options)=>{captured.push({url,options,body:[...options.body.entries()]});return response(table({version:'26.2.NOVA',key:'NEW072',fetched_at:'2026-11-11T15:00:00Z',refresh_state:'updated',refresh_message:'Tabela renovada.'}));};
    const password=d.querySelector('[name="certificate_password"]');password.value='segredo-local';
    field(dom,'name').value='Edição não salva';
    button(dom).click();await settled(dom);
    check(captured.length===1&&captured[0].url==='https://crm.example.invalid/empresa-a/admin/nfse_nacional/ibpt_refresh','Atualização usa endpoint interno do contexto da empresa');
    check(captured[0].options.method==='POST'&&captured[0].options.credentials==='same-origin'&&captured[0].options.redirect==='error','Atualização usa POST privado e não segue redirecionamento');
    check(JSON.stringify(captured[0].body)==='[["expected_uf","BA"],["fixture_csrf","token-inicial"]]','Requisição envia somente UF esperada e CSRF, sem senha, certificado ou formulário fiscal');
    check(field(dom,'name').value==='Edição não salva'&&password.value==='segredo-local'&&d.querySelector('[name="native_setting"]').value==='preservar','Atualização preserva edições não salvas e campos nativos');
    check(text(dom,'version')==='26.2.NOVA'&&text(dom,'key')==='NEW072'&&text(dom,'fetched_at').includes('12:00:00'),'Retorno atualiza metadados sem recarregar a página');
    check(feedback(dom).classList.contains('alert-success')&&feedback(dom).textContent==='Tabela renovada.','Tabela vigente atualizada recebe confirmação correta');
    button(dom).click();await settled(dom);
    check(captured[1].options.body.get('fixture_csrf')==='token-novo','Próxima atualização utiliza o CSRF renovado');dom.window.close();

    dom=await page();calls=0;dom.window.fetch=async()=>{calls++;return response();};
    change(dom,'state','SP','change');
    check(button(dom).disabled&&!manager(dom).querySelector('[data-nfse-ibpt-unsaved]').hidden,'UF não salva bloqueia atualização e orienta salvar');
    button(dom).click();await pause();check(calls===0,'UF alterada não é enviada como escolha livre da tabela');
    change(dom,'state','BA','change');check(!button(dom).disabled,'Restaurar a UF salva libera atualização');
    change(dom,'municipality_code','3550308');check(button(dom).disabled,'Município não salvo também exige salvar antes da atualização');
    change(dom,'municipality_code','2927408');check(!button(dom).disabled,'Restaurar município salvo libera atualização');dom.window.close();

    dom=await page();let pending=await deferred(dom);button(dom).click();await pending.started();button(dom).click();
    d=dom.window.document;d.querySelector('#settings-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));
    check(pending.requests.length===1&&button(dom).disabled,'Cliques repetidos e salvar durante atualização não disparam operação concorrente');
    pending.resolve(response());await settled(dom);check(!button(dom).disabled,'Botão e formulário são liberados após a consulta');dom.window.close();

    dom=await page();pending=await deferred(dom);button(dom).click();await pending.started();change(dom,'state','SP','change');pending.resolve(response(table({version:'NAOAPLICAR'})));await settled(dom);
    check(text(dom,'version')==='26.2.TEST'&&button(dom).disabled,'Resposta tardia é descartada se a UF for alterada durante consulta');dom.window.close();

    dom=await page();pending=await deferred(dom);button(dom).click();await pending.started();dom.window.document.querySelector('#settings-form').action='https://crm.example.invalid/empresa-b/admin/settings';pending.resolve(response(table({version:'NAOAPLICAR'})));await settled(dom);
    check(text(dom,'version')==='26.2.TEST'&&feedback(dom).hidden,'Resposta tardia é descartada se contexto do formulário mudar');dom.window.close();

    dom=await page();dom.window.fetch=async()=>response(table({uf:'SP',version:'NAOAPLICAR'}));button(dom).click();await settled(dom);
    check(text(dom,'uf')==='BA'&&text(dom,'version')==='26.2.TEST'&&feedback(dom).classList.contains('alert-warning'),'Resposta de outra UF não substitui metadados locais');dom.window.close();

    dom=await page();dom.window.fetch=async()=>response(table({status:'expired',status_label:'Vencida',valid_to:'2026-10-31',refresh_state:'updated',refresh_message:'A fonte ainda retorna tabela vencida.'}));button(dom).click();await settled(dom);
    check(feedback(dom).classList.contains('alert-warning')&&!feedback(dom).classList.contains('alert-success')&&text(dom,'valid_to')==='31/10/2026','Download concluído de tabela vencida é aviso, não sucesso de vigência');dom.window.close();

    dom=await page();dom.window.fetch=async()=>response(table({refresh_state:'failed',refresh_message:'Não foi possível atualizar. A tabela vigente foi preservada.'}));button(dom).click();await settled(dom);
    check(feedback(dom).classList.contains('alert-warning')&&text(dom,'key')==='TEST072','Falha de atualização preserva tabela válida e é apresentada como aviso');dom.window.close();

    dom=await page();const bad='<img src=x onerror=window.hacked=1>';
    dom.window.fetch=async()=>response(table({key:bad,source:bad,status_label:bad,refresh_message:bad}));button(dom).click();await settled(dom);
    check(!manager(dom).querySelector('img,script')&&text(dom,'key')===bad&&feedback(dom).textContent===bad&&!dom.window.hacked,'Retorno com HTML permanece texto e não executa conteúdo');dom.window.close();

    dom=await page({table:{key:bad,source:bad,status_label:bad}});
    check(!manager(dom).querySelector('img,script')&&text(dom,'key')===bad,'Renderização PHP escapa metadados da fonte');dom.window.close();

    dom=await page();calls=0;dom.window.fetch=async()=>{calls++;return response();};button(dom).dataset.nfseIbptRefreshUrl='https://outra.example.test/ibpt';button(dom).click();await settled(dom);
    check(calls===0&&feedback(dom).classList.contains('alert-warning'),'Endpoint de outra origem é recusado antes de transmitir CSRF');dom.window.close();

    dom=await page({table:{uf:'',status:'invalid',status_label:'Confira o emitente',can_refresh:false}});calls=0;dom.window.fetch=async()=>{calls++;return response();};button(dom).click();await pause();
    check(button(dom).disabled&&calls===0,'Emitente sem UF válida não permite atualização manual');dom.window.close();

    dom=await page();dom.window.fetch=async()=>response(table({refresh_state:'failed'}),{ok:false,status:503,message:'Consulta temporariamente indisponível.',csrf:'token-erro'});button(dom).click();await settled(dom);
    check(text(dom,'key')==='TEST072'&&feedback(dom).textContent==='Consulta temporariamente indisponível.','Falha HTTP exibe mensagem sem apagar tabela já obtida');
    check(manager(dom).closest('.nfse-root').dataset.csrfHash==='token-erro','Resposta de erro também renova CSRF');dom.window.close();

    dom=await page();const now=Date.now();let clock=now;dom.window.Date.now=()=>clock;calls=0;
    const manualAt=new Date(Math.floor(now/1000)*1000+60000).toISOString().replace('.000Z','Z');
    dom.window.fetch=async()=>{calls++;return response(table({can_refresh:false,next_manual_at:manualAt,refresh_state:'throttled',refresh_message:'Aguarde um minuto.'}));};
    button(dom).click();await settled(dom);check(button(dom).disabled&&feedback(dom).classList.contains('alert-warning'),'Limite manual desabilita botão e informa espera');
    clock=now+61000;field(dom,'name').dispatchEvent(new dom.window.Event('input',{bubbles:true}));
    check(!button(dom).disabled&&calls===1,'Fim do intervalo reabilita botão sem baixar tabela automaticamente');dom.window.close();

    dom=await page();d=dom.window.document;
    check(fileInput(dom).accept==='.csv,text/csv'&&!fileInput(dom).multiple&&!fileInput(dom).name,'Envio aceita um CSV e não integra o arquivo ao formulário geral');
    check(fileInput(dom).id&&d.querySelector('label[for="'+fileInput(dom).id+'"]').textContent.includes('IBPT'),'Seletor de arquivo possui identificação acessível');
    check(importButton(dom).disabled&&!fileInput(dom).disabled,'Importação exige selecionar arquivo antes de habilitar botão');
    const submittedFile=selectFiles(dom);
    check(!importButton(dom).disabled&&manager(dom).textContent.includes('conferida no conteúdo'),'Seleção válida habilita importação e orienta conferir UF no conteúdo ou nome original');
    captured=[];dom.window.fetch=async(url,options)=>{captured.push({url,options});return response(table({version:'26.2.IMPORT',key:'FILE073',acquisition:'upload',acquisition_label:'Arquivo enviado',refresh_state:'updated',refresh_message:'Tabela importada.'}));};
    field(dom,'name').value='Emitente não salvo';d.querySelector('[name="certificate_password"]').value='segredo-nao-enviar';
    importButton(dom).click();await settled(dom);
    check(captured.length===1&&captured[0].url.endsWith('/nfse_nacional/ibpt_import')&&captured[0].options.method==='POST','Importação usa endpoint POST dedicado no contexto atual');
    const uploadBody=captured[0].options.body;
    check([...uploadBody.keys()].join(',')==='expected_uf,ibpt_file,fixture_csrf'&&uploadBody.get('expected_uf')==='BA'&&uploadBody.get('fixture_csrf')==='token-inicial','Upload transmite somente arquivo, UF esperada e CSRF');
    check(uploadBody.get('ibpt_file').name===submittedFile.name&&uploadBody.get('ibpt_file').size===submittedFile.size,'Arquivo selecionado é transmitido com seu nome original');
    check(captured[0].options.credentials==='same-origin'&&captured[0].options.redirect==='error','Upload mantém mesma origem e impede redirecionamentos');
    check(field(dom,'name').value==='Emitente não salvo'&&d.querySelector('[name="certificate_password"]').value==='segredo-nao-enviar'&&d.querySelector('[name="native_setting"]').value==='preservar','Upload preserva edições não salvas, certificado e configurações nativas');
    check(text(dom,'version')==='26.2.IMPORT'&&text(dom,'acquisition_label')==='Arquivo enviado'&&feedback(dom).textContent==='Tabela importada.','Importação atualiza metadados e indica origem de arquivo');
    check(fileInput(dom).value===''&&fileInput(dom).files.length===0&&importButton(dom).disabled,'Sucesso limpa seleção do arquivo e impede envio duplicado involuntário');
    selectFiles(dom);importButton(dom).click();await settled(dom);
    check(captured[1].options.body.get('fixture_csrf')==='token-novo','Novo upload utiliza token CSRF renovado');
    check(d.querySelector('nav').innerHTML==='<i class="fa fa-users"></i>Menu nativo','Upload não altera ícones e navegação do CRM');dom.window.close();

    dom=await page();calls=0;dom.window.fetch=async()=>{calls++;return response();};
    for(const sample of [{names:['BA.csv','SP.csv']},{names:['BA.zip']},{names:['BA.csv'],size:0},{names:['BA.csv'],size:8388609}]){
        selectFiles(dom,sample.names,sample.size);importButton(dom).click();await pause();
        check(importButton(dom).disabled&&!manager(dom).querySelector('[data-nfse-ibpt-file-message]').hidden&&calls===0,'Seleção inválida é bloqueada: '+JSON.stringify(sample));
    }
    selectFiles(dom,['BA.csv'],8388608);check(!importButton(dom).disabled,'Limite de 8 MiB é aceito sem enviar antes de solicitar');
    selectFiles(dom,[]);check(importButton(dom).disabled&&manager(dom).querySelector('[data-nfse-ibpt-file-message]').hidden,'Cancelar seleção desabilita envio e limpa mensagem de arquivo inválido');dom.window.close();

    const futureManual=new Date(Date.now()+3600000).toISOString().replace(/\.\d{3}Z$/,'Z');
    dom=await page({table:{can_refresh:false,next_manual_at:futureManual}});selectFiles(dom);
    check(button(dom).disabled&&!importButton(dom).disabled,'Upload de arquivo independe do intervalo de consulta remota');dom.window.close();

    dom=await page();selectFiles(dom);change(dom,'state','SP','change');
    check(fileInput(dom).disabled&&importButton(dom).disabled,'UF não salva bloqueia seleção e envio do arquivo');
    change(dom,'state','BA','change');check(!fileInput(dom).disabled&&!importButton(dom).disabled,'Restaurar UF salva mantém arquivo selecionado disponível');
    change(dom,'municipality_code','3550308');check(importButton(dom).disabled,'Município pendente bloqueia upload');dom.window.close();
    dom=await page({table:{uf:'',can_refresh:false}});selectFiles(dom);check(fileInput(dom).disabled&&importButton(dom).disabled,'Emitente sem UF impede importação');dom.window.close();

    dom=await page();selectFiles(dom);pending=await deferred(dom);importButton(dom).click();await pending.started();button(dom).click();importButton(dom).click();
    dom.window.document.querySelector('#settings-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));
    check(pending.requests.length===1&&fileInput(dom).disabled&&button(dom).disabled&&importButton(dom).disabled,'Importação bloqueia consulta, novo arquivo, envio repetido e salvamento concorrente');
    pending.resolve(response());await settled(dom);check(!fileInput(dom).disabled&&!button(dom).disabled,'Conclusão libera controles após upload');dom.window.close();

    dom=await page();selectFiles(dom);pending=await deferred(dom);button(dom).click();await pending.started();
    field(dom,'name').dispatchEvent(new dom.window.Event('input',{bubbles:true}));importButton(dom).click();
    check(fileInput(dom).disabled&&importButton(dom).disabled&&pending.requests.length===1,'Consulta remota em andamento bloqueia upload mesmo após digitação em outro campo');
    pending.resolve(response());await settled(dom);check(!fileInput(dom).disabled&&!importButton(dom).disabled,'Arquivo previamente escolhido permanece disponível após consulta');dom.window.close();

    dom=await page();selectFiles(dom);dom.window.fetch=async()=>response(table({version:'NAOAPLICAR'}),{ok:false,status:422,message:'A tabela enviada está vencida.',csrf:'token-upload-erro'});
    importButton(dom).click();await settled(dom);
    check(text(dom,'version')==='26.2.TEST'&&feedback(dom).textContent==='A tabela enviada está vencida.'&&feedback(dom).classList.contains('alert-warning'),'Arquivo rejeitado preserva tabela local e mostra motivo');
    check(fileInput(dom).files.length===1&&!importButton(dom).disabled&&manager(dom).closest('.nfse-root').dataset.csrfHash==='token-upload-erro','Rejeição preserva arquivo para revisão, libera controles e renova CSRF');dom.window.close();

    dom=await page();selectFiles(dom);dom.window.fetch=async()=>response(table({uf:'SP',version:'NAOAPLICAR'}));importButton(dom).click();await settled(dom);
    check(text(dom,'version')==='26.2.TEST'&&text(dom,'uf')==='BA'&&fileInput(dom).files.length===1&&feedback(dom).classList.contains('alert-warning'),'Resposta de importação de outra UF não substitui tabela nem limpa arquivo');dom.window.close();

    for(const alteration of ['state','context','file']){
        dom=await page();selectFiles(dom);pending=await deferred(dom);importButton(dom).click();await pending.started();
        if(alteration==='state')change(dom,'state','SP','change');
        if(alteration==='context')dom.window.document.querySelector('#settings-form').action='https://crm.example.invalid/empresa-b/admin/settings';
        if(alteration==='file')selectFiles(dom,['IBPT_BA_outra.csv']);
        pending.resolve(response(table({version:'NAOAPLICAR'})));await settled(dom);
        check(text(dom,'version')==='26.2.TEST'&&fileInput(dom).files.length===1,'Resposta tardia de upload descartada após alterar '+alteration);dom.window.close();
    }

    dom=await page();selectFiles(dom);calls=0;dom.window.fetch=async()=>{calls++;return response();};importButton(dom).dataset.nfseIbptImportUrl='https://externo.example.test/upload';importButton(dom).click();await settled(dom);
    check(calls===0&&feedback(dom).classList.contains('alert-warning'),'Upload recusa outra origem antes de transmitir arquivo ou CSRF');dom.window.close();

    dom=await page();selectFiles(dom);dom.window.fetch=async()=>{throw new dom.window.DOMException('timeout','AbortError');};importButton(dom).click();await settled(dom);
    check(text(dom,'version')==='26.2.TEST'&&fileInput(dom).files.length===1&&feedback(dom).textContent.includes('Reabra esta guia'),'Timeout orienta conferir resultado sem apagar tabela nem reenviar automaticamente');dom.window.close();

    dom=await page();d=dom.window.document;
    check(keyInput(dom).value===''&&!keyInput(dom).name&&!keyInput(dom).required&&keyInput(dom).maxLength===32,'Chave complementar começa vazia, é opcional e não pertence às configurações gerais');
    check(d.querySelector('label[for="'+keyInput(dom).id+'"]').textContent==='Cadastrar chave IBPT junto com a tabela (se ausente no CSV)'&&manager(dom).textContent.includes('Se o arquivo não contiver chave'),'Campo acessível explica quando informar a chave original');
    setKey(dom,'TEST074');selectFiles(dom,['ibpt-consulta (1).csv']);
    check(keyInput(dom).value==='TEST074'&&!importButton(dom).disabled,'Nome genérico é enviado sem renomeação e preserva chave digitada antes da primeira seleção');
    check(!new dom.window.FormData(d.querySelector('#settings-form')).has('ibpt_key')&&!new dom.window.FormData(d.querySelector('#settings-form')).has('nfse-ibpt-import-key'),'Chave complementar não integra o salvamento geral do formulário');
    captured=[];dom.window.fetch=async(url,options)=>{captured.push({url,options});return response(table({key:'TEST074',acquisition:'upload',acquisition_label:'Arquivo enviado',refresh_state:'updated',refresh_message:'Tabela importada.'}));};
    setKey(dom,' TEST074 ');importButton(dom).click();await settled(dom);
    check(captured.length===1&&[...captured[0].options.body.keys()].join(',')==='expected_uf,ibpt_file,ibpt_key,fixture_csrf'&&captured[0].options.body.get('ibpt_key')==='TEST074','Chave informada acompanha somente o upload e remove espaços externos');
    check(captured[0].options.body.get('ibpt_file').name==='ibpt-consulta (1).csv'&&text(dom,'key')==='TEST074','Arquivo de nome genérico e chave explícita seguem ao servidor, cujo retorno define a chave aplicada');
    check(keyInput(dom).value===''&&fileInput(dom).files.length===0,'Importação concluída limpa chave complementar e arquivo para impedir reaproveitamento em outra tabela');
    dom.window.close();

    dom=await page();selectFiles(dom,['ibpt-consulta (1).csv']);calls=0;dom.window.fetch=async()=>{calls++;return response();};
    for(const invalidKey of ['ABC 123','A<B','a'.repeat(33)]){
        setKey(dom,invalidKey);importButton(dom).click();await pause();
        check(importButton(dom).disabled&&!manager(dom).querySelector('[data-nfse-ibpt-key-message]').hidden&&calls===0,'Chave malformada impede apenas o envio da tabela: '+invalidKey);
    }
    setKey(dom,'');check(!importButton(dom).disabled&&manager(dom).querySelector('[data-nfse-ibpt-key-message]').hidden,'Chave vazia continua permitida quando o próprio arquivo contém o identificador');
    setKey(dom,'TEST074');selectFiles(dom,['outra-consulta.csv']);
    check(keyInput(dom).value===''&&!importButton(dom).disabled,'Trocar o arquivo limpa a chave anterior sem exigir renomeação');
    setKey(dom,'TEST074');selectFiles(dom,[]);
    check(keyInput(dom).value===''&&importButton(dom).disabled,'Cancelar arquivo também remove a chave vinculada à seleção anterior');
    dom.window.close();

    dom=await page();selectFiles(dom,['ibpt-consulta (1).csv']);setKey(dom,'TEST074');
    dom.window.fetch=async()=>response(table({key:'NAOAPLICAR'}),{ok:false,status:422,message:'A chave informada difere da chave do arquivo.'});
    importButton(dom).click();await settled(dom);
    check(keyInput(dom).value==='TEST074'&&fileInput(dom).files.length===1&&text(dom,'key')==='TEST072'&&feedback(dom).textContent==='A chave informada difere da chave do arquivo.','Erro de chave preserva seleção e tabela anterior para revisão');
    dom.window.close();

    dom=await page();selectFiles(dom,['ibpt-consulta (1).csv']);
    dom.window.fetch=async()=>response(table(),{ok:false,status:422,message:'Informe a chave original da tabela IBPT.'});
    importButton(dom).click();await settled(dom);
    check(keyInput(dom).value===''&&text(dom,'key')==='TEST072'&&feedback(dom).textContent==='Informe a chave original da tabela IBPT.','Arquivo sem chave pede informação original e não reutiliza identificador da tabela salva');
    dom.window.close();

    for(const requestKind of ['upload','refresh']){
        dom=await page();selectFiles(dom);setKey(dom,'TEST074');pending=await deferred(dom);
        (requestKind==='upload'?importButton(dom):button(dom)).click();await pending.started();
        check(keyInput(dom).disabled,'Operação em andamento bloqueia chave complementar: '+requestKind);
        if(requestKind==='refresh')check(!pending.requests[0].options.body.has('ibpt_key'),'Consulta à fonte não envia a chave complementar do arquivo');
        pending.resolve(response());await settled(dom);
        check(!keyInput(dom).disabled,'Fim da operação libera o campo de chave: '+requestKind);dom.window.close();
    }
    dom=await page();selectFiles(dom);setKey(dom,'TEST074');pending=await deferred(dom);importButton(dom).click();await pending.started();
    setKey(dom,'OUTRA074');pending.resolve(response(table({version:'NAOAPLICAR',key:'TEST074'})));await settled(dom);
    check(text(dom,'version')==='26.2.TEST'&&text(dom,'key')==='TEST072'&&keyInput(dom).value==='OUTRA074'&&fileInput(dom).files.length===1&&feedback(dom).hidden,'Resposta tardia é descartada quando a chave for alterada durante o upload');
    change(dom,'state','SP','change');check(keyInput(dom).disabled,'UF pendente também bloqueia a chave complementar');
    dom.window.close();

    console.log(JSON.stringify({passed:checks,checks:passed,scope:'Views reais e JavaScript atual; HTTP simulado; sem alteração no CRM ou transmissão fiscal.'},null,2));
})().catch(error=>{console.error(error.stack);process.exit(1);});
