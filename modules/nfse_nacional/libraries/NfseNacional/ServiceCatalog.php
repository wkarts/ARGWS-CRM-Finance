<?php
declare(strict_types=1);
namespace NfseNacional;

/** National cTribNac catalogue, pinned to the published scope checked on 2026-11-10. */
final class ServiceCatalog
{
    public const VERSION = '20260122-nt007-20261029';
    public const VERIFIED_ON = '2026-11-10';
    private const SHA256 = 'f18c22d63023e74099924581390bcd0619885deeb396f74fa06059e89be28c07';
    private static ?array $items = null;

    /** Null returns the catalogue union for company/recipient defaults. No date-based activation. */
    public static function all(?string $environment = null): array
    {
        self::environment($environment);
        self::load();
        return array_values(array_filter(self::$items, static fn(array $item): bool =>
            $environment === null || in_array($environment, $item['environments'], true)));
    }

    /** Exact six-digit lookup. Call normalize() explicitly for a formatted UI value. */
    public static function find(string $code, ?string $environment = null): ?array
    {
        self::environment($environment);
        if (!preg_match('/\A[0-9]{6}\z/D', $code)) { return null; }
        self::load();
        $item = self::$items[$code] ?? null;
        if ($item === null || ($environment !== null && !in_array($environment, $item['environments'], true))) {
            return null;
        }
        return $item;
    }

    public static function has(string $code, ?string $environment = null): bool
    {
        return self::find($code, $environment) !== null;
    }

    /** Only the exact cTribNac format or NN.NN.NN is accepted; no CNAE/NBS coercion. */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/\A[0-9]{6}\z/D', $value)) { return $value; }
        if (preg_match('/\A[0-9]{2}\.[0-9]{2}\.[0-9]{2}\z/D', $value)) { return str_replace('.', '', $value); }
        return null;
    }

    private static function environment(?string $environment): void
    {
        if ($environment !== null && !in_array($environment, ['1', '2'], true)) {
            throw new \InvalidArgumentException('Ambiente inválido para o catálogo de serviços.');
        }
    }

    private static function load(): void
    {
        if (self::$items !== null) { return; }
        $file = dirname(__DIR__, 2) . '/resources/servicos.json';
        if (!is_readable($file)) { throw new \RuntimeException('Catálogo de serviços não encontrado. Reinstale os arquivos do módulo.'); }
        $json = file_get_contents($file);
        if ($json === false || !hash_equals(self::SHA256, hash('sha256', $json))) {
            throw new \RuntimeException('O catálogo de serviços está incompleto ou foi alterado. Reinstale os arquivos do módulo.');
        }
        $rows = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || count($rows) !== 345) {
            throw new \RuntimeException('Estrutura do catálogo de serviços inválida.');
        }
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['code'] ?? null)
                || !preg_match('/\A[0-9]{6}\z/D', $row['code'])
                || isset($items[$row['code']]) || !is_string($row['description'] ?? null)
                || trim($row['description']) === ''
                || !in_array($row['environments'] ?? null, [['1', '2'], ['2']], true)) {
                throw new \RuntimeException('Registro inválido no catálogo de serviços.');
            }
            $items[$row['code']] = $row;
        }
        self::$items = $items;
    }
}
