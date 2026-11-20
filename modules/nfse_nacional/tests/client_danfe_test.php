<?php
declare(strict_types=1);

namespace NfseNacional {
    // Explicit diagnostic fixture: this test never loads fonts or generates PDF.
    final class Danfe
    {
        public static int $calls = 0;
        public static array $status = [];
        public static function diagnostics(): array { ++self::$calls; return self::$status; }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('BASEPATH', __DIR__);
    require dirname(__DIR__) . '/autoload.php';
    function admin_url($path = ''): string { return '/admin/' . $path; }
    function nfse_nacional_can($capability): bool { return $capability === 'view'; }
    function nfse_nacional_client_allowed($id): bool { return $id === 12; }
    function nfse_nacional_status_label($status): string { return (string) $status; }
    function form_open($action, $attributes = []): string { return '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '">'; }
    function form_close(): string { return '</form>'; }
    function get_instance(): object {
        return (object) ['security' => new class {
            public function get_csrf_token_name(): string { return 'fixture_csrf'; }
            public function get_csrf_hash(): string { return 'fixture-token'; }
        }];
    }
    function clientDanfeRender(array $invoices, ?array $providedStatus = null): \DOMXPath {
        $client = (object) ['userid' => 12, 'company' => 'Cliente sintético', 'vat' => '52998224725'];
        $profile = $recipients = $contacts = $documents = [];
        if ($providedStatus !== null) { $danfe_status = $providedStatus; }
        ob_start();
        try { require dirname(__DIR__) . '/views/client_tab.php'; $html = (string) ob_get_contents(); }
        finally { ob_end_clean(); }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new \DOMXPath($dom);
    }
    $checks = 0;
    function clientDanfeCheck(bool $ok, string $label): void {
        if (!$ok) { throw new \RuntimeException('FALHOU: ' . $label); }
        ++$GLOBALS['checks'];
    }
    $rows = [
        ['id' => 144, 'number' => '144', 'date' => '2026-11-10', 'total' => '1500.00', 'document_id' => 4, 'document_status' => 'authorized'],
        ['id' => 145, 'number' => '145', 'date' => '2026-11-10', 'total' => '1000.00', 'document_id' => 5, 'document_status' => 'authorized'],
    ];
    \NfseNacional\Danfe::$status = ['available' => false, 'message' => 'Fontes originais pendentes.'];
    $draft = $rows[0]; $draft['document_status'] = 'draft';
    $view = clientDanfeRender([$draft]);
    clientDanfeCheck(\NfseNacional\Danfe::$calls === 1, 'rascunho também consulta recursos para permitir a prévia sem autorização');
    clientDanfeCheck($view->query('//a[contains(@href,"/document/4")]')->length === 1, 'rascunho mantém acesso ao documento');

    \NfseNacional\Danfe::$calls = 0;
    $view = clientDanfeRender($rows);
    clientDanfeCheck(\NfseNacional\Danfe::$calls === 1, 'duas notas autorizadas compartilham uma consulta ao diagnóstico');
    clientDanfeCheck($view->query('//a[contains(@href,"/download/") or contains(@href,"/preview/")]')->length === 0
        && $view->query('//button[@disabled and normalize-space(.)="DANFSe indisponível"]')->length === 2,
        'DANFSe pendente apresenta botões bloqueados sem links de download');
    clientDanfeCheck($view->query('//a[contains(@href,"/document/")]')->length === 2
        && $view->query('//button[@disabled and @title="Fontes originais pendentes."]')->length === 2,
        'pendência preserva acesso às notas e informa o motivo');

    \NfseNacional\Danfe::$calls = 0;
    \NfseNacional\Danfe::$status = ['available' => true, 'message' => 'Disponível.'];
    $view = clientDanfeRender($rows);
    clientDanfeCheck(\NfseNacional\Danfe::$calls === 1
        && $view->query('//a[contains(@href,"/preview/") and normalize-space(.)="Pré-visualizar DANFSe"]')->length === 2,
        'DANFSe disponível mantém prévias por documento e calcula diagnóstico somente uma vez');
    clientDanfeCheck($view->query('//button[@disabled and normalize-space(.)="DANFSe indisponível"]')->length === 0,
        'estado disponível não apresenta botão de pendência');

    \NfseNacional\Danfe::$calls = 0;
    $view = clientDanfeRender($rows, ['available' => false, 'message' => 'Pacote "pendente" <script>exemplo</script>']);
    clientDanfeCheck(\NfseNacional\Danfe::$calls === 0
        && $view->query('//button[@disabled and normalize-space(.)="DANFSe indisponível"]')->length === 2
        && $view->query('//script')->length === 0,
        'diagnóstico já fornecido é reutilizado e sua mensagem permanece texto escapado');
    echo 'OK: ' . $checks . " verificações do DANFSe na aba do cliente, com diagnóstico simulado; sem gerar PDF.\n";
}
