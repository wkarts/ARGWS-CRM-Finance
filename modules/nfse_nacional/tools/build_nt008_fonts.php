<?php
declare(strict_types=1);

/** Build-time only. Supply the original fonts with permission for their server/application use. */
if (PHP_SAPI !== 'cli' || count($argv) !== 3 || !is_dir($argv[1]) || file_exists($argv[2])) {
    fwrite(STDERR, "Uso: php build_nt008_fonts.php DIRETORIO_FONTES_ORIGINAIS NOVO_DIRETORIO_SAIDA\n");
    exit(1);
}
require_once dirname(__DIR__) . '/libraries/NfseNacional/PdfRuntime.php';
require_once dirname(__DIR__) . '/libraries/NfseNacional/DanfeFonts.php';
use NfseNacional\DanfeFonts;
use NfseNacional\PdfRuntime;

$sources = []; $manifest = ['format' => 1, 'fonts' => []]; $working = ''; $publish = ''; $status = 0;
try {
    foreach (DanfeFonts::REQUIRED as $key => $required) {
        $source = rtrim($argv[1], '/\\') . '/' . $required['file'];
        if (!is_file($source) || filesize($source) > 8388608) { throw new RuntimeException('Arquivo original ausente ou inválido: ' . $required['file']); }
        $sources[$key] = (string) file_get_contents($source);
        $manifest['fonts'][$key] = DanfeFonts::inspectSource($sources[$key], $key);
    }
    PdfRuntime::load();
    $working = rtrim(sys_get_temp_dir(), '/\\') . '/nfse-fonts-build-' . bin2hex(random_bytes(12));
    if (!mkdir($working, 0700) || !mkdir($working . '/output', 0700)) { throw new RuntimeException('Não foi possível preparar a conversão das fontes.'); }
    foreach ($sources as $key => $bytes) {
        $source = $working . '/' . $key . '.ttf';
        if (file_put_contents($source, $bytes, LOCK_EX) !== strlen($bytes)) { throw new RuntimeException('Falha ao preparar a fonte.'); }
        chmod($source, 0600);
        $font = new \NfseNacional\PdfVendor\Com\Tecnick\Pdf\Font\Import($source, $working . '/output/', 'TrueTypeUnicode');
        if ($font->getFontName() !== $key) { throw new RuntimeException('Nome inesperado na fonte convertida: ' . $key); }
        foreach (['json', 'z', 'ctg.z'] as $extension) {
            $name = $key . '.' . $extension;
            $manifest['fonts'][$key]['resources'][$name] = hash_file('sha256', $working . '/output/' . $name);
        }
    }
    file_put_contents($working . '/output/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    DanfeFonts::validate($working . '/output');
    $destination = rtrim($argv[2], '/\\');
    $parent = dirname($destination);
    if (!is_dir($parent) && !mkdir($parent, 0755, true)) { throw new RuntimeException('Não foi possível preparar o diretório final das fontes.'); }
    $publish = $parent . '/.nfse-fonts-publish-' . bin2hex(random_bytes(12));
    if (!mkdir($publish, 0700)) { throw new RuntimeException('Não foi possível preparar o pacote final das fontes.'); }
    foreach (glob($working . '/output/*') ?: [] as $file) {
        $target = $publish . '/' . basename($file);
        if (!copy($file, $target) || !chmod($target, 0644)) { throw new RuntimeException('Falha ao concluir o pacote das fontes.'); }
    }
    DanfeFonts::validate($publish);
    if (file_exists($destination) || is_link($destination) || !chmod($publish, 0755) || !rename($publish, $destination)) {
        throw new RuntimeException('Não foi possível concluir o diretório final das fontes. O destino deve estar livre.');
    }
    $publish = '';
    fwrite(STDOUT, "Arial regular/negrito e Microsoft Sans Serif preparadas e verificadas.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); $status = 1;
} finally {
    if ($publish !== '' && is_dir($publish)) {
        foreach (glob($publish . '/*') ?: [] as $file) { unlink($file); }
        rmdir($publish);
    }
    if ($working !== '' && is_dir($working)) {
        foreach (glob($working . '/output/*') ?: [] as $file) { unlink($file); }
        if (is_dir($working . '/output')) { rmdir($working . '/output'); }
        foreach (glob($working . '/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($working);
    }
}
exit($status);
