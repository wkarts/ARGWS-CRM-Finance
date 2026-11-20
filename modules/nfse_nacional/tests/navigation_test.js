'use strict';

// Development dependency: jsdom, installed outside the deployed module.
// NODE_PATH=/path/to/node_modules node tests/navigation_test.js
// Optional: NFSE_NATIVE_CSS=/path/to/fontawesome.min.css
// Optional: NFSE_NAV_REPORT=/path/to/results.json
// The PHP registry is covered by hooks_test.php. This suite verifies that the
// operational bundle and retired assets never rewrite CRM navigation.
const fs = require('fs');
const path = require('path');
const {JSDOM} = require('jsdom');
const moduleRoot = path.resolve(__dirname, '..');
const passed = [], failed = [], skipped = [], cases = [];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(value, label) { (value ? passed : failed).push(label); }
function ready(dom) {
    return dom.window.document.readyState === 'loading'
        ? new Promise(resolve => dom.window.document.addEventListener('DOMContentLoaded', resolve, {once: true}))
        : Promise.resolve();
}
function shell(adminPath, legacy = false) {
    return `<!doctype html><html><head><style>
        .menu-icon{display:inline-block;width:18px;margin-right:14px;font-family:NativeIcons;color:rgb(107,114,128)}
        .customer-tabs .menu-icon{width:16px;margin-right:10px}
        .nav>li>a{font-size:14px;padding:9px 12px}
    </style></head><body>
      <aside><ul id="side-menu" class="nav metis-menu">
        <li id="portal" class="menu-item-portalsaj"><a href="#"><i class="fa fa-address-card menu-icon"></i>PortalSAJ<span class="fa arrow"></span></a><ul class="nav nav-second-level"><li><a href="${adminPath}/portalsaj">Painel</a></li></ul></li>
        <li id="accounting" class="menu-item-accounting"><a href="#" aria-expanded="false"><i class="fa fa-usd menu-icon"></i>Contabilidade<span class="fa arrow"></span></a></li>
        <li id="sales" class="menu-item-sales"><a href="#"><i class="fa fa-bolt menu-icon"></i>Vendas<span class="fa arrow"></span></a><ul class="nav nav-second-level"><li><a href="${adminPath}/invoices">Faturas</a></li><li><a href="${adminPath}/nfse_nacional">Atalho fiscal de outro módulo</a></li></ul></li>
        <li id="asaas"><a href="#"><i class="fa fa-bank menu-icon"></i>Asaas<span class="fa arrow"></span></a></li>
        <li id="fiscal" class="menu-item-nfse-nacional"><a id="fiscal-link" href="#" data-toggle="collapse" aria-expanded="false" aria-controls="fiscal-documents"><i class="${legacy ? 'fa fa-file-text-o' : 'fa-solid fa-file-invoice'} menu-icon"></i><span class="menu-text">NFSE</span><span class="fa arrow"></span></a><ul class="nav nav-second-level collapse" id="fiscal-documents"><li class="sub-menu-item-nfse-nacional-documentos"><a id="documents-link" href="${adminPath}/nfse_nacional">Documentos</a></li>${legacy ? `<li><a href="${adminPath}/nfse_nacional/settings">Configurações antigas</a></li>` : ''}</ul></li>
        <li id="finance"><a href="#"><i class="fa fa-bank menu-icon"></i>Financeiro<span class="fa arrow"></span></a></li>
        <li id="api"><a href="#"><i class="fa fa-cogs menu-icon"></i>API<span class="fa arrow"></span></a></li>
        <li id="webhooks"><a href="#"><svg class="svg-inline--fa fa-share-nodes"></svg>Webhooks<span class="fa arrow"></span></a></li>
        <li><a href="?display=compact"><i class="fa fa-list menu-icon"></i>Opções da página</a></li>
        <li><a href=""><i class="fa fa-star menu-icon"></i>Sem rota</a></li>
        <li><a href="javascript:void(0)"><i class="fa fa-star menu-icon"></i>Ação</a></li>
        <li><a href="/other-company/admin/nfse_nacional"><i class="fa fa-building menu-icon"></i>Outra empresa</a></li>
      </ul></aside>
      <div id="wrapper"><div class="content">
        <ul id="customer-tabs" class="customer-tabs nav">
          <li><a id="profile-link" href="${adminPath}/clients/client/12"><i class="fa fa-user-circle menu-icon"></i>Perfil</a></li>
          <li><a href="${adminPath}/clients/client/12?group=contacts"><i class="fa fa-users menu-icon"></i>Contatos <span class="badge">1</span></a></li>
          <li class="customer_tab_nfse_nacional"><a id="client-fiscal" data-group="nfse_nacional" href="${adminPath}/clients/client/12?group=nfse_nacional"><i class="fa-solid fa-file-invoice menu-icon"></i>NFS-e</a></li>
          <li><a href="#"><i class="fa fa-tasks menu-icon"></i>Tarefas</a></li>
        </ul>
        <nav id="native-settings"><ul class="nav"><li>Documentos Fiscais<ul><li><a href="${adminPath}/settings?group=nfse_nacional"><i class="fa-solid fa-file-invoice menu-icon"></i>NFS-e Nacional</a></li></ul></li><li><a href="${adminPath}/settings?group=general"><i class="fa fa-cog menu-icon"></i>Geral</a></li></ul></nav>
        <div id="ordinary-content"><a href="${adminPath}/nfse_nacional">Ver documentos</a><a href="${adminPath}/clients/client/12?group=nfse_nacional">Ver dados fiscais</a><div class="row-options"><a href="#">Ver</a> | <a href="#">Editar</a></div><form id="ordinary-form"><input name="company" value="Empresa nativa"><button type="submit">Salvar cadastro</button></form></div>
        <div class="nfse-root" id="fiscal-controls">
          <select data-nfse-environment><option value="2" selected>Testes</option><option value="1">Produção</option></select>
          <p data-nfse-production-note>Aviso fiscal</p>
          <input type="checkbox" data-nfse-local-certificate>
          <fieldset data-nfse-local-certificate-fields><input type="password" value=""></fieldset>
        </div>
      </div></div>
    </body></html>`;
}
const regionIds = ['side-menu', 'customer-tabs', 'native-settings', 'ordinary-content'];
function snapshot(d) {
    return new Map(regionIds.map(id => {
        const node = d.getElementById(id);
        return [id, {node, html: node.outerHTML, descendants: Array.from(node.querySelectorAll('*'))}];
    }));
}
function identical(d, before) {
    return [...before].every(([id, old]) => d.getElementById(id) === old.node && old.node.outerHTML === old.html
        && old.descendants.length === old.node.querySelectorAll('*').length
        && old.descendants.every((node, index) => node === old.node.querySelectorAll('*')[index]));
}
function iconStyles(w) {
    return Array.from(w.document.querySelectorAll('#side-menu .menu-icon,#customer-tabs .menu-icon,#ordinary-content,#ordinary-form input,#ordinary-form button,#native-settings a')).map(node => {
        const s = w.getComputedStyle(node);
        return [s.display, s.width, s.marginRight, s.fontFamily, s.color, s.backgroundColor, s.fontSize, s.borderWidth, s.padding, s.boxSizing].join('|');
    }).join('\n');
}
async function environment(adminPath, suffix, legacy = false) {
    const dom = new JSDOM(shell(adminPath, legacy), {url: 'https://crm.example.test' + adminPath + suffix, runScripts: 'outside-only'});
    await ready(dom);
    const w = dom.window;
    const state = {requests: 0, observers: 0};
    w.fetch = async () => { state.requests++; throw new Error('Unexpected network in navigation test'); };
    w.XMLHttpRequest.prototype.open = function () { state.requests++; throw new Error('Unexpected XHR'); };
    const Observer = w.MutationObserver;
    w.MutationObserver = function (callback) { state.observers++; return new Observer(callback); };
    w.admin_url = w.location.origin + adminPath + '/';
    return {dom, w, d: w.document, state};
}
(async () => {
    const legacy = fs.readFileSync(path.join(moduleRoot, 'assets/navigation-icons.js'), 'utf8');
    const legacyCss = fs.readFileSync(path.join(moduleRoot, 'assets/navigation-icons.css'), 'utf8');
    const bundle = fs.readFileSync(path.join(moduleRoot, 'assets/nfse_nacional.js'), 'utf8');
    const styles = fs.readFileSync(path.join(moduleRoot, 'assets/nfse_nacional.css'), 'utf8');
    const routes = [
        ['documentos', '/nfse_nacional'], ['configurações', '/settings?group=nfse_nacional'],
        ['emitidas nacionais', '/nfse_nacional/national/issued'], ['recebidas', '/nfse_nacional/national/received'],
        ['pacote mensal', '/nfse_nacional/accounting'], ['eventos fiscais', '/nfse_nacional/fiscal_events/local/8'],
        ['configurações antigas', '/nfse_nacional/settings'], ['perfil', '/clients/client/12'],
        ['guia NFS-e', '/clients/client/12?group=nfse_nacional'], ['faturas', '/invoices'], ['painel', '/'],
    ];
    for (const adminPath of ['/admin', '/company-route/admin']) {
        for (const [name, suffix] of routes) {
            const label = `${adminPath}: ${name}`;
            cases.push(label);
            const {dom, w, d, state} = await environment(adminPath, suffix);
            const before = snapshot(d);
            const styleBefore = iconStyles(w);
            w.eval(legacy);
            await delay(10);
            check(identical(d, before) && state.observers === 0, label + ': asset legado é inerte e não instala observador');
            const style = d.createElement('style'); style.textContent = legacyCss; d.head.appendChild(style);
            check(style.sheet.cssRules.length === 0, label + ': CSS legado não contém regras que alterem a navegação');
            const fiscalStyle = d.createElement('style'); fiscalStyle.textContent = styles; d.head.appendChild(fiscalStyle);
            w.eval(bundle);
            await delay(15);
            check(identical(d, before), label + ': bundle preserva integralmente HTML e identidade dos menus, submenus, abas e conteúdo nativos');
            check(iconStyles(w) === styleBefore, label + ': CSS fiscal preserva ícones, navegação e controles externos ao módulo');
            check(d.getElementById('fiscal-documents') && d.getElementById('documents-link').textContent === 'Documentos'
                && d.getElementById('documents-link').querySelectorAll('i,svg').length === 0,
                label + ': submenu Documentos permanece expansível e sem ícone');
            check(d.querySelector('#fiscal-controls [data-nfse-production-note]').hidden
                && !d.querySelector('#fiscal-controls [data-nfse-local-certificate-fields]').hidden
                && !d.querySelector('#fiscal-controls [data-nfse-local-certificate-fields]').disabled,
                label + ': controles fiscais e importação A1 são inicializados sem alterar a navegação');
            w.eval(bundle); w.eval(legacy);
            await delay(10);
            check(identical(d, before) && state.requests === 0, label + ': execução repetida mantém navegação e não realiza requisições');
            dom.window.close();
        }
    }

    for (const adminPath of ['/admin', '/company-route/admin']) {
        const label = adminPath + ': HTML legado'; cases.push(label);
        const {dom, w, d, state} = await environment(adminPath, '/nfse_nacional', true);
        const before = snapshot(d);
        w.eval(legacy); w.eval(bundle);
        await delay(15);
        check(identical(d, before), label + ': script não tenta corrigir, remover ou reconstruir menus antigos no navegador');
        check(state.requests === 0, label + ': leitura de navegação não transmite dados');
        dom.window.close();
    }

    {
        const label = 'handlers nativos'; cases.push(label);
        const {dom, w, d, state} = await environment('/tenant/admin', '/nfse_nacional');
        const anchors = ['fiscal-link', 'documents-link', 'profile-link', 'client-fiscal'].map(id => d.getElementById(id));
        anchors.push(d.querySelector('#sales > a'));
        const calls = anchors.map(() => 0);
        anchors.forEach((node, i) => node.addEventListener('click', event => { calls[i]++; event.preventDefault(); }));
        const before = snapshot(d);
        w.eval(legacy); w.eval(bundle);
        await delay(15);
        anchors.forEach(node => node.dispatchEvent(new w.MouseEvent('click', {bubbles: true, cancelable: true})));
        check(calls.every(count => count === 1), 'handlers: listeners dos menus fiscais e de Vendas e guias do cliente continuam funcionando');
        check(identical(d, before) && state.requests === 0, 'handlers: nenhum link é clonado ou alterado; o clique nativo não dispara operação fiscal');
        const event = new w.Event('submit', {bubbles: true, cancelable: true});
        d.getElementById('ordinary-form').dispatchEvent(event);
        check(!event.defaultPrevented && state.requests === 0, 'handlers: envio de formulário do CRM não é interceptado pelo módulo');
        dom.window.close();
    }

    {
        const label = 'conteúdo dinâmico'; cases.push(label);
        const {dom, w, d, state} = await environment('/tenant/admin', '/invoices');
        w.eval(bundle); w.eval(legacy);
        await delay(15);
        const menu = d.createElement('li');
        menu.innerHTML = '<a href="#"><i class="fa fa-bolt menu-icon"></i>Menu dinâmico</a><ul><li><a href="/tenant/admin/nfse_nacional">Atalho fiscal</a></li></ul>';
        d.getElementById('side-menu').appendChild(menu);
        const tab = d.createElement('li');
        tab.innerHTML = '<a href="/tenant/admin/clients/client/12?group=nfse_nacional"><i class="fa-solid fa-file-invoice menu-icon"></i>NFS-e</a>';
        d.getElementById('customer-tabs').appendChild(tab);
        const before = snapshot(d);
        const newRoot = d.createElement('div'); newRoot.className = 'nfse-root';
        newRoot.innerHTML = '<select data-nfse-environment><option value="2" selected>Testes</option></select><p data-nfse-production-note>Mensagem fiscal</p>';
        d.getElementById('wrapper').appendChild(newRoot);
        await delay(25);
        check(identical(d, before), 'AJAX: novas abas, menus e links fiscais aninhados permanecem exatamente como o tema os criou');
        check(newRoot.querySelector('[data-nfse-production-note]').hidden, 'AJAX: somente o novo conteúdo .nfse-root é inicializado');
        check(menu.firstElementChild.querySelector('i').className === 'fa fa-bolt menu-icon'
            && menu.querySelector('ul').querySelector('a').children.length === 0, 'AJAX: nenhum ícone é acrescentado ou substituído em menus dinâmicos');
        check(state.requests === 0, 'AJAX: inicialização da interface não executa requisição fiscal');
        dom.window.close();
    }

    if (process.env.NFSE_NATIVE_CSS) {
        const nativeCss = fs.readFileSync(process.env.NFSE_NATIVE_CSS, 'utf8');
        check(nativeCss.includes('.fa-solid,.fas{font-weight:900}') && nativeCss.includes('.fa-file-invoice:before{content:"\\f570"}'),
            'CSS nativo fornecido contém o ícone usado pelo registro PHP');
    } else { skipped.push('Compatibilidade do glyph não conferida: NFSE_NATIVE_CSS não informado.'); }
    const result = {passed: passed.length, failed: failed.length, skipped: skipped.length, cases, checks: passed, failures: failed,
        not_executed: skipped, scope: 'Isolamento de DOM/estilos em jsdom; hooks PHP simulam somente interfaces do núcleo. Sem sessão do CRM, emissão ou homologação visual do portal.'};
    if (process.env.NFSE_NAV_REPORT) fs.writeFileSync(process.env.NFSE_NAV_REPORT, JSON.stringify(result, null, 2) + '\n');
    console.log(JSON.stringify(result, null, 2));
    if (failed.length) process.exitCode = 1;
})().catch(error => {console.error(error); process.exitCode = 1;});
