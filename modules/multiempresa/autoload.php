<?php

declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'Multiempresa\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/libraries/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
