<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Integration\Cloudflare;
use Multiempresa\Infrastructure\AdvisoryLock;

final class Organizations
{
    public function __construct(private App $app) {}
    public function detail(array $actor,string $id): array
    {
        $this->app->catalog->assertManager($actor,$id);$r=$this->app->db->one('SELECT * FROM pme_organizations WHERE id=?',[$id])??throw new Failure('organization_missing','Organização não encontrada.',404);
        $c=$this->app->db->one('SELECT * FROM pme_organization_config WHERE organization_id=?',[$id]);$r['configuration']=$c?json_decode($c['data'],true,32,JSON_THROW_ON_ERROR):[];$r['status']=$c['status']??'draft';$r['resources']=(new Resources($this->app))->publicList($id);$r['companies']=$this->app->db->all('SELECT id,name,slug,status FROM pme_companies WHERE organization_id=? ORDER BY name',[$id]);$installation=(new Installation($this->app))->get();$r['installation']=$installation['organization_id']===$id?$installation:null;return $r;
    }
    public function grid(array $actor,array $query): array
    {
        $where=['1=1'];$args=[];if(empty($actor['platform_admin'])){$where[]="EXISTS(SELECT 1 FROM pme_org_members om WHERE om.organization_id=o.id AND om.user_id=? AND om.role='admin')";$args[]=$actor['id'];$where[]='o.id=?';$args[]=$this->app->catalog->organizationFor($actor['id'])??'';}
        if(!empty($query['search'])){$where[]="(LOWER(o.name) LIKE ? ESCAPE '!' OR LOWER(o.slug) LIKE ? ESCAPE '!')";$q='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower(mb_substr($query['search'],0,120))).'%';array_push($args,$q,$q);}
        if(($query['status']??'')!==''){$where[]="COALESCE(oc.status,'draft')=?";$args[]=$query['status'];}else{$where[]="COALESCE(oc.status,'draft')<>'removed'";}
        $from=' FROM pme_organizations o LEFT JOIN pme_organization_config oc ON oc.organization_id=o.id WHERE '.implode(' AND ',$where);$total=(int)$this->app->db->one('SELECT COUNT(*) AS total'.$from,$args)['total'];
        $size=in_array((int)($query['size']??20),[10,20,50,100],true)?(int)($query['size']??20):20;$pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,(int)($query['page']??1)));$sort=['name'=>'o.name','slug'=>'o.slug','status'=>"COALESCE(oc.status,'draft')"][$query['sort']??'name']??'o.name';$direction=($query['direction']??'asc')==='desc'?'DESC':'ASC';
        $rows=$this->app->db->all("SELECT o.*,COALESCE(oc.status,'draft') AS status,oc.data,(SELECT COUNT(*) FROM pme_companies c WHERE c.organization_id=o.id AND c.status<>'removed') AS company_count".$from.' ORDER BY '.$sort.' '.$direction.',o.id LIMIT '.$size.' OFFSET '.(($page-1)*$size),$args);
        $installation=(new Installation($this->app))->get();foreach($rows as &$row){$row['installation']=$installation['organization_id']===$row['id']?$installation:null;$row['configuration']=$row['data']?json_decode($row['data'],true,32,JSON_THROW_ON_ERROR):[];unset($row['data']);}return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$page,'size'=>$size];
    }
    public function save(array $actor,array $input): array
    {
        $id=(string)($input['id']??'');$lock=new AdvisoryLock($this->app,'organizations');
        if($id===''){$id=$this->app->catalog->createOrganization($actor,(string)($input['name']??''),(string)($input['slug']??''));}
        $organizationLock=new AdvisoryLock($this->app,'organization:'.$id);
        $row=$this->detail($actor,$id);if($row['status']==='removed'){throw new Failure('organization_removed','Organização removida.');}
        $name=Catalog::name((string)($input['name']??$row['name']));$config=$row['configuration'];
        $cfg=(new GlobalSettings($this->app))->get()['dns'];if($cfg['per_organization_target']&&array_key_exists('target',$input)){$config['target']=GlobalSettings::target($cfg['type'],(string)$input['target']);}
        // DNS changes are applied only by the explicit DNS operation, with its own preview.
        $config['host']=$this->hostname($cfg,$row['slug']);
        $this->app->db->transaction(function()use($id,$name,$input,$row,$config){$this->app->db->exec('UPDATE pme_organizations SET name=?,active=? WHERE id=?',[$name,array_key_exists('active',$input)?(int)(bool)$input['active']:(int)$row['active'],$id]);$this->app->db->exec('DELETE FROM pme_organization_config WHERE organization_id=?',[$id]);$this->app->db->exec('INSERT INTO pme_organization_config(organization_id,data,status,updated_at) VALUES(?,?,?,?)',[$id,json_encode($config,JSON_THROW_ON_ERROR),$row['status'],time()]);});
        $this->app->audit->log('organization.updated',$actor['id'],null,['organization_id'=>$id,'fields'=>['name','active','target']]);return $this->detail($actor,$id);
    }
    public function hostname(array $cfg,string $org,?string $company=null): string
    {
        if(!GlobalSettings::customDns($cfg)||$cfg['base_domain']===''){return '';}$pattern=$company===null?$cfg['organization_pattern']:$cfg['company_pattern'];$label=str_replace(['{org}','{company}'],[$org,$company??''],$pattern);$host=$label.'.'.$cfg['base_domain'];
        if(strlen($label)>63||strlen($host)>253||!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)){throw new Failure('dns_name','O endereço gerado é inválido. Reduza os identificadores ou ajuste o padrão global.');}return $host;
    }
    /** Preserve the applied organization routing until an organization-wide impact plan changes it. */
    public function effectiveDns(array $organization,array $cfg,bool $applyChanges=false): array
    {
        $applied=$organization['configuration']['applied_dns']??null;
        if(!$applyChanges&&$applied){
            if(!empty($applied['enabled'])&&$cfg['zone_id']!==$applied['zone_id']){throw new Failure('dns_zone_changed','A zona global mudou. Revise os endereços na organização antes de continuar.');}
            $effective=array_replace($cfg,$applied);
            // A shared wildcard always uses the current installation-wide destination.
            if(!empty($effective['managed_wildcard_dns'])&&!empty($cfg['managed_wildcard_dns'])&&$effective['base_domain']===$cfg['base_domain']){foreach(['type','target','ttl','proxied','managed_wildcard_target'] as $key){$effective[$key]=$cfg[$key];}}
            return $effective;
        }
        $cfg['target']=$cfg['per_organization_target']?($organization['configuration']['target']??''):$cfg['target'];
        return $cfg;
    }
    public function provisionDns(array $actor,string $org,?string $company=null,?string $execution=null,?array $snapshot=null,bool $applyChanges=false): array
    {
        $this->app->catalog->assertManager($actor,$org);$r=$this->detail($actor,$org);$cfg=$this->effectiveDns($r,$snapshot??(new GlobalSettings($this->app))->get()['dns'],$applyChanges);
        if(!GlobalSettings::customDns($cfg)){return ['configured'=>true,'managed'=>false,'message'=>'DNS opcional desativado. Acesso pelo endereço atual da aplicação.'];}
        $host=$this->hostname($cfg,$r['slug']);if($host===''){throw new Failure('dns_configuration','Informe o domínio base nas configurações globais para usar endereços personalizados.');}
        $target=$cfg['target'];$ready=true;$companyReady=true;
        if(!empty($cfg['enabled'])&&!empty($cfg['dry_run'])){return ['configured'=>true,'managed'=>false,'simulated'=>true,'message'=>'Simulação concluída. Nenhum registro ou endereço de acesso foi alterado.'];}
        $other=$this->app->db->one('SELECT organization_id FROM pme_organization_hosts WHERE host=?',[$host]);if($other&&$other['organization_id']!==$org){throw new Failure('organization_host','Este endereço pertence a outra organização.');}
        if($this->app->db->one('SELECT company_id FROM pme_hosts WHERE host=?',[$host])){throw new Failure('organization_host','Este endereço já pertence a uma empresa.');}
        if($cfg['enabled']){(new Cloudflare($this->app))->ensure($cfg,$org,null,$host,$target,$actor['id'],$execution);}
        $ready=$this->httpsReady($cfg,$org,null,$host);
        $this->app->db->transaction(function()use($host,$org,$ready){$this->app->db->exec('UPDATE pme_organization_hosts SET verified=0 WHERE organization_id=?',[$org]);$this->app->db->exec('DELETE FROM pme_organization_hosts WHERE host=? AND organization_id=?',[$host,$org]);$this->app->db->exec('INSERT INTO pme_organization_hosts(host,organization_id,verified) VALUES(?,?,?)',[$host,$org,0]);});
        if($ready&&!empty($cfg['enabled'])){$ready=(new \Multiempresa\Integration\DomainReadiness($this->app))->ready($host);}
        if($ready){$this->app->db->exec('UPDATE pme_organization_hosts SET verified=1 WHERE host=? AND organization_id=?',[$host,$org]);}
        if($company&&$cfg['address_mode']==='company'){
            $c=$this->app->catalog->company($company);if($c['organization_id']!==$org){throw new Failure('organization_scope','Empresa de outra organização.',403);}$companyHost=$this->hostname($cfg,$r['slug'],$c['slug']);
            if($companyHost===$host||$this->app->db->one('SELECT host FROM pme_organization_hosts WHERE host=?',[$companyHost])){throw new Failure('dns_collision','Endereço da empresa coincide com um endereço de organização.');}
            $other=$this->app->db->one('SELECT company_id FROM pme_hosts WHERE host=?',[$companyHost]);if($other&&$other['company_id']!==$company){throw new Failure('dns_collision','Endereço da empresa já utilizado.');}
            if($cfg['enabled']){(new Cloudflare($this->app))->ensure($cfg,$org,$company,$companyHost,$target,$actor['id'],$execution);}
            $companyReady=$this->httpsReady($cfg,$org,$company,$companyHost);
            $this->app->db->transaction(function()use($companyHost,$company,$companyReady){$this->app->db->exec('UPDATE pme_hosts SET verified=0 WHERE company_id=?',[$company]);$this->app->db->exec('DELETE FROM pme_hosts WHERE host=? AND company_id=?',[$companyHost,$company]);$this->app->db->exec('INSERT INTO pme_hosts(host,company_id,verified) VALUES(?,?,?)',[$companyHost,$company,0]);});
        }
        if(isset($companyHost)){if($companyReady&&!empty($cfg['enabled'])){$companyReady=(new \Multiempresa\Integration\DomainReadiness($this->app))->ready($companyHost);}if($companyReady){$this->app->db->exec('UPDATE pme_hosts SET verified=1 WHERE host=? AND company_id=?',[$companyHost,$company]);}}
        if($company && $cfg['address_mode']==='organization'){$this->app->db->exec('UPDATE pme_hosts SET verified=0 WHERE company_id=?',[$company]);}
        $conf=$r['configuration'];$conf['host']=$host;$conf['address_mode']=$cfg['address_mode'];$conf['applied_dns']=array_intersect_key($cfg,array_flip(['enabled','manual_confirmed','zone_id','base_domain','organization_pattern','company_pattern','address_mode','type','target','ttl','proxied','per_organization_target','managed_wildcard_dns','managed_wildcard_target','custom_hostname_enabled','custom_hostname_origin','custom_hostname_ca']));
        if(isset($companyHost)){$conf['pending_company_hosts'][$company]=$companyHost;}
        $this->app->db->exec('INSERT INTO pme_organization_config(organization_id,data,status,updated_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),status=VALUES(status),updated_at=VALUES(updated_at)',[$org,json_encode($conf,JSON_THROW_ON_ERROR),$cfg['enabled']?($ready&&$companyReady?'ready':(!empty($cfg['custom_hostname_enabled'])?'pending_tls':'pending_domain')):'manual',time()]);
        return ['configured'=>true,'organization_host'=>$host,'company_host'=>$companyHost??null,'managed'=>$cfg['enabled'],'https_pending'=>!$ready||!$companyReady,'message'=>(!$ready||!$companyReady)?'DNS aplicado. Endereço HTTPS em validação; o acesso atual continua disponível.':'Endereços aplicados.'];
    }
    private function httpsReady(array $cfg,string $org,?string $company,string $host): bool
    {
        if(empty($cfg['enabled'])||empty($cfg['custom_hostname_enabled'])){return true;}
        try{return (new \Multiempresa\Integration\CustomHostnames($this->app))->ensure($cfg,$org,$company,$host)['ready'];}
        catch(\Throwable $e){(new ExecutionLog($this->app))->record('tls.pending','failed',null,$company,$org,null,ExecutionLog::exception($e));return false;}
    }

}
