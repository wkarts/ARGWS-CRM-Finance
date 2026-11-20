<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Infrastructure\AdvisoryLock;
use Multiempresa\Perfex\Adapter;
use Multiempresa\Domain\Failure;

final class ControlJobs
{
    public function __construct(private App $app)
    {
    }
    public function runOne(): bool
    {
        try {
            $lock = new AdvisoryLock($this->app, 'control-jobs');
        } catch (Failure $e) {
            if ($e->reason === 'operation_busy') {
                return false;
            }
            throw $e;
        }
        // A prior process may have died in a DDL operation. Do not repeat it automatically.
        $this->app->db->exec("UPDATE pme_jobs SET status='interrupted',error_code='worker_interrupted' WHERE status='running'");
        $j = $this->app->db->one("SELECT * FROM pme_jobs WHERE status='pending' ORDER BY created_at,id LIMIT 1");
        if (!$j) {
            return false;
        }
        $this->app->db->exec("UPDATE pme_jobs SET status='running',started_at=? WHERE id=?", [time(),$j['id']]);
        try {
            $actor = $this->app->auth->refresh($j['actor_id']);
            $result = [];
            if ($j['type'] === 'update') {
                if (!(int)$actor['platform_admin']) {
                    throw new Failure('platform_admin', 'Permissão global revogada.');
                }
                (new Updates($this->app, new Adapter($this->app)))->execute($j['target']);
            } elseif ($j['type'] === 'backup') {
                $control = new AdvisoryLock($this->app, 'updates');
                $c = $this->app->catalog->company($j['target']);
                $this->app->catalog->assertManager($actor, $c['organization_id']);
                if (!in_array($c['status'], ['active','suspended'], true)) {
                    throw new Failure('backup_state', 'Empresa não está pronta para backup.');
                }
                $gate = $this->app->db->one('SELECT state FROM pme_gate WHERE id=1');
                if ($gate['state'] !== 'active') {
                    throw new Failure('maintenance', 'Existe manutenção global.');
                }
                $this->app->db->exec("UPDATE pme_companies SET status='maintenance' WHERE id=?", [$c['id']]);
                $result['backup'] = (new Backups($this->app))->create($c['id']);
                $this->app->db->exec('UPDATE pme_companies SET status=? WHERE id=?', [$c['status'],$c['id']]);
            } else {
                throw new Failure('job_type', 'Rotina desconhecida.');
            }
            $this->app->db->exec("UPDATE pme_jobs SET status='done',finished_at=?,result=? WHERE id=?", [time(),json_encode($result, JSON_THROW_ON_ERROR),$j['id']]);
        } catch (\Throwable $e) {
            $this->app->db->exec("UPDATE pme_jobs SET status='failed',finished_at=?,error_code=? WHERE id=?", [time(),$e instanceof Failure ? $e->reason : 'job_failed',$j['id']]);
            $this->app->audit->log('job.failed', $j['actor_id'], null, ['job_id' => $j['id']]);
        }
        return true;
    }
}
