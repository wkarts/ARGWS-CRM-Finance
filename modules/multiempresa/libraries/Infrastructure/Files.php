<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

use Multiempresa\Domain\Failure;

final class Files
{
    public static function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new Failure('directory', 'Não foi possível preparar o diretório privado.', 503);
        }
    }
    public static function write(string $path, string $data): void
    {
        self::mkdir(dirname($path));
        $tmp = $path.'.'.bin2hex(random_bytes(5)).'.tmp';
        if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) {
            throw new Failure('disk_write', 'Falha ao persistir arquivo.', 503);
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new Failure('atomic_write', 'Falha na publicação atômica do arquivo.', 503);
        }
    }
    public static function inside(string $root, string $relative, bool $existing = true): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $relative)) {
            throw new Failure('path', 'Caminho inválido.', 403);
        }
        $base = realpath($root);
        $file = $existing ? realpath($root.'/'.$relative) : realpath(dirname($root.'/'.$relative));
        if (!$base || !$file || ($file !== $base && !str_starts_with($file, $base.DIRECTORY_SEPARATOR))) {
            throw new Failure('path', 'Arquivo indisponível.', 404);
        }
        return $existing ? $file : $file.'/'.basename($relative);
    }
    public static function treeHash(string $root): string
    {
        $paths = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isLink()) {
                throw new Failure('source_symlink', 'O pacote de código não pode conter links simbólicos.');
            }
            if ($f->isFile()) {
                $paths[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
        sort($paths);
        $ctx = hash_init('sha256');
        foreach ($paths as $p) {
            hash_update($ctx, $p."\0".hash_file('sha256', $root.'/'.$p)."\n");
        }
        return hash_final($ctx);
    }
}
