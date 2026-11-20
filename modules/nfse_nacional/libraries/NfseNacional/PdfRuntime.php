<?php
declare(strict_types=1);

namespace NfseNacional;

/**
 * Owns the module's self-contained, offline PDF runtime.
 * This entry point remains parseable on PHP 8.1 so diagnostics run before any
 * PHP 8.2 vendor source is loaded. It never loads the application's TCPDF.
 */
final class PdfRuntime
{
    public const VERSION = '7.0.22';
    public const ENGINE_VERSION = '8.84.0';
    public const FONT = 'arial';
    public const CONTENT_FONT = 'microsoftsansserif';
    private static bool $loaded = false;

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/vendor/pdf-runtime/';
    }

    public static function fontPath(): string
    {
        return self::path() . 'fonts/';
    }

    public static function load(): void
    {
        if (PHP_VERSION_ID < 80200) {
            throw new \RuntimeException('A geração do DANFSe pelo módulo requer PHP 8.2 ou superior. Atualize o PHP para utilizar esse recurso.');
        }
        foreach (['ctype', 'curl', 'filter', 'hash', 'json', 'mbstring', 'openssl', 'pcre', 'xml', 'zlib'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new \RuntimeException('A extensão PHP ' . $extension . ' precisa estar habilitada para gerar o DANFSe.');
            }
        }
        if (self::$loaded) { return; }
        foreach (['bootstrap.php', 'autoload-map.json', 'Engine.php', 'packages/tcpdf/tcpdf.php', 'packages/tcpdf/tcpdf_autoconfig.php'] as $file) {
            if (!is_readable(self::path() . $file)) { throw self::incompletePackage(); }
        }
        try {
            require_once self::path() . 'bootstrap.php';
            if (!class_exists('NfseNacional\\PdfVendor\\Engine', false)) { throw self::incompletePackage(); }
        } catch (\Throwable $error) {
            throw new \RuntimeException('O pacote de geração do DANFSe está incompleto ou inválido. Reinstale o módulo NFS-e Nacional.', 0, $error);
        }
        self::$loaded = true;
    }

    /** A new isolated renderer; the returned object exposes the TCPDF API. */
    public static function create(): \NfseNacional\PdfVendor\Engine
    {
        self::load();
        DanfeFonts::validate();
        return new \NfseNacional\PdfVendor\Engine('P', 'mm', 'A4', true, 'UTF-8', false);
    }

    /** Read-only diagnostics; no PDF, font conversion, downloads or files are created. */
    public static function diagnostics(): array
    {
        $result = [
            'available' => false,
            'engine_available' => false,
            'engine' => 'TCPDF',
            'version' => self::VERSION,
            'engine_version' => self::ENGINE_VERSION,
            'fonts_available' => false,
            'label_font' => self::FONT,
            'content_font' => self::CONTENT_FONT,
            'fonts_path' => DanfeFonts::path(),
            'message' => '',
        ];
        try {
            self::load();
            $result['engine_available'] = true;
            DanfeFonts::validate();
            $result['fonts_available'] = true;
            $result['available'] = true;
            $result['message'] = 'Gerador do módulo disponível com Arial e Microsoft Sans Serif para o DANFSe da NT 008.';
        } catch (\Throwable $error) {
            $result['message'] = $error->getMessage();
        }
        return $result;
    }

    private static function incompletePackage(): \RuntimeException
    {
        return new \RuntimeException('O pacote de geração do DANFSe está incompleto ou inválido. Reinstale o módulo NFS-e Nacional.');
    }
}
