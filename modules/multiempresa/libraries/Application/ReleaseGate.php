<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Infrastructure\{Database,Files};
use Multiempresa\Domain\Failure;

/** A separate central connection holds a shared row lock until the entire tenant request ends.
 * Maintenance takes an exclusive lock, draining in-flight requests on every application node. */
final class ReleaseGate
{
    private ?Database $guard = null;
    private $localLock = null;
    public function __construct(private App $app)
    {
    }
    public function enter(string $company): array
    {
        (new CoreMaintenance($this->app))->ensure($company);
        (new ModuleMaintenance($this->app))->ensure($company,false,false);
        $this->guard = new Database($this->app->config['central']);
        $db = $this->guard;
        if ($db->driver() === 'mysql') {
            $db->pdo->beginTransaction();
            $suffix = ' LOCK IN SHARE MODE';
        } else {
            Files::mkdir($this->app->state('locks'));
            $this->localLock = fopen($this->app->state('locks/gate.lock'), 'c');
            flock($this->localLock, LOCK_SH);
            $suffix = '';
        }
        $gate = $db->one('SELECT * FROM pme_gate WHERE id=1'.$suffix);
        $row = $db->one('SELECT c.status,o.active AS organization_active FROM pme_companies c JOIN pme_organizations o ON o.id=c.organization_id WHERE c.id=?'.$suffix, [$company]);
        if (!$gate || $gate['state'] !== 'active' || !$row || $row['status'] !== 'active' || !(int)$row['organization_active']) {
            $this->close();
            throw new Failure('maintenance', 'Empresa em manutenção. Tente novamente mais tarde.', 503);
        }
        $release = $db->one('SELECT * FROM pme_releases WHERE id=?', [$gate['release_id']]);
        if (!$release) {
            $this->close();
            throw new Failure('release', 'Versão indisponível.', 503);
        }
        register_shutdown_function(fn () => $this->close());
        return $release;
    }
    public function close(): void
    {
        if ($this->guard?->pdo->inTransaction()) {
            $this->guard->pdo->rollBack();
        }
        $this->guard = null;
        if (is_resource($this->localLock)) {
            flock($this->localLock, LOCK_UN);
            fclose($this->localLock);
        }
        $this->localLock = null;
    }
    public function __destruct()
    {
        $this->close();
    }
}
