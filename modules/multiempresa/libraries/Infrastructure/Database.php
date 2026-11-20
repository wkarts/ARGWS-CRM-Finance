<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

final class Database
{
    public readonly \PDO $pdo;
    private string $prefix;
    public function __construct(array $config)
    {
        $this->prefix = $config['table_prefix'] ?? 'pme_';
        \Multiempresa\Domain\Id::identifier($this->prefix);
        $this->pdo = new \PDO(
            $config['dsn'],
            $config['username'] ?? null,
            $config['password'] ?? null,
            ($config['options'] ?? []) + [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]
        );
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000');
        }
    }
    private function sql(string $sql): string
    {
        return preg_replace_callback('/[\x27](?:\\\\.|[\x27][\x27]|[^\x27\\\\])*[\x27]|"(?:\\\\.|""|[^"\\\\])*"|\bpme_([a-z_]+)\b/s', fn ($m) => isset($m[1]) ? $this->prefix.$m[1] : $m[0], $sql);
    }
    public function driver(): string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
    public function all(string $sql, array $params = []): array
    {
        $s = $this->pdo->prepare($this->sql($sql));
        $s->execute($params);
        return $s->fetchAll();
    }
    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }
    public function exec(string $sql, array $params = []): int
    {
        $s = $this->pdo->prepare($this->sql($sql));
        $s->execute($params);
        return $s->rowCount();
    }
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            $savepoint = 'pme_'.bin2hex(random_bytes(6));
            $this->pdo->exec('SAVEPOINT '.$savepoint);
            try {
                $value = $fn();
                $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                return $value;
            } catch (\Throwable $e) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                throw $e;
            }
        }
        $this->pdo->beginTransaction();
        try {
            $v = $fn();
            $this->pdo->commit();
            return $v;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } throw $e;
        }
    }
    public static function duplicate(\PDOException $e): bool
    {
        return in_array((string)$e->getCode(), ['23000', '23505'], true);
    }
}
