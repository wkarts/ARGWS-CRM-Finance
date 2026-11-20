<?php
declare(strict_types=1);

/** Closed, module-owned autoloader. No Composer or CRM autoloader is required. */
(static function (): void {
    $map = json_decode((string) file_get_contents(__DIR__ . '/autoload-map.json'), true, 16, JSON_THROW_ON_ERROR);
    spl_autoload_register(static function (string $class) use ($map): void {
        $ownedPrefix = 'NfseNacional\\PdfVendor\\Com\\Tecnick\\';
        if (strncmp($class, $ownedPrefix, strlen($ownedPrefix)) !== 0
            || preg_match('/^[A-Za-z0-9_\\\\]+$/D', $class) !== 1) {
            return;
        }
        foreach ($map as $prefix => $directory) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) { continue; }
            $path = __DIR__ . '/' . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) { require_once $path; return; }
        }
    });
})();

require_once __DIR__ . '/packages/tcpdf/tcpdf.php';
require_once __DIR__ . '/Engine.php';
