<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);

function init_head(): void { echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>'; }
function init_tail(): void {}
function admin_url(string $path = ''): string { return 'https://crm.example.invalid/company-a/admin/' . $path; }

$checks = 0;
function logViewCheck(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function renderLogView(string $name, array $data): string
{
    extract($data, EXTR_SKIP);
    ob_start();
    try { require dirname(__DIR__) . '/views/' . $name . '.php'; return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
}
function logViewDom(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $document->loadHTML($html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    return new DOMXPath($document);
}

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('America/Bahia');
try {
    $attack = '<img src=x onerror="alert(1)"><script>alert(1)</script>';
    $record = [
        'id' => 'evt-a', 'created_at' => '2026-11-10T17:26:00Z', 'level' => 'error',
        'operation' => 'municipality.lookup', 'message' => $attack, 'correlation_id' => 'corr-a',
        'environment' => '2', 'document_id' => 8, 'invoice_id' => 51, 'staff_id' => 1,
        'context' => ['http_status' => 404, 'duration_ms' => 82, 'operation_status' => 'error', 'response' => ['message' => $attack]],
    ];
    $filters = ['date_from' => '2026-11-10', 'http_status' => '404', 'search' => $attack,
        'tenant' => 'company-b', 'next' => 'https://attacker.invalid', 'page' => 2];
    $operations = ['municipality.lookup' => 'Consulta de convênio'];
    $base = ['record' => $record, 'filters' => $filters, 'operations' => $operations, 'export_limit' => 1000,
        'report' => ['rows' => [$record], 'page' => 2, 'pages' => 3, 'per_page' => 1, 'total' => 3,
            'has_more' => true, 'storage' => 'ok', 'retention_days' => 30]];

    foreach (['logs', 'log_detail'] as $name) {
        $html = renderLogView($name, $base);
        $xpath = logViewDom($html);
        logViewCheck($xpath->query('//script|//img')->length === 0, $name . ': diagnóstico não cria HTML executável');
        logViewCheck(str_contains($html, '&lt;img') && str_contains($html, '&lt;script'), $name . ': conteúdo malicioso permanece texto escapado');
        logViewCheck(str_contains($html, '10/11/2026 14:26'), $name . ': UTC é convertido para o fuso da aplicação');
        foreach ($xpath->query('//a') as $link) {
            $href = $link->getAttribute('href');
            logViewCheck(str_starts_with($href, 'https://crm.example.invalid/company-a/admin/'), $name . ': link permanece no contexto da empresa');
            logViewCheck(!str_contains($href, 'company-b') && !str_contains($href, 'attacker.invalid'), $name . ': query rejeita parâmetros de empresa e redirecionamento');
        }
        if ($name === 'logs') {
            logViewCheck($xpath->query('//a[@rel="prev"]')->length === 1 && $xpath->query('//a[@rel="next"]')->length === 1, 'paginação oferece anterior e próxima quando aplicáveis');
            logViewCheck(str_contains($html, 'logs_download?date_from=2026-11-10&amp;http_status=404'), 'exportação preserva os filtros');
            logViewCheck(str_contains($html, 'logs_diagnostic?date_from=2026-11-10&amp;http_status=404'), 'relatorio estruturado mantem filtros');
            logViewCheck(str_contains($html, 'até 1000 registros'), 'limite de exportação é informado');
            logViewCheck($xpath->query('//form[@method="get"]')->length === 1, 'filtro usa formulário nativo de leitura');
        } else {
            logViewCheck($xpath->query('//pre')->length === 1, 'detalhe usa bloco de contexto escapado');
            logViewCheck(str_contains($html, 'logs_diagnostic?event_id=evt-a'), 'detalhe oferece download do diagnostico vinculado a operacao');
        }
    }

    $html = renderLogView('logs', ['report' => ['rows' => [], 'storage' => 'unavailable', 'truncated' => true]]);
    logViewCheck(str_contains($html, 'Não foi possível ler o histórico'), 'falha de armazenamento é explicada');
    logViewCheck(str_contains($html, 'limite de leitura'), 'consulta parcial é explicada');
    logViewCheck(str_contains($html, 'Nenhum registro neste filtro'), 'estado vazio é apresentado');

    foreach (['started' => 'Iniciada', 'success' => 'Concluída', 'error' => 'Falha', 'uncertain' => 'Resultado incerto', 'not_found' => 'Não encontrado', 'partial' => 'Resultado parcial'] as $status => $label) {
        $statusRecord = $record;
        $statusRecord['context']['operation_status'] = $status;
        $data = $base;
        $data['record'] = $statusRecord;
        $data['report']['rows'] = [$statusRecord];
        foreach (['logs', 'log_detail'] as $name) {
            $xpath = logViewDom(renderLogView($name, $data));
            $labels = [];
            foreach ($xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " label ")]') as $node) { $labels[] = trim($node->textContent); }
            logViewCheck(in_array($label, $labels, true), $name . ': estado ' . $status . ' traduzido para português');
        }
    }

    $firstPage = $base;
    $firstPage['report']['page'] = $firstPage['report']['pages'] = 1;
    $firstPage['report']['has_more'] = false;
    $xpath = logViewDom(renderLogView('logs', $firstPage));
    logViewCheck($xpath->query('//a[@rel="prev" or @rel="next"]')->length === 0, 'página única não oferece navegação inexistente');
    logViewCheck($xpath->query('//span[@aria-disabled="true"]')->length === 2, 'limites da paginação ficam desabilitados');

    $missingDate = $base;
    $missingDate['record']['created_at'] = '';
    $missingDate['record']['context'] = [];
    $html = renderLogView('log_detail', $missingDate);
    logViewCheck(str_contains($html, '<p>—<span class="small text-muted">'), 'data ausente não é substituída pela hora atual');
    logViewCheck(str_contains($html, 'Este registro não contém detalhes adicionais.'), 'contexto vazio possui mensagem própria');

    $structuredFilters = $base;
    $structuredFilters['filters']['search'] = ['<script>not scalar</script>'];
    $html = renderLogView('logs', $structuredFilters);
    logViewCheck(!str_contains($html, 'not scalar'), 'filtro estruturado não é renderizado nem reutilizado em links');
    logViewCheck(logViewDom($html)->query('//table[contains(concat(" ", normalize-space(../@class), " "), " table-responsive ")]')->length === 1, 'tabela mantém contêiner responsivo nativo');
} finally {
    date_default_timezone_set($previousTimezone);
}

echo 'OK: ' . $checks . " verificações das views de logs, escape, filtros, estados, paginação e horário.\n";
