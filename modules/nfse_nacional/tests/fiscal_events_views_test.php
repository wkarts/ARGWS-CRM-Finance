<?php
declare(strict_types=1);

// Real views with a synthetic fiscal context. No database, certificate or HTTP calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
function init_head(): void { echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'; }
function init_tail(): void {}
function admin_url(string $path = ''): string { return 'https://crm.example.invalid/empresa-a/admin/' . $path; }
function nfse_nacional_invoice_allowed($id): bool { return (int) $id === 51; }
$viewPermissions = ['view', 'create', 'edit', 'transmit', 'send', 'cancel', 'manifest', 'substitute', 'national_view'];
function nfse_nacional_can(string $capability): bool { return in_array($capability, $GLOBALS['viewPermissions'], true); }
function nfse_nacional_status_label(string $status): string { return ['draft' => 'Rascunho', 'ready' => 'Validada', 'authorized' => 'Autorizada', 'cancelled' => 'Cancelada', 'uncertain' => 'A consultar'][$status] ?? $status; }
function form_open(string $action, array $attributes = []): string {
    $html = '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '"';
    foreach ($attributes as $key => $value) { $html .= ' ' . htmlspecialchars((string) $key, ENT_QUOTES) . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"'; }
    return $html . '><input type="hidden" name="fixture_csrf" value="fixture-token">';
}
function form_close(): string { return '</form>'; }
function &get_instance() {
    static $ci;
    if (!$ci) { $ci = (object) ['security' => new class {
        public function get_csrf_token_name(): string { return 'fixture_csrf'; }
        public function get_csrf_hash(): string { return 'fixture-token'; }
    }]; }
    return $ci;
}
$checks = 0;
function fiscalViewCheck(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function fiscalViewRender(string $name, array $data): string {
    extract($data, EXTR_SKIP); ob_start();
    try { require dirname(__DIR__) . '/views/' . $name . '.php'; return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
}
function fiscalViewDom(string $html): DOMXPath {
    $dom = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    return new DOMXPath($dom);
}

$cancelReasons = ['1' => 'Erro na emissão', '2' => 'Serviço não prestado', '9' => 'Outros'];
$action = ['origin' => 'local', 'id' => 8, 'environment' => '1', 'nfse_number' => '123', 'access_key' => str_repeat('1', 50), 'status' => 'authorized',
    'actions' => [
        '101101' => ['label' => 'Cancelar NFS-e', 'role' => 'prest', 'permission' => 'cancel', 'reasons' => $cancelReasons,
            'reason_required' => true, 'can_submit' => true, 'request_status' => '', 'can_retry' => false],
        '101103' => ['label' => 'Solicitar análise fiscal para cancelamento', 'role' => 'prest', 'permission' => 'cancel', 'reasons' => $cancelReasons,
            'reason_required' => true, 'can_submit' => true, 'request_status' => '', 'can_retry' => false],
    ], 'history' => []];
$html = fiscalViewRender('fiscal_events', ['fiscal_action' => $action]); $xp = fiscalViewDom($html);
fiscalViewCheck($xp->query('//form[@data-nfse-form="fiscal-event-submit"]')->length === 2
    && $xp->query('//form[@data-nfse-form="fiscal-event-consult"]')->length === 2, 'cancelamento e análise possuem envio e consulta separados');
fiscalViewCheck($xp->query('//form[@data-nfse-form="fiscal-event-retry"]')->length === 0, 'pedido ainda não enviado não oferece reenvio');
fiscalViewCheck($xp->query('//form//input[@name="fixture_csrf"]')->length === $xp->query('//form')->length
    && $xp->query('//form[@method!="post"]|//form//form')->length === 0, 'operações utilizam formulários POST independentes com CSRF nativo');
fiscalViewCheck($xp->query('//form[@data-nfse-form="fiscal-event-submit"]//select[@name="reason_code" and @required]/option[@value=""]')->length === 2
    && $xp->query('//form[@data-nfse-form="fiscal-event-submit"]//textarea[@name="reason" and @required and @minlength="15" and @maxlength="255"]')->length === 2,
    'cancelamento e análise exigem escolha do motivo e justificativa de 15 a 255 caracteres');
foreach ($xp->query('//form[@data-nfse-form="fiscal-event-submit"]') as $form) {
    fiscalViewCheck(str_ends_with($form->getAttribute('action'), '/fiscal_event_submit/local/8') && $form->getAttribute('data-reload') === '1'
        && str_contains($form->getAttribute('data-nfse-confirm'), 'NFS-e 123') && str_contains($form->getAttribute('data-nfse-confirm'), 'Produção — validade fiscal'),
        'confirmação de envio identifica nota e ambiente e mantém a origem da rota');
}
$ids = [];
foreach ($xp->query('//*[@id]') as $node) { $ids[] = $node->getAttribute('id'); }
fiscalViewCheck(count($ids) === count(array_unique($ids)), 'campos de motivo e justificativa possuem identificadores únicos por evento');
fiscalViewCheck(str_contains($html, 'não confirma o cancelamento') && str_contains($html, 'não equivalem ao cancelamento')
    && !str_contains($html, 'Protocolo'), 'pedido de análise não é apresentado como cancelamento ou protocolo fictício');
fiscalViewCheck($xp->query('//a[@href="https://crm.example.invalid/empresa-a/admin/nfse_nacional/document/8"]')->length === 1,
    'retorno da consulta local abre a nota original no contexto da empresa');

$registered = $action;
$registered['actions']['101101']['request_status'] = 'registered';
$registered['actions']['101101']['can_retry'] = true; // Defensive: status must prevail over a stale flag.
$registered['history'] = [['id' => 4, 'event_type' => '101101', 'status' => 'registered', 'label' => 'Cancelar NFS-e', 'event_id' => 'EVT-SINTETICO',
    'processed_at' => '2026-11-11T14:00:00-03:00', 'record_number' => '45', 'attempts' => 1, 'last_error' => '', 'updated_at' => '2026-11-11 17:01:00']];
$html = fiscalViewRender('fiscal_events', ['fiscal_action' => $registered]); $xp = fiscalViewDom($html);
fiscalViewCheck($xp->query('//form[@data-nfse-form="fiscal-event-submit" or @data-nfse-form="fiscal-event-retry"]//input[@name="event_type" and @value="101101"]')->length === 0,
    'evento registrado bloqueia envio e reenvio mesmo diante de flags antigas');
fiscalViewCheck($xp->query('//form[@data-nfse-form="fiscal-event-consult"]//input[@value="101101"]')->length === 1
    && $xp->query('//a[@href="https://crm.example.invalid/empresa-a/admin/nfse_nacional/fiscal_event_xml/local/8/101101"]')->length === 1,
    'evento registrado oferece consulta e download do XML confirmado');
fiscalViewCheck(str_contains($html, 'EVT-SINTETICO') && str_contains($html, 'Número do registro:') && str_contains($html, 'Processamento:')
    && !str_contains($html, 'Protocolo'), 'identificação, número do registro e processamento exibem evidência real sem inventar protocolo');

foreach (['processing', 'uncertain'] as $status) {
    $pending = $action; $pending['actions']['101101']['request_status'] = $status; $pending['actions']['101101']['can_retry'] = true;
    $html = fiscalViewRender('fiscal_events', ['fiscal_action' => $pending]); $xp = fiscalViewDom($html);
    fiscalViewCheck($xp->query('//form[@data-nfse-form="fiscal-event-submit"]//input[@value="101101"]')->length === 0
        && $xp->query('//form[@data-nfse-form="fiscal-event-retry"]//input[@name="event_type" and @value="101101"]')->length === 1,
        $status . ': pedido pendente não permite novo pedido e oferece reenvio explícito');
    $retryForm = $xp->query('//form[@data-nfse-form="fiscal-event-retry"]')->item(0);
    fiscalViewCheck(str_ends_with($retryForm->getAttribute('action'), '/fiscal_event_retry/local/8')
        && str_contains($retryForm->getAttribute('data-nfse-confirm'), 'exatamente o mesmo pedido')
        && str_contains($retryForm->getAttribute('data-nfse-confirm'), 'NFS-e 123')
        && str_contains($retryForm->getAttribute('data-nfse-confirm'), 'Produção')
        && $xp->query('//form[@data-nfse-form="fiscal-event-retry"]//*[@name="reason" or @name="reason_code"]')->length === 0,
        $status . ': reenvio informa nota e ambiente e não permite alterar o pedido original');
    $pending['actions']['101101']['can_retry'] = false;
    fiscalViewCheck(fiscalViewDom(fiscalViewRender('fiscal_events', ['fiscal_action' => $pending]))->query('//form[@data-nfse-form="fiscal-event-retry"]')->length === 0,
        $status . ': indisponibilidade do pedido original oculta o reenvio');
}
$cancelled = $registered; $cancelled['status'] = 'cancelled'; $cancelled['actions']['101103']['request_status'] = 'uncertain'; $cancelled['actions']['101103']['can_retry'] = true;
$xp = fiscalViewDom(fiscalViewRender('fiscal_events', ['fiscal_action' => $cancelled]));
fiscalViewCheck($xp->query('//form[@data-nfse-form="fiscal-event-submit" or @data-nfse-form="fiscal-event-retry"]')->length === 0
    && $xp->query('//form[@data-nfse-form="fiscal-event-consult"]')->length === 2, 'nota cancelada conserva consulta mas não envia novos pedidos');
$denied = $action; $denied['actions']['101101']['can_submit'] = false;
fiscalViewCheck(fiscalViewDom(fiscalViewRender('fiscal_events', ['fiscal_action' => $denied]))->query('//form[@data-nfse-form="fiscal-event-submit"]//input[@value="101101"]')->length === 0,
    'can_submit falso prevalece mesmo quando o usuário tem a permissão geral');
$viewPermissions = ['view'];
$xp = fiscalViewDom(fiscalViewRender('fiscal_events', ['fiscal_action' => $action]));
fiscalViewCheck($xp->query('//form[@data-nfse-form="fiscal-event-submit" or @data-nfse-form="fiscal-event-retry"]')->length === 0
    && $xp->query('//form[@data-nfse-form="fiscal-event-consult"]')->length === 2, 'somente view permite consultar sem mostrar ações de envio');
$viewPermissions = ['view', 'create', 'edit', 'transmit', 'send', 'cancel', 'manifest', 'substitute', 'national_view'];

$received = $action;
$received['origin'] = 'national'; $received['environment'] = '2';
$received['actions'] = [
    '203202' => ['label' => 'Confirmar NFS-e recebida', 'role' => 'toma', 'permission' => 'manifest', 'reasons' => [],
        'reason_required' => false, 'can_submit' => true, 'request_status' => '', 'can_retry' => false],
    '203206' => ['label' => 'Rejeitar NFS-e recebida', 'role' => 'toma', 'permission' => 'manifest', 'reasons' => ['1' => 'NFS-e em duplicidade', '9' => 'Outros'],
        'reason_required' => false, 'can_submit' => true, 'request_status' => '', 'can_retry' => false],
];
$html = fiscalViewRender('fiscal_events', ['fiscal_action' => $received]); $xp = fiscalViewDom($html);
fiscalViewCheck($xp->query('//form[@id="nfse-event-submit-203202"]//*[@name="reason" or @name="reason_code"]')->length === 0,
    'confirmação do tomador não envia motivo ou justificativa inexistentes no evento');
fiscalViewCheck($xp->query('//form[@id="nfse-event-submit-203206"]//select[@name="reason_code" and @required]')->length === 1
    && $xp->query('//form[@id="nfse-event-submit-203206"]//textarea[@name="reason" and not(@required) and @minlength="15" and @maxlength="255"]')->length === 1,
    'rejeição do tomador exige motivo e aceita justificativa opcional no tamanho permitido');
fiscalViewCheck(str_contains($html, 'manifestação não cancela a nota') && str_contains($html, 'Para Outros, descreva o motivo'),
    'manifestação e orientação de justificativa não prometem cancelamento');
foreach ($xp->query('//form[@data-nfse-form="fiscal-event-submit"]') as $form) {
    fiscalViewCheck(str_ends_with($form->getAttribute('action'), '/fiscal_event_submit/national/8')
        && str_contains($form->getAttribute('data-nfse-confirm'), 'homologação') && str_contains($form->getAttribute('data-nfse-confirm'), 'sem validade fiscal'),
        'manifestação nacional preserva origem e confirma ambiente de homologação');
}
fiscalViewCheck($xp->query('//a[@href="https://crm.example.invalid/empresa-a/admin/nfse_nacional/national/received?environment=2"]')->length === 1,
    'retorno das manifestações preserva a guia recebidas e o ambiente');

$empty = $action; $empty['actions'] = [];
$html = fiscalViewRender('fiscal_events', ['fiscal_action' => $empty]);
fiscalViewCheck(str_contains($html, 'Nenhum evento está disponível') && str_contains($html, 'Nenhum pedido de evento registrado neste módulo')
    && fiscalViewDom($html)->query('//form')->length === 0, 'estado vazio não afirma ausência definitiva de eventos nacionais');
$attack = '<script>unsafe()</script><img src=x onerror="unsafe()">';
$unsafe = $registered;
foreach (['access_key', 'nfse_number'] as $key) { $unsafe[$key] = $attack; }
$unsafe['actions']['101103']['label'] = $attack; $unsafe['actions']['101103']['reasons']['9'] = $attack;
foreach (['label', 'event_id', 'record_number', 'last_error', 'processed_at'] as $key) { $unsafe['history'][0][$key] = $attack; }
$html = fiscalViewRender('fiscal_events', ['fiscal_action' => $unsafe]); $xp = fiscalViewDom($html);
fiscalViewCheck($xp->query('//script|//img|//*[@onerror]')->length === 0 && str_contains($html, '&lt;script&gt;'),
    'metadados, erros, motivos e confirmações de eventos escapam conteúdo fiscal externo');
$unsafe = $action; $unsafe['actions']['../../bad'] = $unsafe['actions']['101101'];
fiscalViewCheck(!str_contains(fiscalViewRender('fiscal_events', ['fiscal_action' => $unsafe]), '../../bad'), 'tipo de evento inválido não constrói campos ou rotas');
$unsafe = $action; $unsafe['origin'] = '../../bad';
fiscalViewCheck(fiscalViewRender('fiscal_events', ['fiscal_action' => $unsafe]) === '', 'origem inválida não produz uma página com rotas arbitrárias');
$viewPermissions = [];
fiscalViewCheck(fiscalViewRender('fiscal_events', ['fiscal_action' => $action]) === '', 'view de eventos não expõe informações sem permissão');
$viewPermissions = ['view', 'create', 'edit', 'transmit', 'send', 'cancel', 'manifest', 'substitute', 'national_view'];

// Substitution belongs to the original invoice and prepares a reviewed draft.
$draft = ['environment' => '1', 'competence' => '2026-11-10', 'provider' => ['name' => 'Emitente sintético'],
    'recipient' => ['name' => 'Tomador sintético'], 'service' => ['description' => 'Apenas demonstração.'], 'values' => ['service_amount' => '10.00']];
$document = ['id' => 8, 'client_id' => 12, 'invoice_id' => 51, 'status' => 'authorized', 'environment' => '1', 'nfse_number' => '123',
    'draft_hash' => str_repeat('a', 64), 'attempts' => 1, 'access_key' => str_repeat('1', 50), 'email_status' => 'pending'];
$data = ['draft' => $draft, 'document' => $document, 'invoice' => ['id' => 51, 'clientid' => 12, 'status' => 2], 'client' => ['company' => 'Cliente sintético'],
    'can' => array_fill_keys($viewPermissions, true), 'danfe_status' => ['available' => true], 'recipients' => [], 'events' => [], 'replacement_document_id' => 0,
    'fiscal_status' => ['label' => 'Autorizada', 'has_official_xml' => true, 'protocol' => '', 'transmission_label' => 'Autorizada']];
$html = fiscalViewRender('document', $data); $xp = fiscalViewDom($html);
$prepare = $xp->query('//form[@data-nfse-form="substitute"]');
fiscalViewCheck($prepare->length === 1 && $prepare->item(0)->getAttribute('method') === 'post'
    && str_ends_with($prepare->item(0)->getAttribute('action'), '/substitute/8') && $prepare->item(0)->getAttribute('data-nfse-navigate') === '1',
    'nota autorizada prepara a substituição em POST próprio e navega para o novo rascunho');
fiscalViewCheck(str_contains($prepare->item(0)->getAttribute('data-nfse-confirm'), 'apenas prepara o rascunho')
    && str_contains($prepare->item(0)->getAttribute('data-nfse-confirm'), 'NFS-e 123')
    && str_contains($prepare->item(0)->getAttribute('data-nfse-confirm'), 'Produção — validade fiscal')
    && !$prepare->item(0)->hasAttribute('data-nfse-emission'), 'confirmação identifica a nota e limita a ação à preparação sem transmissão ou cancelamento');
fiscalViewCheck($xp->query('//form[@data-nfse-form="substitute"]//input[@name="fixture_csrf"]')->length === 1
    && $xp->query('//form[@data-nfse-form="substitute"]//select[@name="reason_code" and @required]/option')->length === 7
    && $xp->query('//form[@data-nfse-form="substitute"]//textarea[@name="reason" and @required and @minlength="15" and @maxlength="255"]')->length === 1,
    'preparação usa CSRF, catálogo oficial de motivos e justificativa limitada');
fiscalViewCheck($xp->query('//form//form')->length === 0 && $xp->query('//form[@data-nfse-form="substitute"]//*[@name="access_key"]')->length === 0,
    'formulário de substituição é independente e não envia uma chave alterável pelo navegador');
fiscalViewCheck($xp->query('//a[@href="https://crm.example.invalid/empresa-a/admin/nfse_nacional/fiscal_events/local/8"]')->length === 1,
    'nota autorizada oferece acesso aos eventos fiscais da origem local');
foreach (['create', 'edit', 'substitute'] as $permission) {
    $denied = $data; $denied['can'][$permission] = false;
    fiscalViewCheck(fiscalViewDom(fiscalViewRender('document', $denied))->query('//form[@data-nfse-form="substitute"]')->length === 0,
        'sem ' . $permission . ' não oferece preparar substituição');
}
foreach (['draft', 'uncertain', 'cancelled'] as $status) {
    $unavailable = $data; $unavailable['document']['status'] = $status;
    fiscalViewCheck(fiscalViewDom(fiscalViewRender('document', $unavailable))->query('//form[@data-nfse-form="substitute"]')->length === 0,
        $status . ' não oferece preparar uma nova substituição');
}
$unavailable = $data; $unavailable['orphan_document'] = true;
fiscalViewCheck(fiscalViewDom(fiscalViewRender('document', $unavailable))->query('//form[@data-nfse-form="substitute"]')->length === 0,
    'origem indisponível preserva documento fiscal sem preparar substituição');
$source = $data; $source['replacement_document_id'] = 9;
$xp = fiscalViewDom(fiscalViewRender('document', $source));
fiscalViewCheck($xp->query('//form[@data-nfse-form="substitute"]')->length === 0
    && $xp->query('//a[@href="https://crm.example.invalid/empresa-a/admin/nfse_nacional/document/9"]')->length === 1,
    'nota já vinculada aponta para a substituição existente e não oferece duplicar o rascunho');

$replacement = $data;
$replacement['document'] = array_replace($document, ['id' => 9, 'status' => 'draft', 'attempts' => 0, 'access_key' => '', 'nfse_number' => '', 'replacement_of_id' => 8]);
$replacement['original_document_id'] = 8;
$replacement['draft']['substitution'] = ['access_key' => str_repeat('1', 50), 'reason_code' => '99', 'reason' => 'Correção de dados da prestação realizada.'];
$replacement['recipients'] = [['id' => 77, 'selection' => 'recipient:77', 'name' => 'Outro tomador disponível', 'tax_id' => '11144477735']];
$html = fiscalViewRender('document', $replacement); $xp = fiscalViewDom($html);
fiscalViewCheck(str_contains($html, 'Rascunho de substituição') && str_contains($html, 'Preparar este rascunho não cancela a nota original')
    && $xp->query('//a[@href="https://crm.example.invalid/empresa-a/admin/nfse_nacional/document/8"]')->length === 1,
    'rascunho identifica a substituição e permite retornar à NFS-e original');
fiscalViewCheck($xp->query('//form[@id="nfse-document-form"]//select[@name="draft[substitution][reason_code]"]/option[@value="99" and @selected]')->length === 1
    && $xp->query('//form[@id="nfse-document-form"]//textarea[@name="draft[substitution][reason]" and @minlength="15" and @maxlength="255" and @required]')->length === 1,
    'motivo e justificativa podem ser revisados dentro do formulário principal do rascunho');
fiscalViewCheck($xp->query('//*[@name="draft[substitution][access_key]" or @name="original_document_id" or @name="replacement_of_id"]')->length === 0,
    'chave e vínculo da nota original não são campos editáveis ou ocultos enviados pelo formulário');
fiscalViewCheck($xp->query('//select[@name="recipient_selection"]|//*[@data-nfse-recipients]')->length === 0
    && $xp->query('//input[@name="draft[recipient][tax_id]" and @readonly]')->length === 1
    && $xp->query('//button[@data-nfse-lookup="cnpj"]')->length === 0,
    'substituição não oferece outro tomador, alteração do CPF/CNPJ ou consulta que troque sua identidade');
fiscalViewCheck($xp->query('//input[@name="draft[competence]" and @readonly]')->length === 1
    && str_contains($html, 'A competência da nota original é preservada')
    && str_contains($html, 'A troca do CPF/CNPJ não está disponível'), 'competência e identidade do tomador ficam explicitamente preservadas');
fiscalViewCheck($xp->query('//input[@name="draft[recipient][street]" and not(@readonly)]')->length === 1
    && $xp->query('//button[@data-nfse-lookup="cep"]')->length === 1, 'bloqueio da identidade mantém revisão do endereço e consulta do CEP');
$ordinaryDraft = $replacement; unset($ordinaryDraft['draft']['substitution'], $ordinaryDraft['original_document_id'], $ordinaryDraft['document']['replacement_of_id']);
$ordinaryXpath = fiscalViewDom(fiscalViewRender('document', $ordinaryDraft));
fiscalViewCheck($ordinaryXpath->query('//select[@name="recipient_selection"]')->length === 1
    && $ordinaryXpath->query('//input[@name="draft[recipient][tax_id]" and not(@readonly)]')->length === 1
    && $ordinaryXpath->query('//input[@name="draft[competence]" and not(@readonly)]')->length === 1
    && $ordinaryXpath->query('//button[@data-nfse-lookup="cnpj"]')->length === 1,
    'emissão normal mantém seleção de tomadores, consulta cadastral e edição de competência');
$denied = $replacement; $denied['can']['substitute'] = false;
fiscalViewCheck(fiscalViewDom(fiscalViewRender('document', $denied))->query('//fieldset[@disabled]//textarea[@name="draft[substitution][reason]"]')->length === 1,
    'usuário sem permissão de substituição não altera a justificativa fiscal');
$unsafe = $replacement; $unsafe['draft']['substitution']['reason'] = $attack; $unsafe['draft']['substitution']['access_key'] = $attack;
$html = fiscalViewRender('document', $unsafe);
fiscalViewCheck(fiscalViewDom($html)->query('//script|//img|//*[@onerror]')->length === 0 && str_contains($html, '&lt;script&gt;'),
    'chave e justificativa da substituição são escapadas na faixa e no formulário');
$denied = $data; $denied['can']['view'] = false;
fiscalViewCheck(fiscalViewDom(fiscalViewRender('document', $denied))->query('//a[contains(@href,"/fiscal_events/")]')->length === 0,
    'link de eventos locais respeita permissão de consulta');

echo 'OK: ' . $checks . " verificações das views de eventos fiscais e substituição; sem requisições fiscais reais.\n";
