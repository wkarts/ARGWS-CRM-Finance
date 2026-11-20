/* Actual Perfex module views and JavaScript; HTTP is mocked at the module boundary. */
'use strict';
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const {JSDOM} = require('jsdom');
const php = process.env.PHP_BIN || 'php';
const modulePath = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(modulePath, 'assets/nfse_nacional.js'), 'utf8');
const pause = () => new Promise(resolve => setTimeout(resolve, 10));
let checks = 0;
function check(value, message) { if (!value) throw new Error(message); checks++; }
const fixture = String.raw`
define('BASEPATH', __DIR__);
require $argv[1] . '/autoload.php';
function init_head(): void { echo '<!doctype html><html><head><meta charset="UTF-8"></head><body><nav><i class="fa fa-users"></i>Menu nativo</nav>'; }
function init_tail(): void {}
function admin_url(string $path = ''): string { return 'https://crm.example.invalid/empresa-a/admin/' . $path; }
function nfse_nacional_can(string $capability): bool { return true; }
function nfse_nacional_status_label($status): string { return ['draft'=>'Rascunho','authorized'=>'Autorizada','cancelled'=>'Cancelada'][$status] ?? $status; }
function form_open(string $action, array $attrs=[]): string {
  $html='<form method="post" action="'.htmlspecialchars($action,ENT_QUOTES).'"';
  foreach($attrs as $k=>$v){ $html.=' '.htmlspecialchars($k,ENT_QUOTES).'="'.htmlspecialchars((string)$v,ENT_QUOTES).'"'; }
  return $html.'>';
}
function form_close(): string { return '</form>'; }
function &get_instance() {
  static $ci;
  if (!$ci) $ci=(object)['security'=>new class { function get_csrf_token_name(){return 'fixture_csrf';} function get_csrf_hash(){return 'token-inicial';} }];
  return $ci;
}
$input=json_decode($argv[2],true,32,JSON_THROW_ON_ERROR);
$settings=\NfseNacional\Config::defaults();
$settings['tax']['total_tax_mode']='ibpt';
$settings['tax']['simple_rate']='3.00';
if (($input['view']??'')==='settings') {
  init_head(); echo form_open(admin_url('settings'),['id'=>'settings-form']);
  require $argv[1].'/views/settings.php'; echo form_close().'</body></html>'; return;
}
if (($input['view']??'')==='profile') {
  init_head(); echo '<div class="nfse-root">'.form_open(admin_url('nfse_nacional/save_profile/12'),['id'=>'profile-form']);
  require $argv[1].'/views/_ui_helpers.php';
  $nfseProfilePrefix='profile'; $nfseProfileValues=[]; $nfseProfileDefaults=$settings;
  require $argv[1].'/views/emission_profile.php'; echo form_close().'</div></body></html>'; return;
}
$values=array_replace($settings['tax'],['service_amount'=>'252.00','unconditional_discount'=>'0.00','conditional_discount'=>'10.00','iss_rate'=>'3.00']);
$draft=['environment'=>'2','competence'=>'2026-11-11','provider'=>['name'=>'Emitente sintético','tax_id'=>'11222333000181','simple_status'=>'3','state'=>'BA'],'recipient'=>['name'=>'Tomador sintético'],'service'=>['national_code'=>'010101','nbs'=>'101011000','description'=>'Serviço sintético'],'values'=>$values,'ibscbs'=>$settings['ibscbs']];
$draft=array_replace_recursive($draft,$input['draft']??[]);
$document=['id'=>8,'invoice_id'=>51,'client_id'=>12,'status'=>$input['status']??'draft','environment'=>'2','draft_hash'=>'hash-inicial','attempts'=>0];
$invoice=['id'=>51,'clientid'=>12,'subtotal'=>'252.00','total'=>'252.00']; $client=['company'=>'Cliente sintético'];
$official_tax_summary=['base'=>'200.00','rate'=>'4.00','amount'=>'8.00','retention'=>'2'];
$can=['view'=>true,'edit'=>true,'transmit'=>true,'send'=>true,'substitute'=>false];
$recipients=[['id'=>21,'selection'=>'recipient:21','label'=>'Tomador com perfil próprio','name'=>'Tomador escolhido','tax_id'=>'11222333000181','emission_defaults'=>array_replace_recursive($settings,['service'=>['municipality_code'=>'2927408','national_code'=>'010101','municipal_code'=>'','nbs'=>'101012000','description'=>'Outro serviço'],'tax'=>['iss_rate'=>'5.00','retention'=>'2','total_tax_mode'=>'ibpt']])]];
require $argv[1].'/views/document.php';
`;
async function page(options = {}) {
    const result = cp.spawnSync(php, ['-r', fixture, modulePath, JSON.stringify(options)], {encoding:'utf8'});
    if (result.status !== 0 || result.stderr) throw new Error(result.stderr || result.stdout);
    const dom = new JSDOM(result.stdout, {url:'https://crm.example.invalid/empresa-a/admin/nfse_nacional/document/8', runScripts:'outside-only', pretendToBeVisual:true});
    await new Promise(resolve => dom.window.document.addEventListener('DOMContentLoaded', resolve, {once:true}));
    dom.window.Element.prototype.scrollIntoView = function () {};
    dom.window.eval(source);
    return dom;
}
function field(dom, key) {
    const group = key === 'nbs' ? 'service' : 'values';
    return dom.window.document.querySelector('#nfse-document-form').elements.namedItem('draft[' + group + '][' + key + ']');
}
function update(dom, key, value, type = 'input') {
    const input = field(dom, key); input.value = value;
    input.dispatchEvent(new dom.window.Event(type,{bubbles:true}));
}
function text(dom, selector) { return dom.window.document.querySelector(selector).textContent; }
function visible(dom, selector) { return !dom.window.document.querySelector(selector).hidden; }
function inputs(overrides = {}) { return {...{nbs:'101011000', service_amount:'252.00', unconditional_discount:'0.00', simple_rate:'3.00', total_tax_mode:'ibpt'}, ...overrides}; }
function estimate(overrides = {}) {
    return {...{base:'252.00',total_federal:'33.89',total_state:'0.00',total_municipal:'12.60',total:'46.49',text:'Optante do Simples Nacional. Trib. aprox. R$ 33,89 Federal, R$ 0,00 Estadual e R$ 12,60 Municipal. Fonte: IBPT [C44399]. Alíquota do Simples Nacional: 3,0000%.',
        snapshot:{uf:'BA',nbs:'101011000',nacionalfederal:'13.4500',estadual:'0.0000',municipal:'5.0000',valid_from:'2026-11-01',valid_to:'2026-11-30',chave:'C44399',versao:'TESTE'}},...overrides};
}
function payload(summary, id = 8) { return {ok:true,status:200,json:async()=>({ok:true,data:{document:{id,status:'draft',draft_hash:'hash-salvo',tax_summary:summary}},csrf:{name:'fixture_csrf',hash:'token-renovado'}})}; }
async function submit(dom) {
    dom.window.document.querySelector('#nfse-document-form').dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));
    for (let n=0;n<100;n++) { await pause(); if (!dom.window.document.querySelector('[aria-busy="true"]')) return; }
    throw new Error('Salvamento não terminou.');
}

(async function () {
    let dom = await page({view:'settings'});
    let d = dom.window.document;
    let mode = d.querySelector('[name="settings[tax][total_tax_mode]"]');
    check(mode.value === 'ibpt' && mode.options.length === 4, 'Configurações oferecem IBPT automático junto aos modos existentes');
    check(!d.querySelector('[data-nfse-tax-show="simple ibpt"]').hidden && d.querySelector('[data-nfse-tax-show="amount"]').hidden, 'IBPT mostra percentual do Simples e oculta valores manuais');
    check(!d.querySelector('input[name*="url"],select[name*="ibpt_provider"],input[name*="chave"]'), 'Usuário não escolhe fornecedor ou endereço da consulta IBPT');
    check(d.body.textContent.includes('UF do emitente') && d.body.textContent.includes('pelo NBS'), 'Automação informa os dados usados sem pedir escolha de tecnologia');
    mode.value='amount'; mode.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    check(!d.querySelector('[data-nfse-tax-show="amount"]').hidden && d.querySelector('[data-nfse-tax-show="simple ibpt"]').hidden, 'Modo manual continua independente');
    mode.value='simple'; mode.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    check(!d.querySelector('[data-nfse-tax-show="simple ibpt"]').hidden, 'Modo Simples mantém seu percentual');
    check(d.querySelector('nav').innerHTML === '<i class="fa fa-users"></i>Menu nativo', 'Configuração fiscal não muda os ícones de outros menus');
    dom.window.close();

    dom = await page({view:'profile'}); d=dom.window.document;
    const profileMode=d.querySelector('[data-nfse-profile-field="total_tax_mode"] select');
    const inherit=d.querySelector('[data-nfse-profile-field="total_tax_mode"] [data-nfse-profile-inherit]');
    check(profileMode.value === 'ibpt' && profileMode.disabled && inherit.checked, 'Perfil do cliente herda o modo IBPT da empresa');
    check(d.querySelector('[data-nfse-profile-field="total_federal"]').hidden && !d.querySelector('[data-nfse-profile-field="simple_rate"]').hidden, 'Perfil oculta valores manuais e mantém o percentual do Simples no IBPT');
    inherit.checked=false; inherit.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    profileMode.value='amount'; profileMode.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    check(!profileMode.disabled && !d.querySelector('[data-nfse-profile-field="total_federal"]').hidden, 'Tomador pode substituir IBPT por seu próprio modo manual');
    inherit.checked=true; inherit.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    check(profileMode.value==='ibpt' && profileMode.disabled && d.querySelector('[data-nfse-profile-field="total_federal"]').hidden, 'Retornar ao padrão recompõe o IBPT herdado');
    dom.window.close();

    const savedSnapshot = {...estimate().snapshot, description:'Serviço sintético de teste', fonte:'IBPT/empresometro.com.br', fetched_at:'2026-11-11T12:00:00Z', source_hash:'a'.repeat(64)};
    dom=await page({draft:{issued_at:'2026-11-11T09:00:00-03:00',ibpt:savedSnapshot}});
    check(visible(dom,'[data-nfse-ibpt-calculated]') && text(dom,'[data-nfse-ibpt-field="federal_amount"]')==='R$ 33,89', 'Renderização PHP confere o snapshot e apresenta os tributos salvos antes de qualquer requisição');
    check(text(dom,'[data-nfse-ibpt-field="source"]')==='Fonte: IBPT [C44399]' && text(dom,'[data-nfse-ibpt-field="text"]').includes('3,0000%'), 'Renderização PHP mantém fonte e texto fiscal de acordo com o cálculo');
    dom.window.close();
    dom=await page({draft:{issued_at:'2026-11-11T09:00:00-03:00',ibpt:{...savedSnapshot,valid_to:'2026-10-31',valid_from:'2026-09-20'}}});
    check(visible(dom,'[data-nfse-ibpt-calculated]') && text(dom,'[data-nfse-ibpt-field="federal_amount"]')==='R$ 33,89', 'Renderização PHP mantém cálculo da tabela vencida sem bloqueio');
    dom.window.close();
    dom=await page({draft:{issued_at:'2026-11-11T09:00:00-03:00',provider:{state:'SP'},ibpt:savedSnapshot}});
    check(!visible(dom,'[data-nfse-ibpt-calculated]') && text(dom,'[data-nfse-ibpt-pending]').includes('UF'), 'Snapshot de outra UF não se torna uma estimativa válida na view');
    dom.window.close();

    dom=await page(); d=dom.window.document;
    let requests=[];
    dom.window.fetch=async(url,options)=>{requests.push({url,options});return payload({ibpt:estimate(),ibpt_error:'',inputs:inputs()});};
    check(text(dom,'[data-nfse-iss-base]')==='R$ 252,00' && text(dom,'[data-nfse-iss-amount]')==='R$ 7,56', 'ISSQN local usa base e sua própria alíquota, sem descontar o desconto condicionado');
    check(text(dom,'[data-nfse-iss-rate]')==='3,0000%', 'Alíquota informada aparece com quatro casas na prévia');
    update(dom,'iss_rate','5.00');
    check(text(dom,'[data-nfse-iss-amount]')==='R$ 12,60', 'Alteração da alíquota atualiza imediatamente a prévia ISSQN');
    update(dom,'unconditional_discount','2,00');
    check(text(dom,'[data-nfse-iss-base]')==='R$ 250,00' && text(dom,'[data-nfse-iss-amount]')==='R$ 12,50', 'Desconto incondicionado reduz a base exatamente');
    update(dom,'service_amount','0.10'); update(dom,'unconditional_discount','0.00'); update(dom,'iss_rate','5.00');
    check(text(dom,'[data-nfse-iss-amount]')==='R$ 0,01', 'Meio centavo arredonda para cima sem ponto flutuante');
    update(dom,'service_amount','999999999999.99'); update(dom,'iss_rate','9.99');
    check(text(dom,'[data-nfse-iss-amount]')==='R$ 99.900.000.000,00', 'Cálculo suporta limite monetário sem perda de precisão');
    update(dom,'iss_rate','10.00');
    check(text(dom,'[data-nfse-iss-amount]')==='—', 'Alíquota fora do limite local não apresenta um valor fiscal');
    update(dom,'iss_rate','');
    check(text(dom,'[data-nfse-iss-amount]')==='—' && text(dom,'[data-nfse-iss-note]').includes('Informe valores válidos'), 'Alíquota ausente não vira imposto zero');
    update(dom,'iss_taxation','4','change');
    check(text(dom,'[data-nfse-iss-amount]')==='Não se aplica', 'Não incidência não recebe ISSQN inventado');
    update(dom,'iss_taxation','1','change'); update(dom,'service_amount','252.00'); update(dom,'iss_rate','3.00');
    update(dom,'retention','2','change');
    check(text(dom,'[data-nfse-iss-retention]')==='Retido pelo tomador', 'Retenção do ISSQN fica explícita e não modifica o cálculo');
    check(requests.length===0, 'Edição da prévia não consulta serviços externos nem transmite documentos');
    await submit(dom);
    check(requests.length===1 && requests[0].url==='https://crm.example.invalid/empresa-a/admin/nfse_nacional/save/8', 'Consulta automática usa o salvamento interno da empresa');
    check(requests[0].options.body.get('fixture_csrf')==='token-inicial', 'Salvar e atualizar a estimativa preserva CSRF');
    check(visible(dom,'[data-nfse-ibpt-calculated]') && !visible(dom,'[data-nfse-ibpt-pending]'), 'Retorno consistente exibe a estimativa calculada');
    check(text(dom,'[data-nfse-ibpt-field="federal_amount"]')==='R$ 33,89' && text(dom,'[data-nfse-ibpt-field="municipal_amount"]')==='R$ 12,60', 'Tabela mostra federal e municipal aproximados separados do ISSQN');
    check(text(dom,'[data-nfse-ibpt-field="state_amount"]')==='R$ 0,00', 'Percentual estadual zero é apresentado como valor calculado válido');
    check(text(dom,'[data-nfse-ibpt-field="source"]')==='Fonte: IBPT [C44399]' && text(dom,'[data-nfse-ibpt-field="validity"]')==='01/11/2026 a 30/11/2026', 'Chave e vigência da tabela são visíveis');
    check(text(dom,'[data-nfse-ibpt-field="text"]').includes('Alíquota do Simples Nacional: 3,0000%'), 'Observação apresenta o percentual do Simples e a fonte');
    check(field(dom,'iss_rate').value==='3.00' && text(dom,'[data-nfse-iss-amount]')==='R$ 7,56', 'Alíquota municipal do IBPT não substitui alíquota ou valor ISSQN');
    check(!d.querySelector('[name="draft[values][iss_amount]"],[name="draft[ibpt][chave]"]'), 'Resumo somente leitura não publica impostos ou chave manipuláveis');
    update(dom,'nbs','101012000');
    check(!visible(dom,'[data-nfse-ibpt-calculated]') && text(dom,'[data-nfse-ibpt-pending]').includes('Salve o rascunho'), 'Troca de NBS oculta os valores anteriores até novo cálculo');
    update(dom,'nbs','101011000');
    check(visible(dom,'[data-nfse-ibpt-calculated]'), 'Restaurar a entrada original recompõe a estimativa ainda correspondente');
    update(dom,'service_amount','252,00');
    check(visible(dom,'[data-nfse-ibpt-calculated]'), 'Formato decimal equivalente não invalida a correspondência');
    update(dom,'simple_rate','4.00');
    check(!visible(dom,'[data-nfse-ibpt-calculated]'), 'Mudança do percentual do Simples requer texto atualizado');
    update(dom,'simple_rate','3.00');
    let finish;
    dom.window.fetch=()=>new Promise(resolve=>{finish=resolve;});
    const pending=submit(dom);
    for(let n=0;n<100&&!finish;n++) await pause();
    update(dom,'service_amount','300.00'); finish(payload({ibpt:estimate(),ibpt_error:'',inputs:inputs()})); await pending;
    check(!visible(dom,'[data-nfse-ibpt-calculated]') && field(dom,'service_amount').value==='300.00', 'Resposta atrasada não exibe cálculo para valores alterados durante a consulta');
    update(dom,'service_amount','252.00');
    dom.window.fetch=async()=>payload({ibpt:null,ibpt_error:'A tabela IBPT está vencida desde 31/10/2026.',inputs:inputs()}); await submit(dom);
    check(!visible(dom,'[data-nfse-ibpt-calculated]') && text(dom,'[data-nfse-ibpt-pending]').includes('vencida'), 'Tabela vencida aparece sem substituir tributos por zero');
    dom.window.fetch=async()=>payload({ibpt:estimate({text:'<img src=x onerror="unsafe()">'}),ibpt_error:'',inputs:inputs()}); await submit(dom);
    check(text(dom,'[data-nfse-ibpt-field="text"]')==='<img src=x onerror="unsafe()">' && !d.querySelector('[data-nfse-ibpt-calculated] img'), 'Texto retornado não vira HTML executável');
    dom.window.fetch=async()=>payload({ibpt:estimate({text:'OUTRO DOCUMENTO'}),ibpt_error:'',inputs:inputs()},99); await submit(dom);
    check(!text(dom,'[data-nfse-ibpt-field="text"]').includes('OUTRO DOCUMENTO'), 'Resposta de outro documento não contamina o resumo fiscal');
    const recipient=d.querySelector('[data-nfse-draft-recipient]'); recipient.value='recipient:21';recipient.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    check(field(dom,'iss_rate').value==='5.00' && text(dom,'[data-nfse-iss-amount]')==='R$ 12,60' && !visible(dom,'[data-nfse-ibpt-calculated]'), 'Troca do tomador aplica seu ISSQN e invalida a estimativa do NBS anterior');
    update(dom,'total_tax_mode','amount','change');
    check(!visible(dom,'[data-nfse-ibpt-summary]') && !d.querySelector('[data-nfse-tax-show="amount"]').hidden, 'Escolha manual oculta IBPT e preserva o modo existente');
    check(d.querySelector('nav').innerHTML==='<i class="fa fa-users"></i>Menu nativo', 'Atualizações do documento não modificam ícones ou menus externos');
    dom.window.close();

    dom=await page({status:'authorized'});
    check(text(dom,'[data-nfse-iss-base]')==='R$ 200,00' && text(dom,'[data-nfse-iss-amount]')==='R$ 8,00', 'Documento autorizado exibe base e imposto oficiais recebidos do servidor');
    check(text(dom,'[data-nfse-iss-retention]')==='Retido pelo tomador', 'Retenção da nota autorizada utiliza o valor oficial e não a preferência do rascunho');
    update(dom,'service_amount','999.00'); update(dom,'iss_rate','9.99');
    check(text(dom,'[data-nfse-iss-base]')==='R$ 200,00' && text(dom,'[data-nfse-iss-amount]')==='R$ 8,00', 'JavaScript nunca recalcula ou substitui ISSQN oficial');
    dom.window.close();
    console.log('OK: '+checks+' verificações DOM de IBPT, ISSQN, perfis, resposta assíncrona e isolamento.');
}()).catch(error=>{console.error(error);process.exit(1);});
