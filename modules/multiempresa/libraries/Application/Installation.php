<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\AdvisoryLock;

/** The existing CRM can belong to an organization, but is never a provisioned resource. */
final class Installation
{
    public const ID='installation';
    public function __construct(private App $app) {}
    public function get(): array
    {
        $row=$this->app->db->one("SELECT value FROM pme_settings WHERE name='installation_organization'");
        $id=$row?($this->app->vault->open($row['value'])['organization_id']??''):'';
        $org=$id?$this->app->db->one('SELECT id,name,active FROM pme_organizations WHERE id=?',[$id]):null;
        $table=Id::identifier($this->app->config['native_prefix'].'options');
        $q=$this->app->db->pdo->prepare('SELECT value FROM `'.$table.'` WHERE name=?');$q->execute(['companyname']);
        return ['id'=>self::ID,'name'=>(string)($q->fetchColumn()?:'Empresa principal'),'organization_id'=>$org['id']??'','organization_name'=>$org['name']??'','active'=>(bool)($org['active']??false),'protected'=>true];
    }
    public function configure(array $actor,string $id): array
    {
        if(empty($actor['platform_admin'])){throw new Failure('platform_admin','Somente o administrador global pode vincular a instalação principal.',403);}
        $lock=new AdvisoryLock($this->app,'installation-organization');
        if($id!==''&&!$this->app->db->one('SELECT id FROM pme_organizations WHERE id=? AND active=1',[$id])){throw new Failure('organization_missing','Selecione uma organização ativa.');}
        $this->app->db->transaction(function()use($id,$actor){
            $this->app->db->exec("DELETE FROM pme_settings WHERE name='installation_organization'");
            if($id!==''){$this->app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)',['installation_organization',$this->app->vault->seal(['organization_id'=>$id])]);}
            $this->app->audit->log('installation.organization_changed',$actor['id'],null,['organization_id'=>$id,'primary_data_preserved'=>true]);
        });
        (new IdentityCleanup($this->app))->reconcile();
        return $this->get();
    }
    public function canAccess(array $actor,?array $installation=null): bool
    {
        if(!empty($actor['platform_admin'])){return true;}
        $service=new StaffAccess($this->app);$preference=$service->preference($actor['id']);
        if(!$service->primaryAllowed($actor['id'])){return false;}
        if(isset($preference['principal_access'])){return (bool)$preference['principal_access'];}
        return empty($preference['identity_company']);
    }

    public function switchTarget(array $actor,string $company): array
    {
        $installation=$this->get();$target=$this->app->catalog->company($company);
        if(!$this->canAccess($actor,$installation)){throw new Failure('installation_access','Acesso à instalação principal não autorizado.',403);}
        $this->assertSameOrganization($target['organization_id'],$installation);
        $this->app->catalog->membership($actor['id'],$company);
        if($target['status']!=='active'){throw new Failure('company_unavailable','Empresa indisponível.',409);}
        $this->app->db->exec('DELETE FROM pme_sessions WHERE user_id=?',[$actor['id']]);
        $this->app->audit->log('company.switched_from_installation',$actor['id'],$company,['organization_id'=>$target['organization_id']]);
        return $target;
    }
    public function returnRequest(array $session,string $source): string
    {
        $installation=$this->get();$company=$this->app->catalog->company($source);
        if($session['company_id']!==$source||!$this->canAccess(['id'=>$session['user_id'],'platform_admin'=>$session['platform_admin']??0],$installation)){throw new Failure('organization_scope','A instalação principal não pertence à organização atual.',403);}
        $this->app->catalog->membership($session['user_id'],$source);
        $this->assertSameOrganization($company['organization_id'],$installation);
        $raw=Id::token();
        $this->app->db->exec('INSERT INTO pme_switch_tickets(token_hash,user_id,organization_id,source_company,target_company,auth_version,mfa,expires_at) VALUES(?,?,?,?,?,?,?,?)',[hash('sha256',$raw),$session['user_id'],$company['organization_id'],$source,self::ID,(int)$session['auth_version'],(int)$session['mfa'],time()+90]);
        return $raw;
    }
    public function returnToNative(array $actor,string $raw): void
    {
        $t=(new CompanySwitch($this->app))->pending($raw);$installation=$this->get();
        if($t['target_company']!==self::ID||$t['user_id']!==$actor['id']||(int)$t['auth_version']!==(int)$actor['auth_version']||!$this->canAccess($actor,$installation)){throw new Failure('switch_identity','Esta seleção não pertence à sessão ou organização atual.',403);}
        $source=$this->app->catalog->company($t['source_company']);
        $this->assertSameOrganization($source['organization_id'],$installation);
        $this->app->catalog->membership($actor['id'],$t['source_company']);
        $this->app->db->transaction(function()use($t,$actor){
            if($this->app->db->exec('UPDATE pme_switch_tickets SET consumed=1 WHERE token_hash=? AND consumed=0',[$t['token_hash']])!==1){throw new Failure('switch_expired','Seleção já utilizada.',403);}
            $this->app->db->exec('DELETE FROM pme_sessions WHERE user_id=?',[$actor['id']]);
            (new StaffAccess($this->app))->remember($actor['id'],self::ID);
            $this->app->audit->log('company.returned_to_installation',$actor['id'],null,['source_company'=>$t['source_company'],'organization_id'=>$t['organization_id']]);
        });
    }
    public function sameOrganization(string $organization,?array $installation=null): bool
    {
        $installation??=$this->get();
        return $organization!==''&&!empty($installation['active'])&&$installation['organization_id']===$organization;
    }
    private function assertSameOrganization(string $organization,array $installation): void
    {
        if(!$this->sameOrganization($organization,$installation)){throw new Failure('organization_scope','A alternância permite somente empresas da mesma organização. Use a administração central para gerenciar outros grupos.',403);}
    }
}
