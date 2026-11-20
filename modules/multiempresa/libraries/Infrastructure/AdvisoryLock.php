<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

use Multiempresa\Application\App;
use Multiempresa\Domain\Failure;

final class AdvisoryLock
{
    private ?Database $db = null;
    private $file = null;
    private string $key;
    public function __construct(App $app, string $key)
    {
        $this->key = hash('sha256', 'pme:'.$key);
        if ($app->db->driver() === 'mysql') {
            $this->db = new Database($app->config['central']);
            $row = $this->db->one('SELECT GET_LOCK(?,0) AS acquired', [$this->key]);
            if ((int)$row['acquired'] !== 1) {
                throw new Failure('operation_busy', 'Já existe uma operação em andamento.', 409);
            }
        } else {
            Files::mkdir($app->state('locks'));
            $this->file = fopen($app->state('locks/'.$this->key.'.lock'), 'c');
            if (!flock($this->file, LOCK_EX | LOCK_NB)) {
                throw new Failure('operation_busy', 'Operação em andamento.', 409);
            }
        }
    }
    public function close(): void
    {
        if ($this->db) {
            try {
                $this->db->one('SELECT RELEASE_LOCK(?)', [$this->key]);
            } finally {
                $this->db = null;
            }
        }
        if (is_resource($this->file)) {
            flock($this->file, LOCK_UN);
            fclose($this->file);
        }
        $this->file = null;
    }
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }
}
