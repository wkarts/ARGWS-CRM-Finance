<?php
declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\{AdvisoryLock,Files,Process};
use Multiempresa\Perfex\{ModuleRuntime,ModuleExtensions};

/** Incremental updates of already assigned packages. Never creates or restarts an onboarding. */
final class ModuleMaintenance
{
    public function __construct(private App $app) {}
    public static function key(string $company,string $module): string
    {return 'module:'.$company.':'.substr(hash('sha256',$module),0,32);}
    private function read(string $key): ?array
    {$r=$this->app->db->one('SELECT value FROM pme_settings WHERE name=?',[$key]);return $r?$this->app->vault->open($r['value']):null;}
    private function save(string $key,array $state): void
    {$this->app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$this->app->vault->seal($state)]);}
    /** Header/migration changes are immediate. A bounded cache also discovers same-version code replacements. */
    private function source(string $path,bool $force): array
    {
        $main=$path.'/'.basename($path).'.php';
        if(!is_file($main)){throw new Failure('module_missing','Pacote de módulo não encontrado.');}
        $header=file_get_contents($main,false,null,0,8192);preg_match('/^\s*Version:\s*(.+)$/mi',$header,$m);
        $version=trim($m[1]??'');if($version===''){throw new Failure('module_version','O pacote não informa sua versão.');}
        $stamp=hash('sha256',$header);foreach(glob($path.'/migrations/*_*.php')?:[] as $file){$stamp.=basename($file).':'.filemtime($file).':'.filesize($file);}
        $stamp=hash('sha256',$stamp);$key='module-source:'.substr(hash('sha256',$path),0,48);$cached=$this->read($key);
        if(!$force&&$cached&&$cached['stamp']===$stamp&&time()-(int)$cached['checked_at']<30){return $cached;}
        $value=['stamp'=>$stamp,'version'=>$version,'fingerprint'=>Files::treeHash($path),'checked_at'=>time()];$this->save($key,$value);return $value;
    }
    /** Recover an interrupted package publication before a newer core schema is considered. */
    public function recover(string $company): void
    {
        $c=$this->app->catalog->company($company);if($c['status']!=='maintenance'){return;}
        $rows=$this->app->db->all('SELECT name,value FROM pme_settings WHERE name LIKE ?',['module:'.$company.':%']);
        foreach($rows as $row){
            $state=$this->app->vault->open($row['value']);if(!in_array($state['phase']??'',['applied','migrating','restoring','backup'],true)){continue;}
            try{$control=new AdvisoryLock($this->app,'updates');$lock=new AdvisoryLock($this->app,'company:'.$company);$worker=new AdvisoryLock($this->app,'module-worker:'.$company);}
            catch(Failure $e){if($e->reason==='operation_busy'){return;}throw $e;}
            try{
            $state=$this->read($row['name']);if(!$state){continue;}$name=Id::identifier($state['module']);
            $c=$this->app->catalog->company($company);$pdo=$this->ownedConnection($c);
            if($state['phase']==='applied'){$this->complete($c,$name,$state,$pdo);}
            elseif(in_array($state['phase'],['migrating','restoring'],true)){$this->restore($c,$name,$state);}
            elseif($state['phase']==='backup'){$state['phase']='failed';$state['retry_after']=0;$this->save($row['name'],$state);$this->app->db->exec('UPDATE pme_companies SET status=?,error_code=NULL WHERE id=?',[$state['status'],$company]);}
            }finally{$worker->close();$lock->close();$control->close();}
        }
    }
    private function ownedConnection(array $c): \PDO
    {
        $pdo=$this->app->storage($c['mode'])->connect($this->app->vault->open($c['secrets'])['connection']);
        $identity=static fn($db)=>$db->query('SELECT DATABASE() AS name,@@hostname AS host,@@port AS port')->fetch(\PDO::FETCH_ASSOC);
        if($identity($pdo)===$identity($this->app->db->pdo)&&($c['mode']==='database'||str_starts_with($c['table_prefix'],$this->app->config['native_prefix'])||str_starts_with($this->app->config['native_prefix'],$c['table_prefix']))){throw new Failure('primary_protected','A instalação principal está protegida.');}
        if($pdo->query('SELECT company_id FROM `'.$c['table_prefix'].'pme_owner` WHERE id=1')->fetchColumn()!==$c['id']){throw new Failure('storage_owner','Não foi comprovada a propriedade do ambiente.');}
        return $pdo;
    }
    public function ensure(string $company,bool $force=false,bool $core=true,bool $completedOnly=false,?string $only=null): void
    {
        if($core){(new CoreMaintenance($this->app))->ensure($company);}
        $c=$this->app->catalog->company($company);if((int)$c['schema_version']===0||$c['status']==='maintenance'){return;}
        $workflow=$this->app->db->one('SELECT status,steps FROM pme_onboardings WHERE company_id=?',[$company]);
        $finished=null;if($workflow&&!in_array($workflow['status'],['done','cancelled'],true)){if(!$completedOnly){return;}$finished=array_column(array_filter(json_decode($workflow['steps'],true,64,JSON_THROW_ON_ERROR),static fn($step)=>$step['type']==='module'&&$step['status']==='done'),'name');}
        $modules=$this->app->db->all('SELECT * FROM pme_modules WHERE company_id=? ORDER BY name',[$company]);if(!$modules){return;}
        if(!in_array($c['status'],['active','suspended','maintenance'],true)){return;}
        $release=$this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1');
        $pdo=$this->app->storage($c['mode'])->connect($this->app->vault->open($c['secrets'])['connection']);
        $native=$pdo->query('SELECT module_name,installed_version,active FROM `'.$c['table_prefix'].'modules`')->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        foreach($modules as $module){
            $name=Id::identifier($module['name']);if(($only!==null&&$name!==$only)||strtolower($name)==='multiempresa'||($finished!==null&&!in_array($name,$finished,true))){continue;}
            $key=self::key($company,$name);$state=$this->read($key);
            if($c['status']==='maintenance'&&!$state){continue;}
            try{$source=$this->source($release['root'].'/modules/'.$name,$force);}
            catch(\Throwable $e){$this->diagnosis($c,$name,$e);continue;}
            if($state&&$state['phase']==='failed'&&!$force&&($state['fingerprint']??'')===$source['fingerprint']&&time()<(int)($state['retry_after']??0)){continue;}
            $pending=$state||!hash_equals($module['fingerprint'],$source['fingerprint'])||isset($native[$name])&&version_compare($native[$name]['installed_version'],$source['version'],'!=');
            if(!$pending){continue;}
            try{$this->update($company,$name,$source);}
            catch(Failure $e){if($e->reason==='operation_busy'){continue;}throw $e;}
            // A rollback restores the native registry for all packages; reload before processing the next one.
            $c=$this->app->catalog->company($company);if($c['status']==='maintenance'){return;}
        }
    }
    private function update(string $company,string $name,array $source): void
    {
        $control=new AdvisoryLock($this->app,'updates');$lock=new AdvisoryLock($this->app,'company:'.$company);
        $worker=new AdvisoryLock($this->app,'module-worker:'.$company);
        $c=$this->app->catalog->company($company);$key=self::key($company,$name);$state=$this->read($key);
        $gate=$this->app->db->one('SELECT state FROM pme_gate WHERE id=1');
        if($gate['state']!=='active'||(!in_array($c['status'],['active','suspended'],true)&&!$state)){return;}
        $release=$this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1');
        $module=$this->app->db->one('SELECT * FROM pme_modules WHERE company_id=? AND name=?',[$company,$name]);if(!$module){return;}
        $pdo=$this->ownedConnection($c);
        $q=$pdo->prepare('SELECT installed_version,active FROM `'.$c['table_prefix'].'modules` WHERE module_name=?');$q->execute([$name]);$native=$q->fetch(\PDO::FETCH_ASSOC);
        if($state&&$state['phase']==='applied'){
            $this->complete($c,$name,$state,$pdo);$state=null;$c=$this->app->catalog->company($company);
            $module=$this->app->db->one('SELECT * FROM pme_modules WHERE company_id=? AND name=?',[$company,$name]);
        }elseif($state&&in_array($state['phase'],['migrating','restoring'],true)){
            $this->restore($c,$name,$state);$state=$this->read($key);$c=$this->app->catalog->company($company);
            $module=$this->app->db->one('SELECT * FROM pme_modules WHERE company_id=? AND name=?',[$company,$name]);$q->execute([$name]);$native=$q->fetch(\PDO::FETCH_ASSOC);
        }
        $path=$release['root'].'/modules/'.$name;
        $source=$this->source($path,true);
        if($native&&version_compare($native['installed_version'],$source['version'],'>')){$this->diagnosis($c,$name,new Failure('module_downgrade','A versão do pacote é anterior à versão instalada. Nenhum rebaixamento foi aplicado.'));return;}
        if(!$state&&hash_equals($module['fingerprint'],$source['fingerprint'])&&(!$native||version_compare($native['installed_version'],$source['version'],'=='))){return;}
        $state=['company_id'=>$company,'module'=>$name,'fingerprint'=>$source['fingerprint'],'version'=>$source['version'],'release'=>$release,'status'=>$state['status']??($c['status']==='suspended'?'suspended':'active'),'active'=>$native?(int)$native['active']:0,'installed'=>(bool)$native,'phase'=>'backup','backup'=>null,'attempts'=>(int)($state['attempts']??0)+1,'reviews'=>$this->app->db->all('SELECT * FROM pme_module_reviews WHERE company_id=? AND name=?',[$company,$name])];
        // UPDATE drains native requests holding a shared company lease before any file/SQL change.
        $this->app->db->exec("UPDATE pme_companies SET status='maintenance',error_code='module_updating' WHERE id=?",[$company]);$this->save($key,$state);
        try{
            $state['backup']=(new Backups($this->app))->create($company);$state['checksum']=hash_file('sha256',$state['backup']);
            $state['before']=(new ModuleExtensions($this->app))->snapshot($company,$release);
            $state['phase']='migrating';$state['attempt']=Id::new();$this->save($key,$state);
            (new ModuleRuntime($this->app))->mount($path,$this->app->state('companies/'.$company.'/modules/'.$name),$c['table_prefix'],$source['fingerprint']);
            $this->app->db->transaction(function()use($company,$name,$source){
                $this->app->db->exec('UPDATE pme_modules SET fingerprint=? WHERE company_id=? AND name=?',[$source['fingerprint'],$company,$name]);
                $this->app->db->exec('DELETE FROM pme_module_reviews WHERE company_id=? AND name=?',[$company,$name]);
            });
            if($native){
                $provisioning=new DatabaseProvisioning($this->app);$provisioning->grantTables($company,$provisioning->moduleTables($path));
                $worker->close();
                Process::run([$this->app->config['php_binary'],dirname(__DIR__,2).'/scripts/tenant-worker.php'],['company_id'=>$company,'module'=>$name,'operation'=>'module_update','attempt'=>$state['attempt']],180);
                $worker=new AdvisoryLock($this->app,'module-worker:'.$company);
                $state=$this->read($key);
                if(!$state||$state['phase']!=='applied'){throw new Failure('module_update_unverified','A atualização não confirmou sua conclusão.');}
            }else{$state['phase']='applied';$this->save($key,$state);}
            $this->complete($c,$name,$state,$pdo);
        }catch(\Throwable $e){
            $this->diagnosis($c,$name,$e);
            $worker->close();$worker=new AdvisoryLock($this->app,'module-worker:'.$company);
            try{$this->restore($c,$name,$state);}
            catch(\Throwable $recovery){$this->diagnosis($c,$name,$recovery);throw new Failure('module_update_pending','Não foi possível concluir a atualização automática. A recuperação será retomada.',503);}
        }
    }
    private function complete(array $c,string $name,array $state,\PDO $pdo): void
    {
        if(!ModuleRuntime::ready($this->app->state('companies/'.$c['id'].'/modules/'.$name),$c['table_prefix'],$state['fingerprint'])){throw new Failure('module_update_code','Código privado da atualização está incompleto.');}
        $q=$pdo->prepare('SELECT installed_version,active FROM `'.$c['table_prefix'].'modules` WHERE module_name=?');$q->execute([$name]);$native=$q->fetch(\PDO::FETCH_ASSOC);
        if($state['installed']&&(!$native||version_compare($native['installed_version'],$state['version'],'!=')||(int)$native['active']!==$state['active'])){throw new Failure('module_update_version','A versão ou a situação do módulo não corresponde ao esperado.');}
        (new ModuleExtensions($this->app))->capture($c['id'],$state['release'],$name,$state['before']??[]);
        (new DatabaseProvisioning($this->app))->grantTables($c['id']);
        if($state['installed']){Files::write($this->app->state('companies/'.$c['id'].'/modules/'.$name.'/.pme-installed-'.$c['table_prefix']),$state['fingerprint']);}
        $this->app->db->transaction(function()use($c,$name,$state){
            $this->app->db->exec('UPDATE pme_modules SET fingerprint=?,active=? WHERE company_id=? AND name=?',[$state['fingerprint'],$state['active'],$c['id'],$name]);
            $this->app->db->exec('UPDATE pme_companies SET status=?,error_code=NULL WHERE id=?',[$state['status'],$c['id']]);
            $this->app->db->exec('DELETE FROM pme_settings WHERE name=?',[self::key($c['id'],$name)]);
            $this->app->audit->log('module.updated',null,$c['id'],['module'=>$name,'version'=>$state['version'],'active'=>(bool)$state['active'],'backup'=>$state['backup']]);
        });
        try{(new ExecutionLog($this->app))->record('module.update','done',null,$c['id'],$c['organization_id'],null,['module'=>$name,'version'=>$state['version'],'active'=>(bool)$state['active']]);}catch(\Throwable){error_log('Module update completion log unavailable.');}
    }
    private function restore(array $c,string $name,array $state): void
    {
        $state['phase']='restoring';$this->save(self::key($c['id'],$name),$state);
        if(!empty($state['backup'])){(new Backups($this->app))->rollbackOwned($c['id'],$state['backup'],$state['checksum']);}
        $this->app->db->transaction(function()use($c,$name,$state){
            $this->app->db->exec('DELETE FROM pme_module_reviews WHERE company_id=? AND name=?',[$c['id'],$name]);
            foreach($state['reviews']??[] as $r){$this->app->db->exec('INSERT INTO pme_module_reviews(release_id,company_id,name,fingerprint) VALUES(?,?,?,?)',[$r['release_id'],$c['id'],$name,$r['fingerprint']]);}
            $this->app->db->exec('UPDATE pme_companies SET status=?,error_code=NULL WHERE id=?',[$state['status'],$c['id']]);
        });
        $state['last_backup']=$state['backup'];$state['backup']=null;$state['phase']='failed';$state['retry_after']=time()+30;$this->save(self::key($c['id'],$name),$state);
    }
    private function diagnosis(array $c,string $name,\Throwable $e): void
    {(new ExecutionLog($this->app))->record('module.update','failed',null,$c['id'],$c['organization_id'],null,ExecutionLog::exception($e)+['module'=>$name]);}
}
