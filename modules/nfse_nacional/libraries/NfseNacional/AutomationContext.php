<?php
declare(strict_types=1);
namespace NfseNacional;

/** Request-local capability for one invoice, entered only by the server cron worker. */
final class AutomationContext
{
    private static ?array $current = null;
    public static function current(): ?array
    {
        $c = self::$current;
        if ($c !== null && !hash_equals($c['scope'], nfse_nacional_scope())) { throw new \RuntimeException('A empresa mudou durante a automação.'); }
        return $c;
    }
    public static function run(array $context, callable $operation)
    {
        if (self::$current !== null) { throw new \RuntimeException('Contexto de automação já ativo.'); }
        self::$current = $context;
        try { self::current(); return $operation(); }
        finally { self::$current = null; }
    }
}
