<?php

declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\{AdvisoryLock,Files,Process};
use Multiempresa\Perfex\{Adapter,SchemaTemplate};

/** Synchronizes the installed native release. Never upgrades or rewrites the main database. */
final class CoreMaintenance
{
    public function __construct(private App $app) {}

    public function synchronize(): array
    {
        $release=$this->published();
        $actual=Adapter::inspect($release['root']);
        if($this->matches($release,$actual)){return $release;}
        $lock=new AdvisoryLock($this->app,'updates');
        $release=$this->published();$actual=Adapter::inspect($release['root']);
        if($this->matches($release,$actual)){return $release;}
        // Automatic discovery belongs to the installation hosting this module. Staged releases stay explicit.
        if(realpath($release['root'])!==realpath($this->app->config['root'])){
            throw new Failure('release_changed','A versão preparada foi alterada. Registre novamente essa versão na área de operações manuais.',503);
        }
        if((int)$actual['version']<(int)$release['version']){throw new Failure('downgrade','A versão instalada é anterior à versão em uso. O banco não será rebaixado.',503);}
        // Capture validates the native database version, required tables and isolated FK mapping.
        SchemaTemplate::capture($this->app->db->pdo,$this->app->config['native_prefix'],(int)$actual['version']);
        if(!$this->matches($actual,Adapter::inspect($actual['root']))){throw new Failure('core_updating','A aplicação está sendo atualizada. A sincronização continuará na próxima tentativa.',503);}
        $result=$this->app->db->transaction(function()use($release,$actual){
            $gate=$this->app->db->one('SELECT * FROM pme_gate WHERE id=1 FOR UPDATE');
            if($gate['state']!=='active'||$gate['release_id']!==$release['id']){throw new Failure('maintenance','Uma atualização está em andamento. Aguarde a conclusão.',503);}
            $next=$this->app->db->one('SELECT * FROM pme_releases WHERE root=? AND version=? AND fingerprint=? ORDER BY created_at DESC,id DESC',[$actual['root'],$actual['version'],$actual['fingerprint']]);
            if(!$next){$id=(new Adapter($this->app))->register($actual['root']);$next=$this->app->db->one('SELECT * FROM pme_releases WHERE id=?',[$id]);}
            if(!$this->matches($actual,$next)){throw new Failure('core_updating','A aplicação está sendo atualizada. A sincronização continuará na próxima tentativa.',503);}
            $this->app->db->exec('UPDATE pme_gate SET release_id=? WHERE id=1',[$next['id']]);
            $this->app->audit->log('core.synchronized',null,null,['previous_release'=>$release['id'],'release'=>$next['id'],'version'=>(int)$next['version']]);
            return $next;
        });
        return $result;
    }

    private function matches(array $a,array $b): bool
    {
        return (int)$a['version']===(int)$b['version']&&hash_equals($a['fingerprint'],$b['fingerprint']);
    }
    private function published(): array
    {
        return $this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1')??throw new Failure('release','A instalação ainda está sendo preparada.',503);
    }
    private function state(string $company): ?array
    {
        $r=$this->app->db->one('SELECT value FROM pme_settings WHERE name=?',['core:'.$company]);
        return $r?$this->app->vault->open($r['value']):null;
    }
    private function save(string $company,array $state): void
    {
        $this->app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['core:'.$company,$this->app->vault->seal($state)]);
    }

    /** Called before an isolated request or provisioning stage, and by the native scheduler. */
    public function ensure(string $company): array
    {
        (new ModuleMaintenance($this->app))->recover($company);
        $release=$this->synchronize();$c=$this->app->catalog->company($company);$state=$this->state($company);
        if((int)$c['schema_version']===0){return $release;}
        if(!$state&&(int)$c['schema_version']===(int)$release['version']){return $release;}
        $control=new AdvisoryLock($this->app,'updates');$lock=new AdvisoryLock($this->app,'company:'.$company);
        $c=$this->app->catalog->company($company);$state=$this->state($company);$release=$this->published();
        if((int)$c['schema_version']>(int)$release['version']){throw new Failure('downgrade','O banco é mais recente que o código instalado. O banco não será rebaixado.',503);}
        if(!$state&&(int)$c['schema_version']===(int)$release['version']){return $release;}
        $gate=$this->app->db->one('SELECT state FROM pme_gate WHERE id=1');
        if($gate['state']!=='active'){throw new Failure('maintenance','Atualização em andamento. Aguarde a conclusão.',503);}
        if(!$state&&!in_array($c['status'],['active','suspended','failed','provisioning'],true)){throw new Failure('maintenance','A empresa está em manutenção.',503);}
        if(!$state){
            $connection=$this->app->vault->open($c['secrets'])['connection'];$pdo=$this->app->storage($c['mode'])->connect($connection);
            $identity=static fn($db)=>$db->query('SELECT DATABASE() AS name,@@hostname AS host,@@port AS port')->fetch(\PDO::FETCH_ASSOC);
            if($identity($pdo)===$identity($this->app->db->pdo)&&($c['mode']==='database'||str_starts_with($c['table_prefix'],$this->app->config['native_prefix'])||str_starts_with($this->app->config['native_prefix'],$c['table_prefix']))){throw new Failure('primary_protected','A instalação principal está protegida.');}
            $prefix=Id::prefix($c['table_prefix'], $c['mode']);
            if($pdo->query('SELECT company_id FROM `'.$prefix.'pme_owner` WHERE id=1')->fetchColumn()!==$company){throw new Failure('storage_owner','O banco não pertence a esta empresa.');}
            $versions=$pdo->query('SELECT version FROM `'.$prefix.'migrations`')->fetchAll(\PDO::FETCH_COLUMN);
            if(count($versions)!==1){throw new Failure('schema_version','O banco não possui uma versão de esquema válida.');}
            if((int)$versions[0]>(int)$release['version']){throw new Failure('downgrade','O banco é mais recente que a aplicação. O banco não será rebaixado.');}
            // A process may have finished SQL but not persisted central metadata.
            if((int)$versions[0]===(int)$release['version']){
                (new Adapter($this->app))->verify($c,$release['root']);
                $this->app->db->exec('UPDATE pme_companies SET schema_version=? WHERE id=?',[$release['version'],$company]);
                return $release;
            }
        }
        $workerLock=new AdvisoryLock($this->app,'core-worker:'.$company);
        if($state&&$c['status']==='suspended'){$state['status']='suspended';}
        $backupService=new Backups($this->app);
        $state??=['status'=>$c['status']==='active'?'active':'suspended','schema_version'=>(int)$c['schema_version'],'phase'=>'backup','backup'=>null];
        // UPDATE waits for shared company locks held by in-flight tenant requests.
        $this->app->db->exec("UPDATE pme_companies SET status='maintenance',error_code='core_updating' WHERE id=?",[$company]);
        $this->save($company,$state);
        try {
            if(in_array($state['phase'],['migrating','restoring'],true)){
                $state['phase']='restoring';$this->save($company,$state);
                $backupService->rollbackOwned($company,$state['backup'],$state['checksum']);
                $state['phase']='backup';$state['backup']=null;$this->save($company,$state);
            }
            if(!$state['backup']){$state['backup']=$backupService->create($company);$state['checksum']=hash_file('sha256',$state['backup']);}
            $state['phase']='migrating';$state['release_id']=$release['id'];$state['attempt']=Id::new();$this->save($company,$state);
            (new DatabaseProvisioning($this->app))->grantTables($company,array_merge((new DatabaseProvisioning($this->app))->requiredTables([], $release['root']),(new DatabaseProvisioning($this->app))->moduleTables($release['root'].'/application/migrations')));
            $workerLock->close();
            Process::run([$this->app->config['php_binary'],dirname(__DIR__,2).'/scripts/tenant-worker.php'],['company_id'=>$company,'operation'=>'migrate','release'=>$release,'automatic'=>true,'attempt'=>$state['attempt']],180);
            $workerLock=new AdvisoryLock($this->app,'core-worker:'.$company);
            (new Adapter($this->app))->verify($c,$release['root']);
            if(!$this->matches($release,Adapter::inspect($release['root']))){throw new Failure('core_updating','A aplicação mudou durante a atualização. A operação será retomada automaticamente.',503);}
            $this->app->db->transaction(function()use($company,$release,$state){
                $this->app->db->exec('UPDATE pme_companies SET schema_version=?,status=?,error_code=NULL WHERE id=?',[$release['version'],$state['status'],$company]);
                $this->app->db->exec('DELETE FROM pme_settings WHERE name=?',['core:'.$company]);
                $this->app->audit->log('core.company_updated',null,$company,['version'=>(int)$release['version'],'backup'=>$state['backup']]);
            });
        } catch(\Throwable $e) {
            if(($state['phase']??'')==='migrating'){
                try{
                    // An orphan worker may still be executing SQL after a web request is terminated.
                    $workerLock->close();$workerLock=new AdvisoryLock($this->app,'core-worker:'.$company);
                    $state['phase']='restoring';$this->save($company,$state);
                    $backupService->rollbackOwned($company,$state['backup'],$state['checksum']);$state['phase']='backup';$state['backup']=null;$this->save($company,$state);
                }
                catch(\Throwable $restore){(new ExecutionLog($this->app))->record('core.rollback','failed',null,$company,$c['organization_id'],null,ExecutionLog::exception($restore));}
            }
            (new ExecutionLog($this->app))->record('core.update','failed',null,$company,$c['organization_id'],null,ExecutionLog::exception($e));
            throw new Failure('core_update_pending','A atualização automática ainda não foi concluída. Os dados foram preservados; o módulo tentará novamente. Consulte os logs se a indisponibilidade continuar.',503,ExecutionLog::exception($e));
        }
        return $release;
    }
}
