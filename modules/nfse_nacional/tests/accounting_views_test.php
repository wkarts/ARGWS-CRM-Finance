<?php
declare(strict_types=1);

// Render the real views with synthetic data. No e-mail, database or HTTP access.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
function init_head(): void { echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body>'; }
function init_tail(): void {}
function admin_url(string $path = ''): string { return 'https://crm.example.invalid/empresa-a/admin/' . $path; }
function nfse_nacional_settings_url(): string { return admin_url('settings?group=nfse_nacional'); }
$accountingViewPermissions = ['national_view', 'accounting_send', 'settings', 'report_crm', 'report_issued', 'report_received', 'report_package', 'report_export'];
function nfse_nacional_can(string $capability): bool { return in_array($capability, $GLOBALS['accountingViewPermissions'], true); }
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
function accountingViewCheck(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['checks'];
}
function accountingViewRender(string $name, array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    try { require dirname(__DIR__) . '/views/' . $name . '.php'; return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
}
function accountingViewDom(string $html): DOMXPath {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    return new DOMXPath($dom);
}

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('America/Bahia');
try {
    $recipients = [['id' => 'recipient-a', 'name' => 'Contabilidade de demonstração', 'email' => 'contabilidade@example.test']];
    $filters = ['environment' => '1', 'month' => '2026-11', 'direction' => 'both', 'format' => 'both'];
    $base = ['filters' => $filters, 'recipients' => $recipients, 'report' => null, 'can_send' => true, 'history' => []];
    $html = accountingViewRender('accounting', $base); $xp = accountingViewDom($html);
    accountingViewCheck($xp->query('//nav[@aria-label="Documentos da NFS-e"]//a')->length === 4, 'Pacote mensal é quarta guia de Documentos');
    accountingViewCheck($xp->query('//nav[@aria-label="Documentos da NFS-e"]//a[@aria-current="page" and contains(@href,"/accounting")]')->length === 1, 'guia Pacote mensal selecionada');
    accountingViewCheck($xp->query('//form[@method="get"]')->length === 1 && $xp->query('//input[@type="month" and @required]')->length === 1, 'conferência usa GET e competência obrigatória');
    accountingViewCheck($xp->query('//form[@method="post"]')->length === 0, 'sem conferência não oferece download ou envio');
    accountingViewCheck(str_contains($html, 'não bloqueia lançamentos') && str_contains($html, 'Rascunhos não entram') && str_contains($html, 'Sincronize as notas nacionais'), 'instruções delimitam competência, rascunhos e histórico disponível');
    accountingViewCheck(str_contains($html, 'Nenhum envio registrado para esta consulta'), 'histórico inicial vazio informado');

    $base['report'] = ['issued' => 4, 'received' => 3, 'total' => 7, 'cancelled' => 1, 'local' => 2, 'national' => 5, 'missing_competence' => 2, 'fingerprint' => str_repeat('a', 64)];
    $base['history'] = [['id' => 1, 'month' => '2026-11', 'direction' => 'both', 'format' => 'both', 'status' => 'sent', 'created_at' => '2026-11-10T17:00:00Z', 'sent_at' => '2026-11-10T17:01:00Z', 'recipient_label' => 'Contabilidade de demonstração']];
    $html = accountingViewRender('accounting', $base); $xp = accountingViewDom($html);
    $download = $xp->query('//form[contains(@action,"/accounting_download")]')->item(0);
    $send = $xp->query('//form[contains(@action,"/accounting_send")]')->item(0);
    accountingViewCheck($download !== null && !$download->hasAttribute('data-nfse-form') && $download->getAttribute('method') === 'post'
        && str_contains($download->getAttribute('class'), 'nfse-accounting-download'), 'download binário usa POST com transporte próprio e atualização do token CSRF');
    accountingViewCheck($send !== null && $send->getAttribute('data-nfse-form') === 'accounting-send' && $send->getAttribute('data-reload') === '1', 'envio utiliza fluxo assíncrono previsto');
    accountingViewCheck(str_contains($send->getAttribute('data-confirm'), 'arquivos fiscais') && str_contains($send->getAttribute('data-confirm'), '11/2026') && str_contains($send->getAttribute('data-confirm'), 'Produção') && str_contains($send->getAttribute('data-confirm'), 'destinatários selecionados'), 'confirmação informa conteúdo, competência, ambiente e destinatários');
    foreach ([$download, $send] as $form) {
        foreach ($filters + ['fingerprint' => str_repeat('a', 64)] as $name => $value) {
            accountingViewCheck($xp->query('.//input[@type="hidden" and @name="' . $name . '" and @value="' . $value . '"]', $form)->length === 1, 'ações mantêm seleção conferida e fingerprint');
        }
        accountingViewCheck($xp->query('.//input[@name="fixture_csrf"]', $form)->length === 1, 'download e envio usam CSRF nativo');
        accountingViewCheck($xp->query('.//button[@type="submit" and not(@disabled)]', $form)->length === 1, 'pacote conferido permite ação');
    }
    accountingViewCheck($xp->query('//input[@name="recipient_ids[]" and @value="recipient-a" and not(@checked)]')->length === 1, 'destinatários dependem de seleção explícita por identificador cadastrado');
    accountingViewCheck($xp->query('//form[@data-nfse-form="accounting-send"]//input[@name="email" or @name="recipient_email"]')->length === 0, 'envio não aceita endereço livre no formulário');
    accountingViewCheck(str_contains($html, '2 documentos sem competência registrada não foram incluídos'), 'documentos sem competência sinalizados');
    accountingViewCheck(str_contains($html, 'Aceito pelo servidor de e-mail') && str_contains($html, 'não confirma a entrega'), 'histórico não confunde aceitação SMTP com entrega final');
    accountingViewCheck(str_contains($html, '10/11/2026 14:01'), 'histórico converte UTC para fuso da aplicação');
    foreach ($xp->query('//a | //form') as $node) {
        $url = $node->getAttribute($node->tagName === 'a' ? 'href' : 'action');
        accountingViewCheck(str_starts_with($url, admin_url()), 'links e ações preservam empresa ativa');
    }
    accountingViewCheck(!preg_match('/\bNSU\b|endpoint|CNPJWS|BrasilAPI/i', $html), 'página não expõe tecnologia interna');

    $data = $base; $data['can_send'] = false; $xp = accountingViewDom(accountingViewRender('accounting', $data));
    accountingViewCheck($xp->query('//form[contains(@action,"/accounting_send")]')->length === 0 && $xp->query('//form[contains(@action,"/accounting_download")]')->length === 1, 'somente leitura permite baixar sem oferecer envio');
    $GLOBALS['accountingViewPermissions'] = ['national_view'];
    $xp = accountingViewDom(accountingViewRender('accounting', $base));
    accountingViewCheck($xp->query('//form[contains(@action,"/accounting_send")]')->length === 0, 'permissão accounting_send imposta mesmo com flag positiva');
    $GLOBALS['accountingViewPermissions'] = ['national_view', 'accounting_send', 'settings'];
    $data = $base; $data['recipients'] = [];
    $html = accountingViewRender('accounting', $data); $xp = accountingViewDom($html);
    accountingViewCheck(!str_contains($html, 'data-nfse-form="accounting-send"') && str_contains($html, 'Cadastre os destinatários'), 'sem destinatários cadastro orientado sem formulário de envio');
    accountingViewCheck($xp->query('//a[contains(@href,"settings?group=nfse_nacional")]')->length === 1, 'cadastro aponta para configurações nativas da NFS-e');
    foreach (['empty', 'stale'] as $case) {
        $data = $base;
        if ($case === 'empty') { $data['report']['total'] = 0; } else { $data['report']['fingerprint'] = ''; }
        $xp = accountingViewDom(accountingViewRender('accounting', $data));
        accountingViewCheck($xp->query('//form[@method="post"]//button[@type="submit" and not(@disabled)]')->length === 0, 'pacote ' . $case . ' não oferece ação ativa');
    }
    $attack = '<img src=x onerror="unsafe()"><script>unsafe()</script>';
    $data = $base; $data['recipients'][0]['name'] = $attack; $data['recipients'][0]['email'] = $attack; $data['history'][0]['recipient_label'] = $attack;
    $html = accountingViewRender('accounting', $data); $xp = accountingViewDom($html);
    accountingViewCheck($xp->query('//script|//img')->length === 0 && str_contains($html, '&lt;script&gt;'), 'nomes e destinatários de histórico escapados');

    $settings = \NfseNacional\Config::defaults();
    $settings['accounting_recipients'] = [['id' => 'recipient-a', 'name' => $attack, 'email' => 'contabilidade@example.test']];
    $html = accountingViewRender('settings', ['settings' => $settings]); $xp = accountingViewDom($html);
    accountingViewCheck($xp->query('//ul[@aria-label="Configurações da NFS-e"]/li/a[@id="nfse-settings-accounting-tab" and @href="#nfse-settings-accounting" and @role="tab"]')->length === 1, 'Contabilidade permanece disponível como guia nativa nas configurações');
    accountingViewCheck($xp->query('//div[@data-nfse-email-grid]//tbody/tr')->length === 1, 'grid contains saved recipients without fixed empty rows');
    accountingViewCheck($xp->query('//tr[@data-id="recipient-a"]')->length === 1, 'identifier preserved in grid');
    accountingViewCheck($xp->query('//button[@data-nfse-email-add]')->length === 1, 'new recipient action');
    accountingViewCheck($xp->query('//button[@data-email-edit]')->length === 1 && $xp->query('//button[@data-email-delete]')->length === 1, 'edit and remove actions');
    accountingViewCheck($xp->query('//script|//img')->length === 0 && str_contains($html, '&lt;script&gt;'), 'recipient text safely escaped');
    accountingViewCheck(str_contains($html, 'Destinatários de e-mail') && !str_contains($html, 'até 10 destinatários'), 'generic title without fixed recipient limit');
    $GLOBALS['accountingViewPermissions'] = [];
    accountingViewCheck(accountingViewRender('accounting', $base) === '', 'página não renderiza dados sem national_view');
    echo 'OK: ' . $checks . " verificações das views do pacote mensal.\n";
} finally { date_default_timezone_set($previousTimezone); }
