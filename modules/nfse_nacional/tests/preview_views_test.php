<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

function init_head(): void { echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'; }
function init_tail(): void {}
function admin_url(string $path = ''): string { return 'https://crm.example.invalid/empresa-a/admin/' . $path; }
function nfse_nacional_can(string $capability): bool { return true; }
function nfse_nacional_invoice_allowed($id): bool { return (int) $id === 51; }
function nfse_nacional_status_label($status): string {
    return ['draft' => 'Rascunho', 'ready' => 'Validada', 'processing' => 'Em processamento', 'uncertain' => 'A consultar',
        'authorized' => 'Autorizada', 'rejected' => 'Rejeitada', 'cancelled' => 'Cancelada'][$status] ?? (string) $status;
}
function form_open(string $action, array $attributes = []): string {
    $html = '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '"';
    foreach ($attributes as $key => $value) { $html .= ' ' . htmlspecialchars((string) $key, ENT_QUOTES) . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"'; }
    return $html . '>';
}
function form_close(): string { return '</form>'; }
function &get_instance() {
    static $instance;
    if (!$instance) {
        $instance = (object) ['security' => new class {
            public function get_csrf_token_name(): string { return 'fixture_csrf'; }
            public function get_csrf_hash(): string { return 'fixture-token'; }
        }];
    }
    return $instance;
}

$checks = 0;
function previewViewCheck(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function previewViewRender(string $name, array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    try { require dirname(__DIR__) . '/views/' . $name . '.php'; return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
}
function previewViewDom(string $html): DOMXPath {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    return new DOMXPath($dom);
}

$draft = ['environment' => '2', 'competence' => '2026-11-10', 'provider' => ['name' => 'Emitente sintético'],
    'recipient' => ['name' => 'Tomador sintético'], 'service' => ['description' => 'Apenas demonstração.'], 'values' => ['service_amount' => '10.00']];
$base = ['draft' => $draft, 'invoice' => ['id' => 51, 'clientid' => 12, 'status' => 2], 'client' => ['company' => 'Cliente sintético'],
    'can' => ['view' => true, 'edit' => true, 'transmit' => true, 'send' => true], 'danfe_status' => ['available' => true],
    'environment' => '2', 'recipients' => [], 'events' => []];
$cases = ['draft' => 'Não enviada', 'ready' => 'Não enviada', 'rejected' => 'Rejeitada, sem autorização',
    'processing' => 'Enviada, confirmação pendente', 'uncertain' => 'Enviada, confirmação pendente',
    'authorized' => 'Autorizada', 'cancelled' => 'Cancelada'];

foreach ($cases as $status => $transmission) {
    $issued = in_array($status, ['authorized', 'cancelled'], true);
    $document = ['id' => 8, 'client_id' => 12, 'invoice_id' => 51, 'status' => $status, 'environment' => '2',
        'draft_hash' => str_repeat('a', 64), 'attempts' => in_array($status, ['draft', 'ready'], true) ? 0 : 1,
        'nfse_number' => $issued ? '123' : '', 'access_key' => $issued ? str_repeat('1', 50) : '',
        'client_name' => 'Cliente sintético', 'service_amount' => '10.00', 'email_status' => 'pending'];
    $fiscalStatus = ['label' => nfse_nacional_status_label($status), 'transmission_label' => $transmission,
        'explanation' => 'Situação confirmada no exemplo artificial.', 'has_official_xml' => $issued,
        'protocol' => '', 'processed_at' => $issued ? '2026-11-10T14:00:00-03:00' : '',
        'checked_at' => $issued ? '2026-11-10 14:05:00' : '',
        'cancellation_event_id' => $status === 'cancelled' ? 'EVT-EXEMPLO' : '',
        'cancellation_record_number' => $status === 'cancelled' ? '1' : '',
        'cancelled_at' => $status === 'cancelled' ? '2026-11-10T14:04:00-03:00' : ''];
    $data = $base + ['document' => $document, 'documents' => [$document], 'fiscal_status' => $fiscalStatus];
    $html = previewViewRender('document', $data);
    $xpath = previewViewDom($html);
    previewViewCheck($xpath->query('//a[@data-nfse-preview and @href="https://crm.example.invalid/empresa-a/admin/nfse_nacional/preview/8" and @target="_blank" and @rel="noopener noreferrer"]')->length === 1, $status . ': prévia GET mantém empresa e abre em aba segura');
    previewViewCheck($xpath->query('//a[@data-nfse-preview and (@data-nfse-url or @data-nfse-emission or contains(@class,"nfse-action"))]')->length === 0, $status . ': prévia não executa ação de transmissão');
    previewViewCheck($xpath->query('//a[contains(@href,"/download/8/pdf")]')->length === 1, $status . ': download do PDF existe antes e após emissão');
    previewViewCheck($xpath->query('//a[contains(@href,"/download/8/xml")]')->length === ($issued ? 1 : 0), $status . ': XML é oferecido somente para nota recebida');
    previewViewCheck(str_contains($html, $transmission) && str_contains($html, 'Ambiente de testes.'), $status . ': transmissão e ambiente estão explícitos');
    if ($status === 'cancelled') {
        previewViewCheck($xpath->query('//button[contains(@data-nfse-url,"/send/") or contains(@data-nfse-url,"/transmit/") or contains(@data-nfse-url,"/retry_email/")]')->length === 0, 'cancelada não pode transmitir nem enviar cópia como nota ativa');
        previewViewCheck($xpath->query('//fieldset[@disabled]')->length === 1 && $xpath->query('//button[@type="submit"]')->length === 0, 'cancelada preserva campos sem editar o documento fiscal');
        previewViewCheck(str_contains($html, 'Evento de cancelamento:') && str_contains($html, 'EVT-EXEMPLO') && str_contains($html, 'Registro do evento:'), 'cancelada exibe identificação real do evento separada do protocolo');
        previewViewCheck(!str_contains($html, 'Protocolo de cancelamento:'), 'identificação de evento não é apresentada como protocolo');
    }
    if ($issued) {
        previewViewCheck(str_contains($html, 'Não informado pelo autorizador.') && str_contains($html, 'Processamento no autorizador:'), $status . ': não inventa protocolo ausente e mostra data oficial');
        previewViewCheck($xpath->query('//button[contains(@data-nfse-url,"/reconcile/8")]')->length === 1, $status . ': atualização de situação está disponível');
        previewViewCheck($xpath->query('//form[@data-nfse-form="recover-key"]')->length === 0, $status . ': documento fiscal confirmado não oferece recuperação de outra chave');
    } else {
        previewViewCheck(!str_contains($html, 'Protocolo de transmissão:'), $status . ': rascunho não possui protocolo fiscal falso');
    }

    foreach (['manage', 'invoice_panel'] as $view) {
        $html = previewViewRender($view, $data);
        $xpath = previewViewDom($html);
        previewViewCheck($xpath->query('//a[@data-nfse-preview and contains(@href,"/empresa-a/admin/nfse_nacional/preview/8")]')->length === 1, $view . '/' . $status . ': acesso direto à prévia no contexto da fatura');
        if ($status === 'cancelled') {
            previewViewCheck(str_contains($html, 'NFS-e 123') && str_contains($html, 'Cancelada'), $view . ': cancelada não volta a ser rotulada como rascunho');
            previewViewCheck($xpath->query('//button[contains(@data-nfse-url,"/transmit/")]')->length === 0, $view . ': nota cancelada não é retransmitida');
        }
    }
}

$pending = $base + ['document' => ['id' => 8, 'invoice_id' => 51, 'client_id' => 12, 'status' => 'uncertain', 'environment' => '2',
    'dps_id' => 'DPS355030821122233300018100001000000000000001', 'attempts' => 1, 'draft_hash' => str_repeat('b', 64),
    'last_error' => 'A consulta não confirmou a autorização.'], 'can_recover_by_key' => true,
    'fiscal_status' => ['label' => 'A consultar', 'transmission_label' => 'Enviada, confirmação pendente',
        'last_check_attempt_at' => '2026-11-11 12:10:00', 'checked_at' => '2026-11-11 12:00:00',
        'check_error' => 'A consulta não confirmou a autorização.']];
foreach (['uncertain', 'processing'] as $pendingStatus) {
    $pending['document']['status'] = $pendingStatus;
    $pendingHtml = previewViewRender('document', $pending);
    $pendingXpath = previewViewDom($pendingHtml);
    $query = $pendingXpath->query('//button[@data-nfse-url="https://crm.example.invalid/empresa-a/admin/nfse_nacional/reconcile/8" and @data-reload="1"]');
    previewViewCheck($query->length === 1 && str_contains($query->item(0)->textContent, 'Consultar transmissão')
        && !$query->item(0)->hasAttribute('data-nfse-emission'), $pendingStatus . ': consulta tem ação própria e não dispara emissão');
    previewViewCheck(str_contains($pendingHtml, 'sem gerar nova numeração') && str_contains($pendingHtml, 'DPS original'),
        $pendingStatus . ': explicação preserva a emissão original durante a recuperação');
    previewViewCheck($pendingXpath->query('//details/summary[text()="Já tenho a chave de acesso"]')->length === 1
        && $pendingXpath->query('//form[@method="post" and @action="https://crm.example.invalid/empresa-a/admin/nfse_nacional/recover_key/8" and @data-nfse-form="recover-key" and @data-reload="1"]')->length === 1,
        $pendingStatus . ': recuperação opcional usa POST no contexto da empresa e recarrega a situação');
    previewViewCheck($pendingXpath->query('//form[@data-nfse-form="recover-key"]//input[@name="access_key" and @maxlength="50" and @minlength="50" and @pattern="[A-Za-z0-9]{50}" and @required]')->length === 1
        && $pendingXpath->query('//form[@data-nfse-form="recover-key"]//input[@type="file"]')->length === 0,
        $pendingStatus . ': chave tem tamanho e formato controlados e não aceita upload de XML');
    previewViewCheck($pendingXpath->query('//form')->length === 2 && $pendingXpath->query('//form//form')->length === 0
        && $pendingXpath->query('//form[@data-nfse-form="recover-key"]//input[@name="draft_hash"]')->length === 0,
        $pendingStatus . ': recuperação e edição do rascunho são formulários separados');
    previewViewCheck(str_contains($pendingHtml, 'Última tentativa de consulta:') && str_contains($pendingHtml, 'Última consulta fiscal:')
        && substr_count($pendingHtml, 'A consulta não confirmou a autorização.') === 1,
        $pendingStatus . ': tentativa, última confirmação e erro aparecem sem mensagens duplicadas');
}

$noKeyRecovery = $pending;
$noKeyRecovery['can_recover_by_key'] = false;
previewViewCheck(previewViewDom(previewViewRender('document', $noKeyRecovery))->query('//form[@data-nfse-form="recover-key"]')->length === 0,
    'indisponibilidade do XML original informada pelo controller oculta a recuperação por chave');
$noKeyRecovery = $pending;
$noKeyRecovery['can']['view'] = false;
$noKeyXpath = previewViewDom(previewViewRender('document', $noKeyRecovery));
previewViewCheck($noKeyXpath->query('//form[@data-nfse-form="recover-key"]|//button[contains(@data-nfse-url,"/reconcile/")]')->length === 0,
    'permissão de consulta controla tanto consulta de transmissão quanto recuperação por chave');
foreach (['dps_id' => '', 'attempts' => 0] as $field => $value) {
    $noKeyRecovery = $pending;
    unset($noKeyRecovery['can_recover_by_key']);
    $noKeyRecovery['document'][$field] = $value;
    previewViewCheck(previewViewDom(previewViewRender('document', $noKeyRecovery))->query('//form[@data-nfse-form="recover-key"]')->length === 0,
        'fallback sem ' . $field . ' não apresenta recuperação de uma transmissão inexistente');
}
$unsafeCheck = $pending;
$unsafeCheck['fiscal_status']['check_error'] = '<script>privateError()</script>';
$unsafeCheck['document']['last_error'] = '<script>privateError()</script>';
$unsafeCheckHtml = previewViewRender('document', $unsafeCheck);
previewViewCheck(previewViewDom($unsafeCheckHtml)->query('//script')->length === 0
    && substr_count($unsafeCheckHtml, '&lt;script&gt;privateError()&lt;/script&gt;') === 1,
    'erro da consulta é escapado e nunca executa conteúdo remoto');

$bad = $data;
$bad['fiscal_status']['label'] = '<script>unsafe()</script>';
$bad['fiscal_status']['explanation'] = '<img src=x onerror="unsafe()">';
$bad['fiscal_status']['protocol'] = '<script>protocol()</script>';
$bad['fiscal_status']['cancellation_event_id'] = '<img src=x onerror="event()">';
$html = previewViewRender('document', $bad);
previewViewCheck(previewViewDom($html)->query('//script|//img')->length === 0 && str_contains($html, '&lt;script&gt;'), 'respostas fiscais e identificadores são escapados');

$unavailable = $data;
$unavailable['danfe_status'] = ['available' => false, 'message' => 'Recursos do módulo indisponíveis.'];
$html = previewViewRender('document', $unavailable);
$xpath = previewViewDom($html);
previewViewCheck($xpath->query('//a[@data-nfse-preview or contains(@href,"/download/8/pdf")]')->length === 0
    && str_contains($html, 'Recursos do módulo indisponíveis.'), 'falta de recursos bloqueia prévia e PDF com motivo legível');
previewViewCheck($xpath->query('//a[contains(@href,"/download/8/xml")]')->length === 1, 'pendência do PDF preserva XML histórico');

foreach (['authorized', 'pending'] as $collection) {
    $html = previewViewRender('manage', ['documents' => [], 'filters' => ['collection' => $collection], 'stats' => ['authorized' => 1, 'cancelled' => 2]]);
    $xpath = previewViewDom($html);
    previewViewCheck($xpath->query('//select[@name="status"]/option[@value="cancelled"]')->length === ($collection === 'authorized' ? 1 : 0), $collection . ': filtro de canceladas fica entre as emitidas');
}

echo 'OK: ' . $checks . " verificações de prévias, situação fiscal, cancelamento e permissões visuais.\n";
