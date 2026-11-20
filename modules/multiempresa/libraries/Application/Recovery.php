<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Domain\{Id, Failure};
use Multiempresa\Infrastructure\{AdvisoryLock, Files, MySqlStorage};
use Multiempresa\Perfex\Adapter;

/** Company-level restore. Global upgrades use their own per-step recovery protocol. */
final class Recovery
{
    public function __construct(private App $app)
    {
    }
    public function restore(string $company, string $backup, array $connection, string $prefix, ?string $updateId = null): void
    {
        $control = new AdvisoryLock($this->app, 'updates');
        $lock = new AdvisoryLock($this->app, 'company:'.$company);
        $c = $this->app->catalog->company($company);
        $gate = $this->app->db->one('SELECT * FROM pme_gate WHERE id=1');
        $update=null; $step=null;
        if ($updateId !== null) {
            $update=$this->app->db->one("SELECT * FROM pme_updates WHERE id=? AND status IN ('failed','running')",[$updateId]);
            $step=$this->app->db->one("SELECT * FROM pme_update_steps WHERE update_id=? AND company_id=? AND status IN ('failed','migrating')",[$updateId,$company]);
            if (!$update || !$step || $gate['state']!=='maintenance' || $update['source_release']!==$gate['release_id'] || $step['backup']!==$backup || !hash_equals((string)$step['checksum'],hash_file('sha256',$backup))) {
                throw new Failure('restore_update','Etapa de recuperação ou backup inválido.');
            }
        } elseif ($gate['state'] !== 'active' || !in_array($c['status'], ['maintenance', 'suspended'], true)) {
            throw new Failure('restore_state', 'Suspenda a empresa antes de restaurar.');
        }
        Id::prefix($prefix, $c['mode']);
        $identity = MySqlStorage::identity($connection);
        $this->app->storage($c['mode'])->validate($connection, $prefix);
        foreach ($this->app->db->all('SELECT id,mode,table_prefix FROM pme_companies WHERE storage_identity=?', [$identity]) as $other) {
            if ($c['mode'] === 'database' || $other['mode'] === 'database' || str_starts_with($prefix, $other['table_prefix']) || str_starts_with($other['table_prefix'], $prefix)) {
                throw new Failure('storage_overlap', 'O destino da restauração está reservado. Use outro banco ou prefixo.');
            }
        }
        $this->app->db->exec("UPDATE pme_companies SET status='maintenance' WHERE id=?", [$company]);
        $files = $this->app->state('recovery/'.Id::new());
        $manifest = (new Backups($this->app))->restoreEmpty($backup, $connection, $prefix, $files, $company);
        if ($manifest['company_id'] !== $company) {
            throw new Failure('restore_company', 'Backup pertence a outra empresa. O destino restaurado permanece sem vínculo.');
        }
        $release = $this->app->db->one('SELECT * FROM pme_releases WHERE id=?', [$gate['release_id']]);
        $secrets = $this->app->vault->open($c['secrets']);
        $secrets['connection'] = $connection;
        $replacement = array_replace($c, ['secrets' => $this->app->vault->seal($secrets), 'table_prefix' => $prefix]);
        (new Adapter($this->app))->verify($replacement, $release['root']);
        foreach (['uploads', 'media'] as $kind) {
            $dest = $this->app->state('companies/'.$company.'/'.$kind);
            if (is_dir($dest) && !rename($dest, $dest.'.before-recovery-'.Id::new())) {
                throw new Failure('restore_files', 'Falha ao preservar os anexos atuais. A empresa permanece bloqueada.');
            }
            if (is_dir($files.'/'.$kind)) {
                if (!rename($files.'/'.$kind, $dest)) {
                    throw new Failure('restore_files', 'Falha ao disponibilizar anexos restaurados. A empresa permanece bloqueada.');
                }
            } else {
                Files::mkdir($dest);
            }
        }
        $this->app->db->transaction(function () use ($company, $replacement, $prefix, $identity, $manifest, $updateId) {
            $this->app->db->exec('UPDATE pme_companies SET secrets=?,table_prefix=?,storage_identity=?,error_code=NULL WHERE id=?', [$replacement['secrets'], $prefix, $identity, $company]);
            $this->app->db->exec('DELETE FROM pme_modules WHERE company_id=?', [$company]);
            $this->app->db->exec('DELETE FROM pme_sessions WHERE company_id=?', [$company]);
            $this->app->db->exec('UPDATE pme_tokens SET revoked=1 WHERE company_id=?', [$company]);
            foreach ($manifest['modules'] ?? [] as $module) {
                $this->app->db->exec('INSERT INTO pme_modules(company_id,name,fingerprint,active,approved_by) VALUES(?,?,?,?,?)', [$company, $module['name'], $module['fingerprint'], $module['active'], $module['approved_by']]);
            }
            if ($updateId !== null) {
                $this->app->db->exec("UPDATE pme_update_steps SET status='pending',error_code=NULL WHERE update_id=? AND company_id=?",[$updateId,$company]);
                $this->app->db->exec("UPDATE pme_updates SET status='running' WHERE id=?",[$updateId]);
            }
            // Suspension requires an explicit operator activation after inspection.
            $this->app->db->exec("UPDATE pme_companies SET status='suspended' WHERE id=?", [$company]);
            $this->app->audit->log('company.restore_verified', null, $company, ['target_prefix' => $prefix]);
        });
    }
}
