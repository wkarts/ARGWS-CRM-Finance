<?php
declare(strict_types=1);

// Negative guards only. These checks do not homologate a font, PDF or licensed source.
// Set PHP_BIN when invoking through a custom PHP wrapper; subprocesses use the same runtime.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__ . '/');

use NfseNacional\Danfe;
use NfseNacional\DanfeFonts;
use NfseNacional\PdfRuntime;

$checks = 0;
function fontCheck(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function fontRefuses(callable $operation, string $label, string $reason = ''): void
{
    try { $operation(); }
    catch (RuntimeException | InvalidArgumentException $expected) {
        if ($reason !== '' && !str_contains($expected->getMessage(), $reason)) { throw new RuntimeException('FALHOU: ' . $label . '; recusa inesperada: ' . $expected->getMessage()); }
        fontCheck(true, $label); return;
    }
    throw new RuntimeException('FALHOU: ' . $label);
}
function removeFontFixture(string $path): void
{
    if (!is_dir($path)) { return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($path);
}
function copyRuntimeWithoutFonts(string $source, string $destination): void
{
    mkdir($destination, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        if ($relative === 'fonts' || str_starts_with($relative, 'fonts/')) { continue; }
        $target = $destination . '/' . $relative;
        if ($file->isDir()) { mkdir($target, 0700); } else { copy($file->getPathname(), $target); }
    }
    mkdir($destination . '/fonts', 0700);
}
function negativeFontMetadata(string $family, string $postscript, bool $bold = false, int $embedding = 0): string
{
    // An adversarial metadata structure, with no glyphs and never converted or rendered.
    $family = mb_convert_encoding($family, 'UTF-16BE', 'UTF-8');
    $postscript = mb_convert_encoding($postscript, 'UTF-16BE', 'UTF-8');
    $names = pack('nnn', 0, 2, 30)
        . pack('nnnnnn', 3, 1, 0x0409, 1, strlen($family), 0)
        . pack('nnnnnn', 3, 1, 0x0409, 6, strlen($postscript), strlen($family)) . $family . $postscript;
    $os2 = str_repeat("\0", 64);
    $os2 = substr_replace($os2, pack('n', $embedding), 8, 2);
    $os2 = substr_replace($os2, pack('n', $bold ? 0x20 : 0), 62, 2);
    return "\0\1\0\0" . pack('nnnn', 2, 0, 0, 0)
        . 'OS/2' . pack('NNN', 0, 44, 64)
        . 'name' . pack('NNN', 0, 108, strlen($names)) . $os2 . $names;
}
function negativeFontManifest(): array
{
    return ['format' => 1, 'fonts' => ['arial' => [
        'family' => 'Arial', 'bold' => false, 'postscript_name' => 'ArialMT',
        'source_sha256' => str_repeat('a', 64), 'embedding_flags' => 0, 'resources' => [],
    ]]];
}
function writeNegativeFontManifest(string $directory, array $manifest): void
{
    file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
}

$module = dirname(__DIR__);
if (($argv[1] ?? '') === 'missing-fonts') {
    // A separate package in a fresh process: installed files are never hidden, removed or replaced.
    $temporary = sys_get_temp_dir() . '/nfse-missing-fonts-' . bin2hex(random_bytes(8));
    mkdir($temporary . '/libraries/NfseNacional', 0700, true);
    try {
        copy($module . '/autoload.php', $temporary . '/autoload.php');
        foreach (['PdfRuntime', 'DanfeFonts', 'Danfe', 'NfseDocument', 'XmlSigner'] as $name) {
            copy($module . '/libraries/NfseNacional/' . $name . '.php', $temporary . '/libraries/NfseNacional/' . $name . '.php');
        }
        copyRuntimeWithoutFonts($module . '/vendor/pdf-runtime', $temporary . '/vendor/pdf-runtime');
        require $temporary . '/autoload.php';
        PdfRuntime::load();
        fontCheck(!class_exists('TCPDF', false) && !defined('K_PATH_FONTS'), 'carregar motor isolado não ocupa nomes ou diretórios do host');
        $GLOBALS['host_pdf_used'] = false;
        class TCPDF { public function __construct() { $GLOBALS['host_pdf_used'] = true; } }
        define('K_PATH_FONTS', '/host/canary/fonts/');
        $diagnostics = PdfRuntime::diagnostics();
        fontCheck($diagnostics['engine_available'] && !$diagnostics['fonts_available'] && !$diagnostics['available'], 'diagnóstico distingue motor disponível de fontes originais ausentes');
        $danfe = Danfe::diagnostics();
        fontCheck($danfe['tcpdf_available'] && !$danfe['available'] && !$danfe['exact_fonts'] && str_contains($danfe['message'], 'originais'), 'DANFSe não declara disponibilidade ou tipografia exata sem o pacote original');
        fontRefuses(static fn() => PdfRuntime::create(), 'criação do motor de renderização recusa ausência das originais', 'originais');
        $key = str_repeat('1', 50);
        $dps = 'DPS35503082' . '11222333000181' . '00001' . str_pad('1', 15, '0', STR_PAD_LEFT);
        // Structurally sufficient for the parser; no fiscal authorization is asserted.
        $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS' . $key . '"><nNFSe>1</nNFSe><cStat>100</cStat><dhProc>2026-11-10T12:00:00-03:00</dhProc><DPS><infDPS Id="' . $dps . '"><tpAmb>2</tpAmb><dhEmi>2026-11-10T12:00:00-03:00</dhEmi><serie>1</serie><nDPS>1</nDPS></infDPS></DPS></infNFSe></NFSe>';
        fontRefuses(static fn() => Danfe::render($xml), 'render interrompe sem gerar PDF substituto', 'originais');
        fontCheck(!$GLOBALS['host_pdf_used'] && K_PATH_FONTS === '/host/canary/fonts/', 'bloqueio não recorre ao TCPDF ou ao diretório de fontes do CRM');
        fontCheck((glob($temporary . '/vendor/pdf-runtime/fonts/*') ?: []) === [], 'diagnóstico e recusa não convertem, baixam ou gravam fontes em runtime');
    } finally { removeFontFixture($temporary); }
    echo json_encode(['checks' => $checks], JSON_THROW_ON_ERROR); exit;
}

require $module . '/autoload.php';
$temporary = sys_get_temp_dir() . '/nfse-font-guards-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
try {
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'ausência do manifesto exige as fontes originais', 'originais');
    file_put_contents($temporary . '/manifest.json', '{not-json');
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'manifesto corrompido é recusado', 'pacote de fontes');
    file_put_contents($temporary . '/manifest.json', str_repeat(' ', 32769));
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'manifesto além do limite é recusado antes de interpretar', 'pacote de fontes');
    writeNegativeFontManifest($temporary, ['format' => 2, 'fonts' => []]);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'formato de manifesto desconhecido é recusado', 'pacote de fontes');
    $manifest = negativeFontManifest(); $manifest['fonts']['arial']['family'] = 'Liberation Sans';
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'manifesto não aceita família substituta como Arial', 'identificação');
    $manifest = negativeFontManifest(); $manifest['fonts']['arial']['bold'] = true;
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'manifesto não confunde regular com negrito', 'identificação');
    $manifest = negativeFontManifest(); $manifest['fonts']['arial']['source_sha256'] = 'sem-hash';
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'manifesto exige hash do original', 'identificação');
    writeNegativeFontManifest($temporary, negativeFontManifest());
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'manifesto sem recursos não libera a fonte', 'incompletas');

    $manifest = negativeFontManifest();
    file_put_contents($temporary . '/arial.json', '{}');
    $manifest['fonts']['arial']['resources']['arial.json'] = str_repeat('0', 64);
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'recurso alterado não passa pelo hash registrado', 'incompletas');
    $descriptor = ['type' => 'Type1', 'file' => 'arial.z', 'ctg' => 'arial.ctg.z', 'name' => 'ArialMT'];
    file_put_contents($temporary . '/arial.json', json_encode($descriptor, JSON_THROW_ON_ERROR));
    file_put_contents($temporary . '/arial.z', 'NOT-A-FONT');
    file_put_contents($temporary . '/arial.ctg.z', 'NOT-A-MAP');
    foreach (['json', 'z', 'ctg.z'] as $extension) { $manifest['fonts']['arial']['resources']['arial.' . $extension] = hash_file('sha256', $temporary . '/arial.' . $extension); }
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'descritor de outro formato não é aceito mesmo com hashes coerentes', 'recursos das fontes');

    $wrongFont = negativeFontMetadata('Família diferente para teste negativo', 'DifferentFont');
    $descriptor['type'] = 'TrueTypeUnicode';
    file_put_contents($temporary . '/arial.json', json_encode($descriptor, JSON_THROW_ON_ERROR));
    $manifest['fonts']['arial']['resources']['arial.json'] = hash_file('sha256', $temporary . '/arial.json');
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'programa comprimido inválido não é aceito por hashes do contêiner', 'programa da fonte');
    file_put_contents($temporary . '/arial.z', gzcompress($wrongFont));
    $manifest['fonts']['arial']['resources']['arial.z'] = hash_file('sha256', $temporary . '/arial.z');
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'bytes descompactados precisam coincidir com hash do original', 'programa da fonte');
    $manifest['fonts']['arial']['source_sha256'] = hash('sha256', $wrongFont);
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'identidade declarada não mascara família diferente dentro do programa comprimido', 'original esperada');
    $oversized = str_repeat('X', 8388609);
    file_put_contents($temporary . '/arial.z', gzcompress($oversized));
    $manifest['fonts']['arial']['resources']['arial.z'] = hash_file('sha256', $temporary . '/arial.z');
    $manifest['fonts']['arial']['source_sha256'] = hash('sha256', $oversized);
    writeNegativeFontManifest($temporary, $manifest); unset($oversized);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'descompressão é limitada mesmo quando o arquivo comprimido é pequeno', 'programa da fonte');
    $restrictedMetadata = negativeFontMetadata('Arial', 'ArialMT');
    file_put_contents($temporary . '/arial.z', gzcompress($restrictedMetadata));
    $manifest['fonts']['arial']['resources']['arial.z'] = hash_file('sha256', $temporary . '/arial.z');
    $manifest['fonts']['arial']['source_sha256'] = hash('sha256', $restrictedMetadata);
    $manifest['fonts']['arial']['embedding_flags'] = 8;
    writeNegativeFontManifest($temporary, $manifest);
    fontRefuses(static fn() => DanfeFonts::validate($temporary), 'manifesto não pode alterar flags de incorporação do programa original', 'metadados da fonte');
    fontRefuses(static fn() => DanfeFonts::inspectSource($wrongFont, 'arial'), 'metadados de outra família não são considerados Arial', 'original esperada');
    file_put_contents($temporary . '/arial.ttf', $wrongFont);
    fontRefuses(static fn() => DanfeFonts::inspectSource((string) file_get_contents($temporary . '/arial.ttf'), 'arial'), 'renomear arquivo não altera a identidade interna da fonte', 'original esperada');
    fontRefuses(static fn() => DanfeFonts::inspectSource(substr($wrongFont, 0, 11), 'arial'), 'cabeçalho TrueType truncado é recusado', 'TrueType original');
    fontRefuses(static fn() => DanfeFonts::inspectSource(substr($wrongFont, 0, 80), 'arial'), 'tabela TrueType truncada é recusada', 'Tabela de fonte');
    fontRefuses(static fn() => DanfeFonts::inspectSource(substr_replace($wrongFont, pack('N', 0x7fffffff), 20, 4), 'arial'), 'offset fora dos bytes do arquivo é recusado', 'Tabela de fonte');
    fontRefuses(static fn() => DanfeFonts::inspectSource(negativeFontMetadata('Arial', 'ArialMT', false, 0x0002), 'arial'), 'metadados adversariais com incorporação restrita são recusados', 'licença técnica');
    fontRefuses(static fn() => DanfeFonts::inspectSource(negativeFontMetadata('Arial', 'ArialMT', false, 0x0100), 'arial'), 'fonte que proíbe subset não pode ser convertida silenciosamente', 'licença técnica');
    fontRefuses(static fn() => DanfeFonts::inspectSource(negativeFontMetadata('Arial', 'ArialMT', false, 0x0200), 'arial'), 'restrição de incorporação somente bitmap é respeitada', 'licença técnica');

    $process = proc_open([(string) (getenv('PHP_BIN') ?: PHP_BINARY), __FILE__, 'missing-fonts'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Não foi possível iniciar teste isolado das fontes.'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $status = proc_close($process);
    $result = json_decode((string) $output, true);
    if ($status !== 0 || !is_array($result) || !isset($result['checks'])) { throw new RuntimeException('FALHOU: pacote sem fontes: ' . trim((string) $output)); }
    $checks += (int) $result['checks'];
} finally { removeFontFixture($temporary); }

echo 'OK: ' . $checks . " verificações negativas de identidade, integridade e bloqueio de fontes. Não homologam PDF ou fontes originais.\n";
