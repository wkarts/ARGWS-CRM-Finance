<?php
declare(strict_types=1);
namespace NfseNacional;

/** Serve the assets belonging to the module copy executing in this company. */
final class Assets
{
    private const FILES = [
        'style' => ['nfse_nacional.css', 'text/css'],
        'script' => ['nfse_nacional.js', 'application/javascript'],
        'navigation' => ['navigation-icons.js', 'application/javascript'],
    ];

    public static function read(string $key): array
    {
        if (!isset(self::FILES[$key])) {
            throw new \InvalidArgumentException('Recurso não encontrado.');
        }
        [$file, $mime] = self::FILES[$key];
        $directory = realpath(__DIR__ . '/../../assets');
        $path = $directory === false ? false : realpath($directory . '/' . $file);
        if ($path === false || dirname($path) !== $directory || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Recurso do módulo indisponível.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('Recurso do módulo indisponível.');
        }
        return ['content' => $bytes, 'mime' => $mime, 'hash' => hash('sha256', $bytes)];
    }

    public static function url(string $key): string
    {
        static $versions = [];
        if (!isset($versions[$key])) { $versions[$key] = self::read($key)['hash']; }
        // module_dir_url() can point at the central installation under Multiempresa.
        // The administrative route stays within the active company's PHP runtime.
        return admin_url('nfse_nacional/asset/' . $key) . '?v=' . $versions[$key];
    }
}
