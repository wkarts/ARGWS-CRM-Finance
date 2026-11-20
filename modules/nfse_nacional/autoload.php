<?php

defined('BASEPATH') or exit('No direct script access allowed');

spl_autoload_register(static function ($class) {
    $prefix = 'NfseNacional\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/\\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\z/', $relative)) {
        return;
    }
    $file = __DIR__ . '/libraries/NfseNacional/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
