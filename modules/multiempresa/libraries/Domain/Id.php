<?php

declare(strict_types=1);

namespace Multiempresa\Domain;

final class Id
{
    public static function new(): string
    {
        return bin2hex(random_bytes(16));
    }
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
    public static function slug(string $value): string
    {
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value) || strlen($value) > 63) {
            throw new Failure('invalid_slug', 'Identificador inválido. Use letras minúsculas, números e hífen.');
        }
        return $value;
    }
    public static function identifier(string $value): string
    {
        if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/D', $value)) {
            throw new Failure('invalid_identifier', 'Identificador SQL inválido.');
        }
        return $value;
    }
    /** An empty table prefix is valid only for a whole, exclusive database. */
    public static function prefix(string $value, string $mode = 'database'): string
    {
        if ($value === '' && $mode === 'database') {
            return '';
        }
        return self::identifier($value);
    }
    public static function email(string $value): string
    {
        $value = strtolower(trim($value));
        if (strlen($value) > 190 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new Failure('invalid_email', 'E-mail inválido.');
        }
        return $value;
    }
}
