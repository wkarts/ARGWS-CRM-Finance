/* Fiscal screens and registration forms. */
(function () {
    'use strict';
    if (window.__nfseNacionalUiInitialized) { return; }
    window.__nfseNacionalUiInitialized = true;

    var activeRequests = new WeakSet();
    var initializedRoots = new WeakSet();
    var bridgedValidators = new WeakMap();
    var invoiceRecipientForms = new WeakMap();
    var emissionProfileStates = new WeakMap();
    var ibptDisplayStates = new WeakMap();
    var ibptManagerStates = new WeakMap();
    var requestQueue = Promise.resolve();
    var knownStatuses = ['draft', 'ready', 'processing', 'authorized', 'cancelled', 'rejected', 'uncertain'];
    var statusLabels = {draft: 'Rascunho', ready: 'Validada', processing: 'Em processamento', authorized: 'Autorizada', cancelled: 'Cancelada', rejected: 'Rejeitada', uncertain: 'A consultar'};
    var statusClasses = {draft: 'label-default', ready: 'label-info', processing: 'label-warning', authorized: 'label-success', cancelled: 'label-danger', rejected: 'label-danger', uncertain: 'label-warning'};
    var emissionProfileFields = {
        service: ['municipality_code', 'national_code', 'municipal_code', 'nbs', 'description'],
        tax: ['iss_taxation', 'retention', 'iss_rate', 'total_tax_mode', 'total_federal', 'total_state', 'total_municipal', 'simple_rate'],
        ibscbs: ['enabled', 'operation_indicator', 'destination_indicator', 'cst', 'class_code', 'final_consumer', 'purpose']
    };

    function rootOf(node) { return node && node.closest ? node.closest('.nfse-root') : null; }
    function feedbackOf(node) {
        var modal = node.closest('.modal');
        return (modal && modal.querySelector('.nfse-feedback')) || (rootOf(node) && rootOf(node).querySelector('.nfse-feedback'));
    }
    function errorTexts(errors) {
        var result = [];
        function collect(value) {
            if (result.length >= 20 || value === null || value === undefined) { return; }
            if (typeof value === 'string') { if (value.trim()) { result.push(value); } return; }
            if (Array.isArray(value)) { value.forEach(collect); return; }
            if (typeof value === 'object') {
                if (typeof value.message === 'string') { result.push((value.field ? value.field + ': ' : '') + value.message); }
                else { Object.keys(value).forEach(function (key) { collect(value[key]); }); }
            }
        }
        collect(errors);
        return result;
    }
    function notify(node, type, message, errors) {
        var area = feedbackOf(node);
        if (!area) { return; }
        area.hidden = false;
        area.className = 'nfse-feedback alert alert-' + (type === 'error' ? 'danger' : type) + ' nfse-feedback-' + type;
        area.textContent = '';
        var text = document.createElement('p');
        text.textContent = message || (type === 'error' ? 'Não foi possível concluir a operação.' : 'Operação concluída.');
        area.appendChild(text);
        var details = errorTexts(errors);
        if (details.length) {
            var list = document.createElement('ul');
            details.forEach(function (detail) { var li = document.createElement('li'); li.textContent = detail; list.appendChild(li); });
            area.appendChild(list);
        }
        area.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }
    function csrf(node) {
        if (window.csrfData && typeof window.csrfData.token_name === 'string' && typeof window.csrfData.hash === 'string' && window.csrfData.hash) { return {name: window.csrfData.token_name, hash: window.csrfData.hash}; }
        var root = rootOf(node);
        if (root && root.dataset.csrfName && root.dataset.csrfHash) { return {name: root.dataset.csrfName, hash: root.dataset.csrfHash}; }
        return null;
    }
    function updateCsrf(token) {
        if (!token || typeof token.name !== 'string' || typeof token.hash !== 'string') { return; }
        document.querySelectorAll('.nfse-root').forEach(function (root) { root.dataset.csrfName = token.name; root.dataset.csrfHash = token.hash; });
        document.querySelectorAll('input[type="hidden"]').forEach(function (input) { if (input.name === token.name) { input.value = token.hash; } });
        if (window.csrfData) { window.csrfData.token_name = token.name; window.csrfData.hash = token.hash; }
    }
    function applyDocument(data, node) {
        var doc = data && (data.document || (data.draft_hash ? data : null));
        if (!doc) { return; }
        var root = rootOf(node);
        var form = root && root.querySelector('#nfse-document-form');
        if (!form || (doc.id && form.dataset.documentId && String(doc.id) !== form.dataset.documentId)) { return; }
        var input = form.querySelector('input[name="draft_hash"]');
        if (input && doc.draft_hash) { input.value = doc.draft_hash; }
        if (knownStatuses.indexOf(doc.status) !== -1) {
            root.querySelectorAll('.nfse-title-line .nfse-status').forEach(function (badge) { badge.className = 'label ' + statusClasses[doc.status] + ' nfse-status nfse-status-' + doc.status; badge.textContent = statusLabels[doc.status]; });
        }
        var fiscal = doc.fiscal_status;
        if (fiscal && typeof fiscal === 'object') {
            [['label', '[data-nfse-fiscal-label]'], ['transmission_label', '[data-nfse-transmission-label]'], ['explanation', '[data-nfse-fiscal-explanation]']].forEach(function (field) {
                if (typeof fiscal[field[0]] !== 'string') { return; }
                root.querySelectorAll(field[1]).forEach(function (element) { element.textContent = fiscal[field[0]]; });
            });
        }
        if (plainObject(doc.tax_summary)) { applyTaxSummary(form, doc.tax_summary); }
    }
    function busy(node, loading) {
        var settings = node.closest('[data-nfse-settings]');
        var group = node.tagName === 'FORM' ? node : ((settings && settings.closest('form')) || node.closest('form[data-nfse-form]') || node.closest('tr') || node.closest('.nfse-invoice-panel') || node);
        if (loading) {
            group.classList.add('nfse-busy');
            group.setAttribute('aria-busy', 'true');
            var controls = group.tagName === 'BUTTON' ? [group] : group.querySelectorAll('button, input[type="submit"], select[data-nfse-prepare-recipient]');
            Array.prototype.forEach.call(controls, function (button) { if (!button.disabled) { button.dataset.nfseDisabledByRequest = '1'; button.disabled = true; } });
        } else {
            group.classList.remove('nfse-busy');
            group.removeAttribute('aria-busy');
            var disabled = group.tagName === 'BUTTON' ? [group] : group.querySelectorAll('[data-nfse-disabled-by-request]');
            Array.prototype.forEach.call(disabled, function (button) { if (button.dataset.nfseDisabledByRequest === '1') { button.disabled = false; delete button.dataset.nfseDisabledByRequest; } });
        }
    }
    function safeUrl(url) {
        if (!url) { return null; }
        try { var parsed = new URL(url, window.location.href); return parsed.origin === window.location.origin ? parsed.href : null; } catch (_) { return null; }
    }
    function queueRequest(operation) {
        var job = requestQueue.then(operation);
        requestQueue = job.catch(function () {});
        return job;
    }
    function request(node, url, body) {
        return queueRequest(function () { return performRequest(node, url, body); });
    }
    async function performRequest(node, url, body) {
        var endpoint = safeUrl(url);
        if (!endpoint) { throw new Error('Endereço da operação inválido. Reabra a página.'); }
        var data = body || new FormData();
        var token = csrf(node);
        if (token) { data.set(token.name, token.hash); }
        var controller = new AbortController();
        var timer = window.setTimeout(function () { controller.abort(); }, 90000);
        try {
            var response = await fetch(endpoint, {method: 'POST', body: data, credentials: 'same-origin', redirect: 'error', cache: 'no-store', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal});
            var payload;
            try { payload = await response.json(); }
            catch (_) { throw new Error(response.status === 403 ? 'A sessão ou o token de segurança expirou. Reabra a página e tente novamente.' : 'O servidor não retornou uma resposta válida. Reabra o documento e consulte sua situação.'); }
            updateCsrf(payload.csrf);
            applyDocument(payload.data, node);
            if (!response.ok || !payload.ok) {
                var error = new Error(payload.message || 'Revise os dados informados.');
                error.errors = payload.errors || (payload.data && payload.data.errors);
                error.payload = payload;
                error.httpStatus = response.status;
                throw error;
            }
            return payload;
        } finally { window.clearTimeout(timer); }
    }
    function displayFailure(node, error, transmitting) {
        var message = error.name === 'AbortError' ? 'A consulta demorou além do esperado. Reabra o documento para conferir a situação.' : error.message;
        if (transmitting && (!error.payload || error.httpStatus >= 500)) { message += ' O resultado da transmissão pode estar pendente; consulte a DPS antes de emitir novamente.'; }
        notify(node, 'error', message, error.errors);
        var data = error.payload && error.payload.data;
        var logUrl = data && safeUrl(data.log_url);
        var area = feedbackOf(node);
        if (area && logUrl && /\/nfse_nacional\/logs\?/.test(logUrl)) {
            var link = document.createElement('a');
            link.className = 'btn btn-default btn-sm';
            link.href = logUrl;
            link.textContent = 'Ver detalhes no log';
            area.appendChild(link);
        }
    }
    async function navigateResponse(node, payload) {
        var panel = node.closest('.nfse-invoice-panel[data-nfse-context-url]');
        if (panel && (node.dataset.nfseRefresh === '1' || node.dataset.reload === '1')) {
            try { await refreshInvoicePanel(panel, payload.message || 'Operação concluída.'); }
            catch (_) {
                notify(node, 'info', (payload.message || 'Operação concluída.') + ' Reabra a fatura para atualizar o painel da NFS-e.');
                addReviewLink(node, payload.data && safeUrl(payload.data.url), 'Abrir documento');
            }
            return true;
        }
        var url = payload.data && safeUrl(payload.data.url);
        if (node.dataset.nfseNavigate === '1' && url) { window.location.assign(url); return true; }
        if (node.dataset.reload === '1') { window.setTimeout(function () { window.location.reload(); }, 500); return true; }
        return false;
    }
    function validForm(form) {
        if (typeof form.reportValidity === 'function') { return form.reportValidity(); }
        return true;
    }
    function lookupIdentifier(value) { return String(value || '').toUpperCase().replace(/[.\/\-\s]+/g, ''); }
    function lookupFeedback(button, message, error) {
        var field = button.closest('.form-group');
        var area = field && field.querySelector('[data-nfse-lookup-feedback]');
        if (!area) { return; }
        area.hidden = false;
        area.className = 'help-block ' + (error ? 'text-danger' : 'text-muted');
        area.textContent = message;
    }
    async function lookupRegistration(button) {
        var form = button.closest('form');
        var group = button.closest('.input-group');
        var input = group && group.querySelector('input');
        var type = button.dataset.nfseLookup;
        var prefix = button.dataset.nfseLookupPrefix;
        if (!form || !input || !rootOf(button) || !prefix || activeRequests.has(form)) { return; }
        var identifier = lookupIdentifier(input.value);
        if ((type === 'cnpj' && !/^[A-Z0-9]{12}[0-9]{2}$/.test(identifier))
            || (type === 'cep' && !/^[0-9]{8}$/.test(identifier)) || ['cnpj', 'cep'].indexOf(type) === -1) {
            lookupFeedback(button, type === 'cnpj' ? 'Informe um CNPJ válido para consultar.' : 'Informe um CEP com 8 dígitos.', true);
            input.focus();
            return;
        }
        var endpoint = safeUrl(button.dataset.nfseLookupUrl);
        if (!endpoint) { lookupFeedback(button, 'Reabra a página para consultar os dados.', true); return; }
        // Company settings initially reuse CRM data. Explicit CNPJ/CEP lookups
        // refresh returned fields without overwriting edits made during the request.
        var refreshIssuer = prefix === 'settings[provider]' && !!button.closest('[data-nfse-settings]');
        var keys = type === 'cep' ? ['street', 'district', 'city', 'state', 'zip', 'municipality_code']
            : ['tax_id', 'name', 'trade_name', 'email', 'phone', 'street', 'number', 'complement', 'district', 'city', 'state', 'zip', 'municipality_code', 'municipal_registration'];
        var snapshot = {};
        keys.forEach(function (key) {
            var target = form.elements.namedItem(prefix + '[' + key + ']');
            if (target && typeof target.value === 'string') { snapshot[key] = {input: target, value: target.value}; }
        });
        var actionBefore = form.action;
        var body = new FormData(); body.set('value', identifier);
        activeRequests.add(form);
        busy(form, true);
        lookupFeedback(button, 'Consultando dados…', false);
        try {
            var payload = await request(button, endpoint, body);
            if (!form.isConnected || form.action !== actionBefore || lookupIdentifier(input.value) !== identifier) {
                lookupFeedback(button, 'O campo foi alterado durante a consulta. Consulte novamente.', false);
                return;
            }
            var fields = payload.data && payload.data.fields;
            var changed = 0;
            var editedDuringLookup = false;
            if (fields && typeof fields === 'object' && !Array.isArray(fields)) {
                keys.forEach(function (key) {
                    var before = snapshot[key];
                    if (!before || before.input.disabled || before.input.readOnly || key === 'tax_id') { return; }
                    if (before.input.value !== before.value) { editedDuringLookup = true; return; }
                    if (!refreshIssuer && before.value.trim() !== '') { return; }
                    var value = fields[key];
                    if (typeof value !== 'string' || !value.trim()) { return; }
                    if (before.input.tagName === 'SELECT' && !Array.prototype.some.call(before.input.options, function (option) { return option.value === value; })) { return; }
                    if (before.input.maxLength > 0) { value = value.slice(0, before.input.maxLength); }
                    if (value === before.input.value) { return; }
                    before.input.value = value;
                    before.input.dispatchEvent(new Event('input', {bubbles: true}));
                    before.input.dispatchEvent(new Event('change', {bubbles: true}));
                    changed++;
                });
            }
            if (refreshIssuer && fields && Object.keys(fields).length) {
                var message = changed ? (type === 'cep' ? 'Endereço do estabelecimento atualizado. Confira antes de salvar.' : 'Dados do emitente atualizados. Confira antes de salvar.') : 'Consulta concluída. Confira os dados do emitente antes de salvar.';
                if (editedDuringLookup) { message += ' As alterações feitas durante a consulta foram preservadas.'; }
                if (type === 'cnpj' && !fields.municipal_registration && snapshot.municipal_registration) {
                    message += ' A inscrição municipal não foi retornada; confira-a no cadastro da prefeitura.';
                }
                lookupFeedback(button, message, false);
                return;
            }
            lookupFeedback(button, changed ? 'Dados preenchidos. Confira antes de salvar. Os campos já preenchidos foram preservados.'
                : (fields && Object.keys(fields).length ? 'Dados localizados. Os campos já preenchidos foram preservados.' : (payload.message || 'Dados não localizados. Você pode preencher os campos.')), false);
        } catch (error) {
            lookupFeedback(button, error.name === 'AbortError' ? 'A consulta demorou além do esperado. Tente novamente ou preencha os campos.' : error.message, true);
        } finally { activeRequests.delete(form); busy(form, false); refreshIbptManagers(rootOf(button)); }
    }
    function ibptManagerDate(value) {
        if (typeof value !== 'string') { return '—'; }
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            var day = new Date(value + 'T00:00:00Z');
            return Number.isFinite(day.getTime()) && day.toISOString().slice(0, 10) === value ? value.slice(8) + '/' + value.slice(5, 7) + '/' + value.slice(0, 4) : '—';
        }
        if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value)) { return '—'; }
        var date = new Date(value);
        if (!Number.isFinite(date.getTime())) { return '—'; }
        return new Intl.DateTimeFormat('pt-BR', {timeZone: 'America/Bahia', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false}).format(date);
    }
    function ibptManagerFeedback(panel, message, warning) {
        var area = panel.querySelector('[data-nfse-ibpt-update-feedback]');
        if (!area) { return; }
        area.hidden = false;
        area.className = 'alert mtop15 alert-' + (warning ? 'warning' : 'success');
        area.textContent = message;
    }
    function ibptManagerDirty(panel, state) {
        var root = rootOf(panel);
        var fields = root && root.querySelector('[name="settings[provider][state]"]');
        var municipality = root && root.querySelector('[name="settings[provider][municipality_code]"]');
        var older = panel.querySelector('[data-nfse-ibpt-allow-older-toggle]');
        return !fields || !municipality || !older || fields.value.trim().toUpperCase() !== state.providerState
            || municipality.value.trim() !== state.providerMunicipality || older.checked !== state.allowOlder;
    }
    function ibptImportFile(panel) {
        var input = panel.querySelector('[data-nfse-ibpt-file]');
        var files = input && input.files;
        if (!files || files.length === 0) { return {file: null, message: ''}; }
        if (files.length !== 1) { return {file: null, message: 'Selecione somente um arquivo CSV da tabela IBPT.'}; }
        var file = files[0];
        if (!file || !/\.csv$/i.test(file.name || '')) { return {file: null, message: 'Selecione um arquivo no formato CSV da tabela IBPT.'}; }
        if (!Number.isSafeInteger(file.size) || file.size < 1 || file.size > 8 * 1024 * 1024) {
            return {file: null, message: 'O arquivo deve conter dados e ter no máximo 8 MB.'};
        }
        return {file: file, message: ''};
    }
    function ibptImportKey(panel) {
        var input = panel.querySelector('[data-nfse-ibpt-import-key]');
        var value = input ? input.value.trim() : '';
        return {value: value, valid: value === '' || /^[A-Za-z0-9]{1,32}$/.test(value)};
    }
    function refreshIbptManager(panel) {
        var state = ibptManagerStates.get(panel);
        if (!state) { return; }
        var button = panel.querySelector('[data-nfse-ibpt-refresh]');
        var warning = panel.querySelector('[data-nfse-ibpt-unsaved]');
        var waiting = panel.querySelector('[data-nfse-ibpt-manual-wait]');
        var dirty = ibptManagerDirty(panel, state);
        var root = rootOf(panel), form = root && root.closest('form');
        var loading = state.loading || activeRequests.has(root) || (form && activeRequests.has(form));
        var remaining = state.nextManualAt - Date.now();
        if (state.nextManualAt > 0 && remaining <= 0) { state.canRefresh = true; }
        if (warning) { warning.hidden = !dirty; }
        if (button) { button.disabled = loading || dirty || !state.uf || !state.canRefresh || remaining > 0; }
        var upload = panel.querySelector('[data-nfse-ibpt-import]');
        var fileInput = panel.querySelector('[data-nfse-ibpt-file]');
        var keyInput = panel.querySelector('[data-nfse-ibpt-import-key]');
        var keyMessage = panel.querySelector('[data-nfse-ibpt-key-message]');
        var suppliedKey = ibptImportKey(panel);
        var selection = ibptImportFile(panel);
        var fileMessage = panel.querySelector('[data-nfse-ibpt-file-message]');
        if (fileInput) { fileInput.disabled = loading || dirty || !state.uf; }
        if (keyInput) { keyInput.disabled = loading || dirty || !state.uf; }
        if (upload) { upload.disabled = loading || dirty || !state.uf || !selection.file || !suppliedKey.valid; }
        if (keyMessage) {
            keyMessage.hidden = suppliedKey.valid;
            keyMessage.textContent = suppliedKey.valid ? '' : 'A chave IBPT deve conter até 32 letras e números, sem espaços ou símbolos.';
        }
        if (fileMessage) {
            fileMessage.hidden = !selection.message && state.loading !== 'upload';
            fileMessage.className = 'help-block' + (selection.message ? ' text-danger' : '');
            fileMessage.textContent = selection.message || (state.loading === 'upload' ? 'Enviando e validando a tabela da UF ' + state.uf + '…' : '');
        }
        if (waiting) {
            waiting.hidden = dirty || state.loading === 'upload' || (!state.loading && !!state.uf && state.canRefresh && remaining <= 0);
            waiting.textContent = !state.uf ? 'Confira e salve a UF do emitente na guia Emitente.'
                : state.loading ? 'Consultando a tabela da UF ' + state.uf + '…'
                : remaining > 0 ? 'Nova atualização manual disponível a partir de ' + ibptManagerDate(new Date(state.nextManualAt).toISOString().replace('.000Z', 'Z')) + '.'
                : 'Confira o cadastro do emitente antes de atualizar a tabela.';
        }
        if (remaining > 0 && !state.timer) {
            state.timer = window.setTimeout(function () {
                state.timer = null;
                if (panel.isConnected) { refreshIbptManager(panel); }
            }, Math.min(remaining + 25, 2147483647));
        }
    }
    function refreshIbptManagers(root) {
        if (!root) { return; }
        root.querySelectorAll('[data-nfse-ibpt-manager]').forEach(refreshIbptManager);
    }
    function initializeIbptManagers(root) {
        root.querySelectorAll('[data-nfse-ibpt-manager]').forEach(function (panel) {
            if (ibptManagerStates.has(panel)) { return; }
            var nextManualAt = Date.parse(panel.dataset.nfseIbptNextManualAt || '');
            ibptManagerStates.set(panel, {uf: /^[A-Z]{2}$/.test(panel.dataset.nfseIbptUf || '') ? panel.dataset.nfseIbptUf : '',
                providerState: (panel.dataset.nfseIbptProviderState || '').trim().toUpperCase(),
                providerMunicipality: (panel.dataset.nfseIbptProviderMunicipality || '').trim(),
                allowOlder: panel.dataset.nfseIbptAllowOlder === '1',
                canRefresh: panel.dataset.nfseIbptCanRefresh === '1', nextManualAt: Number.isFinite(nextManualAt) ? nextManualAt : 0,
                loading: false, timer: null, selectedFile: null});
            refreshIbptManager(panel);
        });
    }
    function applyIbptManager(panel, data) {
        var state = ibptManagerStates.get(panel);
        var labels = {current: 'Vigente', expired: 'Vencida', future: 'Vigência futura', missing: 'Ainda não obtida', invalid: 'Indisponível', mixed: 'Revisar tabela'};
        if (!state || !plainObject(data) || data.uf !== state.uf || !Object.prototype.hasOwnProperty.call(labels, data.status)) {
            throw new Error('A tabela retornada não corresponde à UF desta empresa. Reabra as configurações.');
        }
        var dates = ['valid_from', 'valid_to', 'fetched_at', 'last_attempt_at', 'next_auto_check_at'];
        panel.querySelectorAll('[data-nfse-ibpt-table-field]').forEach(function (field) {
            var key = field.dataset.nfseIbptTableField;
            var value = data[key];
            var display = typeof value === 'string' && value !== '' ? value : Number.isSafeInteger(value) && value >= 0 ? value.toLocaleString('pt-BR') : '—';
            if (dates.indexOf(key) !== -1) { display = ibptManagerDate(value); }
            if (key === 'next_auto_check_at' && display === '—') { display = data.auto_refresh_paused === true
                ? 'Suspensa para preservar a tabela anterior selecionada' : 'Na próxima emissão com IBPT, se necessário'; }
            field.textContent = display;
        });
        var badge = panel.querySelector('[data-nfse-ibpt-table-status]');
        var classes = {current: 'success', expired: 'warning', future: 'info', missing: 'default', invalid: 'warning', mixed: 'warning'};
        if (badge) { badge.className = 'label label-' + classes[data.status]; badge.textContent = typeof data.status_label === 'string' ? data.status_label : labels[data.status]; }
        var message = panel.querySelector('[data-nfse-ibpt-table-message]');
        if (message) { message.textContent = typeof data.message === 'string' ? data.message : ''; }
        var policy = panel.querySelector('[data-nfse-ibpt-auto-policy]');
        if (policy) { policy.textContent = typeof data.auto_policy === 'string' ? data.auto_policy : ''; }
        state.canRefresh = data.can_refresh === true;
        var next = typeof data.next_manual_at === 'string' ? Date.parse(data.next_manual_at) : NaN;
        state.nextManualAt = Number.isFinite(next) ? next : 0;
        if (state.timer) { window.clearTimeout(state.timer); state.timer = null; }
        refreshIbptManager(panel);
    }
    async function refreshIbptTable(button) {
        var panel = button.closest('[data-nfse-ibpt-manager]');
        var root = rootOf(button), form = root && root.closest('form');
        var state = panel && ibptManagerStates.get(panel);
        if (!state || !form || activeRequests.has(root) || activeRequests.has(form) || state.loading) { return; }
        refreshIbptManager(panel);
        if (button.disabled) { return; }
        var endpoint = safeUrl(button.dataset.nfseIbptRefreshUrl);
        if (!endpoint) { ibptManagerFeedback(panel, 'Reabra as configurações para atualizar a tabela.', true); return; }
        var expectedUf = state.uf, actionBefore = form.action;
        var body = new FormData(); body.set('expected_uf', expectedUf);
        state.loading = true; activeRequests.add(root); activeRequests.add(form); busy(form, true); refreshIbptManager(panel);
        var feedback = panel.querySelector('[data-nfse-ibpt-update-feedback]');
        if (feedback) { feedback.hidden = true; }
        try {
            var payload = await request(button, endpoint, body);
            if (!panel.isConnected || form.action !== actionBefore || state.uf !== expectedUf || ibptManagerDirty(panel, state)) { return; }
            var data = payload.data && payload.data.ibpt_table;
            applyIbptManager(panel, data);
            var warning = data.status !== 'current' || ['failed', 'throttled'].indexOf(data.refresh_state) !== -1;
            ibptManagerFeedback(panel, typeof data.refresh_message === 'string' && data.refresh_message ? data.refresh_message
                : payload.message || 'Consulta da tabela concluída.', warning);
        } catch (error) {
            if (panel.isConnected && form.action === actionBefore && state.uf === expectedUf && !ibptManagerDirty(panel, state)) {
                var table = error.payload && error.payload.data && error.payload.data.ibpt_table;
                if (table && table.uf === expectedUf) { try { applyIbptManager(panel, table); } catch (_) { /* Preserve the original failure. */ } }
                ibptManagerFeedback(panel, error.name === 'AbortError' ? 'A atualização demorou além do esperado. Tente novamente em instantes.' : error.message, true);
            }
        } finally {
            state.loading = false; activeRequests.delete(root); activeRequests.delete(form); busy(form, false); refreshIbptManager(panel);
        }
    }
    async function importIbptTable(button) {
        var panel = button.closest('[data-nfse-ibpt-manager]');
        var root = rootOf(button), form = root && root.closest('form');
        var state = panel && ibptManagerStates.get(panel);
        if (!state || !form || activeRequests.has(root) || activeRequests.has(form) || state.loading) { return; }
        refreshIbptManager(panel);
        if (button.disabled) { return; }
        var selection = ibptImportFile(panel), fileInput = panel.querySelector('[data-nfse-ibpt-file]');
        var keyInput = panel.querySelector('[data-nfse-ibpt-import-key]'), suppliedKey = ibptImportKey(panel);
        if (!selection.file || !suppliedKey.valid) { return; }
        var endpoint = safeUrl(button.dataset.nfseIbptImportUrl);
        if (!endpoint) { ibptManagerFeedback(panel, 'Reabra as configurações para enviar a tabela.', true); return; }
        var expectedUf = state.uf, actionBefore = form.action, selectedFile = selection.file, selectedKey = suppliedKey.value;
        var body = new FormData(); body.set('expected_uf', expectedUf); body.set('ibpt_file', selectedFile, selectedFile.name);
        if (selectedKey !== '') { body.set('ibpt_key', selectedKey); }
        state.loading = 'upload'; activeRequests.add(root); activeRequests.add(form); busy(form, true); refreshIbptManager(panel);
        var feedback = panel.querySelector('[data-nfse-ibpt-update-feedback]');
        if (feedback) { feedback.hidden = true; }
        try {
            var payload = await request(button, endpoint, body);
            if (!panel.isConnected || form.action !== actionBefore || state.uf !== expectedUf || ibptManagerDirty(panel, state)
                || ibptImportFile(panel).file !== selectedFile || ibptImportKey(panel).value !== selectedKey) { return; }
            var data = payload.data && payload.data.ibpt_table;
            applyIbptManager(panel, data);
            var warning = data.status !== 'current' || ['failed', 'throttled'].indexOf(data.refresh_state) !== -1;
            if (!warning) { fileInput.value = ''; if (keyInput) { keyInput.value = ''; } state.selectedFile = null; }
            ibptManagerFeedback(panel, typeof data.refresh_message === 'string' && data.refresh_message ? data.refresh_message
                : payload.message || 'Tabela importada e disponível localmente.', warning);
        } catch (error) {
            if (panel.isConnected && form.action === actionBefore && state.uf === expectedUf && !ibptManagerDirty(panel, state)
                && ibptImportFile(panel).file === selectedFile && ibptImportKey(panel).value === selectedKey) {
                ibptManagerFeedback(panel, error.name === 'AbortError'
                    ? 'O envio demorou além do esperado. Reabra esta guia para conferir a tabela antes de tentar novamente.' : error.message, true);
            }
        } finally {
            state.loading = false; activeRequests.delete(root); activeRequests.delete(form); busy(form, false); refreshIbptManager(panel);
        }
    }
    async function downloadAccounting(form) {
        if (activeRequests.has(form) || !validForm(form)) { return; }
        activeRequests.add(form); busy(form, true);
        try {
            await queueRequest(async function () {
                var endpoint = safeUrl(form.action);
                if (!endpoint) { throw new Error('Endereço do pacote inválido. Reabra a página.'); }
                var body = new FormData(form), token = csrf(form);
                if (token) { body.set(token.name, token.hash); }
                var controller = new AbortController();
                var timer = window.setTimeout(function () { controller.abort(); }, 120000);
                try {
                    var response = await fetch(endpoint, {method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal});
                    var csrfName = response.headers.get('X-NFSe-CSRF-Name'), csrfHash = response.headers.get('X-NFSe-CSRF-Hash');
                    if (csrfName && csrfHash) { updateCsrf({name: csrfName, hash: csrfHash}); }
                    if (!response.ok || (response.headers.get('Content-Type') || '').indexOf('application/zip') === -1) {
                        var payload;
                        try { payload = await response.json(); } catch (parseError) {
                            if (parseError.name === 'AbortError') { throw parseError; }
                            throw new Error('Não foi possível baixar o pacote. Atualize a página e confira novamente os documentos.');
                        }
                        updateCsrf(payload.csrf);
                        var problem = new Error(payload.message || 'O pacote não foi gerado.'); problem.payload = payload; throw problem;
                    }
                    var nameMatch = /filename="([A-Za-z0-9._-]+\.zip)"/.exec(response.headers.get('Content-Disposition') || '');
                    var blob = await response.blob(), download = document.createElement('a');
                    var objectUrl = URL.createObjectURL(blob);
                    download.href = objectUrl; download.download = nameMatch ? nameMatch[1] : 'NFSe_pacote_mensal.zip';
                    document.body.appendChild(download); download.click(); download.remove();
                    window.setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 1000);
                    notify(form, 'success', 'Pacote gerado. O download foi iniciado.');
                } finally { window.clearTimeout(timer); }
            });
        } catch (error) {
            if (error.name === 'AbortError') { error = new Error('O download excedeu o tempo de espera. Reabra a página e tente novamente somente a ação Baixar pacote.'); }
            displayFailure(form, error, false);
        }
        finally { busy(form, false); activeRequests.delete(form); }
    }
    async function submitForm(form) {
        if (activeRequests.has(form) || (form.dataset.nfseForm !== 'document' && !validForm(form))) { return; }
        var confirmation = form.dataset.nfseConfirm || form.dataset.confirm;
        if (confirmation && !window.confirm(confirmation)) { return; }
        if (form.id === 'nfse-recipient-form') { rememberClientTab(form.closest('.nfse-client-tab'), '#nfse-client-recipients'); }
        activeRequests.add(form);
        var body = new FormData(form);
        busy(form, true);
        try {
            var payload = await request(form, form.action, body);
            notify(form, payload.data && payload.data.run && payload.data.run.last_error ? 'warning' : 'success', payload.message || 'Dados salvos.');
            form.querySelectorAll('input[type="password"], input[type="file"]').forEach(function (input) { input.value = ''; });
            await navigateResponse(form, payload);
        } catch (error) { displayFailure(form, error, false); }
        finally { busy(form, false); activeRequests.delete(form); }
    }
    async function action(button) {
        if (activeRequests.has(button)) { return; }
        if (button.dataset.nfseConfirm && !window.confirm(button.dataset.nfseConfirm)) { return; }
        if ((button.dataset.nfseUrl || '').indexOf('/delete_recipient/') !== -1) { rememberClientTab(button.closest('.nfse-client-tab'), '#nfse-client-recipients'); }
        var preparation = button.hasAttribute('data-nfse-use-recipient') && button.closest('.nfse-invoice-panel');
        var recipientChoice = preparation && preparation.querySelector('[data-nfse-prepare-recipient]');
        var preparationBody;
        if (recipientChoice) {
            if (recipientChoice.disabled || !validRecipientSelection(recipientChoice.value)) { return; }
            preparationBody = new FormData();
            preparationBody.set('recipient_selection', recipientChoice.value);
        }
        activeRequests.add(button);
        busy(button, true);
        var emitting = button.dataset.nfseEmission === '1';
        if (emitting) { notify(button, 'info', 'Preparando, validando e transmitindo a NFS-e. Aguarde o retorno do ambiente nacional.'); }
        try {
            var payload = await request(button, button.dataset.nfseUrl, preparationBody);
            notify(button, 'success', payload.message || 'Operação concluída.');
            if (button.dataset.nfseResult === 'municipality') {
                var display = document.getElementById('nfse-municipality-result');
                if (display) { display.textContent = JSON.stringify(payload.data, null, 2); display.hidden = false; }
            }
            await navigateResponse(button, payload);
        } catch (error) {
            var feedbackNode = button;
            var panel = button.closest('.nfse-invoice-panel[data-nfse-context-url]');
            if (emitting && panel) {
                try { feedbackNode = await refreshInvoicePanel(panel) || button; } catch (_) { /* Preserve the transmission error and its review link. */ }
            }
            displayFailure(feedbackNode, error, emitting);
            if (emitting && button.dataset.nfseUrl.indexOf('/emit_invoice/') !== -1) {
                var area = feedbackOf(feedbackNode);
                var reviewUrl = error.payload && error.payload.data && safeUrl(error.payload.data.url);
                var review = document.createElement(reviewUrl ? 'a' : 'button');
                review.className = 'btn btn-default btn-sm';
                if (reviewUrl) { review.href = reviewUrl; review.textContent = 'Revisar rascunho'; }
                else {
                    review.type = 'button'; review.classList.add('nfse-action'); review.textContent = 'Abrir rascunho e conferir situação';
                    review.dataset.nfseUrl = button.dataset.nfseUrl.replace('/emit_invoice/', '/prepare/'); review.dataset.nfseNavigate = '1';
                    if (button.hasAttribute('data-nfse-use-recipient')) { review.dataset.nfseUseRecipient = '1'; }
                }
                if (area) { area.appendChild(review); }
            }
        }
        finally { busy(button, false); activeRequests.delete(button); }
    }
    async function documentAction(button) {
        var form = document.getElementById('nfse-document-form');
        if (!form || activeRequests.has(form) || !validForm(form)) { return; }
        var transmitting = button.dataset.nfseDocumentAction === 'transmit';
        var body = new FormData(form);
        activeRequests.add(form);
        busy(form, true);
        notify(button, 'info', transmitting ? 'Validando e transmitindo a NFS-e. Aguarde o retorno do ambiente nacional.' : 'Validando os dados da emissão.');
        var posted = false;
        try {
            if (form.dataset.canSave === '1') { await request(form, form.action, body); }
            posted = transmitting;
            var payload = await request(button, button.dataset.nfseUrl);
            notify(button, 'success', payload.message || (transmitting ? 'Transmissão concluída.' : 'Validação concluída.'));
            if (transmitting) { window.setTimeout(function () { window.location.reload(); }, 600); }
        } catch (error) {
            displayFailure(button, error, posted);
            var doc = error.payload && error.payload.data && error.payload.data.document;
            if (posted && (!error.payload || (doc && ['processing', 'uncertain', 'authorized', 'cancelled'].indexOf(doc.status) !== -1) || error.httpStatus >= 500)) {
                window.setTimeout(function () { window.location.reload(); }, 2400);
            }
        } finally { busy(form, false); activeRequests.delete(form); }
    }
    function sourceVisibility(root) {
        var select = root.querySelector('[data-nfse-recipient-source]');
        if (select) { root.querySelectorAll('[data-nfse-source-show]').forEach(function (section) { section.hidden = section.dataset.nfseSourceShow !== select.value; }); }
        root.querySelectorAll('[data-nfse-tax]').forEach(function (group) {
            var mode = group.querySelector('[data-nfse-tax-mode]');
            group.querySelectorAll('[data-nfse-tax-show]').forEach(function (section) { section.hidden = !mode || section.dataset.nfseTaxShow.split(/\s+/).indexOf(mode.value) === -1; });
        });
        root.querySelectorAll('[data-nfse-emission-profile]').forEach(function (profile) {
            var mode = profile.querySelector('[data-nfse-profile-field="total_tax_mode"] select');
            if (!mode) { return; }
            ['total_federal', 'total_state', 'total_municipal', 'simple_rate'].forEach(function (key) {
                var field = profile.querySelector('[data-nfse-profile-field="' + key + '"]');
                if (!field) { return; }
                field.hidden = key === 'simple_rate' ? ['simple', 'ibpt'].indexOf(mode.value) === -1 : mode.value !== 'amount';
                field.querySelectorAll('[required], [data-nfse-tax-required]').forEach(function (control) {
                    control.dataset.nfseTaxRequired = '1';
                    control.required = !field.hidden;
                });
            });
        });
        root.querySelectorAll('[data-nfse-ibs]').forEach(function (group) {
            var toggle = group.querySelector('[data-nfse-ibs-toggle]');
            var fields = group.querySelector('[data-nfse-ibs-fields]');
            if (fields) { fields.hidden = !toggle || (toggle.type === 'checkbox' ? !toggle.checked : toggle.value !== '1'); }
        });
        var environment = root.querySelector('[data-nfse-environment]');
        if (environment) { root.querySelectorAll('[data-nfse-production-note]').forEach(function (note) { note.hidden = environment.value !== '1'; }); }
        var localCertificate = root.querySelector('[data-nfse-local-certificate]');
        var localFields = root.querySelector('[data-nfse-local-certificate-fields]');
        if (localFields) {
            localFields.hidden = false;
            localFields.disabled = false;
            localFields.querySelectorAll('input[type="password"]').forEach(function (input) { input.required = false; });
        }
        if (localCertificate) { localCertificate.removeAttribute('aria-expanded'); }
        refreshTaxSummaries(root);
    }
    // Exact decimal arithmetic is confined to the local preview. Official values
    // and IBPT percentages are supplied by the server and are never overwritten.
    function taxScaled(value, scale) {
        if (typeof window.BigInt !== 'function') { return null; }
        var text = String(value || '').trim();
        if (text.indexOf(',') !== -1) {
            if (text.indexOf('.') !== -1) {
                if (!/^[0-9]{1,3}(\.[0-9]{3})*,[0-9]+$/.test(text)) { return null; }
                text = text.replace(/\./g, '');
            }
            text = text.replace(',', '.');
        }
        var match = text.match(/^([0-9]{1,12})(?:\.([0-9]{1,6}))?$/);
        if (!match) { return null; }
        var fraction = match[2] || '';
        if (fraction.length > scale && /[1-9]/.test(fraction.slice(scale))) { return null; }
        return window.BigInt(match[1] + fraction.slice(0, scale).padEnd(scale, '0'));
    }
    function taxDecimal(value, scale, localized) {
        var text = value.toString().padStart(scale + 1, '0');
        var whole = text.slice(0, text.length - scale), fraction = text.slice(-scale);
        if (localized) { whole = whole.replace(/\B(?=([0-9]{3})+(?![0-9]))/g, '.'); }
        return whole + (localized ? ',' : '.') + fraction;
    }
    function taxMoney(value) {
        var amount = taxScaled(value, 2);
        return amount === null ? '—' : 'R$ ' + taxDecimal(amount, 2, true);
    }
    function taxField(form, key) {
        var group = key === 'nbs' ? 'service' : 'values';
        var input = form.elements.namedItem('draft[' + group + '][' + key + ']');
        return input && typeof input.value === 'string' ? input.value : '';
    }
    function taxInputKey(key, value) {
        if (['service_amount', 'unconditional_discount', 'simple_rate'].indexOf(key) === -1) { return String(value || '').trim(); }
        if (key === 'simple_rate' && String(value || '').trim() === '') { return ''; }
        var minor = taxScaled(value, 2);
        return minor === null ? 'invalid:' + String(value || '') : minor.toString();
    }
    function matchesTaxInputs(form, saved) {
        return plainObject(saved) && ['nbs', 'service_amount', 'unconditional_discount', 'simple_rate', 'total_tax_mode'].every(function (key) {
            return ownValue(saved, key) && taxInputKey(key, saved[key]) === taxInputKey(key, taxField(form, key));
        });
    }
    function setTaxText(scope, selector, text) {
        var field = scope.querySelector(selector);
        if (field) { field.textContent = text; }
    }
    function refreshIssSummary(form) {
        var panel = form.querySelector('[data-nfse-iss-summary]');
        if (!panel || panel.dataset.nfseIssOfficial === '1') { return; }
        var service = taxScaled(taxField(form, 'service_amount'), 2);
        var discount = taxScaled(taxField(form, 'unconditional_discount'), 2);
        var rate = taxScaled(taxField(form, 'iss_rate'), 2);
        var taxable = taxField(form, 'iss_taxation') === '1';
        var base = service !== null && discount !== null && service >= discount ? service - discount : null;
        var applicable = taxable && base !== null && rate !== null && rate <= window.BigInt('999');
        setTaxText(panel, '[data-nfse-iss-base]', base === null ? '—' : 'R$ ' + taxDecimal(base, 2, true));
        setTaxText(panel, '[data-nfse-iss-rate]', rate === null ? '—' : taxDecimal(rate * window.BigInt('100'), 4, true) + '%');
        setTaxText(panel, '[data-nfse-iss-amount]', !taxable ? 'Não se aplica' : (applicable ? 'R$ ' + taxDecimal((base * rate + window.BigInt('5000')) / window.BigInt('10000'), 2, true) : '—'));
        setTaxText(panel, '[data-nfse-iss-retention]', {'1': 'Não retido', '2': 'Retido pelo tomador', '3': 'Retido pelo intermediário'}[taxField(form, 'retention')] || '—');
        setTaxText(panel, '[data-nfse-iss-note]', !taxable
            ? 'Esta operação não utiliza o cálculo local de ISSQN. Confira o enquadramento na guia Tributação.'
            : (!applicable ? 'Informe valores válidos e a alíquota de ISSQN na guia Tributação para calcular a prévia.'
                : 'A prévia usa a alíquota informada, sobre serviços menos desconto incondicionado. O valor autorizado depende do regime e da apuração municipal.'));
    }
    function ibptState(panel) {
        var state = ibptDisplayStates.get(panel);
        if (!state) {
            var inputs;
            try { inputs = JSON.parse(panel.dataset.nfseIbptInputs || '{}'); } catch (_) { inputs = {}; }
            var calculated = panel.querySelector('[data-nfse-ibpt-calculated]');
            var pending = panel.querySelector('[data-nfse-ibpt-pending]');
            state = {inputs: inputs, available: !!calculated && !calculated.hidden, message: pending ? pending.textContent : ''};
            ibptDisplayStates.set(panel, state);
        }
        return state;
    }
    function refreshIbptSummary(form) {
        var panel = form.querySelector('[data-nfse-ibpt-summary]');
        if (!panel) { return; }
        panel.hidden = taxField(form, 'total_tax_mode') !== 'ibpt';
        if (panel.hidden) { return; }
        var state = ibptState(panel);
        var changed = !matchesTaxInputs(form, state.inputs);
        var pending = panel.querySelector('[data-nfse-ibpt-pending]');
        var calculated = panel.querySelector('[data-nfse-ibpt-calculated]');
        if (calculated) { calculated.hidden = changed || !state.available; }
        if (pending) {
            pending.hidden = !changed && state.available;
            pending.textContent = changed ? 'Os dados da estimativa foram alterados. Salve o rascunho para atualizar os tributos aproximados.'
                : (state.message || 'Informe o NBS do serviço e salve o rascunho para obter a estimativa.');
        }
    }
    function refreshTaxSummaries(root) {
        var form = root.matches && root.matches('#nfse-document-form') ? root : root.querySelector('#nfse-document-form');
        if (!form) { return; }
        refreshIssSummary(form);
        refreshIbptSummary(form);
    }
    function applyTaxSummary(form, summary) {
        var panel = form.querySelector('[data-nfse-ibpt-summary]');
        if (panel && plainObject(summary.inputs)) {
            var estimate = plainObject(summary.ibpt) ? summary.ibpt : null;
            var snapshot = estimate && plainObject(estimate.snapshot) ? estimate.snapshot : null;
            var available = !!snapshot && !summary.ibpt_error && ['base', 'total_federal', 'total_state', 'total_municipal', 'total', 'text'].every(function (key) { return typeof estimate[key] === 'string'; });
            ibptDisplayStates.set(panel, {inputs: summary.inputs, available: available, message: typeof summary.ibpt_error === 'string' ? summary.ibpt_error : ''});
            if (available) {
                var date = function (value) { return typeof value === 'string' && /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(value) ? value.split('-').reverse().join('/') : '—'; };
                var fields = {source: 'Fonte: IBPT [' + String(snapshot.chave || '') + ']', uf: String(snapshot.uf || ''), nbs: String(snapshot.nbs || ''),
                    validity: date(snapshot.valid_from) + ' a ' + date(snapshot.valid_to), version: String(snapshot.versao || ''), base: taxMoney(estimate.base), total: taxMoney(estimate.total), text: estimate.text};
                [['federal', 'nacionalfederal'], ['state', 'estadual'], ['municipal', 'municipal']].forEach(function (entry) {
                    fields[entry[0] + '_rate'] = String(snapshot[entry[1]] || '').replace('.', ',') + '%';
                    fields[entry[0] + '_amount'] = taxMoney(estimate['total_' + entry[0]]);
                });
                Object.keys(fields).forEach(function (key) { setTaxText(panel, '[data-nfse-ibpt-field="' + key + '"]', fields[key]); });
            }
        }
        refreshTaxSummaries(form);
    }
    function plainObject(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
    function ownValue(object, key) { return plainObject(object) && Object.prototype.hasOwnProperty.call(object, key); }
    function setNamedValue(scope, name, value) {
        var controls = scope.querySelectorAll('[name="' + name + '"]');
        var hasToggle = Array.prototype.some.call(controls, function (control) { return control.type === 'checkbox' || control.type === 'radio'; });
        controls.forEach(function (control) {
            if (control.type === 'checkbox' || control.type === 'radio') { control.checked = control.value === value; }
            else if (control.type === 'hidden' && hasToggle) { /* Keep the unchecked value of the native checkbox pair. */ }
            else if (control.matches('[data-nfse-service-catalog],[data-nfse-nbs-catalog]')) { setServiceCode(control, value); }
            else { control.value = value; }
        });
    }
    function profileFieldInfo(field) {
        var profile = field.closest('[data-nfse-emission-profile]');
        if (!profile) { return null; }
        var group = field.dataset.nfseProfileGroup, key = field.dataset.nfseProfileField, prefix = profile.dataset.nfseProfilePrefix;
        if (['profile', 'recipient'].indexOf(prefix) === -1 || !ownValue(emissionProfileFields, group) || emissionProfileFields[group].indexOf(key) === -1) { return null; }
        var inherit = field.querySelector('[data-nfse-profile-inherit]'), values = field.querySelector('[data-nfse-profile-value]');
        var control = values && values.querySelector('select[name],textarea[name],input[name]:not([type="hidden"])');
        if (!inherit || !values || !control) { return null; }
        var defaults;
        try { defaults = JSON.parse(profile.dataset.nfseProfileDefaults || '{}'); } catch (_) { defaults = {}; }
        var baseline = ownValue(defaults, group) && ownValue(defaults[group], key) && typeof defaults[group][key] === 'string' ? defaults[group][key] : '';
        return {profile: profile, group: group, key: key, inherit: inherit, values: values, control: control, baseline: baseline,
            name: prefix + '[emission_profile][' + group + '][' + key + ']', readOnly: profile.dataset.nfseProfileReadonly === '1'};
    }
    function profileControlValue(control) { return control.type === 'checkbox' ? (control.checked ? control.value : '0') : control.value; }
    function renderProfileField(field) {
        var info = profileFieldInfo(field);
        if (!info) { return; }
        var state = emissionProfileStates.get(field);
        if (!state) { state = {inherited: info.inherit.checked, override: info.inherit.checked ? undefined : profileControlValue(info.control)}; }
        if (!state.inherited && info.inherit.checked) { state.override = profileControlValue(info.control); }
        if (info.inherit.checked) { setNamedValue(info.values, info.name, info.baseline); }
        else if (state.inherited) { setNamedValue(info.values, info.name, state.override === undefined ? info.baseline : state.override); }
        state.inherited = info.inherit.checked;
        emissionProfileStates.set(field, state);
        var disabled = info.readOnly || info.inherit.checked;
        info.values.disabled = disabled;
        info.values.querySelectorAll('input[name],select[name],textarea[name]').forEach(function (control) { control.disabled = disabled; });
        if (info.readOnly) { info.inherit.disabled = true; }
        var label = field.querySelector('[data-nfse-profile-default-label]');
        if (label) {
            var text = info.baseline;
            if (info.control.tagName === 'SELECT') {
                var option = Array.prototype.find.call(info.control.options, function (item) { return item.value === info.baseline; });
                if (option) { text = option.textContent; }
            }
            label.textContent = text ? (text.length > 180 ? text.slice(0, 177) + '…' : text) : 'Não informado';
            label.title = text;
        }
    }
    function initializeEmissionProfiles(scope) {
        scope.querySelectorAll('[data-nfse-profile-field][data-nfse-profile-group]').forEach(renderProfileField);
    }
    function loadEmissionProfile(scope, profile) {
        scope.querySelectorAll('[data-nfse-profile-field][data-nfse-profile-group]').forEach(function (field) {
            var info = profileFieldInfo(field);
            if (!info) { return; }
            var present = ownValue(profile, info.group) && ownValue(profile[info.group], info.key) && typeof profile[info.group][info.key] === 'string';
            info.inherit.checked = !present;
            emissionProfileStates.set(field, {inherited: !present, override: present ? profile[info.group][info.key] : undefined});
            setNamedValue(info.values, info.name, present ? profile[info.group][info.key] : info.baseline);
            renderProfileField(field);
        });
    }
    function editRecipient(button, isNew, isClone) {
        var form = document.getElementById('nfse-recipient-form');
        if (!form || !window.jQuery) { return; }
        var data = {};
        if (!isNew) {
            var source = isClone ? (button.closest('tr') && button.closest('tr').querySelector('[data-nfse-edit-recipient]')) : button;
            try { data = source && JSON.parse(source.dataset.nfseEditRecipient || '{}'); }
            catch (_) { data = null; }
            if (!plainObject(data)) { notify(button, 'error', 'Não foi possível abrir este cadastro. Recarregue a página.'); return; }
        }
        form.reset();
        form.querySelectorAll('[data-nfse-lookup-feedback]').forEach(function (area) { area.hidden = true; area.textContent = ''; });
        form.action = form.dataset.baseAction + (!isClone && data.id ? '/' + encodeURIComponent(data.id) : '');
        ['label', 'name', 'tax_id', 'email', 'phone', 'municipality_code', 'zip', 'street', 'number', 'complement', 'district', 'service_code', 'description'].forEach(function (key) {
            var input = form.elements.namedItem('recipient[' + key + ']');
            if (input && input.hasAttribute('data-nfse-service-catalog')) { setServiceCode(input, data[key] || ''); }
            else if (input) { input.value = data[key] || ''; }
        });
        loadEmissionProfile(form, data.emission_profile);
        sourceVisibility(rootOf(form) || form);
        refreshServiceCatalogs(form);
        var heading = document.getElementById('nfse-recipient-modal-title');
        if (heading) { heading.textContent = isClone ? 'Clonar tomador' : (isNew ? 'Novo tomador' : 'Editar tomador'); }
        var area = form.querySelector('.nfse-feedback');
        if (area) { area.hidden = true; }
        if ((isNew || isClone) && window.jQuery.fn.tab) {
            var modal = form.closest('.modal');
            var firstTab = modal && modal.querySelector('.nav-tabs > li:first-child > a[data-toggle="tab"]');
            if (firstTab) { window.jQuery(firstTab).tab('show'); }
        }
        window.jQuery('#nfse-recipient-modal').modal({show: true, keyboard: true});
    }
    function changeDraftRecipient(select) {
        var form = select.closest('form');
        var element = form && form.querySelector('[data-nfse-recipients]');
        if (!element || !select.value) { return; }
        var map;
        try { map = JSON.parse(element.dataset.nfseRecipients || '{}'); } catch (_) { return; }
        var data = map[select.value];
        if (!data) { return; }
        var defaults = data.emission_defaults;
        if (defaults !== undefined && !Object.keys(emissionProfileFields).every(function (group) {
            return ownValue(defaults, group) && emissionProfileFields[group].every(function (key) { return ownValue(defaults[group], key) && typeof defaults[group][key] === 'string'; });
        })) {
            select.value = '';
            notify(select, 'error', 'Os padrões deste tomador estão incompletos. Reabra o rascunho antes de trocar o tomador.');
            return;
        }
        ['name', 'tax_id', 'email', 'phone', 'municipality_code', 'zip', 'street', 'number', 'complement', 'district'].forEach(function (key) {
            var field = form.elements.namedItem('draft[recipient][' + key + ']');
            if (field) { field.value = data[key] || ''; }
        });
        if (defaults !== undefined) {
            Object.keys(emissionProfileFields).forEach(function (group) {
                var draftGroup = group === 'tax' ? 'values' : group;
                emissionProfileFields[group].forEach(function (key) { setNamedValue(form, 'draft[' + draftGroup + '][' + key + ']', defaults[group][key]); });
            });
        } else if (plainObject(data.service_defaults)) {
            ['national_code', 'description'].forEach(function (key) {
                var field = form.elements.namedItem('draft[service][' + key + ']');
                if (field && typeof data.service_defaults[key] === 'string') {
                    if (field.hasAttribute('data-nfse-service-catalog')) { setServiceCode(field, data.service_defaults[key]); }
                    else { field.value = data.service_defaults[key]; }
                }
            });
        }
        sourceVisibility(rootOf(select) || form);
        refreshServiceCatalogs(form);
    }
    function setServiceCode(select, value) {
        select.querySelectorAll('[data-nfse-legacy-service]').forEach(function (option) { if (option.value !== value) { option.remove(); } });
        var found = Array.prototype.some.call(select.options, function (option) { return option.value === value; });
        if (value && !found) {
            var historical = document.createElement('option');
            historical.value = value;
            historical.textContent = value + (select.hasAttribute('data-nfse-nbs-catalog')
                ? ' — NBS salvo anteriormente, não localizado nesta tabela IBPT'
                : ' — Código salvo anteriormente, não localizado no catálogo');
            historical.dataset.nfseLegacyService = '1';
            select.appendChild(historical);
        }
        select.value = value;
    }
    function refreshServiceCatalogs(scope) {
        if (!window.jQuery || !window.jQuery.fn.selectpicker) { return; }
        scope.querySelectorAll('[data-nfse-service-catalog],[data-nfse-nbs-catalog]').forEach(function (select) {
            var picker = window.jQuery(select);
            if (picker.data('selectpicker')) { picker.selectpicker('refresh'); }
            else { picker.selectpicker({liveSearch: true, liveSearchNormalize: true, width: '100%', size: 8}); }
        });
    }
    function nativeSettingsRoot(form) {
        if (!form || form.tagName !== 'FORM') { return null; }
        var roots = form.querySelectorAll('[data-nfse-settings]');
        for (var i = 0; i < roots.length; i++) {
            var root = roots[i];
            var visible = root.closest('form') === form;
            for (var current = root; visible && current && current !== form; current = current.parentElement) {
                if (current.hidden || (current.classList.contains('tab-pane') && !current.classList.contains('active')) || window.getComputedStyle(current).display === 'none') { visible = false; }
            }
            if (visible) { return root; }
        }
        return null;
    }
    function scopedFormData(root) {
        var data = new FormData();
        root.querySelectorAll('input[name],select[name],textarea[name]').forEach(function (field) {
            if (field.matches(':disabled') || ['button', 'submit', 'reset', 'image'].indexOf(field.type) !== -1) { return; }
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) { return; }
            if (field.type === 'file') { Array.prototype.forEach.call(field.files || [], function (file) { data.append(field.name, file, file.name); }); }
            else if (field.tagName === 'SELECT' && field.multiple) { Array.prototype.forEach.call(field.options, function (option) { if (option.selected && !option.disabled) { data.append(field.name, option.value); } }); }
            else { data.append(field.name, field.value); }
        });
        return data;
    }
    function validScope(root) {
        var fields = root.querySelectorAll('input,select,textarea');
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].matches(':disabled') && fields[i].willValidate && typeof fields[i].reportValidity === 'function' && !fields[i].reportValidity()) { return false; }
        }
        return true;
    }
    async function submitSettings(root) {
        if (activeRequests.has(root) || !validScope(root)) { return; }
        activeRequests.add(root);
        var body = scopedFormData(root);
        busy(root, true);
        try {
            var payload = await request(root, root.dataset.nfseUrl, body);
            notify(root, 'success', payload.message || 'Configurações salvas.');
            root.querySelectorAll('input[type="password"],input[type="file"]').forEach(function (input) { input.value = ''; });
            await navigateResponse(root, payload);
        } catch (error) { displayFailure(root, error, false); }
        finally { busy(root, false); activeRequests.delete(root); }
    }
    function bridgeSettingsValidator(root) {
        var form = root.closest('form');
        var validator = form && window.jQuery && window.jQuery(form).data('validator');
        if (!validator || !validator.settings) { return; }
        var previousBridge = bridgedValidators.get(validator);
        if (previousBridge && validator.settings.submitHandler === previousBridge.wrapped) { return; }
        var original = validator.settings.submitHandler;
        var wrapped = function (submittedForm, event) {
            var settings = nativeSettingsRoot(submittedForm || form);
            if (!settings) { return typeof original === 'function' ? original.apply(this, arguments) : true; }
            if (event) { event.preventDefault(); event.stopImmediatePropagation(); }
            submitSettings(settings);
            return false;
        };
        bridgedValidators.set(validator, {original: original, wrapped: wrapped});
        validator.settings.submitHandler = wrapped;
    }
    function addReviewLink(node, url, label) {
        var area = feedbackOf(node);
        if (!area || !url) { return; }
        var link = document.createElement('a');
        link.href = url; link.className = 'btn btn-default btn-sm'; link.textContent = label;
        area.appendChild(link);
    }
    function invoiceContext(url) {
        return queueRequest(async function () {
            var endpoint = safeUrl(url);
            if (!endpoint) { throw new Error('Endereço da fatura inválido. Reabra a página.'); }
            var controller = new AbortController();
            var timer = window.setTimeout(function () { controller.abort(); }, 30000);
            try {
                var response = await fetch(endpoint, {method: 'GET', credentials: 'same-origin', redirect: 'error', cache: 'no-store', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal});
                var payload = await response.json();
                updateCsrf(payload.csrf);
                if (!response.ok || !payload.ok || !payload.data || typeof payload.data.html !== 'string') { throw new Error(payload.message || 'Não foi possível carregar os dados da NFS-e.'); }
                var parsed = new DOMParser().parseFromString(payload.data.html, 'text/html');
                var panel = parsed.querySelector('.nfse-root.nfse-invoice-panel[data-nfse-invoice-id]');
                if (!panel || parsed.querySelector('script,iframe,object,embed,base')) { throw new Error('O painel da NFS-e não pôde ser exibido. Reabra a fatura.'); }
                panel.querySelectorAll('*').forEach(function (element) {
                    Array.prototype.forEach.call(element.attributes, function (attribute) { if (/^on/i.test(attribute.name)) { element.removeAttribute(attribute.name); } });
                });
                var imported = document.importNode(panel, true);
                if (payload.csrf && typeof payload.csrf.name === 'string' && typeof payload.csrf.hash === 'string') { imported.dataset.csrfName = payload.csrf.name; imported.dataset.csrfHash = payload.csrf.hash; }
                return imported;
            } finally { window.clearTimeout(timer); }
        });
    }
    async function refreshInvoicePanel(panel, message) {
        var replacement = await invoiceContext(panel.dataset.nfseContextUrl);
        if (replacement.dataset.nfseInvoiceId !== panel.dataset.nfseInvoiceId) { throw new Error('A fatura foi alterada. Reabra seu painel.'); }
        if (!panel.isConnected) { return null; }
        panel.replaceWith(replacement);
        initializeRoot(replacement);
        if (message) { notify(replacement, 'success', message); }
        return replacement;
    }
    function invoiceModal() {
        var modal = document.getElementById('nfse-invoice-modal');
        if (modal) { return modal; }
        modal = document.createElement('div');
        modal.id = 'nfse-invoice-modal'; modal.className = 'modal fade nfse-root'; modal.tabIndex = -1;
        modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-labelledby', 'nfse-invoice-modal-title');
        var dialog = document.createElement('div'); dialog.className = 'modal-dialog modal-lg'; dialog.setAttribute('role', 'document');
        var content = document.createElement('div'); content.className = 'modal-content';
        var header = document.createElement('div'); header.className = 'modal-header';
        var close = document.createElement('button'); close.type = 'button'; close.className = 'close'; close.dataset.dismiss = 'modal'; close.setAttribute('aria-label', 'Fechar');
        var symbol = document.createElement('span'); symbol.setAttribute('aria-hidden', 'true'); symbol.textContent = '×'; close.appendChild(symbol);
        var title = document.createElement('h4'); title.id = 'nfse-invoice-modal-title'; title.className = 'modal-title'; title.textContent = 'NFS-e da fatura';
        header.appendChild(close); header.appendChild(title);
        var body = document.createElement('div'); body.className = 'modal-body';
        var feedback = document.createElement('div'); feedback.className = 'nfse-feedback'; feedback.hidden = true; feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite');
        var host = document.createElement('div'); host.dataset.nfseContextHost = '1';
        body.appendChild(feedback); body.appendChild(host); content.appendChild(header); content.appendChild(body); dialog.appendChild(content); modal.appendChild(dialog); document.body.appendChild(modal);
        if (window.jQuery) {
            window.jQuery(modal).on('hidden.bs.modal', function () { delete modal.dataset.requestedInvoice; host.textContent = ''; feedback.hidden = true; });
        }
        return modal;
    }
    async function openInvoiceContext(button) {
        var id = button.dataset.nfseOpenInvoice;
        if (!/^[1-9][0-9]*$/.test(id || '') || activeRequests.has(button)) { return; }
        var existing = document.getElementById('nfse-invoice-panel-' + id);
        if (existing) {
            var parentModal = existing.closest('.modal');
            if (parentModal && window.jQuery) { window.jQuery(parentModal).modal({show: true, keyboard: true}); }
            existing.scrollIntoView({behavior: 'smooth', block: 'center'});
            return;
        }
        if (!window.jQuery) { return; }
        activeRequests.add(button);
        var modal = invoiceModal();
        modal.dataset.requestedInvoice = id;
        var host = modal.querySelector('[data-nfse-context-host]');
        host.textContent = '';
        notify(modal, 'info', 'Carregando os dados da NFS-e desta fatura.');
        window.jQuery(modal).modal({show: true, keyboard: true});
        try {
            var panel = await invoiceContext(button.dataset.nfseContextUrl);
            if (panel.dataset.nfseInvoiceId !== id) { throw new Error('A fatura retornada não corresponde à seleção. Reabra a página.'); }
            if (modal.dataset.requestedInvoice !== id) { return; }
            modal.querySelector('.nfse-feedback').hidden = true;
            host.replaceChildren(panel);
            initializeRoot(panel);
        } catch (error) { if (modal.dataset.requestedInvoice === id) { displayFailure(modal, error, false); } }
        finally { activeRequests.delete(button); }
    }
    function validRecipientSelection(value) {
        return typeof value === 'string' && (['', 'client', 'primary_contact'].indexOf(value) !== -1 || /^recipient:[1-9][0-9]*$/.test(value));
    }
    function refreshRecipientPicker(select) {
        if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.selectpicker === 'function') {
            var picker = window.jQuery(select);
            if (!picker.data('selectpicker')) { picker.selectpicker(); }
            else { picker.selectpicker('refresh'); }
        }
    }
    function recipientFormStatus(state, text, error) {
        state.status.textContent = text;
        state.status.className = 'help-block' + (error ? ' text-danger' : '');
    }
    function updateAutomationSummary(select) {
        var field = select.closest('[data-nfse-invoice-recipient-field]'); if (!field) { return; }
        var summary = field.querySelector('[data-nfse-automation-summary]'); if (!summary) { return; }
        var selected = select.options[select.selectedIndex];
        var message = selected ? selected.dataset.automationSummary || '' : '';
        var explicit = field.querySelector('[name="nfse_nacional_automatic"]');
        summary.textContent = (message || 'Nenhuma emissão automática selecionada para este tomador.') + (explicit && explicit.checked ? ' Emissão antecipada selecionada para esta fatura.' : '');
    }
    document.addEventListener('change', function (event) {
        var field = event.target.closest('[data-nfse-invoice-recipient-field]');
        if (field) { var select = field.querySelector('[data-nfse-invoice-recipient]'); if (select) { updateAutomationSummary(select); } }
    });
    function resetInvoiceRecipient(state) {
        state.select.replaceChildren(new Option('Usar padrão do cliente', ''));
        state.select.value = '';
        state.select.disabled = true;
        state.scopeInput.disabled = true;
        refreshRecipientPicker(state.select);
    }
    async function loadInvoiceRecipientOptions(form, force) {
        var state = invoiceRecipientForms.get(form);
        if (!state || !form.isConnected) { return; }
        var clientId = String(state.client.value || '');
        if (!force && state.clientId === clientId) { return; }
        state.clientId = clientId;
        state.generation++;
        if (state.controller) { state.controller.abort(); }
        resetInvoiceRecipient(state);
        if (!/^[1-9][0-9]*$/.test(clientId)) {
            recipientFormStatus(state, 'Selecione o cliente para definir quem receberá a NFS-e.', false);
            return;
        }
        var generation = state.generation;
        var controller = new AbortController();
        state.controller = controller;
        var timer = window.setTimeout(function () { controller.abort(); }, 30000);
        recipientFormStatus(state, 'Carregando os tomadores deste cliente…', false);
        try {
            var endpoint = new URL(state.endpoint);
            endpoint.searchParams.set('client_id', clientId);
            endpoint.searchParams.set('invoice_id', state.invoiceId);
            var response = await fetch(endpoint.href, {method: 'GET', credentials: 'same-origin', redirect: 'error', cache: 'no-store', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal});
            var payload = await response.json();
            if (!form.isConnected || state.generation !== generation || String(state.client.value || '') !== clientId) { return; }
            var data = payload && payload.data;
            if (!response.ok || !payload.ok || !data) { throw new Error((payload && payload.message) || 'Não foi possível carregar os tomadores. Reabra a fatura para tentar novamente.'); }
            if (String(data.client_id) !== clientId || String(data.invoice_id) !== state.invoiceId || data.company_scope !== state.scope
                || !Array.isArray(data.options) || !validRecipientSelection(data.selection)) {
                throw new Error('O cliente ou a empresa mudou. Reabra a fatura para selecionar o tomador.');
            }
            var fragment = document.createDocumentFragment();
            var values = [];
            data.options.forEach(function (item) {
                if (!item || !validRecipientSelection(item.value) || typeof item.label !== 'string' || values.indexOf(item.value) !== -1) {
                    throw new Error('A lista de tomadores não pôde ser exibida. Reabra a fatura.');
                }
                values.push(item.value);
                var option = new Option(item.label, item.value); option.dataset.automationSummary = item.automation_summary || ''; fragment.appendChild(option);
            });
            if (values.indexOf('') === -1 || values.indexOf(data.selection) === -1) { throw new Error('O tomador salvo não está disponível. Revise o cadastro do cliente antes de emitir.'); }
            state.select.replaceChildren(fragment);
            state.select.value = data.selection;
            state.select.disabled = false;
            state.scopeInput.value = state.scope;
            state.scopeInput.disabled = false;
            refreshRecipientPicker(state.select);
            updateAutomationSummary(state.select);
            recipientFormStatus(state, 'Esta escolha será usada nas novas NFS-e desta fatura. O cliente responsável pela cobrança permanece o mesmo.', false);
        } catch (error) {
            if (!form.isConnected || state.generation !== generation || String(state.client.value || '') !== clientId) { return; }
            resetInvoiceRecipient(state);
            recipientFormStatus(state, error.name === 'AbortError' ? 'A consulta demorou além do esperado. Reabra a fatura para selecionar o tomador.' : error.message, true);
        } finally { window.clearTimeout(timer); if (state.generation === generation) { state.controller = null; } }
    }
    function initializeInvoiceRecipientForms(scope) {
        var template = document.querySelector('template[data-nfse-invoice-form-template]');
        if (!template || !template.content) { return; }
        var forms = [];
        if (scope.matches && scope.matches('form#invoice-form')) { forms.push(scope); }
        scope.querySelectorAll('form#invoice-form').forEach(function (form) { forms.push(form); });
        forms.forEach(function (form) {
            if (invoiceRecipientForms.has(form) || form.querySelector('[name="nfse_nacional_recipient"]')) { return; }
            var client = form.querySelector('select#clientid[name="clientid"]');
            var anchor = client && client.closest('.f_client_id');
            var endpoint = safeUrl(template.dataset.nfseOptionsUrl);
            var companyScope = template.dataset.nfseCompanyScope;
            if (!client || !anchor || !form.contains(anchor) || !endpoint || !/^[a-f0-9]{64}$/.test(companyScope || '')) { return; }
            var action = safeUrl(form.getAttribute('action') || window.location.href);
            var match = action && new URL(action).pathname.match(/(?:^|\/)invoices\/invoice(?:\/([1-9][0-9]*))?\/?$/);
            if (!match || String(form.method).toLowerCase() !== 'post') { return; }
            var field = template.content.firstElementChild.cloneNode(true);
            if (match[1]) { field.querySelectorAll('[data-nfse-auto-create-option]').forEach(function (node) { node.remove(); }); }
            anchor.insertAdjacentElement('afterend', field);
            var state = {client: client, clientId: null, select: field.querySelector('[data-nfse-invoice-recipient]'), scopeInput: field.querySelector('[data-nfse-invoice-recipient-scope]'),
                status: field.querySelector('[data-nfse-invoice-recipient-status]'), endpoint: endpoint, invoiceId: match[1] || '0', scope: companyScope, generation: 0, controller: null};
            invoiceRecipientForms.set(form, state);
            loadInvoiceRecipientOptions(form, true);
        });
    }
    function rememberClientTab(root, hash) {
        if (!root || !root.querySelector('[data-nfse-client-tabs]') || ['#nfse-client-emission', '#nfse-client-recipients', '#nfse-client-documents'].indexOf(hash) === -1) { return; }
        if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + hash);
        }
    }
    function restoreClientTab(root) {
        var hash = window.location.hash;
        if (['#nfse-client-emission', '#nfse-client-recipients', '#nfse-client-documents'].indexOf(hash) === -1) { return; }
        var link = root.querySelector('[data-nfse-client-tabs] a[href="' + hash + '"]');
        if (link && window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.tab === 'function') { window.jQuery(link).tab('show'); }
    }
    function initializeRoot(root) {
        if (!root || initializedRoots.has(root)) { return; }
        initializedRoots.add(root);
        initializeEmissionProfiles(root);
        initializeIbptManagers(root);
        sourceVisibility(root);
        refreshServiceCatalogs(root);
        restoreClientTab(root);
        root.querySelectorAll('[data-nfse-prepare-recipient]').forEach(refreshRecipientPicker);
        if (root.hasAttribute('data-nfse-settings') && window.jQuery) {
            var form = root.closest('form');
            if (form) {
                bridgeSettingsValidator(root);
                window.jQuery(form).off('submit.nfseNativeSettings').on('submit.nfseNativeSettings', function (event) {
                    var settings = nativeSettingsRoot(this);
                    if (settings) { event.preventDefault(); event.stopImmediatePropagation(); submitSettings(settings); }
                });
            }
        }
    }
    function initializeRoots(scope) {
        if (scope.matches && scope.matches('.nfse-root')) { initializeRoot(scope); }
        scope.querySelectorAll('.nfse-root').forEach(initializeRoot);
    }
    function init() {
        initializeRoots(document);
        initializeInvoiceRecipientForms(document);
        document.addEventListener('submit', function (event) {
            if (event.target.matches('form.nfse-accounting-download')) { event.preventDefault(); downloadAccounting(event.target); return; }
            var settings = nativeSettingsRoot(event.target);
            if (settings) { event.preventDefault(); event.stopImmediatePropagation(); submitSettings(settings); return; }
            var form = event.target.closest('form[data-nfse-form]');
            if (form) { event.preventDefault(); submitForm(form); }
        }, true);
        document.addEventListener('click', function (event) {
            var gridAction = event.target.closest('[data-nfse-grid-action]');
            if (!gridAction || gridAction.disabled || gridAction.getAttribute('aria-disabled') === 'true') { return; }
            event.preventDefault();
            event.stopPropagation();
            openInvoiceContext(gridAction);
        }, true);
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-nfse-document-action], .nfse-action, [data-nfse-new-recipient], [data-nfse-edit-recipient], [data-nfse-clone-recipient], [data-nfse-open-invoice], [data-nfse-lookup], [data-nfse-ibpt-refresh], [data-nfse-ibpt-import]');
            if (!button || button.disabled) { return; }
            event.preventDefault();
            if (button.hasAttribute('data-nfse-ibpt-refresh')) { refreshIbptTable(button); }
            else if (button.hasAttribute('data-nfse-ibpt-import')) { importIbptTable(button); }
            else if (button.hasAttribute('data-nfse-lookup')) { lookupRegistration(button); }
            else if (button.hasAttribute('data-nfse-open-invoice')) { openInvoiceContext(button); }
            else if (button.hasAttribute('data-nfse-document-action')) { documentAction(button); }
            else if (button.hasAttribute('data-nfse-new-recipient')) { editRecipient(button, true); }
            else if (button.hasAttribute('data-nfse-clone-recipient')) { editRecipient(button, false, true); }
            else if (button.hasAttribute('data-nfse-edit-recipient')) { editRecipient(button, false); }
            else { action(button); }
        });
        document.addEventListener('change', function (event) {
            if (event.target.matches('#invoice-form select#clientid[name="clientid"]')) { loadInvoiceRecipientOptions(event.target.closest('form'), false); }
            var root = rootOf(event.target);
            if (!root) { return; }
            if (event.target.matches('[data-nfse-profile-inherit]')) {
                var field = event.target.closest('[data-nfse-profile-field][data-nfse-profile-group]');
                if (field) { renderProfileField(field); refreshServiceCatalogs(field); }
            }
            if (event.target.matches('[data-nfse-ibpt-file]')) {
                var ibptPanel = event.target.closest('[data-nfse-ibpt-manager]');
                var ibptState = ibptPanel && ibptManagerStates.get(ibptPanel);
                if (ibptState) {
                    var currentFile = ibptImportFile(ibptPanel).file;
                    var importKey = ibptPanel.querySelector('[data-nfse-ibpt-import-key]');
                    if (ibptState.selectedFile && ibptState.selectedFile !== currentFile && importKey) { importKey.value = ''; }
                    ibptState.selectedFile = currentFile;
                }
            }
            sourceVisibility(root);
            refreshIbptManagers(root);
            if (event.target.matches('[data-nfse-draft-recipient]')) { changeDraftRecipient(event.target); }
        });
        document.addEventListener('input', function (event) {
            var root = rootOf(event.target);
            if (root && event.target.closest('#nfse-document-form')) { refreshTaxSummaries(root); }
            refreshIbptManagers(root);
        });
        document.addEventListener('invalid', function (event) {
            var root = rootOf(event.target);
            if (!root || !window.jQuery || !window.jQuery.fn.tab) { return; }
            var panes = [];
            for (var parent = event.target.parentElement; parent && parent !== root; parent = parent.parentElement) {
                if (parent.classList.contains('tab-pane')) { panes.unshift(parent); }
            }
            panes.forEach(function (pane) {
                if (pane.classList.contains('active')) { return; }
                var tab = root.querySelector('a[aria-controls="' + pane.id + '"]');
                if (tab) { window.jQuery(tab).tab('show'); }
            });
        }, true);
        if (window.jQuery) { window.jQuery(document).on('shown.bs.modal.nfseNacional', '#nfse-recipient-modal', function () { refreshServiceCatalogs(this); var input = this.querySelector('input[name="recipient[label]"]'); if (input) { input.focus(); } }); }
        if (window.jQuery) {
            window.jQuery(document).on('change.nfseInvoiceRecipient changed.bs.select.nfseInvoiceRecipient', '#invoice-form select#clientid[name="clientid"]', function () { loadInvoiceRecipientOptions(this.closest('form'), false); });
            window.jQuery(document).on('shown.bs.tab.nfseClientTabs', '[data-nfse-client-tabs] a[data-toggle="tab"]', function () { rememberClientTab(this.closest('.nfse-client-tab'), this.getAttribute('href')); });
        }
        if (window.jQuery) { window.jQuery(function () { document.querySelectorAll('[data-nfse-settings]').forEach(bridgeSettingsValidator); }); }
        if (window.MutationObserver) {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType !== 1) { return; }
                        if (node.matches('.nfse-root') || node.querySelector('.nfse-root')) { initializeRoots(node); }
                        if (node.matches('form#invoice-form') || node.querySelector('form#invoice-form')) { initializeInvoiceRecipientForms(node); }
                    });
                });
            });
            observer.observe(document.getElementById('wrapper') || document.body, {childList: true, subtree: true});
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
    // Editable e-mail grid: save one row at a time, using the same CSRF queue as other CRM operations.
    function emailRow(id, name, address) {
        var row = document.createElement('tr'); row.dataset.id = id;
        var nameCell = document.createElement('td'); nameCell.dataset.emailName = ''; nameCell.textContent = name;
        var addressCell = document.createElement('td'); addressCell.dataset.emailAddress = ''; addressCell.textContent = address;
        var actions = document.createElement('td');
        [['edit', 'Editar', 'default', 'pencil'], ['delete', 'Remover', 'danger', 'trash']].forEach(function (action) {
            var button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-' + action[2] + ' btn-sm';
            button.dataset['email' + action[0].charAt(0).toUpperCase() + action[0].slice(1)] = ''; button.title = action[1];
            var icon = document.createElement('i'); icon.className = 'fa fa-' + action[3]; icon.setAttribute('aria-hidden', 'true');
            button.appendChild(icon); var label = document.createElement('span'); label.className = 'sr-only'; label.textContent = action[1]; button.appendChild(label); actions.appendChild(button); actions.appendChild(document.createTextNode(' '));
        });
        row.append(nameCell, addressCell, actions); return row;
    }
    function emailGridRender(grid, rows) {
        var body = grid.querySelector('[data-nfse-email-body]'); body.textContent = '';
        rows.forEach(function (row) { body.appendChild(emailRow(row.id, row.name, row.email)); });
        grid.querySelector('[data-nfse-email-count]').textContent = rows.length + ' destinatários cadastrados. Alterações salvas individualmente.';
        emailGridFilter(grid);
    }
    function emailGridFilter(grid) {
        var term = grid.querySelector('[data-nfse-email-search]').value.toLocaleLowerCase('pt-BR');
        var rows = Array.from(grid.querySelectorAll('[data-nfse-email-body] tr'));
        var matches = rows.filter(function (row) { return !!row.querySelector('input') || row.textContent.toLocaleLowerCase('pt-BR').indexOf(term) !== -1; });
        var pages = Math.max(1, Math.ceil(matches.length / 25));
        var page = Math.max(1, Math.min(pages, parseInt(grid.dataset.page || '1', 10)));
        grid.dataset.page = String(page);
        rows.forEach(function (row) { var index = matches.indexOf(row); row.hidden = !row.querySelector('input') && (index < (page - 1) * 25 || index >= page * 25); });
        var status = grid.querySelector('[data-email-page-status]'); if (status) { status.textContent = 'Página ' + page + ' de ' + pages + ' · ' + matches.length + ' destinatários'; }
        var previous = grid.querySelector('[data-email-page-previous]'); var next = grid.querySelector('[data-email-page-next]');
        if (previous) { previous.disabled = page <= 1; } if (next) { next.disabled = page >= pages; }
    }
    function emailGridEdit(row) {
        if (row.querySelector('input')) { return; }
        row.dataset.originalName = row.querySelector('[data-email-name]').textContent;
        row.dataset.originalEmail = row.querySelector('[data-email-address]').textContent;
        [['[data-email-name]', 'text', row.dataset.originalName, 'Nome'], ['[data-email-address]', 'email', row.dataset.originalEmail, 'E-mail']].forEach(function (field) {
            var cell = row.querySelector(field[0]); cell.textContent = '';
            var input = document.createElement('input'); input.className = 'form-control'; input.type = field[1]; input.value = field[2]; input.maxLength = field[1] === 'email' ? 254 : 100; input.setAttribute('aria-label', field[3]); cell.appendChild(input);
        });
        var actions = row.lastElementChild; actions.textContent = '';
        [['save', 'Salvar', 'primary'], ['cancel', 'Cancelar', 'default']].forEach(function (action) {
            var button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-' + action[2] + ' btn-sm'; button.dataset['email' + action[0].charAt(0).toUpperCase() + action[0].slice(1)] = ''; button.textContent = action[1]; actions.appendChild(button); actions.appendChild(document.createTextNode(' '));
        }); row.querySelector('input').focus();
    }
    function variableRateControls(root) {
        if (!root) { return; }
        var simpleToggle = root.querySelector('[data-nfse-variable-rate]');
        var issToggle = root.querySelector('[data-nfse-variable-iss-rate]');
        if (!simpleToggle) { return; }
        var simpleEnabled = simpleToggle.checked, issEnabled = !!issToggle && issToggle.checked;
        var share = root.querySelector('[data-nfse-shared-rate]'); var shared = !!share && share.checked;
        var simpleRate = root.dataset.nfseMonthlyRate || '', issRate = shared ? simpleRate : root.dataset.nfseMonthlyIssRate || '';
        var values = [['simple_rate', simpleEnabled, simpleRate, root.dataset.nfseFixedRate || ''], ['iss_rate', issEnabled, issRate, root.dataset.nfseFixedIssRate || '']];
        values.forEach(function (entry) { var field = root.querySelector('[name="settings[tax][' + entry[0] + ']"]'); if (field) { field.readOnly = entry[1]; field.value = entry[1] ? entry[2] : entry[3]; field.title = entry[1] ? 'Alíquota da competência consultada. Edite em Alíquotas por competência.' : ''; } });
        root.querySelectorAll('[data-nfse-rate-consult], [data-nfse-rate-manage]').forEach(function (element) { element.hidden = !simpleEnabled && !issEnabled; });
        var status = root.querySelector('[data-nfse-settings-rate-status]');
        if (status) {
            var messages = [];
            if (simpleEnabled) { messages.push(simpleRate === '' ? 'Simples: Sem alíquota para esta competência. Cadastre o mês antes de emitir.' : 'Simples: ' + simpleRate.replace('.', ',') + '%.'); }
            if (issEnabled) { messages.push(issRate === '' ? 'ISSQN: Sem alíquota para esta competência. Cadastre o mês na guia ' + (shared ? 'Simples Nacional' : 'ISSQN') + ' antes de emitir.' : 'ISSQN: ' + issRate.replace('.', ',') + '% (' + (shared ? 'mesmo percentual do Simples' : 'cadastro separado') + ').'); if (Number(issRate) > 5) { messages.push('Percentual superior a 5% não pode ser usado no ISSQN. Selecione cadastro separado.'); } }
            status.textContent = messages.join(' ');
        }
        // Only each enabled percentage is read-only; the estimation method and other tax controls remain editable.
    }
    async function lookupMonthlyRate(root) {
        var month = root.querySelector('[data-nfse-settings-rate-month]'); var status = root.querySelector('[data-nfse-settings-rate-status]');
        if (!month || !status || !/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/.test(month.value)) { return; }
        var endpoint = safeUrl(root.dataset.nfseRateUrl); if (!endpoint) { return; }
        var requested = month.value; root.dataset.rateRequest = requested; root.dataset.nfseMonthlyRate = ''; root.dataset.nfseMonthlyIssRate = ''; variableRateControls(root);
        status.textContent = 'Consultando alíquota da competência…';
        try {
            var url = new URL(endpoint); url.searchParams.set('competence', requested);
            var response = await window.fetch(url.href, {method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            var payload = await response.json(); if (root.dataset.rateRequest !== requested || month.value !== requested) { return; }
            if (!response.ok || !payload.ok) { throw new Error('Não foi possível consultar a alíquota.'); }
            root.dataset.nfseMonthlyRate = payload.data.rate === null ? '' : String(payload.data.rate);
            root.dataset.nfseMonthlyIssRate = payload.data.iss_rate == null ? '' : String(payload.data.iss_rate);
            root.dataset.nfseRateMonth = requested; variableRateControls(root);
        } catch (error) { if (root.dataset.rateRequest === requested) { status.textContent = 'Não foi possível consultar a alíquota. Reabra a guia ou tente novamente.'; } }
    }
    document.addEventListener('change', function (event) {
        var root = rootOf(event.target);
        if (event.target.matches('[data-nfse-variable-rate], [data-nfse-variable-iss-rate], [data-nfse-shared-rate]')) { variableRateControls(root); }
        if (event.target.matches('[data-nfse-settings-rate-month]')) { lookupMonthlyRate(root); }
        if (event.target.matches('[name="settings[tax][simple_rate]"]') && root) { var toggle = root.querySelector('[data-nfse-variable-rate]'); if (toggle && !toggle.checked) { root.dataset.nfseFixedRate = event.target.value; } }
        if (event.target.matches('[name="settings[tax][iss_rate]"]') && root) { var issToggle = root.querySelector('[data-nfse-variable-iss-rate]'); if (issToggle && !issToggle.checked) { root.dataset.nfseFixedIssRate = event.target.value; } }
    });
    document.addEventListener('input', function (event) { var grid = event.target.closest('[data-nfse-email-grid]'); if (grid && event.target.matches('[data-nfse-email-search]')) { grid.dataset.page = '1'; emailGridFilter(grid); } });
    document.addEventListener('click', async function (event) {
        var rateButton = event.target.closest('[data-nfse-rate-edit]');
        if (rateButton) { var rateRoot = rootOf(rateButton); var original = rateRoot.querySelector('[name="original_competence"]'); if (original) { original.value = rateButton.dataset.month; } rateRoot.querySelector('[name="competence"]').value = rateButton.dataset.month; rateRoot.querySelector('[name="rate"]').value = rateButton.dataset.rate; rateRoot.querySelector('[name="competence"]').readOnly = true; var form = rateRoot.querySelector('[data-nfse-rate-form]'); if (form) { form.dataset.editing = '1'; var saveButton = form.querySelector('button[type="submit"]'); if (saveButton) { saveButton.disabled = false; } var label = form.querySelector('[data-nfse-rate-save-label]'); if (label) { label.textContent = 'Salvar alteração'; } } rateRoot.querySelector('[name="rate"]').focus(); return; }
        var resetRate = event.target.closest('[data-nfse-rate-new]'); if (resetRate) { var originalRate = rootOf(resetRate).querySelector('[name="original_competence"]'); if (originalRate) { originalRate.value = ''; } var competenceInput = rootOf(resetRate).querySelector('[name="competence"]'); if (competenceInput) { competenceInput.readOnly = false; } var saveLabel = rootOf(resetRate).querySelector('[data-nfse-rate-save-label]'); var rateForm = rootOf(resetRate).querySelector('[data-nfse-rate-form]'); var allowsCreate = !rateForm || rateForm.dataset.canCreate !== '0'; if (rateForm) { var save = rateForm.querySelector('button[type="submit"]'); if (save) { save.disabled = !allowsCreate; } } if (saveLabel) { saveLabel.textContent = allowsCreate ? 'Incluir alíquota' : 'Selecione uma competência'; } }
        var grid = event.target.closest('[data-nfse-email-grid]'); if (!grid) { return; }
        var previousPage = event.target.closest('[data-email-page-previous]'); var nextPage = event.target.closest('[data-email-page-next]');
        if (previousPage || nextPage) { grid.dataset.page = String(Math.max(1, parseInt(grid.dataset.page || '1', 10) + (nextPage ? 1 : -1))); emailGridFilter(grid); return; }
        var button = event.target.closest('button'); if (!button) { return; } event.preventDefault();
        var row = button.closest('tr');
        if (button.hasAttribute('data-nfse-email-add')) {
            if (grid.querySelector('tbody input')) { grid.querySelector('tbody input').focus(); return; }
            row = emailRow('', '', ''); grid.querySelector('tbody').prepend(row); emailGridEdit(row); return;
        }
        if (!row) { return; }
        if (button.hasAttribute('data-email-edit')) { emailGridEdit(row); return; }
        if (button.hasAttribute('data-email-cancel')) {
            if (!row.dataset.id) { row.remove(); } else { row.replaceWith(emailRow(row.dataset.id, row.dataset.originalName, row.dataset.originalEmail)); } return;
        }
        var saving = button.hasAttribute('data-email-save'); var deleting = button.hasAttribute('data-email-delete');
        if (!saving && !deleting) { return; }
        if (activeRequests.has(grid)) { return; }
        var data = new FormData(); data.set('id', row.dataset.id || '');
        if (saving) {
            var name = row.querySelector('[data-email-name] input'); var address = row.querySelector('[data-email-address] input');
            if (!name.value.trim() || !address.value.trim() || !address.checkValidity()) { notify(button, 'error', 'Informe nome e e-mail válidos.'); return; }
            data.set('name', name.value); data.set('email', address.value);
        }
        activeRequests.add(grid); busy(button, true);
        try { var response = await request(button, saving ? grid.dataset.saveUrl : grid.dataset.deleteUrl, data); emailGridRender(grid, response.data.recipients); notify(grid, 'success', response.message); }
        catch (error) { displayFailure(button, error, false); }
        finally { activeRequests.delete(grid); busy(grid, false); }
    });
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { document.querySelectorAll('[data-nfse-settings]').forEach(variableRateControls); }); }
    else { document.querySelectorAll('[data-nfse-settings]').forEach(variableRateControls); }
    function initializeExtraGrids() {
        document.querySelectorAll('[data-nfse-email-grid]').forEach(emailGridFilter);
        // Competence rates are rendered and paginated on the server, without DataTables defaults or AJAX.
        document.querySelectorAll('[data-nfse-sync-progress]').forEach(function (panel) {
            var labels = {pending: 'Agendada', running: 'Em andamento', waiting: 'Aguardando próxima consulta', completed: 'Concluída', failed: 'Falha — retomar'};
            var active = ['pending', 'running', 'waiting']; var latest = {}, mayProcess = false;
            function timestamp(value) { return typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) ? Date.parse(value.replace(' ', 'T') + 'Z') : NaN; }
            function displayDate(value) { var time = timestamp(value); return Number.isFinite(time) ? new Date(time).toLocaleString('pt-BR') : '—'; }
            function show(run) {
                var previousStatus = panel.dataset.status;
                latest = run; panel.dataset.status = run.status || '';
                panel.querySelector('[data-sync-status]').textContent = labels[run.status] || 'Não iniciada';
                panel.querySelector('[data-sync-count]').textContent = String(run.processed || 0);
                panel.querySelector('[data-sync-batches]').textContent = String(run.batches || 0);
                panel.querySelector('[data-sync-message]').textContent = run.last_error || (run.status === 'completed' ? 'Todos os lotes disponibilizados nesta execução foram consultados. Atualize a listagem para ver os documentos.' : run.worker_stalled ? 'A fila aguarda execução. Use Retomar para processar agora e verifique o cron do CRM para continuar com a tela fechada.' : 'Busca em andamento. O cron do CRM continua com a tela fechada; esta tela também processa lotes adicionais automaticamente.');
                var worker = panel.querySelector('[data-sync-worker]'), next = panel.querySelector('[data-sync-next]');
                if (worker) { worker.textContent = run.last_worker_at ? displayDate(run.last_worker_at) : 'Nenhum lote processado nesta execução'; }
                if (next) { next.textContent = displayDate(run.next_attempt_at || run.next_run_at); }
                if (run.status === 'completed' && active.indexOf(previousStatus) !== -1 && !panel.dataset.resultsRefreshed) { panel.dataset.resultsRefreshed = '1'; window.setTimeout(function () { window.location.reload(); }, 800); }
            }
            async function poll() {
                if (!panel.isConnected || active.indexOf(panel.dataset.status) === -1) { return; }
                var root = rootOf(panel), form = root.querySelector('[data-nfse-form="national-sync"]');
                try {
                    var progressUrl = safeUrl(panel.dataset.progressUrl);
                    if (!progressUrl) { return; }
                    var response = await window.fetch(progressUrl, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                    if (response.ok) { var payload = await response.json(); updateCsrf(payload.csrf); if (payload.ok && payload.data) { mayProcess = payload.data.can_process === true; show(payload.data.run || {}); } }
                    var due = timestamp(latest.next_attempt_at || latest.next_run_at);
                    if (panel.dataset.canProcess === '1' && mayProcess && active.indexOf(latest.status) !== -1 && Number.isFinite(due) && due <= Date.now() && form && !activeRequests.has(form)) {
                        activeRequests.add(form); busy(form, true);
                        try {
                            var body = new FormData(); body.set('environment', panel.dataset.environment);
                            var processed = await request(root, panel.dataset.processUrl, body);
                            if (processed.data && processed.data.run) { show(processed.data.run); }
                        } finally { activeRequests.delete(form); busy(form, false); }
                    }
                } catch (ignored) { /* Cursor and queue remain authoritative; cron and the next poll can resume safely. */ }
                if (panel.isConnected && active.indexOf(panel.dataset.status) !== -1) { window.setTimeout(poll, 10000); }
            }
            if (active.indexOf(panel.dataset.status) !== -1) { window.setTimeout(poll, 1000); }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initializeExtraGrids); } else { initializeExtraGrids(); }
}());
