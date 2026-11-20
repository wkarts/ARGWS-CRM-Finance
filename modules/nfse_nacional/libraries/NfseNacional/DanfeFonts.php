<?php
declare(strict_types=1);
namespace NfseNacional;

/** Original NT 008 font resources, supplied under a license permitting their server use. */
final class DanfeFonts
{
    public const REQUIRED = [
        'arial' => ['file' => 'arial.ttf', 'family' => 'Arial', 'bold' => false],
        'arialb' => ['file' => 'arialbd.ttf', 'family' => 'Arial', 'bold' => true],
        'microsoftsansserif' => ['file' => 'micross.ttf', 'family' => 'Microsoft Sans Serif', 'bold' => false],
    ];

    public static function path(): string { return PdfRuntime::fontPath() . 'nt008/'; }

    public static function missingMessage(): string
    {
        return 'As fontes originais Arial e Microsoft Sans Serif precisam ser incluídas no pacote do módulo para gerar o DANFSe no padrão da NT 008. Não será utilizada uma fonte substituta.';
    }

    /** Validate prepared resources; no system or Perfex font directory is consulted. */
    public static function validate(?string $directory = null): array
    {
        $directory = $directory ?? self::path();
        $directory = rtrim($directory, '/\\') . '/';
        $file = $directory . 'manifest.json';
        if (!is_file($file)) { throw new \RuntimeException(self::missingMessage()); }
        if (filesize($file) > 32768) { throw new \RuntimeException('O pacote de fontes do DANFSe está inválido.'); }
        $manifest = json_decode((string) file_get_contents($file), true);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_array($manifest['fonts'] ?? null)) {
            throw new \RuntimeException('O pacote de fontes do DANFSe está inválido.');
        }
        foreach (self::REQUIRED as $key => $expected) {
            $font = $manifest['fonts'][$key] ?? null;
            if (!is_array($font) || ($font['family'] ?? '') !== $expected['family']
                || ($font['bold'] ?? null) !== $expected['bold']
                || !self::isExpectedPostScript((string) ($font['postscript_name'] ?? ''), $key)
                || !preg_match('/\A[0-9a-f]{64}\z/', (string) ($font['source_sha256'] ?? ''))) {
                throw new \RuntimeException('A identificação das fontes do DANFSe não corresponde à NT 008.');
            }
            foreach (['json', 'z', 'ctg.z'] as $extension) {
                $name = $key . '.' . $extension;
                $hash = $font['resources'][$name] ?? '';
                if (!is_string($hash) || !preg_match('/\A[0-9a-f]{64}\z/', $hash)
                    || !is_file($directory . $name) || filesize($directory . $name) > 8388608
                    || !hash_equals($hash, (string) hash_file('sha256', $directory . $name))) {
                    throw new \RuntimeException('As fontes originais do DANFSe estão incompletas ou foram alteradas. Reinstale o pacote de fontes do módulo.');
                }
            }
            $descriptor = json_decode((string) file_get_contents($directory . $key . '.json'), true);
            if (!is_array($descriptor) || ($descriptor['type'] ?? '') !== 'TrueTypeUnicode'
                || ($descriptor['file'] ?? '') !== $key . '.z' || ($descriptor['ctg'] ?? '') !== $key . '.ctg.z'
                || ($descriptor['name'] ?? '') !== ($font['postscript_name'] ?? '')) {
                throw new \RuntimeException('Os recursos das fontes do DANFSe não correspondem ao pacote preparado.');
            }
            $program = @gzuncompress((string) file_get_contents($directory . $key . '.z'), 8388608);
            if (!is_string($program) || strlen($program) > 8388608 || !hash_equals($font['source_sha256'], hash('sha256', $program))) {
                throw new \RuntimeException('O programa da fonte incorporada não corresponde ao arquivo original verificado.');
            }
            $source = self::inspectSource($program, $key);
            foreach (['family', 'bold', 'postscript_name', 'embedding_flags'] as $property) {
                if (($font[$property] ?? null) !== $source[$property]) {
                    throw new \RuntimeException('Os metadados da fonte incorporada não correspondem ao pacote preparado.');
                }
            }
        }
        return $manifest;
    }

    /** Read bounded TrueType metadata before converting a licensed source font at build time. */
    public static function inspectSource(string $bytes, string $key): array
    {
        if (!isset(self::REQUIRED[$key]) || strlen($bytes) < 12 || strlen($bytes) > 8388608
            || substr($bytes, 0, 4) !== "\x00\x01\x00\x00") {
            throw new \InvalidArgumentException('Informe o arquivo TrueType original da fonte exigida.');
        }
        $count = self::u16($bytes, 4);
        if ($count < 1 || $count > 256 || 12 + $count * 16 > strlen($bytes)) {
            throw new \InvalidArgumentException('Diretório de fonte TrueType inválido.');
        }
        $tables = [];
        for ($i = 0; $i < $count; ++$i) {
            $offset = 12 + $i * 16; $tag = substr($bytes, $offset, 4);
            $start = self::u32($bytes, $offset + 8); $length = self::u32($bytes, $offset + 12);
            if (isset($tables[$tag]) || $start > strlen($bytes) || $length > strlen($bytes) - $start) {
                throw new \InvalidArgumentException('Tabela de fonte TrueType inválida.');
            }
            $tables[$tag] = substr($bytes, $start, $length);
        }
        if (strlen($tables['OS/2'] ?? '') < 64 || strlen($tables['name'] ?? '') < 6) {
            throw new \InvalidArgumentException('A fonte não contém os metadados necessários para a incorporação.');
        }
        $names = $tables['name']; $nameCount = self::u16($names, 2); $strings = self::u16($names, 4);
        if ($nameCount > 4096 || 6 + $nameCount * 12 > strlen($names) || $strings < 6 + $nameCount * 12 || $strings > strlen($names)) {
            throw new \InvalidArgumentException('Nomes internos da fonte inválidos.');
        }
        $families = []; $postscript = '';
        for ($i = 0; $i < $nameCount; ++$i) {
            $record = 6 + $i * 12;
            $platform = self::u16($names, $record); $id = self::u16($names, $record + 6);
            if (!in_array($platform, [0, 3], true) || !in_array($id, [1, 6, 16], true)) { continue; }
            $length = self::u16($names, $record + 8); $offset = $strings + self::u16($names, $record + 10);
            if ($length > 2048 || $length % 2 !== 0 || $offset > strlen($names) || $length > strlen($names) - $offset) {
                throw new \InvalidArgumentException('Nome interno da fonte truncado.');
            }
            $value = trim(mb_convert_encoding(substr($names, $offset, $length), 'UTF-8', 'UTF-16BE'));
            if ($id === 6) { $postscript = $value; } else { $families[] = $value; }
        }
        $expected = self::REQUIRED[$key];
        $bold = (self::u16($tables['OS/2'], 62) & 0x20) !== 0;
        if (!in_array($expected['family'], $families, true) || $bold !== $expected['bold'] || !self::isExpectedPostScript($postscript, $key)) {
            throw new \InvalidArgumentException('O arquivo não é a fonte original esperada: ' . $expected['family'] . ($expected['bold'] ? ' Bold.' : '.'));
        }
        $embedding = self::u16($tables['OS/2'], 8);
        // The renderer subsets fonts in a regular document. A restrictive source must not be converted silently.
        if (($embedding & 0x0300) !== 0 || (($embedding & 0x000E) !== 0 && ($embedding & 0x0008) === 0)) {
            throw new \InvalidArgumentException('A licença técnica desta fonte restringe a incorporação utilizada pelo DANFSe. Utilize uma edição licenciada compatível.');
        }
        return ['family' => $expected['family'], 'bold' => $bold, 'postscript_name' => $postscript,
            'source_sha256' => hash('sha256', $bytes), 'embedding_flags' => $embedding];
    }

    private static function isExpectedPostScript(string $name, string $key): bool
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name) ?? '');
        return in_array($name, [
            'arial' => ['arial', 'arialmt', 'arialregular'],
            'arialb' => ['arialbold', 'arialboldmt'],
            'microsoftsansserif' => ['microsoftsansserif'],
        ][$key] ?? [], true);
    }

    private static function u16(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($bytes)) { throw new \InvalidArgumentException('Fonte TrueType truncada.'); }
        return (int) unpack('n', substr($bytes, $offset, 2))[1];
    }

    private static function u32(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($bytes)) { throw new \InvalidArgumentException('Fonte TrueType truncada.'); }
        return (int) unpack('N', substr($bytes, $offset, 4))[1];
    }
}
