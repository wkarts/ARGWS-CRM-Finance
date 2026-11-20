<?php
declare(strict_types=1);

// Actual PHP views: no database, certificate, HTTP request or fiscal operation.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/autoload.php';
function init_head(): void { echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'; }
function init_tail(): void {}
function admin_url(string $path = ''): string { return 'https://crm.example.invalid/empresa-a/admin/' . $path; }
function nfse_nacional_settings_url(): string { return admin_url('settings?group=nfse_nacional'); }
$viewPermissions = ['view', 'national_view', 'national_sync', 'logs', 'settings', 'report_crm', 'report_issued', 'report_received', 'report_package', 'report_export'];
function nfse_nacional_can(string $capability): bool { return in_array($capability, $GLOBALS['viewPermissions'], true); }
function nfse_nacional_status_label(string $status): string { return ['draft' => 'Rascunho', 'authorized' => 'Autorizada', 'cancelled' => 'Cancelada'][$status] ?? $status; }
function form_open(string $action, array $attributes = []): string {
    $html = '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '"';
    foreach ($attributes as $key => $value) { $html .= ' ' . htmlspecialchars((string) $key, ENT_QUOTES) . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"'; }
    return $html . '><input type="hidden" name="fixture_csrf" value="fixture-token">';
}
function form_close(): string { return '</form>'; }
function &get_instance() {
    static $ci;
    if (!$ci) {
        $ci = (object) ['security' => new class {
            public function get_csrf_token_name(): string { return 'fixture_csrf'; }
            public function get_csrf_hash(): string { return 'fixture-token'; }
        }];
    }
    return $ci;
}
$checks = 0;
function nationalViewCheck(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function nationalViewRender(string $name, array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    try { require dirname(__DIR__) . '/views/' . $name . '.php'; return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
}
function nationalViewDom(string $html): DOMXPath {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    return new DOMXPath($dom);
}
function nationalViewQuery(DOMXPath $xpath, string $query): DOMNodeList { return $xpath->query($query); }

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('America/Bahia');
try {
    $attack = '<img src=x onerror="unsafe()"><script>unsafe()</script>';
    $row = ['id' => 8, 'environment' => '1', 'direction' => 'issued', 'status' => 'authorized',
        'nfse_number' => '123', 'access_key' => str_repeat('1', 50),
        'provider_name' => 'Prestador de demonstração', 'provider_tax_id' => '11222333000181',
        'recipient_name' => 'Tomador de demonstração', 'recipient_tax_id' => '52998224725',
        'service_amount' => '250.00', 'issued_at' => '2026-11-10T17:00:00Z', 'competence' => '2026-11-10'];
    $base = ['direction' => 'issued', 'filters' => ['environment' => '1', 'date_from' => '2026-11-01', 'date_to' => '2026-11-10', 'search' => 'Serviço', 'status' => 'authorized', 'company' => 'empresa-b', 'next' => 'https://attacker.invalid'],
        'report' => ['rows' => [$row], 'total' => 120, 'page' => 2, 'pages' => 3, 'per_page' => 50],
        'sync' => ['last_success_at' => '2026-11-10T17:15:00Z', 'last_attempt_at' => '2026-11-10T17:15:00Z', 'last_error' => '', 'has_more' => true],
        'can_sync' => true, 'configuration_ready' => true];

    foreach (['issued', 'received'] as $direction) {
        $data = $base; $data['direction'] = $direction; $data['report']['rows'][0]['direction'] = $direction;
        $html = nationalViewRender('national', $data); $xp = nationalViewDom($html);
        $tabLinks = nationalViewQuery($xp, '//nav[@aria-label="Documentos da NFS-e"]//a');
        nationalViewCheck($tabLinks->length === 4, $direction . ': quatro guias principais consistentes');
        nationalViewCheck(nationalViewQuery($xp, '//nav[@aria-label="Documentos da NFS-e"]//a[@aria-current="page" and contains(@href,"national/' . $direction . '")]')->length === 1, $direction . ': guia correta selecionada');
        nationalViewCheck(nationalViewQuery($xp, '//nav[@aria-label="Documentos da NFS-e"]//i')->length === 0, 'guias não acrescentam ícones desnecessários');
        nationalViewCheck(nationalViewQuery($xp, '//form[@method="get"]')->length === 1 && nationalViewQuery($xp, '//form[@method="post"]')->length === 1, 'filtro GET e sincronização POST separados');
        nationalViewCheck(nationalViewQuery($xp, '//form[@data-nfse-form="national-sync" and @data-reload="1"]//input[@name="environment" and @value="1"]')->length === 1, 'sincronização envia o ambiente aplicado');
        nationalViewCheck(nationalViewQuery($xp, '//form[@data-nfse-form="national-sync"]//input[@name="fixture_csrf"]')->length === 1, 'formulário utiliza proteção CSRF nativa');
        nationalViewCheck(nationalViewQuery($xp, '//form[@data-nfse-form="national-sync"]//button[not(@disabled)]')->length === 1, 'sincronização disponível quando configuração e permissão permitem');
        nationalViewCheck(str_contains($html, 'Iniciar sincronização') && str_contains($html, 'busca só termina quando o ADN confirmar que não há novos documentos'), 'consulta parcial oferece continuidade sem prometer documentos adicionais');
        nationalViewCheck(str_contains($html, '10/11/2026 14:00') && str_contains($html, '10/11/2026 14:15'), 'datas UTC convertidas para fuso da aplicação');
        $counterparty = $direction === 'issued' ? 'Tomador de demonstração' : 'Prestador de demonstração';
        nationalViewCheck(str_contains($html, $counterparty), $direction . ': contraparte correta na grade');
        nationalViewCheck(nationalViewQuery($xp, '//a[contains(@href,"/national_preview/8") and @target="_blank" and @rel="noopener noreferrer"]')->length === 1, 'prévia abre PDF da rota protegida');
        nationalViewCheck(nationalViewQuery($xp, '//a[contains(@href,"/national_download/8/pdf")]')->length === 1 && nationalViewQuery($xp, '//a[contains(@href,"/national_download/8/xml")]')->length === 1, 'downloads XML e PDF disponíveis');
        nationalViewCheck(nationalViewQuery($xp, '//a[@href="https://crm.example.invalid/empresa-a/admin/nfse_nacional/fiscal_events/national/8"]')->length === 1,
            $direction . ': evento fiscal usa a origem nacional e o identificador do documento arquivado');
        nationalViewCheck(nationalViewQuery($xp, '//a[@rel="prev"]')->length === 1 && nationalViewQuery($xp, '//a[@rel="next"]')->length === 1, 'paginação anterior e próxima');
        foreach (nationalViewQuery($xp, '//a[@rel="prev" or @rel="next"]') as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $params);
            nationalViewCheck($params['environment'] === '1' && $params['date_from'] === '2026-11-01' && $params['date_to'] === '2026-11-10' && $params['search'] === 'Serviço' && $params['status'] === 'authorized', 'paginação preserva filtros aplicados');
        }
        foreach (nationalViewQuery($xp, '//a') as $link) {
            $href = $link->getAttribute('href');
            nationalViewCheck(str_starts_with($href, admin_url()) && !str_contains($href, 'empresa-b') && !str_contains($href, 'attacker.invalid'), 'navegação preserva contexto da empresa e rejeita query estranha');
        }
        nationalViewCheck(!str_contains($html, '/transmit/') && !str_contains($html, '/send/') && !str_contains($html, '/invoices/invoice'), 'consulta nacional não oferece emissão, e-mail nem faturas artificiais');
        nationalViewCheck(!preg_match('/cnpjws|brasilapi|sefin\.nfse\.gov/i', $html), 'interface não expõe detalhes técnicos da consulta');
    }

    $data = $base;
    foreach (['nfse_number','access_key','provider_name','provider_tax_id','recipient_name','recipient_tax_id'] as $key) { $data['report']['rows'][0][$key] = $attack; }
    $data['filters']['search'] = $attack; $data['sync']['last_error'] = $attack;
    $html = nationalViewRender('national', $data); $xp = nationalViewDom($html);
    nationalViewCheck(nationalViewQuery($xp, '//script|//img')->length === 0 && str_contains($html, '&lt;script&gt;'), 'conteúdo importado, filtros e erros não criam HTML executável');
    nationalViewCheck(str_contains($html, 'Os documentos já sincronizados permanecem disponíveis'), 'falha de atualização preserva clareza sobre histórico local');

    $empty = $base; $empty['report'] = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]; $empty['sync'] = [];
    $html = nationalViewRender('national', $empty); $xp = nationalViewDom($html);
    nationalViewCheck(str_contains($html, 'Documentos ainda não sincronizados') && str_contains($html, 'A disponibilidade do histórico depende do ambiente nacional.'), 'sem primeira sincronização não afirma inexistência de notas');
    nationalViewCheck(nationalViewQuery($xp, '//a[@rel="prev" or @rel="next"]')->length === 0 && nationalViewQuery($xp, '//span[@aria-disabled="true"]')->length === 2, 'página única não navega para páginas inexistentes');
    $empty['sync']['last_success_at'] = '2026-11-10T17:15:00Z';
    nationalViewCheck(str_contains(nationalViewRender('national', $empty), 'Nenhum documento sincronizado corresponde aos filtros'), 'histórico vazio qualifica resultado aos documentos sincronizados e filtros');

    $data = $base; $data['configuration_ready'] = false;
    $html = nationalViewRender('national', $data); $xp = nationalViewDom($html);
    nationalViewCheck(nationalViewQuery($xp, '//form[@data-nfse-form="national-sync"]//button[@disabled]')->length === 1 && str_contains($html, 'Configure os dados do emitente'), 'sem configuração sincronização desabilitada com orientação');
    $data = $base; $data['sync']['next_allowed_at'] = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
    $html = nationalViewRender('national', $data); $xp = nationalViewDom($html);
    nationalViewCheck(nationalViewQuery($xp, '//form[@data-nfse-form="national-sync"]//button[not(@disabled)]')->length === 1 && str_contains($html, 'rotina continuará automaticamente'), 'intervalo de consulta respeitado visualmente');
    $data = $base; $data['can_sync'] = false;
    $xp = nationalViewDom(nationalViewRender('national', $data));
    nationalViewCheck(nationalViewQuery($xp, '//form[@method="post"]')->length === 0 && nationalViewQuery($xp, '//a[contains(@href,"/national_download/8/xml")]')->length === 1, 'permissão somente leitura conserva download e não mostra sincronização');
    $data = $base; $data['report']['rows'][0]['status'] = 'cancelled';
    $html = nationalViewRender('national', $data);
    nationalViewCheck(str_contains($html, 'label-danger') && str_contains($html, 'Cancelada') && str_contains($html, '/national_preview/8'), 'nota cancelada permanece consultável com situação explícita');
    $data['report']['rows'][0]['status'] = 'unknown';
    $html = nationalViewRender('national', $data);
    nationalViewCheck(str_contains($html, 'Situação a conferir') && nationalViewQuery(nationalViewDom($html), '//a[contains(@href,"/fiscal_events/")]')->length === 0,
        'estado desconhecido não é apresentado como autorização nem oferece eventos fiscais');

    $GLOBALS['viewPermissions'] = ['national_view', 'report_issued', 'report_received', 'report_export'];
    $xp = nationalViewDom(nationalViewRender('national', $base));
    nationalViewCheck(nationalViewQuery($xp, '//a[contains(@href,"/fiscal_events/")]')->length === 0
        && nationalViewQuery($xp, '//a[contains(@href,"/national_download/8/xml")]')->length === 1,
        'consulta nacional sem permissão view conserva download e não oferece eventos fiscais');
    $GLOBALS['viewPermissions'] = ['view', 'national_view', 'national_sync', 'logs', 'settings', 'report_crm', 'report_issued', 'report_received', 'report_package', 'report_export'];

    $manage = ['documents' => [], 'filters' => ['collection' => 'pending'], 'stats' => []];
    $xp = nationalViewDom(nationalViewRender('manage', $manage));
    nationalViewCheck(nationalViewQuery($xp, '//nav[@aria-label="Documentos da NFS-e"]//a[@aria-current="page" and not(contains(@href,"national/"))]')->length === 1, 'Documentos CRM seleciona No CRM');
    nationalViewCheck(nationalViewQuery($xp, '//input[@name="collection" and @value="pending"]')->length === 1 && nationalViewQuery($xp, '//a[contains(@href,"collection=authorized")]')->length === 1, 'filtros internos do CRM preservados');
    $GLOBALS['viewPermissions'] = ['view', 'report_crm'];
    $xp = nationalViewDom(nationalViewRender('manage', $manage));
    nationalViewCheck(nationalViewQuery($xp, '//nav[@aria-label="Documentos da NFS-e"]//a')->length === 1, 'sem national_view não exibe guias nacionais');
    nationalViewCheck(nationalViewRender('national', $base) === '', 'view nacional não renderiza dados sem permissão');
    echo 'OK: ' . $checks . " verificações das views de documentos nacionais.\n";
} finally { date_default_timezone_set($previousTimezone); }
