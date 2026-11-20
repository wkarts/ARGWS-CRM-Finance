<?php
declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Domain\{Context,Failure,Id};
use Multiempresa\Infrastructure\{AdvisoryLock,Files};

/** Only state changes for previously approved code are exposed to a company. */
final class TenantModules
{
    public function __construct(private App $app) {}
    public function change(Context $ctx,array $actor,string $name,bool $active): void
    {
        if(($actor['company_id']??null)!==$ctx->companyId){throw new Failure('module_access','Acesso não autorizado.',403);}
        $this->app->catalog->membership($actor['user_id'],$ctx->companyId);
        $pdo=$this->app->storage($ctx->mode)->connect($ctx->connection);
        $q=$pdo->prepare('SELECT active,admin,email FROM `'.Id::prefix($ctx->prefix, $ctx->mode).'staff` WHERE staffid=?');$q->execute([$actor['staff_id']]);$staff=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$staff||!(int)$staff['active']||!(int)$staff['admin']||strtolower($staff['email'])!==$actor['email']){throw new Failure('module_access','Somente administradores desta empresa podem alterar seus módulos.',403);}
        Id::identifier($name);
        (new ModuleMaintenance($this->app))->ensure($ctx->companyId,true,true,false,$name);
        $row=$this->app->db->one('SELECT * FROM pme_modules WHERE company_id=? AND name=?',[$ctx->companyId,$name]);
        if(!$row||strtolower($name)==='multiempresa'){throw new Failure('module_unavailable','Este módulo não está disponível para esta empresa.',403);}
        $release=$this->app->db->one('SELECT r.* FROM pme_releases r JOIN pme_gate g ON g.release_id=r.id WHERE g.id=1');
        $path=$release['root'].'/modules/'.$name;
        if(!is_file($path.'/'.$name.'.php')||!hash_equals($row['fingerprint'],Files::treeHash($path))){throw new Failure('module_update','A atualização deste módulo deve ser concluída na instalação principal.',409);}
        $q=$pdo->prepare('SELECT installed_version FROM `'.Id::prefix($ctx->prefix, $ctx->mode).'modules` WHERE module_name=?');$q->execute([$name]);$installed=$q->fetchColumn();
        preg_match('/^\s*Version:\s*(.+)$/mi',file_get_contents($path.'/'.$name.'.php',false,null,0,8192),$header);
        if(!isset($header[1])||($installed!==false&&version_compare((string)$installed,trim($header[1]),'!='))){throw new Failure('module_update','A atualização deste módulo deve ser concluída na principal.',409);}
        if((bool)$row['active']===$active){return;}
        // Executed before the request obtains a shared release lease. The services own their locks/backups.
        $service=new Modules($this->app);
        if($active){$service->approve($ctx->companyId,$name,true);}else{$service->disable($ctx->companyId,$name);}
        $this->app->audit->log('module.state_changed',$actor['user_id'],$ctx->companyId,['module'=>$name,'active'=>$active]);
    }
}
