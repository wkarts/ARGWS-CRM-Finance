<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\{Failure,Id};

final class Resources
{
    public function __construct(private App $app) {}
    public function find(string $key): ?array
    {
        $r=$this->app->db->one('SELECT * FROM pme_resources WHERE resource_key=?',[$key]);if($r){$r['data']=$this->app->vault->open($r['data']);}return $r;
    }
    public function reserve(string $org,?string $company,string $kind,string $key,array $data): array
    {
        $old=$this->find($key);if($old){if($old['organization_id']!==$org||$old['company_id']!==$company||$old['kind']!==$kind){throw new Failure('resource_owner','Recurso já reservado por outra empresa ou organização.',409);}return $old;}
        $this->app->db->exec('INSERT INTO pme_resources(id,organization_id,company_id,kind,resource_key,data,status,updated_at) VALUES(?,?,?,?,?,?,?,?)',[Id::new(),$org,$company,$kind,$key,$this->app->vault->seal($data),'reserved',time()]);return $this->find($key);
    }
    public function update(string $key,array $data,string $status='ready'): void
    {
        $this->app->db->exec('UPDATE pme_resources SET data=?,status=?,updated_at=? WHERE resource_key=?',[$this->app->vault->seal($data),$status,time(),$key]);
    }
    public function list(string $org,?string $company=null): array
    {
        $args=[$org];$where='organization_id=?';if($company!==null){$where.=' AND company_id=?';$args[]=$company;}
        $rows=$this->app->db->all('SELECT * FROM pme_resources WHERE '.$where.' ORDER BY kind,resource_key',$args);foreach($rows as &$r){$r['data']=$this->app->vault->open($r['data']);}unset($r);return $rows;
    }
    public function publicList(string $org,?string $company=null): array
    {
        return array_map(static function($r){$d=array_intersect_key($r['data'],array_flip(['host','name','type','target','hostname_id','hostname_status','ssl_status','record_id','zone_id','database','prefix','username','tables']));return ['id'=>$r['id'],'kind'=>$r['kind'],'status'=>$r['status'],'company_id'=>$r['company_id'],'details'=>$d];},$this->list($org,$company));
    }
}
