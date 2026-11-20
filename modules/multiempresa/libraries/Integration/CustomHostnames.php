<?php
declare(strict_types=1);
namespace Multiempresa\Integration;
use Multiempresa\Application\{App,Resources,GlobalSettings,ExecutionLog};
use Multiempresa\Infrastructure\AdvisoryLock;
use Multiempresa\Domain\Failure;

/** Optional Cloudflare for SaaS certificates. No certificate or server configuration is fabricated. */
final class CustomHostnames
{
    private Cloudflare $cf;
    public function __construct(private App $app,?Cloudflare $cf=null){$this->cf=$cf??new Cloudflare($app);}
    private function id(string $id): string
    {
        if(!preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/',$id)){throw new Failure('hostname_id','Identificador de hostname inválido.');}return $id;
    }
    public function ensure(array $cfg,string $org,?string $company,string $host): array
    {
        if(!empty($cfg['dry_run'])){return ['ready'=>false,'simulated'=>true];}
        $key='tls:'.$cfg['zone_id'].':'.$host;$lock=new AdvisoryLock($this->app,$key);$ledger=new Resources($this->app);
        $r=$ledger->reserve($org,$company,'custom_hostname',$key,['host'=>$host,'zone_id'=>$cfg['zone_id'],'write_attempted'=>false]);
        $wanted=['custom_hostname_origin'=>$cfg['custom_hostname_origin']??'','custom_hostname_ca'=>$cfg['custom_hostname_ca']??'auto'];
        $body=['hostname'=>$host,'ssl'=>['method'=>'http','type'=>'dv']];
        if($wanted['custom_hostname_origin']!==''){$body['custom_origin_server']=$wanted['custom_hostname_origin'];}
        if($wanted['custom_hostname_ca']!=='auto'){$body['ssl']['certificate_authority']=$wanted['custom_hostname_ca'];}
        if(!empty($r['data']['hostname_id'])){
            $record=$this->cf->api($cfg,'GET','/custom_hostnames/'.$this->id($r['data']['hostname_id']));$this->owned($r,$record);
            if(($r['data']['configuration']??[])!==$wanted){$record=$this->cf->api($cfg,'PATCH','/custom_hostnames/'.$this->id($r['data']['hostname_id']),$body);}
        }else{
            $matches=$this->cf->api($cfg,'GET','/custom_hostnames?hostname='.rawurlencode($host).'&per_page=50');
            foreach($matches as $match){if(strtolower($match['hostname']??'')===$host){throw new Failure('hostname_owner','Já existe um hostname HTTPS sem vínculo comprovado com este módulo. O registro existente foi preservado.');}}
            $data=$r['data'];$data['write_attempted']=true;$ledger->update($key,$data,'creating');
            try{$record=$this->cf->api($cfg,'POST','/custom_hostnames',$body);}
            catch(Failure $e){$status=$e->diagnostic['http_status']??0;if($status>=400&&$status<500){$data['write_attempted']=false;$ledger->update($key,$data,'reserved');}throw $e;}
        }
        if(strtolower($record['hostname']??'')!==$host){throw new Failure('hostname_scope','A Cloudflare retornou outro hostname.');}
        $data=['host'=>$host,'zone_id'=>$cfg['zone_id'],'hostname_id'=>$this->id($record['id']??''),'configuration'=>$wanted,'write_attempted'=>true];
        return $this->save($r,$data,$record);
    }
    private function owned(array $r,array $record): void
    {
        if(($record['id']??'')!==($r['data']['hostname_id']??null)||strtolower($record['hostname']??'')!==$r['data']['host']){throw new Failure('hostname_owner','O hostname HTTPS foi alterado fora do módulo; a operação foi interrompida.');}
    }
    private function save(array $r,array $data,array $record): array
    {
        $data['hostname_status']=(string)($record['status']??'pending');$data['ssl_status']=(string)($record['ssl']['status']??'pending');
        $ready=$data['hostname_status']==='active'&&$data['ssl_status']==='active';
        (new Resources($this->app))->update($r['resource_key'],$data,$ready?'ready':'pending');
        return ['ready'=>$ready,'status'=>$data['hostname_status'],'ssl_status'=>$data['ssl_status']];
    }
    public function refreshPending(int $limit=5): void
    {
        $cfg=(new GlobalSettings($this->app))->get()['dns'];if(!$cfg['enabled']||!empty($cfg['dry_run'])||empty($cfg['custom_hostname_enabled'])){return;}
        $rows=$this->app->db->all("SELECT resource_key FROM pme_resources WHERE kind='custom_hostname' AND status IN ('pending','reserved') ORDER BY updated_at LIMIT ".max(1,min(10,$limit)));
        foreach($rows as $row){$r=(new Resources($this->app))->find($row['resource_key']);$config=array_replace($cfg,$r['data']['configuration']??[],['zone_id'=>$r['data']['zone_id']]);
            try{$result=$this->ensure($config,$r['organization_id'],$r['company_id'],$r['data']['host']);
                if($result['ready']){
                    $table=$r['company_id']?'pme_hosts':'pme_organization_hosts';$column=$r['company_id']?'company_id':'organization_id';$id=$r['company_id']??$r['organization_id'];
                    // Only the current requested hostname is promoted; old certificates cannot restore retired routes.
                    $conf=$this->app->db->one('SELECT data FROM pme_organization_config WHERE organization_id=?',[$r['organization_id']]);$settings=json_decode($conf['data']??'{}',true);$current=$r['company_id']?($settings['pending_company_hosts'][$r['company_id']]??null):($settings['host']??null);
                    if($current===$r['data']['host']&&(new DomainReadiness($this->app))->ready($current)){$this->app->db->exec('UPDATE '.$table.' SET verified=1 WHERE host=? AND '.$column.'=?',[$current,$id]);}

                }
            }catch(\Throwable $e){(new ExecutionLog($this->app))->record('tls.pending','failed',null,$r['company_id'],$r['organization_id'],null,ExecutionLog::exception($e));$this->app->db->exec('UPDATE pme_resources SET updated_at=? WHERE resource_key=?',[time(),$r['resource_key']]);}
        }
    }
    public function remove(array $cfg,array $r): void
    {
        $lock=new AdvisoryLock($this->app,$r['resource_key']);$d=$r['data'];$cfg['zone_id']=$d['zone_id'];
        if(!empty($d['hostname_id'])){
            if(!empty($cfg['dry_run'])){throw new Failure('dns_dry_run','Desative a simulação para excluir hostnames HTTPS, ou escolha preservar o DNS.');}
            try{$record=$this->cf->api($cfg,'GET','/custom_hostnames/'.$this->id($d['hostname_id']));}
            catch(Failure $e){if(($e->diagnostic['http_status']??0)!==404){throw $e;}$record=null;}
            if($record){$this->owned($r,$record);$this->cf->api($cfg,'DELETE','/custom_hostnames/'.$this->id($d['hostname_id']));}
        }elseif(!empty($d['write_attempted'])){throw new Failure('hostname_uncertain','Uma criação HTTPS foi interrompida sem confirmação. Confira o hostname na Cloudflare antes de removê-lo, ou escolha preservar o DNS.');}
        (new Resources($this->app))->update($r['resource_key'],$d,'deleted');
    }
}
