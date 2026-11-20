<?php

declare(strict_types=1);

namespace Multiempresa\Http;

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }
    public static function redirect(string $url): never
    {
        header('Cache-Control: no-store');
        header('Location: '.$url, true, 303);
        exit;
    }
    public static function cookie(string $name, string $value, string $path = '/', int $expires = 0, bool $secure = true): void
    {
        setcookie($name, $value, ['expires' => $expires,'path' => $path,'secure' => $secure,'httponly' => true,'samesite' => 'Lax']);
    }
    public static function escape(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
