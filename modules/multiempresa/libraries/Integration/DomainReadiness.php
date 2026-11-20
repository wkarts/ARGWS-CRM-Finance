<?php
declare(strict_types=1);
namespace Multiempresa\Integration;
use Multiempresa\Application\App;
use Multiempresa\Infrastructure\HttpClient;

/** Promote a managed hostname only after valid HTTPS reaches this exact installation. */
final class DomainReadiness
{
    public function __construct(private App $app,private ?HttpClient $http=null){$this->http??=new HttpClient();}
    public function proof(string $host): string{return $this->app->vault->mac('domain-ready:'.strtolower($host));}
    public function ready(string $host): bool
    {
        try{$r=$this->http->json('GET','https://'.$host.'/__pme_domain_check',[],null,3);return isset($r['proof'])&&is_string($r['proof'])&&hash_equals($this->proof($host),$r['proof']);}
        catch(\Throwable){return false;}
    }
    public function refreshPending(int $limit=3): void
    {
        if(!empty((new \Multiempresa\Application\GlobalSettings($this->app))->get()['dns']['dry_run'])){return;}
        $checked=0;$limit=max(1,$limit);
        $rows=$this->app->db->all("SELECT oc.organization_id FROM pme_organization_config oc JOIN pme_organizations o ON o.id=oc.organization_id WHERE o.active=1 AND oc.status IN ('pending_tls','pending_domain') ORDER BY oc.updated_at,oc.organization_id LIMIT 10");
        foreach($rows as $org){
            if($checked>=$limit){break;}
            try{$lock=new \Multiempresa\Infrastructure\AdvisoryLock($this->app,'organization:'.$org['organization_id']);}
            catch(\Multiempresa\Domain\Failure $e){if($e->reason==='operation_busy'){continue;}throw $e;}
            try{
                $row=$this->app->db->one('SELECT data,status FROM pme_organization_config WHERE organization_id=?',[$org['organization_id']]);
                if(!$row||!in_array($row['status'],['pending_tls','pending_domain'],true)){continue;}
                $conf=json_decode($row['data'],true);$cfg=$conf['applied_dns']??[];
                if(empty($cfg['enabled'])){continue;}
                $hosts=[['host'=>$conf['host']??'','company'=>null]];
                foreach($conf['pending_company_hosts']??[] as $company=>$host){$hosts[]=['host'=>$host,'company'=>$company];}
                $size=count($hosts);$cursor=(int)($conf['readiness_cursor']??0)%$size;$allReady=true;
                for($offset=0;$offset<$size;$offset++){
                    $index=($cursor+$offset)%$size;$item=$hosts[$index];$host=$item['host'];
                    $table=$item['company']?'pme_hosts':'pme_organization_hosts';$column=$item['company']?'company_id':'organization_id';$id=$item['company']??$org['organization_id'];
                    $entry=$this->app->db->one('SELECT verified FROM '.$table.' WHERE host=? AND '.$column.'=?',[$host,$id]);
                    if(!$entry||(int)$entry['verified']){continue;}
                    if(!empty($cfg['custom_hostname_enabled'])){$tls=$this->app->db->one('SELECT status FROM pme_resources WHERE resource_key=?',['tls:'.$cfg['zone_id'].':'.$host]);if(($tls['status']??'')!=='ready'){$allReady=false;continue;}}
                    if($checked>=$limit){$allReady=false;break;}
                    $checked++;$conf['readiness_cursor']=($index+1)%$size;
                    if($this->ready($host)){$this->app->db->exec('UPDATE '.$table.' SET verified=1 WHERE host=? AND '.$column.'=?',[$host,$id]);}else{$allReady=false;}
                }
                // Rotate both organizations and their hosts so an unavailable first host cannot starve the rest.
                $this->app->db->exec('UPDATE pme_organization_config SET data=?,status=?,updated_at=? WHERE organization_id=?',[json_encode($conf,JSON_THROW_ON_ERROR),$allReady?'ready':$row['status'],time(),$org['organization_id']]);
            }finally{$lock->close();}
        }
    }
}
