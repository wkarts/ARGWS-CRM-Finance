<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Domain\{Id, Failure};
use Multiempresa\Infrastructure\{AdvisoryLock, Files, Process};

/** First installation/migrations retain their backup and maintenance workflow; state toggles use native hooks. */
final class Modules
{
    public function __construct(private App $app)
    {
    }
    public static function selection(mixed $names): array
    {
        if(!is_array($names)||count($names)>100){throw new Failure('modules','Seleção de módulos inválida.');}
        foreach($names as $name){if(!is_string($name)){throw new Failure('modules','Seleção de módulos inválida.');}Id::identifier($name);if(strtolower($name)==='multiempresa'){throw new Failure('central_module','Multiempresa é exclusivo da administração central.');}}
        $names=array_values(array_unique($names));sort($names);return $names;
    }
    /** Publish reviewed private code without executing its installer or activation hook. */
    public function makeAvailable(string $companyId,string $name,bool $reviewed,bool $onlyNew=false): void
    {
        self::selection([$name]);
        if(!$reviewed){throw new Failure('module_review','Confirme os módulos a disponibilizar.');}
        (new CoreMaintenance($this->app))->ensure($companyId);
        $previous=$this->app->db->one('SELECT active FROM pme_modules WHERE company_id=? AND name=?',[$companyId,$name]);
        if($onlyNew&&$previous){return;}
        if(!empty($previous['active'])){$this->disable($companyId,$name);}
        $update=new AdvisoryLock($this->app,'updates');$lock=new AdvisoryLock($this->app,'company:'.$companyId);
        if($onlyNew&&$this->app->db->one('SELECT active FROM pme_modules WHERE company_id=? AND name=?',[$companyId,$name])){return;}
        $company=$this->app->catalog->company($companyId);$release=(new CompanyManager($this->app))->release();
        if(!in_array($company['status'],['active','suspended'],true)||($this->app->db->one('SELECT state FROM pme_gate WHERE id=1')['state']??'')!=='active'){throw new Failure('module_busy','Empresa em manutenção.');}
        if(!empty($this->app->db->one('SELECT active FROM pme_modules WHERE company_id=? AND name=?',[$companyId,$name])['active'])){throw new Failure('operation_busy','O módulo mudou de estado. Tente novamente.',409);}
        $root=$release['root'].'/modules/'.$name;if(!is_file($root.'/'.$name.'.php')){throw new Failure('module_missing','Módulo indisponível: '.$name);}
        $hash=Files::treeHash($root);
        (new \Multiempresa\Perfex\ModuleRuntime($this->app))->mount($root,$this->app->state('companies/'.$companyId.'/modules/'.$name),$company['table_prefix'],$hash);
        $this->app->db->transaction(function()use($companyId,$name,$hash,$release){
            $this->app->db->exec('DELETE FROM pme_module_reviews WHERE company_id=? AND release_id=? AND name=?',[$companyId,$release['id'],$name]);
            $this->app->db->exec('INSERT INTO pme_modules(company_id,name,fingerprint,active,approved_by) VALUES(?,?,?,0,?) ON DUPLICATE KEY UPDATE fingerprint=VALUES(fingerprint),active=0',[$companyId,$name,$hash,'operator']);
        });
        $this->app->audit->log('module.available',null,$companyId,['module'=>$name,'active'=>false]);
    }
    public function approve(string $companyId, string $name, bool $reviewed, ?string $releaseId = null): array
    {
        if(!$releaseId){(new CoreMaintenance($this->app))->ensure($companyId);(new ModuleMaintenance($this->app))->ensure($companyId,false,false);}
        $updateLock = new AdvisoryLock($this->app, 'updates');
        $companyLock = new AdvisoryLock($this->app, 'company:'.$companyId);
        $company = $this->app->catalog->company($companyId);
        $name = Id::identifier($name);
        $release = $releaseId
            ? $this->app->db->one('SELECT * FROM pme_releases WHERE id=?', [$releaseId])
            : $this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1');
        if (!$release) {
            throw new Failure('release', 'Versão não encontrada.');
        }
        $root = $release['root'].'/modules/'.$name;
        if (!$reviewed || !is_file($root.'/'.$name.'.php') || strtolower($name) === 'multiempresa') {
            throw new Failure('module_review', 'Aprovação exige homologação prévia do módulo nos dois modos de armazenamento.');
        }
        $fingerprint = Files::treeHash($root);
        if ($releaseId) {
            $this->app->db->transaction(function () use ($release, $companyId, $name, $fingerprint) {
                $this->app->db->exec('DELETE FROM pme_module_reviews WHERE release_id=? AND company_id=? AND name=?', [$release['id'], $companyId, $name]);
                $this->app->db->exec('INSERT INTO pme_module_reviews(release_id,company_id,name,fingerprint) VALUES(?,?,?,?)', [$release['id'], $companyId, $name, $fingerprint]);
            });
            $this->app->audit->log('module.release_reviewed', null, $companyId, ['module' => $name, 'release_id' => $release['id']]);
            return ['fingerprint' => $fingerprint, 'review_only' => true];
        }
        $gate = $this->app->db->one('SELECT state FROM pme_gate WHERE id=1');
        if ($gate['state'] !== 'active' || !in_array($company['status'], ['active', 'suspended'], true)) {
            throw new Failure('module_busy', 'Resolva a manutenção vigente antes de instalar módulos.');
        }
        $installed=$this->app->db->one('SELECT fingerprint,active FROM pme_modules WHERE company_id=? AND name=?',[$companyId,$name]);
        $stateOnly=false;
        if($installed && hash_equals($installed['fingerprint'],$fingerprint) && \Multiempresa\Perfex\ModuleRuntime::ready($this->app->state('companies/'.$companyId.'/modules/'.$name),$company['table_prefix'],$fingerprint)) {
            $pdo=$this->app->storage($company['mode'])->connect($this->app->vault->open($company['secrets'])['connection']);
            $check=$pdo->prepare('SELECT active,installed_version FROM `'.$company['table_prefix'].'modules` WHERE module_name=?');$check->execute([$name]);$native=$check->fetch(\PDO::FETCH_ASSOC);
            preg_match('/^\s*Version:\s*(.+)$/mi',file_get_contents($root.'/'.$name.'.php',false,null,0,8192),$header);
            if($native&&isset($header[1])&&version_compare((string)$native['installed_version'],trim($header[1]),'==')){if((int)$native['active']===1){$this->app->db->exec('UPDATE pme_modules SET active=1 WHERE company_id=? AND name=?',[$companyId,$name]);return ['fingerprint'=>$fingerprint,'already_active'=>true];}$marker=$this->installedMarker($company,$name);$stateOnly=is_file($marker)&&hash_equals($fingerprint,trim(file_get_contents($marker)));}
        }
        $workflow=$this->app->db->one('SELECT status FROM pme_onboardings WHERE company_id=?',[$companyId]);
        $firstManualActivation=$installed&&!(int)$installed['active']&&empty($native)&&(!$workflow||$workflow['status']==='done');
        $this->app->db->exec("UPDATE pme_companies SET status='maintenance',error_code='module_installing' WHERE id=?", [$companyId]);
        $backup = null;
        $backupHash = null;
        try {
            $backup = $stateOnly?null:(new Backups($this->app))->create($companyId);
            if($backup){$backupHash=hash_file('sha256',$backup);}
            $this->app->db->transaction(function () use ($companyId, $name, $fingerprint) {
                $this->app->db->exec('DELETE FROM pme_modules WHERE company_id=? AND name=?', [$companyId, $name]);
                $this->app->db->exec('INSERT INTO pme_modules(company_id,name,fingerprint,active,approved_by) VALUES(?,?,?,1,?)', [$companyId, $name, $fingerprint, 'operator']);
            });
            $provisioning=new DatabaseProvisioning($this->app);
            if(!$stateOnly){$provisioning->grantTables($companyId,$provisioning->moduleTables($root));}
            $extensions=new \Multiempresa\Perfex\ModuleExtensions($this->app);$before=$extensions->snapshot($companyId,$release);
            try { Process::run([$this->app->config['php_binary'] ?? PHP_BINARY, dirname(__DIR__, 2).'/scripts/tenant-worker.php'], ['company_id' => $companyId, 'operation' => 'module_activate', 'module' => $name]); }
            finally { $extensions->capture($companyId,$release,$name,$before); }
            if(!$stateOnly){\Multiempresa\Perfex\SchemaTemplate::finalizeDependencies($this->app,$company);}
            $this->app->db->exec('UPDATE pme_companies SET status=?,error_code=NULL WHERE id=?', [$company['status'], $companyId]);
            Files::write($this->installedMarker($company,$name),$fingerprint);
            $this->app->audit->log('module.approved', null, $companyId, ['module' => $name, 'fingerprint' => $fingerprint, 'backup' => $backup]);
            return ['fingerprint' => $fingerprint, 'backup' => $backup];
        } catch (\Throwable $e) {
            if($firstManualActivation&&!$stateOnly){
                try{if($backup&&$backupHash){(new Backups($this->app))->rollbackOwned($companyId,$backup,$backupHash);}$this->failedToggle($company,$name);}
                catch(\Throwable $recovery){$this->app->db->exec("UPDATE pme_companies SET error_code='module_recovery_failed' WHERE id=?",[$companyId]);throw new Failure('module_recovery_failed','A recuperação do módulo não terminou. O backup foi mantido para retomar com segurança.',503,ExecutionLog::exception($recovery));}
            }elseif($stateOnly){$this->failedToggle($company,$name);}else{$this->app->db->exec("UPDATE pme_companies SET error_code='module_failed' WHERE id=?", [$companyId]);}
            $this->app->audit->log('module.failed', null, $companyId, ['module' => $name, 'backup' => $backup]);
            throw $e;
        }
    }
    public function disable(string $companyId,string $name): void
    {
        Id::identifier($name);
        (new ModuleMaintenance($this->app))->ensure($companyId,true,true,false,$name);
        if (strtolower($name)==='multiempresa') { throw new Failure('central_module','Multiempresa é exclusivo da instância administrativa central.'); }
        $update=new AdvisoryLock($this->app,'updates');
        $lock=new AdvisoryLock($this->app,'company:'.$companyId);
        $c=$this->app->catalog->company($companyId);
        $gate=$this->app->db->one('SELECT state FROM pme_gate WHERE id=1');
        if ($gate['state']!=='active' || !in_array($c['status'],['active','suspended'],true)) { throw new Failure('module_busy','Empresa em manutenção.'); }
        if (!$this->app->db->one('SELECT name FROM pme_modules WHERE company_id=? AND name=? AND active=1',[$companyId,$name])) { throw new Failure('module_missing','Módulo inativo ou não homologado.'); }
        $row=$this->app->db->one('SELECT fingerprint FROM pme_modules WHERE company_id=? AND name=?',[$companyId,$name]);
        $pdo=$this->app->storage($c['mode'])->connect($this->app->vault->open($c['secrets'])['connection']);$q=$pdo->prepare('SELECT active FROM `'.$c['table_prefix'].'modules` WHERE module_name=?');$q->execute([$name]);
        if((int)$q->fetchColumn()===1){Files::write($this->installedMarker($c,$name),$row['fingerprint']);}
        $this->app->db->exec("UPDATE pme_companies SET status='maintenance',error_code='module_disabling' WHERE id=?",[$companyId]);
        try {
            Process::run([$this->app->config['php_binary'],dirname(__DIR__,2).'/scripts/tenant-worker.php'],['company_id'=>$companyId,'operation'=>'module_deactivate','module'=>$name]);
            $this->app->db->exec('UPDATE pme_modules SET active=0 WHERE company_id=? AND name=?',[$companyId,$name]);
            $this->app->db->exec('UPDATE pme_companies SET status=?,error_code=NULL WHERE id=?',[$c['status'],$companyId]);
            $this->app->audit->log('module.disabled',null,$companyId,['module'=>$name]);
        } catch (\Throwable $e) {
            $this->failedToggle($c,$name);throw $e;
        }
    }

    private function installedMarker(array $company,string $name): string
    {return $this->app->state('companies/'.$company['id'].'/modules/'.$name.'/.pme-installed-'.$company['table_prefix']);}
    private function failedToggle(array $company,string $name): void
    {
        // A failing optional extension stays off. Do not turn an already provisioned company
        // into a new onboarding, and never leave its entire site in maintenance for a toggle.
        $pdo=$this->app->storage($company['mode'])->connect($this->app->vault->open($company['secrets'])['connection']);
        $q=$pdo->prepare('UPDATE `'.$company['table_prefix'].'modules` SET active=0 WHERE module_name=?');$q->execute([$name]);
        $this->app->db->exec('UPDATE pme_modules SET active=0 WHERE company_id=? AND name=?',[$company['id'],$name]);
        $this->app->db->exec('UPDATE pme_companies SET status=?,error_code=NULL WHERE id=?',[$company['status'],$company['id']]);
        $this->app->audit->log('module.toggle_failed',null,$company['id'],['module'=>$name,'disabled'=>true]);
    }

}
