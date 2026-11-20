/* Optional DOM regression: run with jsdom available in NODE_PATH. No network or fiscal API is used. */
'use strict';
const fs = require('fs');
const path = require('path');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../assets/nfse_nacional.js'), 'utf8');
const wait = () => new Promise(resolve => setTimeout(resolve, 30));
let checks = 0;
function check(condition, message) { if (!condition) throw new Error(message); checks++; }

(async function () {
    const dom = new JSDOM(`<!doctype html><html><body>
        <nav><i class="fa fa-users"></i><span class="nfse-status">Menu preservado</span></nav>
        <div id="wrapper"><div class="nfse-root" data-csrf-name="fixture_csrf" data-csrf-hash="token-inicial">
            <div class="nfse-feedback" hidden></div>
            <p class="nfse-title-line"><span class="label nfse-status">Rascunho</span></p>
            <strong data-nfse-fiscal-label>Rascunho</strong>
            <strong data-nfse-transmission-label>Não enviada</strong>
            <p data-nfse-fiscal-explanation>Dados salvos.</p>
            <a data-nfse-preview href="/empresa-a/admin/nfse_nacional/preview/8" target="_blank" rel="noopener noreferrer">Pré-visualizar DANFSe</a>
            <form id="nfse-document-form" data-nfse-form="document" data-document-id="8" data-can-save="1" action="/empresa-a/admin/nfse_nacional/save/8" method="post">
                <input name="draft_hash" value="hash-inicial"><button type="submit">Salvar</button>
            </form>
        </div></div></body></html>`, {url: 'https://crm.example.invalid/empresa-a/admin/nfse_nacional/document/8', runScripts: 'outside-only', pretendToBeVisual: true});
    await new Promise(resolve => dom.window.document.addEventListener('DOMContentLoaded', resolve, {once: true}));
    dom.window.Element.prototype.scrollIntoView = function () {};
    let requests = [];
    const fiscal = {label: 'Validada', transmission_label: 'Não enviada', explanation: '<img src=x onerror="unsafe()">'};
    let responseDocument = {id: 8, status: 'ready', draft_hash: 'hash-novo', fiscal_status: fiscal};
    dom.window.fetch = async function (url, options) {
        requests.push({url, method: options.method, csrf: options.body.get('fixture_csrf')});
        return {ok: true, status: 200, json: async () => ({ok: true, data: {document: responseDocument}, csrf: {name: 'fixture_csrf', hash: 'token-novo'}})};
    };
    dom.window.eval(source);
    const d = dom.window.document;
    const preview = d.querySelector('[data-nfse-preview]');
    preview.addEventListener('click', event => event.preventDefault());
    preview.click();
    await wait();
    check(requests.length === 0, 'Abrir prévia não deve salvar, reservar ou transmitir pelo JavaScript');
    check(preview.href === 'https://crm.example.invalid/empresa-a/admin/nfse_nacional/preview/8', 'A prévia mantém o contexto da empresa');
    const form = d.querySelector('#nfse-document-form');
    form.dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
    await wait();
    check(requests.length === 1 && requests[0].method === 'POST' && requests[0].csrf === 'token-inicial', 'Salvar permanece ação explícita com CSRF');
    check(d.querySelector('[data-nfse-fiscal-label]').textContent === 'Validada', 'Status fiscal é atualizado após salvar');
    check(d.querySelector('[data-nfse-transmission-label]').textContent === 'Não enviada', 'Validação não se transforma em transmissão');
    check(d.querySelector('[data-nfse-fiscal-explanation]').textContent === fiscal.explanation && !d.querySelector('[data-nfse-fiscal-explanation] img'), 'Explicação fiscal é texto sem HTML executável');
    check(d.querySelector('nav').innerHTML === '<i class="fa fa-users"></i><span class="nfse-status">Menu preservado</span>', 'Atualização fiscal não altera ícones ou outros menus');

    responseDocument = {id: 99, status: 'cancelled', draft_hash: 'outro-documento', fiscal_status: {label: 'Não pertence a esta tela'}};
    form.dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
    await wait();
    check(d.querySelector('[data-nfse-fiscal-label]').textContent === 'Validada' && d.querySelector('[name="draft_hash"]').value === 'hash-novo', 'Resposta de outro documento não contamina status nem hash');

    responseDocument = {id: 8, status: 'cancelled', draft_hash: 'hash-cancelado', fiscal_status: {label: 'Cancelada', transmission_label: 'Cancelamento confirmado', explanation: 'Evento oficial registrado.'}};
    form.dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
    await wait();
    check(d.querySelector('.nfse-title-line .nfse-status').textContent === 'Cancelada' && d.querySelector('.nfse-title-line .nfse-status').classList.contains('label-danger'), 'Cancelamento possui rótulo e classe reconhecidos');
    check(d.querySelector('[data-nfse-transmission-label]').textContent === 'Cancelamento confirmado', 'Situação consultada é aplicada sem inventar protocolo');
    dom.window.close();
    console.log('OK: ' + checks + ' verificações DOM de prévia, transmissão e isolamento visual.');
}()).catch(error => { console.error(error); process.exit(1); });
