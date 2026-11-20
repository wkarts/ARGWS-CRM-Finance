<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Infrastructure\Database;
use Multiempresa\Domain\Failure;

final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }
    public function hit(string $key, int $limit, int $window): void
    {
        $bucket = hash('sha256', $key.':'.intdiv(time(), $window));
        try {
            $this->db->exec('INSERT INTO pme_rate_limits(bucket,count,expires_at) VALUES(?,0,?)', [$bucket,time() + $window]);
        } catch (\PDOException $e) {
            if (!Database::duplicate($e)) {
                throw $e;
            }
        }
        if ($this->db->exec('UPDATE pme_rate_limits SET count=count+1 WHERE bucket=? AND count<?', [$bucket,$limit]) !== 1) {
            throw new Failure('rate_limit', 'Limite de tentativas atingido. Tente novamente mais tarde.', 429);
        }
    }
}
