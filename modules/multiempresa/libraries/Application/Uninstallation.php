<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\AdvisoryLock;

/** Reviewable removal with durable progress. Native CRM data never enters the tenant inventory. */
final class Uninstallation
{
    public function __construct(private App $app) {}
    private function admin(array $actor): void
    {
        if(empty($actor['platform_admin'])){throw new Failure('platform_admin','Somente o administrador global pode remover o módulo.',403);}
    }
    private function save(array $state): void
    {
        $this->app->db->transaction(function()use($state){$key='uninstall:'.$state['id'];$this->app->db->exec('DELETE FROM pme_settings WHERE name=?',[$key]);$this->app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)',[$key,$this->app->vault->seal($state)]);});
    }
    public function state(array $actor,string $id): array
    {
        $this->admin($actor);$row=$this->app->db->one('SELECT value FROM pme_settings WHERE name=?',['uninstall:'.$id])??throw new Failure('uninstall_missing','Revisão não encontrada.',404);
        $state=$this->app->vault->open($row['value']);
        if($state['actor_id']!==$actor['id']){throw new Failure('uninstall_owner','Esta revisão pertence a outro administrador.',403);}
        return $state;
    }
    public function latest(array $actor): ?array
    {
        $this->admin($actor);$latest=null;
        foreach($this->app->db->all("SELECT value FROM pme_settings WHERE name LIKE 'uninstall:%'") as $row){$s=$this->app->vault->open($row['value']);if($s['actor_id']===$actor['id']&&in_array($s['status'],['running','failed','ready'],true)&&(!$latest||$s['created_at']>$latest['created_at'])){$latest=$s;}}
        return $latest;
    }
    public function prepare(array $actor,string $mode): array
    {
        $this->admin($actor);if(!in_array($mode,['keep','remove'],true)){throw new Failure('uninstall_mode','Selecione preservar ou remover as empresas.');}
        $lock=new AdvisoryLock($this->app,'module-uninstall');
        foreach($this->app->db->all("SELECT value FROM pme_settings WHERE name LIKE 'uninstall:%'") as $row){$active=$this->app->vault->open($row['value']);if($active['status']==='running'){throw new Failure('uninstall_running','Conclua a execução em andamento antes de preparar outra revisão.',409);}}
        $orgs=$this->app->db->all('SELECT id,name FROM pme_organizations ORDER BY id');
        $state=['id'=>Id::new(),'actor_id'=>$actor['id'],'mode'=>$mode,'status'=>'prepared','created_at'=>time(),'expires_at'=>time()+900,'step'=>0,'steps'=>[],'organization_ids'=>array_column($orgs,'id'),'company_ids'=>array_column($this->app->db->all('SELECT id FROM pme_companies ORDER BY id'),'id'),'installation'=>(new Installation($this->app))->get()];
        if($mode==='remove'){
            foreach($orgs as $org){$plan=(new ImpactPlans($this->app))->create($actor,'delete_organization',$org['id'],['delete_data'=>true,'delete_files'=>true,'delete_dns'=>true,'delete_backups'=>true]);$state['steps'][]=['organization_id'=>$org['id'],'label'=>$org['name'],'status'=>'pending','plan_id'=>$plan['plan_id'],'name'=>$plan['confirmation_name'],'impact'=>$plan['impact']];}
        }else{
            foreach($orgs as $org){$state['steps'][]=['organization_id'=>$org['id'],'label'=>$org['name'],'status'=>'preserved','impact'=>['companies'=>$this->app->db->all('SELECT id,name,status,table_prefix AS prefix FROM pme_companies WHERE organization_id=? ORDER BY name',[$org['id']]),'organization_resources'=>[]]];}
        }
        $state['shared_dns']=(new Resources($this->app))->publicList(\Multiempresa\Integration\Cloudflare::SHARED);
        $state['warnings']=[
            $mode==='remove'?'Os registros wildcard compartilhados criados pelo módulo também serão removidos.':'Os registros wildcard compartilhados serão preservados.',
            'A instalação principal, seu banco, arquivos, usuários, configurações e demais módulos serão mantidos.',
            $mode==='keep'?'Empresas, bancos, tabelas, arquivos, certificados, backups e registros DNS serão preservados. O acesso administrado pelo Multiempresa ficará indisponível até reinstalar e ativar o módulo.':'Os cadastros e os recursos empresariais listados serão removidos: tabelas, bancos criados pelo módulo quando vazios, contas MySQL geradas, arquivos, certificados, backups e DNS administrado. Bancos manuais e recursos externos sem propriedade comprovada serão preservados.',
            $mode==='keep'?'O histórico e a configuração privada serão preservados para reinstalação.':'Após concluir a remoção, o histórico, a configuração privada e as tabelas do Multiempresa também serão excluídos.',
            'Uma falha mantém o módulo e o diagnóstico disponíveis para concluir a remoção. Etapas concluídas não são desfeitas.'
        ];
        $this->save($state);return $state;
    }
    public function confirm(array $actor,string $id,string $name,bool $accepted): array
    {
        $lock=new AdvisoryLock($this->app,'module-uninstall');$s=$this->state($actor,$id);
        if($s['status']!=='prepared'||$s['expires_at']<time()){throw new Failure('plan_expired','Gere uma nova revisão antes de remover o módulo.',409);}
        if(!$accepted||trim($name)!=='Multiempresa'){throw new Failure('confirmation','Confirme o escopo e digite Multiempresa.');}
        if($s['organization_ids']!==array_column($this->app->db->all('SELECT id FROM pme_organizations ORDER BY id'),'id')||$s['company_ids']!==array_column($this->app->db->all('SELECT id FROM pme_companies ORDER BY id'),'id')||$s['installation']!==(new Installation($this->app))->get()){throw new Failure('plan_changed','O cadastro mudou. Revise os recursos novamente.',409);}
        if(($s['shared_dns']??[])!==(new Resources($this->app))->publicList(\Multiempresa\Integration\Cloudflare::SHARED)){throw new Failure('plan_changed','O DNS compartilhado mudou. Revise a remoção novamente.',409);}
        $this->app->db->transaction(function()use(&$s,$actor){
            $table=Id::identifier($this->app->config['native_prefix'].'modules');$q=$this->app->db->pdo->prepare('UPDATE `'.$table.'` SET active=0 WHERE module_name=?');$q->execute(['multiempresa']);
            $this->app->db->exec('DELETE FROM pme_sessions');$this->app->db->exec('DELETE FROM pme_tickets');
            $s['status']=$s['mode']==='keep'?'ready':'running';$s['confirmed_at']=time();$this->save($s);
            (new ExecutionLog($this->app))->record('uninstall.confirmed','running',$actor['id'],null,null,$s['id'],['mode'=>$s['mode'],'organizations'=>$s['organization_ids'],'companies'=>$s['company_ids'],'primary_protected'=>true]);
        });return $s;
    }
    public function advance(array $actor,string $id): array
    {
        $lock=new AdvisoryLock($this->app,'module-uninstall');$s=$this->state($actor,$id);
        if($s['status']!=='running'){return $s;}
        try{
            if($s['step']<count($s['steps'])){
                $step=&$s['steps'][$s['step']];
                (new ImpactPlans($this->app))->confirm($actor,$step['plan_id'],$step['name'],true);
                $step['status']='done';$s['step']++;unset($step);$this->save($s);
            }else{
                if(array_diff(array_column($this->app->db->all('SELECT id FROM pme_companies ORDER BY id'),'id'),$s['company_ids'])){throw new Failure('plan_changed','Novas empresas foram encontradas. Revise a remoção novamente.',409);}
                if($this->app->db->one("SELECT id FROM pme_companies WHERE status<>'removed' LIMIT 1")){throw new Failure('uninstall_incomplete','Há empresas que ainda não foram removidas.',409);}
                foreach((new Resources($this->app))->list(\Multiempresa\Integration\Cloudflare::SHARED) as $resource){if($resource['kind']==='dns'&&$resource['status']!=='deleted'){(new \Multiempresa\Integration\Cloudflare($this->app))->remove((new GlobalSettings($this->app))->get()['dns'],$resource,$actor['id'],$id);}}
                $this->purgeCatalog($id);$s['status']='ready';$this->save($s);
                (new ExecutionLog($this->app))->record('uninstall.resources_removed','done',$actor['id'],null,null,$id,['primary_protected'=>true]);
            }
        }catch(\Throwable $error){$s['status']='failed';$s['diagnosis']=$error instanceof Failure?$error->getMessage():'A remoção falhou. Consulte os logs e revise os recursos restantes.';$this->save($s);(new ExecutionLog($this->app))->record('uninstall.failed','failed',$actor['id'],null,null,$id,ExecutionLog::exception($error));}
        return $s;
    }
    private function purgeCatalog(string $id): void
    {
        // Only module-owned catalog rows; native tables and audit history are not touched.
        $this->app->db->transaction(function()use($id){
            foreach(['certificates','profiles','onboardings','module_reviews','modules','update_steps','updates','jobs','events','endpoints','tokens','tickets','switch_tickets','sessions','memberships','org_members','user_organizations','hosts','organization_hosts','organization_config','resources','plans','companies','organizations','lookup_cache','rate_limits'] as $table){$this->app->db->exec('DELETE FROM pme_'.$table);}
            $this->app->db->exec('DELETE FROM pme_settings WHERE name<>?',['uninstall:'.$id]);
        });
    }
    public function assertReady(array $actor,string $id): void
    {
        $lock=new AdvisoryLock($this->app,'module-uninstall');$s=$this->state($actor,$id);
        if($s['status']!=='ready'){throw new Failure('uninstall_incomplete','Conclua as etapas confirmadas antes de remover o código do módulo.',409);}
    }
    public function finish(array $actor,string $id): void
    {
        $this->assertReady($actor,$id);$s=$this->state($actor,$id);
        if($s['mode']==='remove'){
            // Owned paths only. Never follow company runtime links into the principal application.
            foreach(['bootstrap','companies','backups','recovery','tmp','locks','installation.php','install.lock'] as $path){$this->removeOwnedPath($this->app->state($path));}
            \Multiempresa\Infrastructure\Schema::uninstall($this->app->db);
            $directory=rtrim($this->app->state(),'/');if(is_dir($directory)&&count(scandir($directory))===2){rmdir($directory);}
            return;
        }
        $s['status']='done';$this->save($s);$this->app->audit->log('module.uninstalled',$actor['id'],null,['mode'=>$s['mode'],'primary_protected'=>true,'execution_id'=>$id]);
    }
    private function removeOwnedPath(string $path): void
    {
        if(is_link($path)||is_file($path)){if(!unlink($path)){throw new Failure('uninstall_files','Não foi possível remover um arquivo privado do módulo.');}return;}
        if(!is_dir($path)){return;}
        foreach(new \DirectoryIterator($path) as $entry){if(!$entry->isDot()){$this->removeOwnedPath($entry->getPathname());}}
        if(!rmdir($path)){throw new Failure('uninstall_files','Não foi possível remover um diretório privado do módulo.');}
    }
}
