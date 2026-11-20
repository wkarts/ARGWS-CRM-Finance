<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

use Multiempresa\Contracts\StorageDriver;
use Multiempresa\Domain\{Failure,Id};

class MySqlStorage implements StorageDriver
{
    public function name(): string
    {
        return 'database';
    }
    public function connect(array $c): \PDO
    {
        Id::identifier($c['database'] ?? '');
        $host = $c['host'] ?? '';
        $port = (int)($c['port'] ?? 3306);
        if (!preg_match('/\A[a-zA-Z0-9_.:-]+\z/', $host) || $port < 1 || $port > 65535) {
            throw new Failure('database_host', 'Servidor de banco inválido.');
        }
        $dsn = 'mysql:host='.$host.';port='.$port.';dbname='.$c['database'].';charset=utf8mb4';
        if (!empty($c['socket'])) {
            $dsn = 'mysql:unix_socket='.$c['socket'].';dbname='.$c['database'].';charset=utf8mb4';
        }
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES => false,\PDO::ATTR_TIMEOUT => 10,\PDO::MYSQL_ATTR_MULTI_STATEMENTS => false];
        if (!empty($c['ssl_ca'])) {
            $options[\PDO::MYSQL_ATTR_SSL_CA] = $c['ssl_ca'];
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        return new \PDO($dsn, $c['username'], $c['password'], $options);
    }
    public function validate(array $credentials, string $prefix): void
    {
        Id::prefix($prefix, $this->name());
        if (strlen($prefix)>16) { throw new Failure('prefix_length','Use um prefixo com até 16 caracteres para comportar todas as tabelas nativas.'); }
        $pdo = $this->connect($credentials);
        $all = $pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);
        if ($this->name() === 'database') {
            foreach ($all as $r) {
                if (!str_starts_with($r[0], $prefix)) {
                    throw new Failure('database_not_exclusive', 'O banco contém tabelas fora do prefixo empresarial. Use um banco exclusivo ou o modo prefixo.');
                }
            }
        }
        foreach ($all as $r) {
            if (str_starts_with($r[0], $prefix) && $r[1] !== 'BASE TABLE') {
                throw new Failure('unsupported_view', 'Views exigem adaptador específico antes da operação.');
            }
        }
    }
    public function tables(\PDO $pdo, string $prefix): array
    {
        Id::prefix($prefix, $this->name());
        $names = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as $r) {
            if (str_starts_with($r[0], $prefix)) {
                if ($r[1] !== 'BASE TABLE') {
                    throw new Failure('unsupported_view', 'Views não são suportadas pelo backup genérico.');
                }
                $names[] = $r[0];
            }
        }
        sort($names);
        return $names;
    }
    public static function identity(array $c): string
    {
        return hash('sha256', strtolower($c['host']).':'.($c['port'] ?? 3306).':'.($c['socket'] ?? '').':'.$c['database']);
    }
}
