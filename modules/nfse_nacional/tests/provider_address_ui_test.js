/* Actual settings view + production JavaScript, with HTTP mocked at the boundary. */
'use strict';
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../assets/nfse_nacional.js'), 'utf8');
const php = process.env.PHP_BIN || 'php';
const pause = () => new Promise(resolve => setTimeout(resolve, 10));
const prefix = 'settings[provider]';
const addressKeys = ['zip', 'street', 'number', 'complement', 'district', 'city', 'state', 'municipality_code'];
let checks = 0;
function check(ok, label) { if (!ok) throw new Error(label); checks++; }
async function page(provider = {}) {
    const render = cp.spawnSync(php, [path.join(__dirname, 'fixtures/provider_address_view.php'), JSON.stringify(provider)], {encoding: 'utf8'});
    if (render.status !== 0) throw new Error(render.stderr || render.stdout);
    const dom = new JSDOM(render.stdout, {url: 'https://crm.example.invalid/empresa-a/admin/settings?group=nfse_nacional', runScripts: 'outside-only', pretendToBeVisual: true});
    await new Promise(resolve => dom.window.document.addEventListener('DOMContentLoaded', resolve, {once: true}));
    dom.window.Element.prototype.scrollIntoView = function () {};
    dom.window.document.querySelector('[data-nfse-settings]').dataset.reload = '0';
    dom.window.eval(source);
    return dom;
}
function field(dom, key) { return dom.window.document.getElementById('settings-form').elements.namedItem(prefix + '[' + key + ']'); }
function button(dom, type) { return dom.window.document.querySelector('[data-nfse-lookup="' + type + '"]'); }
function result(fields) { return {ok: true, status: 200, json: async () => ({ok: true, message: 'Dados localizados.', data: {fields}, csrf: {name: 'fixture_csrf', hash: 'fixture-next'}})}; }
async function settle(dom) {
    for (let n = 0; n < 80; n++) { await pause(); if (!dom.window.document.querySelector('[aria-busy="true"]')) return; }
    throw new Error('The operation did not finish.');
}
function feedback(dom, type) { return dom.window.document.getElementById(button(dom, type).getAttribute('aria-controls')).textContent; }

(async function () {
    let dom = await page({street: 'Rua "A" <script>unsafe()</script>'});
    let d = dom.window.document;
    const address = d.querySelector('[aria-labelledby="nfse-settings-address-title"]');
    check(!!address && addressKeys.every(key => address.contains(field(dom, key))), 'Todos os campos devem ficar no grupo Endereço do estabelecimento');
    check(addressKeys.every(key => d.querySelectorAll('[name="' + prefix + '[' + key + ']"]').length === 1), 'Endereço e código IBGE não podem ser duplicados');
    check(addressKeys.every(key => !field(dom, key).required), 'Endereço parcial deve poder ser salvo');
    check(addressKeys.every(key => d.querySelector('label[for="' + field(dom, key).id + '"]')), 'Cada campo precisa de rótulo associado');
    check(field(dom, 'street').value === 'Rua "A" <script>unsafe()</script>' && !d.querySelector('script'), 'Endereço salvo deve ser exibido como texto seguro');
    check(field(dom, 'state').options.length === 28 && field(dom, 'state').value === 'BA', 'UF precisa das 27 opções brasileiras e opção vazia');
    check(d.querySelector('[data-nfse-environment] option[value="2"]').textContent === 'Produção restrita (homologação) — sem validade fiscal', 'Homologação deve estar identificada explicitamente');
    check(!d.querySelector('input[name*="url"], input[name*="endpoint"]'), 'Configurações não devem permitir editar endpoints');
    dom.window.close();

    dom = await page();
    const updated = {tax_id: '11222333000181', name: 'Empresa consultada', zip: '01310000', street: 'Avenida Paulista', number: '1000', complement: 'Sala 10', district: 'Bela Vista', city: 'São Paulo', state: 'SP', municipality_code: '3550308', simple_status: '3', national_code: '999999'};
    let requests = [];
    dom.window.fetch = async (url, options) => { requests.push({url, method: options.method, fields: Array.from(options.body.entries())}); return result(updated); };
    button(dom, 'cnpj').click(); await settle(dom);
    check(addressKeys.every(key => field(dom, key).value === updated[key]), 'CNPJ deve preencher e atualizar todos os campos do endereço, inclusive cidade e UF');
    check(field(dom, 'name').value === updated.name && field(dom, 'municipal_registration').value === '000123', 'CNPJ atualiza razão social e preserva IM ausente na resposta');
    check(field(dom, 'simple_status').value === '1' && dom.window.document.querySelector('[name="settings[service][national_code]"]').value === '', 'Consulta cadastral não pode alterar tributação ou código do serviço');
    check(requests.length === 1 && requests[0].url === 'https://crm.example.invalid/empresa-a/admin/nfse_nacional/lookup/cnpj' && requests[0].method === 'POST' && requests[0].fields.some(([key,value]) => key === 'fixture_csrf' && value === 'fixture-initial'), 'Consulta precisa preservar empresa, endpoint interno e CSRF');
    check(feedback(dom, 'cnpj').includes('inscrição municipal não foi retornada'), 'IM ausente deve ter orientação sem inventar valor');
    dom.window.close();

    dom = await page(); field(dom, 'zip').value = '01310-000';
    dom.window.fetch = async () => result({...updated, name: 'Não alterar', municipal_registration: '999', number: '999', complement: 'Não alterar', email: 'nao-usar@example.test'});
    button(dom, 'cep').click(); await settle(dom);
    check(['zip','street','district','city','state','municipality_code'].every(key => field(dom, key).value === updated[key]), 'CEP novo deve atualizar os campos retornados mesmo se havia endereço anterior');
    check(field(dom, 'number').value === '20' && field(dom, 'complement').value === 'Sala 3', 'CEP não pode substituir número ou complemento');
    check(field(dom, 'name').value === 'Emitente de demonstração' && field(dom, 'municipal_registration').value === '000123' && field(dom, 'email').value === 'financeiro@example.test', 'CEP não pode modificar identidade, IM ou contato');
    check(feedback(dom, 'cep').includes('Endereço do estabelecimento atualizado') && !feedback(dom, 'cep').includes('inscrição municipal'), 'CEP precisa de mensagem própria e curta');
    dom.window.close();

    dom = await page(); field(dom, 'zip').value = '01310-000';
    let finish;
    dom.window.fetch = () => new Promise(resolve => { finish = resolve; });
    button(dom, 'cep').click();
    for (let n = 0; n < 80 && !finish; n++) await pause();
    field(dom, 'street').value = 'Rua corrigida manualmente';
    field(dom, 'city').value = 'Município revisado';
    finish(result(updated)); await settle(dom);
    check(field(dom, 'street').value === 'Rua corrigida manualmente' && field(dom, 'city').value === 'Município revisado' && field(dom, 'state').value === 'SP', 'Consulta deve preservar edições realizadas enquanto aguardava retorno');
    check(feedback(dom, 'cep').includes('alterações feitas durante a consulta foram preservadas'), 'Edições concorrentes precisam ser informadas');
    dom.window.close();

    dom = await page(); field(dom, 'zip').value = '01310-000'; finish = null;
    dom.window.fetch = () => new Promise(resolve => { finish = resolve; });
    button(dom, 'cep').click();
    for (let n = 0; n < 80 && !finish; n++) await pause();
    field(dom, 'zip').value = '40020-000'; finish(result(updated)); await settle(dom);
    check(field(dom, 'street').value === 'Rua de demonstração' && field(dom, 'state').value === 'BA', 'Resposta de CEP antigo não pode preencher o novo endereço');
    dom.window.close();

    dom = await page();
    dom.window.fetch = async () => result({state: 'XX', street: '<img src=x onerror="unsafe()">'});
    button(dom, 'cnpj').click(); await settle(dom);
    check(field(dom, 'state').value === 'BA' && !dom.window.document.querySelector('img'), 'UF inválida não apaga a seleção e texto da consulta não cria HTML');
    dom.window.close();

    dom = await page(); requests = [];
    dom.window.fetch = async (url, options) => { requests.push({url, fields: Array.from(options.body.entries())}); return result({}); };
    const expected = Object.fromEntries(addressKeys.map(key => [key, field(dom, key).value]));
    dom.window.document.getElementById('settings-form').dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true})); await settle(dom);
    check(requests.length === 1 && requests[0].url.endsWith('/empresa-a/admin/nfse_nacional/settings'), 'Salvar deve usar o controller do módulo e a empresa ativa');
    check(addressKeys.every(key => requests[0].fields.some(([name,value]) => name === prefix + '[' + key + ']' && value === expected[key])), 'Todos os campos do endereço devem participar do salvamento');
    check(!requests[0].fields.some(([name]) => name === 'unrelated_native_option') && dom.window.document.querySelector('nav').innerHTML === '<i class="fa fa-users"></i>Menu nativo', 'Salvar endereço não deve publicar opções do núcleo ou alterar ícones globais');
    dom.window.close();
    console.log('OK: ' + checks + ' verificações da interface de endereço do estabelecimento.');
})().catch(error => { console.error(error); process.exitCode = 1; });
