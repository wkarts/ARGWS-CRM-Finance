/* Real accounting view and production bundle. All HTTP and binary downloads are mocked. */
'use strict';
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const {JSDOM} = require('jsdom');
const moduleRoot = path.resolve(__dirname, '..');
const php = process.env.PHP_BIN || 'php';
const source = fs.readFileSync(path.join(moduleRoot, 'assets/nfse_nacional.js'), 'utf8');
const passed = [], failures = [];
const pause = () => new Promise(resolve => setTimeout(resolve, 5));
const baseUrl = 'https://crm.example.invalid/empresa-a/admin/';
const phpFixture = String.raw`
define('BASEPATH', $argv[1]); require $argv[1] . '/autoload.php';
function init_head() { echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'; }
function init_tail() {}
function admin_url($path = '') { return 'https://crm.example.invalid/empresa-a/admin/' . $path; }
function nfse_nacional_settings_url() { return admin_url('settings?group=nfse_nacional'); }
function nfse_nacional_can($cap) { return in_array($cap, ['view', 'national_view', 'accounting_send', 'settings', 'report_crm', 'report_issued', 'report_received', 'report_package', 'report_export'], true); }
function form_open($url, $attrs = []) {
    $s = '<form method="post" action="' . htmlspecialchars($url, ENT_QUOTES) . '"';
    foreach ($attrs as $key => $value) { $s .= ' ' . htmlspecialchars($key, ENT_QUOTES) . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"'; }
    return $s . '><input type="hidden" name="fixture_csrf" value="fixture-token">';
}
function form_close() { return '</form>'; }
function &get_instance() { static $ci; if (!$ci) { $ci = (object) ['security' => new class {
    function get_csrf_token_name() { return 'fixture_csrf'; } function get_csrf_hash() { return 'fixture-token'; }
}]; } return $ci; }
$filters = ['environment' => '1', 'month' => '2026-11', 'direction' => 'both', 'format' => 'both'];
$recipients = [['id' => 'accountant-a', 'name' => 'Contabilidade de demonstração', 'email' => 'contabilidade@example.invalid'],
    ['id' => 'accountant-b', 'name' => 'Segundo destinatário de demonstração', 'email' => 'contador2@example.invalid']];
$report = ['issued' => 1, 'received' => 1, 'total' => 2, 'cancelled' => 0, 'local' => 1, 'national' => 1,
    'missing_competence' => 0, 'fingerprint' => str_repeat('a', 64)];
$can_send = true; $history = [];
require $argv[1] . '/views/accounting.php';
`;
const fixture = cp.spawnSync(php, ['-r', phpFixture, moduleRoot], {encoding: 'utf8'});
if (fixture.status !== 0 || /Warning:|Fatal error:/.test(fixture.stdout)) throw new Error(fixture.stderr || fixture.stdout);
const fixtureHtml = fixture.stdout;

function check(value, label) { if (!value) throw new Error(label); passed.push(label); }
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return {promise, resolve, reject}; }
function headers(values) { const normalized = Object.fromEntries(Object.entries(values || {}).map(([key, value]) => [key.toLowerCase(), value])); return {get: key => normalized[key.toLowerCase()] || null}; }
function jsonResponse(payload, status = 200, extraHeaders = {}) { return {ok: status >= 200 && status < 300, status, headers: headers({'Content-Type': 'application/json', ...extraHeaders}), json: async () => payload}; }
async function until(condition, label) { for (let n = 0; n < 100; n++) { if (condition()) return; await pause(); } throw new Error(label || 'A operação de teste não terminou.'); }
async function settle(env) { await until(() => !env.d.querySelector('[aria-busy="true"]'), 'Controles permaneceram ocupados.'); await pause(); }
async function createPage(options = {}) {
    const dom = new JSDOM(fixtureHtml, {url: baseUrl + 'nfse_nacional/accounting', runScripts: 'outside-only', pretendToBeVisual: true});
    await new Promise(resolve => dom.window.document.addEventListener('DOMContentLoaded', resolve, {once: true}));
    const w = dom.window, d = w.document;
    const state = {calls: [], confirmations: [], confirmAnswer: true, downloads: [], blobs: [], revoked: [], timers: new Map(), timerId: 0};
    w.Element.prototype.scrollIntoView = function () {};
    w.confirm = text => { state.confirmations.push(text); return state.confirmAnswer; };
    w.setTimeout = function (callback, ms) { const id = ++state.timerId; state.timers.set(id, {callback, ms}); return id; };
    w.clearTimeout = id => state.timers.delete(id);
    w.URL.createObjectURL = blob => { state.blobs.push(blob); return 'blob:https://crm.example.invalid/package-' + state.blobs.length; };
    w.URL.revokeObjectURL = url => state.revoked.push(url);
    const nativeClick = w.HTMLAnchorElement.prototype.click;
    w.HTMLAnchorElement.prototype.click = function () {
        if (this.hasAttribute('download')) { state.downloads.push({href: this.href, name: this.download, connected: this.isConnected}); return; }
        return nativeClick.call(this);
    };
    if (!options.rootTokenOnly) { w.csrfData = {token_name: 'fixture_csrf', hash: 'fixture-token'}; }
    const env = {dom, w, d, state, handler: async () => { throw new Error('Requisição inesperada no teste.'); }};
    w.fetch = async (url, request) => {
        state.calls.push({url, request, entries: Array.from(request.body.entries()), csrf: request.body.get('fixture_csrf')});
        return env.handler(url, request);
    };
    env.download = d.querySelector('form.nfse-accounting-download');
    env.send = d.querySelector('form[data-nfse-form="accounting-send"]');
    env.send.querySelector('[name="recipient_ids[]"]').checked = true;
    const native = d.createElement('aside');
    native.innerHTML = '<nav id="native-menu"><a href="/empresa-a/admin/invoices"><i class="fa fa-bolt menu-icon"></i>Vendas</a><a href="/empresa-a/admin/nfse_nacional">NFSE</a></nav><input type="hidden" name="fixture_csrf" value="fixture-token">';
    d.body.prepend(native);
    env.menuSnapshot = native.querySelector('nav').outerHTML;
    env.tabs = d.querySelector('nav[aria-label="Documentos da NFS-e"]');
    env.tabsSnapshot = env.tabs.outerHTML;
    w.eval(source);
    env.submit = form => { const event = new w.Event('submit', {bubbles: true, cancelable: true}); form.dispatchEvent(event); return event; };
    return env;
}
function zipResponse(env, config = {}) {
    const blob = new env.w.Blob([new Uint8Array([0x50, 0x4b, 0x03, 0x04, 0, 255, 13, 10])], {type: 'application/zip'});
    return {blobValue: blob, ok: true, status: 200, headers: headers({'Content-Type': 'application/zip',
        'Content-Disposition': 'attachment; filename="NFSe_2026-11.zip"', 'X-NFSe-CSRF-Name': 'fixture_csrf', 'X-NFSe-CSRF-Hash': 'binary-token', ...(config.headers || {})}),
        blob: config.blob || (async () => blob), json: async () => { throw new Error('Resposta binária não deve ser convertida para JSON.'); }};
}
async function runCase(label, operation) {
    try { await operation(); } catch (error) { failures.push({case: label, error: error.message}); }
}

(async function () {
    await runCase('ZIP e CSRF por cabeçalhos', async () => {
        const env = await createPage();
        try {
            const response = zipResponse(env); env.handler = async () => response;
            const sentinel = env.d.createElement('button'); sentinel.disabled = true; sentinel.textContent = 'Bloqueado pela configuração'; env.download.appendChild(sentinel);
            const event = env.submit(env.download); env.submit(env.download);
            await settle(env);
            check(event.defaultPrevented && env.state.calls.length === 1, 'Download intercepta o POST binário e bloqueia duplo clique');
            const call = env.state.calls[0];
            check(call.url === baseUrl + 'nfse_nacional/accounting_download' && call.request.method === 'POST'
                && call.request.credentials === 'same-origin' && call.request.redirect === 'error' && call.request.cache === 'no-store', 'Download mantém empresa, credenciais locais e política de redirecionamento');
            check(call.csrf === 'fixture-token' && call.entries.some(([name, value]) => name === 'fingerprint' && value === 'a'.repeat(64))
                && ['environment', 'month', 'direction', 'format'].every(name => call.entries.some(([key]) => key === name)), 'POST binário contém CSRF e seleção previamente conferida');
            check(env.w.csrfData.hash === 'binary-token' && Array.from(env.d.querySelectorAll('[name="fixture_csrf"]')).every(input => input.value === 'binary-token')
                && env.d.querySelector('.nfse-root').dataset.csrfHash === 'binary-token', 'Cabeçalhos do ZIP renovam CSRF global, roots e formulários nativos');
            check(env.state.blobs[0] === response.blobValue && env.state.blobs[0].size === 8 && env.state.downloads.length === 1
                && env.state.downloads[0].connected && env.state.downloads[0].name === 'NFSe_2026-11.zip', 'Resposta ZIP é baixada como Blob original com nome seguro');
            check(!env.d.querySelector('a[download]') && env.d.querySelector('.nfse-feedback').textContent.includes('O download foi iniciado.'), 'Link temporário é removido e a interface informa o início do download');
            const revoke = [...env.state.timers.values()].find(timer => timer.ms === 1000); check(!!revoke, 'URL temporária possui liberação programada'); revoke.callback();
            check(env.state.revoked[0] === env.state.downloads[0].href, 'Liberação utiliza exatamente a URL criada para o Blob');
            check(!env.download.hasAttribute('aria-busy') && !env.download.querySelector('button[type="submit"]').disabled && sentinel.disabled,
                'Fim do download libera controles próprios e preserva os bloqueios anteriores');
            check(env.state.confirmations.length === 0, 'Download não solicita confirmação de envio por e-mail');
            check(env.tabs.outerHTML === env.tabsSnapshot && env.d.querySelector('#native-menu').outerHTML === env.menuSnapshot, 'Download e atualização CSRF não modificam guias, menus ou ícones');
        } finally { env.dom.window.close(); }
    });

    await runCase('Confirmação nativa do envio', async () => {
        const env = await createPage();
        try {
            env.handler = async () => jsonResponse({ok: true, message: 'Aceito pelo servidor de e-mail.', data: {}, csrf: {name: 'fixture_csrf', hash: 'send-token'}});
            env.state.confirmAnswer = false; env.submit(env.send); await pause();
            check(env.state.calls.length === 0 && !env.send.hasAttribute('aria-busy'), 'Cancelar a confirmação não executa envio nem ocupa o formulário');
            check(env.state.confirmations.length === 1 && ['arquivos fiscais', '11/2026', 'Produção', 'destinatários selecionados'].every(text => env.state.confirmations[0].includes(text)), 'Confirmação nativa identifica conteúdo, competência, ambiente e destinatários');
            env.state.confirmAnswer = true; env.submit(env.send); env.submit(env.send); await settle(env);
            check(env.state.calls.length === 1 && env.state.calls[0].url.endsWith('/accounting_send'), 'Aceite dispara somente um POST do pacote contábil mesmo com clique repetido');
            check(env.state.calls[0].entries.filter(([key]) => key === 'recipient_ids[]').map(([, value]) => value).join() === 'accountant-a'
                && !env.state.calls[0].entries.some(([key]) => key === 'email'), 'Envio usa apenas os identificadores selecionados e não envia um endereço livre');
            check([...env.state.timers.values()].some(timer => timer.ms === 500) && env.state.downloads.length === 0, 'Envio concluído programa atualização da página sem gerar download adicional');
        } finally { env.dom.window.close(); }
    });

    await runCase('Fila compartilhada entre ZIP e envio', async () => {
        const env = await createPage();
        try {
            const binary = deferred();
            env.handler = async url => url.endsWith('/accounting_download') ? zipResponse(env, {blob: () => binary.promise})
                : jsonResponse({ok: true, message: 'Aceito.', data: {}, csrf: {name: 'fixture_csrf', hash: 'after-send'}});
            env.submit(env.download); await until(() => env.state.calls.length === 1);
            env.submit(env.send); await pause(); await pause();
            check(env.state.calls.length === 1 && env.download.hasAttribute('aria-busy') && env.send.hasAttribute('aria-busy'), 'Fila mantém o envio aguardando a conclusão do corpo binário');
            check(env.w.csrfData.hash === 'binary-token', 'CSRF dos cabeçalhos é atualizado mesmo enquanto o Blob ainda é recebido');
            binary.resolve(new env.w.Blob(['PK teste'], {type: 'application/zip'})); await settle(env);
            check(env.state.calls.length === 2 && env.state.calls[1].url.endsWith('/accounting_send') && env.state.calls[1].csrf === 'binary-token', 'Operação seguinte na fila usa o token renovado pelo download');
            check(env.w.csrfData.hash === 'after-send' && env.state.downloads.length === 1, 'Fila conclui ZIP e envio sem repetir requisições');
        } finally { env.dom.window.close(); }
    });

    await runCase('Erro JSON e continuidade da fila', async () => {
        const env = await createPage();
        try {
            const response = deferred(); const unsafe = '<img src=x onerror="unsafe()">Pacote alterado; confira novamente.';
            env.handler = async url => url.endsWith('/accounting_download') ? response.promise
                : jsonResponse({ok: true, message: 'Consulta sintética concluída.', data: {}, csrf: {name: 'fixture_csrf', hash: 'after-error'}});
            env.submit(env.download); await until(() => env.state.calls.length === 1);
            const other = env.send.cloneNode(true); other.removeAttribute('data-confirm'); other.removeAttribute('data-reload'); other.dataset.nfseForm = 'offline-follow-up'; other.action = baseUrl + 'nfse_nacional/offline-follow-up';
            env.d.querySelector('.nfse-root').appendChild(other); env.submit(other);
            response.resolve(jsonResponse({ok: false, message: unsafe, csrf: {name: 'fixture_csrf', hash: 'json-error-token'}, data: {log_url: baseUrl + 'nfse_nacional/logs?operation=accounting.download'}}, 422,
                {'X-NFSe-CSRF-Name': 'fixture_csrf', 'X-NFSe-CSRF-Hash': 'header-error-token'}));
            await settle(env);
            check(env.state.downloads.length === 0 && env.state.blobs.length === 0, 'Erro JSON do download não é salvo como um ZIP');
            check(env.state.calls.length === 2 && env.state.calls[1].csrf === 'json-error-token', 'Resposta JSON atualiza o CSRF e libera a próxima operação da fila');
            check(!env.d.querySelector('.nfse-feedback img,.nfse-feedback script') && !env.download.hasAttribute('aria-busy'), 'Erro remoto não cria HTML executável nem mantém formulário bloqueado');
            // Repeat just the failing download to inspect its own feedback without a later job replacing it.
            env.handler = async () => jsonResponse({ok: false, message: unsafe, data: {log_url: baseUrl + 'nfse_nacional/logs?operation=accounting.download'}, csrf: {name: 'fixture_csrf', hash: 'error-final'}}, 422);
            env.submit(env.download); await settle(env);
            check(env.d.querySelector('.nfse-feedback').textContent.includes(unsafe)
                && env.d.querySelector('.nfse-feedback a').href === baseUrl + 'nfse_nacional/logs?operation=accounting.download', 'Mensagem JSON é texto seguro e oferece o log autorizado da operação');
        } finally { env.dom.window.close(); }
    });

    await runCase('Resposta inválida, nomes e origens', async () => {
        const env = await createPage({rootTokenOnly: true});
        try {
            env.handler = async () => zipResponse(env, {headers: {'Content-Disposition': 'attachment; filename="../../arquivo.zip"'}});
            env.submit(env.download); await settle(env);
            check(env.state.calls[0].csrf === 'fixture-token' && env.state.downloads[0].name === 'NFSe_pacote_mensal.zip', 'Sem csrfData usa token da root e rejeita nome de arquivo com caminho');
            env.handler = async () => ({ok: false, status: 403, headers: headers({'Content-Type': 'text/html', 'X-NFSe-CSRF-Name': 'fixture_csrf', 'X-NFSe-CSRF-Hash': 'html-token'}), json: async () => { throw new Error('HTML não é JSON'); }});
            env.submit(env.download); await settle(env);
            check(env.state.downloads.length === 1 && env.d.querySelector('.nfse-feedback').textContent.includes('Não foi possível baixar o pacote'), 'Resposta HTML não inicia download nem expõe seu conteúdo');
            check(env.d.querySelector('.nfse-root').dataset.csrfHash === 'html-token', 'CSRF dos cabeçalhos é preservado mesmo em resposta de erro não JSON');
            env.handler = async () => { throw new TypeError('Falha de comunicação sintética.'); };
            env.submit(env.download); await settle(env);
            check(!env.download.hasAttribute('aria-busy') && env.d.querySelector('.nfse-feedback').textContent.includes('Falha de comunicação sintética.'), 'Falha de rede libera o formulário e mostra retorno seguro');
            const before = env.state.calls.length; env.download.action = 'https://outside.example.invalid/archive'; env.submit(env.download); await settle(env);
            check(env.state.calls.length === before && env.d.querySelector('.nfse-feedback').textContent.includes('Endereço do pacote inválido'), 'Download não envia CSRF ou parâmetros a outra origem');
        } finally { env.dom.window.close(); }
    });

    await runCase('Validação e navegação nativas', async () => {
        const env = await createPage();
        try {
            const required = env.d.createElement('input'); required.name = 'offline_required'; required.required = true; env.download.appendChild(required);
            env.submit(env.download); await pause();
            check(env.state.calls.length === 0 && !env.download.hasAttribute('aria-busy'), 'Validação HTML impede download com formulário inválido');
            const filter = env.d.querySelector('form[method="get"]'); const event = env.submit(filter);
            check(!event.defaultPrevented && env.state.calls.length === 0, 'Conferência GET permanece navegação nativa e não aciona operação fiscal');
            let nativeClicks = 0;
            env.tabs.querySelectorAll('a').forEach(anchor => anchor.addEventListener('click', click => { nativeClicks++; click.preventDefault(); }));
            env.tabs.querySelectorAll('a').forEach(anchor => anchor.click()); await pause();
            check(nativeClicks === 4 && env.state.calls.length === 0 && env.tabs.outerHTML === env.tabsSnapshot, 'Quatro guias de documentos mantêm seus listeners e links sem chamadas fiscais');
            env.w.eval(source);
            required.remove(); env.handler = async () => zipResponse(env); env.submit(env.download); await settle(env);
            check(env.state.calls.length === 1 && env.state.downloads.length === 1, 'Carregar o bundle novamente não duplica o handler de download');
        } finally { env.dom.window.close(); }
    });

    for (const stalledStage of ['headers', 'body']) await runCase('Limite de espera do download em ' + stalledStage, async () => {
        const env = await createPage();
        try {
            const next = deferred();
            env.handler = async (url, request) => {
                if (!url.endsWith('/accounting_download')) return next.promise;
                const interrupted = new Promise((resolve, reject) => {
                    if (request.signal) request.signal.addEventListener('abort', () => reject(new env.w.DOMException('Interrompido pelo limite de espera.', 'AbortError')), {once: true});
                });
                return stalledStage === 'headers' ? interrupted : zipResponse(env, {blob: () => interrupted});
            };
            env.submit(env.download); await until(() => env.state.calls.length === 1);
            env.submit(env.send); await pause();
            const deadline = [...env.state.timers.values()].find(timer => timer.ms === 120000);
            check(!!env.state.calls[0].request.signal && !!deadline, stalledStage + ': download pendente possui AbortSignal e limite de 120 segundos');
            deadline.callback(); await until(() => env.state.calls.length === 2 && !env.download.hasAttribute('aria-busy'));
            check(env.state.calls[0].request.signal.aborted && ![...env.state.timers.values()].some(timer => timer.ms === 120000),
                stalledStage + ': limite aborta o transporte e é removido no finally');
            check(env.d.querySelector('.nfse-feedback').textContent.includes('somente a ação Baixar pacote')
                && env.d.querySelector('.nfse-feedback').textContent.includes('Reabra a página'), stalledStage + ': timeout orienta nova tentativa apenas do download');
            if (stalledStage === 'body') check(env.state.calls[1].csrf === 'binary-token', 'Timeout do corpo conserva o token já recebido nos cabeçalhos para a próxima operação');
            next.resolve(jsonResponse({ok: true, message: 'Fila liberada.', data: {}})); await settle(env);
            check(env.state.calls.length === 2 && env.state.calls[1].url.endsWith('/accounting_send') && env.state.downloads.length === 0,
                stalledStage + ': expiração libera a fila sem repetir POST nem criar arquivo incompleto');
            check(!env.download.hasAttribute('aria-busy') && !env.send.hasAttribute('aria-busy'), stalledStage + ': expiração devolve o controle dos formulários');
        } finally { env.dom.window.close(); }
    });
    const report = {passed: passed.length, failed: failures.length, checks: passed, failures,
        scope: 'View PHP real e bundle de produção em jsdom. fetch, confirmação, Blob/download e temporizadores simulados; sem rede, portal, SEFIN ou SMTP.'};
    if (process.env.NFSE_ACCOUNTING_UI_REPORT) fs.writeFileSync(process.env.NFSE_ACCOUNTING_UI_REPORT, JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report, null, 2));
    if (failures.length) process.exitCode = 1;
}()).catch(error => { console.error(error); process.exitCode = 1; });
