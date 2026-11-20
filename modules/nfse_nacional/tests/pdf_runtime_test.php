<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Each loading order gets a fresh PHP process because class/constant declarations cannot be undone.
// PHP_BIN may point to the same custom PHP wrapper used by tools/test.sh.
$case = $argv[1] ?? '';
if ($case === '') {
    define('BASEPATH', __DIR__ . '/');
    require dirname(__DIR__) . '/autoload.php';
    $diagnostics = NfseNacional\PdfRuntime::diagnostics();
    if (empty($diagnostics['available'])) {
        $pending = !empty($diagnostics['engine_available']) && !is_file(NfseNacional\DanfeFonts::path() . 'manifest.json');
        fwrite(STDERR, ($pending ? 'PENDENTE: ' : 'FALHOU: ') . ($diagnostics['message'] ?? 'Recursos PDF indisponíveis.') . PHP_EOL
            . "Gate de geração e coexistência PDF não executado. Forneça o pacote original antes da homologação.\n");
        exit($pending ? 2 : 1);
    }
    $checks = 0;
    foreach (['host-first', 'module-first', 'missing-package'] as $scenario) {
        $process = proc_open([(string) (getenv('PHP_BIN') ?: PHP_BINARY), __FILE__, $scenario],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar o teste de isolamento do PDF.'); }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $result = json_decode((string) $output, true);
        if ($status !== 0 || !is_array($result) || !isset($result['checks'])) {
            throw new RuntimeException('FALHOU: PDF ' . $scenario . ': ' . trim((string) $output));
        }
        $checks += (int) $result['checks'];
    }
    echo 'OK: ' . $checks . " verificações do motor PDF interno e isolamento do CRM.\n";
    exit;
}

if (!in_array($case, ['host-first', 'module-first', 'missing-package'], true)) {
    throw new InvalidArgumentException('Cenário de teste de PDF desconhecido.');
}
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
function runtimeCheck(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function installHostPdf(): void
{
    class TCPDF { public const OWNER = 'host'; }
    class TCPDF_STATIC {}
    class TCPDF_FONTS {}
    class TCPDF_IMAGES {}
    class TCPDF_COLORS {}
    class TCPDF_FILTERS {}
    class TCPDF2DBarcode {}
    class QRcode {}
    define('K_PATH_FONTS', '/host/fonts/must-not-be-used/');
    define('K_PATH_MAIN', '/host/tcpdf/must-not-be-used/');
    define('K_PATH_CACHE', '/host/cache/must-not-be-used/');
    define('PDF_FONT_NAME_MAIN', 'host-font-canary');
}
function fontSnapshot(string $path): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $files[substr($file->getPathname(), strlen($path))] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($files);
    return $files;
}
function copyIncompleteRuntime(string $source, string $destination): void
{
    mkdir($destination, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        if ($relative === 'fonts/nt008/arialb.ctg.z') { continue; }
        $target = $destination . '/' . $relative;
        if ($file->isDir()) { mkdir($target, 0700); } else { copy($file->getPathname(), $target); }
    }
}

$module = dirname(__DIR__);
define('BASEPATH', __DIR__ . '/');
if ($case === 'missing-package') {
    // A separate, deliberately incomplete package: never remove or rename the installed module's files.
    $temporary = sys_get_temp_dir() . '/nfse-pdf-test-' . bin2hex(random_bytes(8));
    if (!mkdir($temporary . '/libraries/NfseNacional', 0700, true)) { throw new RuntimeException('Falha ao criar fixture isolada.'); }
    try {
        copy($module . '/autoload.php', $temporary . '/autoload.php');
        copy($module . '/libraries/NfseNacional/PdfRuntime.php', $temporary . '/libraries/NfseNacional/PdfRuntime.php');
        copy($module . '/libraries/NfseNacional/DanfeFonts.php', $temporary . '/libraries/NfseNacional/DanfeFonts.php');
        copyIncompleteRuntime($module . '/vendor/pdf-runtime', $temporary . '/vendor/pdf-runtime');
        require $temporary . '/autoload.php';
        $failure = null;
        try { NfseNacional\PdfRuntime::create(); }
        catch (Throwable $expected) { $failure = $expected; }
        runtimeCheck($failure instanceof RuntimeException && preg_match('/módulo|pacote|fonte/iu', $failure->getMessage()) === 1, 'fonte empacotada ausente gera erro explicativo');
        $diagnostics = NfseNacional\PdfRuntime::diagnostics();
        runtimeCheck(empty($diagnostics['available']), 'diagnóstico do pacote incompleto não declara o motor disponível');
        runtimeCheck(!class_exists('TCPDF', false), 'falha não procura nem inicializa TCPDF do CRM');
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($temporary);
    }
} else {
    if ($case === 'host-first') { installHostPdf(); }
    require $module . '/autoload.php';
    $fontPath = NfseNacional\PdfRuntime::fontPath();
    runtimeCheck(is_dir($fontPath) && str_starts_with((string) realpath($fontPath), realpath($module) . DIRECTORY_SEPARATOR), 'fontes pertencem ao módulo');
    $before = fontSnapshot($fontPath);
    NfseNacional\PdfRuntime::load();
    if ($case === 'module-first') {
        runtimeCheck(!class_exists('TCPDF', false) && !class_exists('TCPDF_FONTS', false) && !class_exists('TCPDF2DBarcode', false), 'motor interno não ocupa as classes globais do CRM');
        runtimeCheck(!defined('K_PATH_FONTS') && !defined('K_PATH_MAIN') && !defined('K_PATH_CACHE'), 'motor interno não define diretórios globais de TCPDF');
        installHostPdf();
    }
    $pdf = NfseNacional\PdfRuntime::create();
    runtimeCheck(!($pdf instanceof TCPDF), 'geração usa o motor interno, mesmo com TCPDF global carregado');
    $fonts = NfseNacional\Danfe::fontDiagnostics();
    runtimeCheck($fonts['fonts_available'] && $fonts['exact_fonts'] && $fonts['label_font'] === 'arial' && $fonts['content_font'] === 'microsoftsansserif', 'gate de coexistência exige o conjunto de fontes originais');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont($fonts['label_font'], 'B', 12);
    $pdf->Cell(100, 8, 'Teste de emissão');
    $pdf->SetFont($fonts['content_font'], '', 10);
    $pdf->Ln(10);
    $pdf->Cell(100, 8, 'Prestação de serviço — R$ 1.000,00');
    $pdf->write2DBarcode('https://www.nfse.gov.br/ConsultaPublica', 'QRCODE,M', 10, 40, 20, 20);
    $bytes = $pdf->Output('runtime-test.pdf', 'S');
    runtimeCheck(is_string($bytes) && str_starts_with($bytes, '%PDF-') && strlen($bytes) > 5000, 'PDF e QR gerados sem usar o motor ou as fontes globais');
    runtimeCheck(TCPDF::OWNER === 'host' && K_PATH_FONTS === '/host/fonts/must-not-be-used/' && K_PATH_MAIN === '/host/tcpdf/must-not-be-used/' && K_PATH_CACHE === '/host/cache/must-not-be-used/' && PDF_FONT_NAME_MAIN === 'host-font-canary', 'classes e configuração do host permanecem intactas');
    runtimeCheck($before === fontSnapshot($fontPath), 'geração não cria nem altera arquivos na pasta de fontes do módulo');
}
echo json_encode(['checks' => $checks], JSON_THROW_ON_ERROR);
