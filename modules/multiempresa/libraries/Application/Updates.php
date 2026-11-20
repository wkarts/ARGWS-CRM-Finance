<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Contracts\MigrationAdapter;
use Multiempresa\Infrastructure\AdvisoryLock;
use Multiempresa\Domain\{Id,Failure};

final class Updates
{
    public function __construct(private App $app, private MigrationAdapter $adapter)
    {
    }
    public function plan(array $actor, string $target): string
    {
        if (!(int)$actor['platform_admin']) {
            throw new Failure('platform_admin', 'Atualizações exigem administrador global.', 403);
        }
        $r = $this->app->db->one('SELECT * FROM pme_releases WHERE id=?', [$target]) ?? throw new Failure('release', 'Versão não encontrada.');
        $gate = $this->app->db->one('SELECT * FROM pme_gate WHERE id=1');
        if ($gate['state'] !== 'active') {
            throw new Failure('update_busy', 'Já existe manutenção ou atualização em andamento.', 409);
        }
        $companies = $this->app->db->all("SELECT * FROM pme_companies WHERE status IN ('active','suspended') ORDER BY id");
        if (!$companies) {
            throw new Failure('update_empty', 'Cadastre uma empresa antes de planejar a atualização.');
        }
        foreach ($companies as $c) {
            $workflow=$this->app->db->one('SELECT status FROM pme_onboardings WHERE company_id=?',[$c['id']]);
            if($workflow && !in_array($workflow['status'],['done','cancelled'],true)) {throw new Failure('onboarding_pending','Conclua ou remova a implantação pendente antes de atualizar o núcleo.');}
            $this->adapter->preflight($c, $r['root']);
        }
        $id = Id::new();
        $this->app->db->transaction(function () use ($id, $gate, $target, $actor, $companies) {
            $this->app->db->exec("INSERT INTO pme_updates(id,source_release,target_release,status,created_at,actor_id) VALUES(?,?,?,'planned',?,?)", [$id,$gate['release_id'],$target,time(),$actor['id']]);
            foreach ($companies as $c) {
                $this->app->db->exec("INSERT INTO pme_update_steps(update_id,company_id,status) VALUES(?,?,'pending')", [$id,$c['id']]);
            }
            $this->app->audit->log('update.planned', $actor['id'], null, ['update_id' => $id]);
        });
        return $id;
    }
    public function execute(string $id): void
    {
        $lock = new AdvisoryLock($this->app, 'updates');
        $u = $this->app->db->one('SELECT * FROM pme_updates WHERE id=?', [$id]) ?? throw new Failure('update_missing', 'Atualização não encontrada.');
        if ($u['status'] === 'done') {
            return;
        }
        if ($u['status'] === 'failed') {
            throw new Failure('update_recovery', 'Restaure a etapa em destino vazio pela tela Atualizações e backups, informando o ID do plano antes de retomar.');
        }
        $target = $this->app->db->one('SELECT * FROM pme_releases WHERE id=?', [$u['target_release']]);
        $steps = $this->app->db->all('SELECT * FROM pme_update_steps WHERE update_id=? ORDER BY company_id', [$id]);
        // Exclusive UPDATE waits for shared locks held by all HTTP/jobs, then new requests see maintenance.
        $this->app->db->transaction(function () use ($u, $id, $steps) {
            $suffix = $this->app->db->driver() === 'mysql' ? ' FOR UPDATE' : '';
            $gate = $this->app->db->one('SELECT * FROM pme_gate WHERE id=1'.$suffix);
            if ($gate['release_id'] !== $u['source_release'] || !in_array($gate['state'], ['active','maintenance'], true)) {
                throw new Failure('update_stale', 'Plano não corresponde à versão vigente.', 409);
            }
            $current = $this->app->db->all("SELECT id FROM pme_companies WHERE status IN ('active','suspended') ORDER BY id");
            if (array_column($current, 'id') !== array_column($steps, 'company_id')) {
                throw new Failure('update_membership', 'A lista de empresas mudou. Gere um novo plano.', 409);
            }
            $this->app->db->exec("UPDATE pme_gate SET state='maintenance' WHERE id=1");
            $this->app->db->exec("UPDATE pme_updates SET status='running' WHERE id=?", [$id]);
        });
        foreach ($steps as $step) {
            if ($step['status'] === 'done') {
                continue;
            }
            if (in_array($step['status'], ['migrating','failed'], true)) {
                throw new Failure('update_recovery', 'Migração interrompida exige restauração validada antes de retomar.');
            }
            $c = $this->app->catalog->company($step['company_id']);
            try {
                $this->adapter->preflight($c, $target['root']);
                $backup = $step['backup'] ?: ((new Backups($this->app))->create($c['id']));
                $this->app->db->exec("UPDATE pme_update_steps SET status='migrating',backup=?,checksum=?,attempts=attempts+1 WHERE update_id=? AND company_id=?", [$backup,hash_file('sha256', $backup),$id,$c['id']]);
                $this->adapter->migrate($c, $target['root']);
                $this->adapter->verify($c, $target['root']);
                $this->app->db->transaction(function () use ($id, $c, $target) {
                    $this->app->db->exec('UPDATE pme_companies SET schema_version=? WHERE id=?', [$target['version'],$c['id']]);
                    $this->app->db->exec("UPDATE pme_update_steps SET status='done',error_code=NULL WHERE update_id=? AND company_id=?", [$id,$c['id']]);
                });
            } catch (\Throwable $e) {
                $code = $e instanceof Failure ? $e->reason : 'migration_failed';
                $this->app->db->exec("UPDATE pme_update_steps SET status='failed',error_code=? WHERE update_id=? AND company_id=?", [$code,$id,$c['id']]);
                $this->app->db->exec("UPDATE pme_updates SET status='failed' WHERE id=?", [$id]);
                $this->app->audit->log('update.failed', $u['actor_id'], $c['id'], ['update_id' => $id,'reason' => $code]);
                throw $e;
            }
        }
        foreach ($steps as $step) {
            $this->adapter->verify($this->app->catalog->company($step['company_id']), $target['root']);
        }
        $this->app->db->transaction(function () use ($id, $target, $u) {
            foreach ($this->app->db->all('SELECT * FROM pme_module_reviews WHERE release_id=?', [$target['id']]) as $review) {
                $this->app->db->exec('UPDATE pme_modules SET fingerprint=? WHERE company_id=? AND name=?', [$review['fingerprint'],$review['company_id'],$review['name']]);
            }
            $this->app->db->exec("UPDATE pme_gate SET state='active',release_id=? WHERE id=1", [$target['id']]);
            $this->app->db->exec("UPDATE pme_updates SET status='done' WHERE id=?", [$id]);
            $this->app->audit->log('update.published', $u['actor_id'], null, ['update_id' => $id]);
        });
    }
}
