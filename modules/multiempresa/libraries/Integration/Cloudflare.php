<?php
declare(strict_types=1);
namespace Multiempresa\Integration;
use Multiempresa\Application\{App,Resources,ExecutionLog,GlobalSettings};
use Multiempresa\Infrastructure\{HttpClient,AdvisoryLock};
use Multiempresa\Domain\Failure;

final class Cloudflare
{
    // Shared installation resource, not an organization or a removable principal company.
    public const SHARED='00000000000000000000000000000000';
    public function __construct(private App $app,private ?HttpClient $http=null){$this->http??=new HttpClient();}
    public function api(array $cfg,string $method,string $path,?array $body=null): array
    {
        $zone=strtolower(trim($cfg['zone_id']??''));$token=trim(preg_replace('/\ABearer\s+/i','',trim($cfg['token']??'')));
        if(!preg_match('/\A[a-f0-9]{32}\z/',$zone)||$token===''||preg_match('/[\r\n]/',$token)){throw new Failure('cloudflare_configuration','Configure a zona e o token da API Cloudflare.');}
        $base=rtrim($cfg['api_base_url']??'https://api.cloudflare.com/client/v4','/');
        if($base!=='https://api.cloudflare.com/client/v4'){throw new Failure('cloudflare_endpoint','O token só pode ser enviado à API oficial HTTPS da Cloudflare.');}
        if(!empty($cfg['dry_run'])&&$method!=='GET'){throw new Failure('dns_dry_run','A simulação está ativa. Nenhum recurso externo foi alterado.');}
        try{$r=$this->http->json($method,$base.'/zones/'.$zone.$path,['Authorization'=>'Bearer '.$token],$body,15);}
        catch(Failure $e){if(str_starts_with($e->reason,'http_')){$this->failure($e->diagnostic['http_status']??0,$e->diagnostic['provider_codes']??[],$method,$path,$e->reason);}throw $e;}
        if(empty($r['success'])){$this->failure(0,array_map(static fn($e)=>(int)($e['code']??0),$r['errors']??[]),$method,$path,'cloudflare_api');}
        return $r['result']??[];
    }
    private function failure(int $http,array $codes,string $method,string $path,string $reason): never
    {
        $ssl=str_starts_with($path,'/custom_hostnames');$operation=$ssl?'HTTPS':($path===''?'zona':'DNS');
        $message='A Cloudflare não concluiu a '.($method==='GET'?'consulta':'alteração').' de '.$operation.'.';
        if($http){$message.=' HTTP '.$http.'.';}if($codes){$message.=' Código(s): '.implode(', ',array_slice($codes,0,10)).'.';}
        if(in_array($http,[401,403],true)||array_intersect($codes,[10000,9109,10001])){$message.=' Confira se o token tem acesso ao ID desta zona e permissão de '.($ssl?'SSL e certificados':($path===''?'leitura da zona':'DNS')).'.';}
        if($http===429){$message.=' O limite de consultas foi atingido; tente novamente em alguns instantes.';}
        if($ssl){$message.=' A autoridade escolhida e a origem precisam ser compatíveis com o plano da conta.';}
        throw new Failure($reason,$message,503,['http_status'=>$http,'provider_codes'=>array_slice($codes,0,10),'method'=>$method,'service'=>$operation]);
    }
    public function diagnose(array $cfg): array
    {
        // DNS-only tokens need not have Zone Read permission. Check the actual endpoint used by provisioning first.
        try{$this->records($cfg,$cfg['base_domain']);}
        catch(Failure $e){
            if(!in_array($e->diagnostic['http_status']??0,[401,403],true)){throw $e;}
            $status=$this->tokenStatus($cfg);
            $detail=match($status){
                'active'=>' O token está ativo, mas a consulta de DNS desta zona foi recusada. Confira o ID da zona e o acesso DNS concedido a ela.',
                'inactive'=>' A verificação da Cloudflare informou que o token não está ativo.',
                default=>' A verificação adicional não confirmou o token. Confira se foi informado um API Token (Bearer), seu acesso à zona e eventuais restrições de IP.',
            };
            throw new Failure($e->reason,$e->getMessage().$detail,$e->status,$e->diagnostic+['credential_status'=>$status]);
        }
        $zone=null;
        try{$zone=$this->api($cfg,'GET','');}catch(Failure $e){if(!in_array($e->reason,['http_403','cloudflare_api'],true)){throw $e;}}
        if($zone&&(!empty($cfg['zone_name'])&&strtolower($zone['name']??'')!==$cfg['zone_name'])){throw new Failure('dns_zone_name','O ID da zona corresponde a outro domínio. Confira o nome da zona.');}
        if($zone&&$cfg['base_domain']!==($zone['name']??'')&&!str_ends_with($cfg['base_domain'],'.'.($zone['name']??'!'))){throw new Failure('dns_zone_name','O domínio base não pertence ao ID de zona informado.');}
        return ['message'=>'Conexão e consulta DNS confirmadas'.($zone?' na zona '.$zone['name']:'').'. A permissão de escrita será verificada ao aplicar os endereços.','zone_name'=>$zone['name']??null];
    }
    private function tokenStatus(array $cfg): string
    {
        // Only a read-only diagnostic after the DNS request has validated endpoint/credentials.
        // Account-owned tokens may not support this user-token endpoint: do not call them invalid.
        $token=trim(preg_replace('/\ABearer\s+/i','',trim($cfg['token'])));
        try{$r=$this->http->json('GET','https://api.cloudflare.com/client/v4/user/tokens/verify',['Authorization'=>'Bearer '.$token],null,10);}
        catch(Failure){return 'unknown';}
        if(empty($r['success'])||!is_string($r['result']['status']??null)){return 'unknown';}
        return $r['result']['status']==='active'?'active':'inactive';
    }
    public function records(array $cfg,string $host): array
    {
        $all=[];for($page=1;$page<=20;$page++){$rows=$this->api($cfg,'GET','/dns_records?name='.rawurlencode($host).'&per_page=100&page='.$page);$all=array_merge($all,$rows);if(count($rows)<100){return $all;}}
        throw new Failure('dns_record_limit','Há registros demais neste endereço. Revise a zona antes de continuar.');
    }
    public function ensure(array $cfg,string $org,?string $company,string $host,string $target,?string $actor=null,?string $execution=null): array
    {
        if(!empty($cfg['managed_wildcard_dns'])){
            $host='*.'.$cfg['base_domain'];$target=$cfg['managed_wildcard_target']?:$target;$org=self::SHARED;$company=null;
        }
        $host=strtolower($host);$target=GlobalSettings::target($cfg['type'],$target);$key='dns:'.$cfg['zone_id'].':'.$host;
        $lock=new AdvisoryLock($this->app,$key);$ledger=new Resources($this->app);
        $tag='multiempresa:'.substr($this->app->vault->mac('dns-owner'),0,16).':'.($company??$org);
        if(!empty($cfg['dry_run'])){return ['host'=>$host,'type'=>$cfg['type'],'target'=>$target,'simulated'=>true];}
        $r=$ledger->reserve($org,$company,'dns',$key,['host'=>$host,'zone_id'=>$cfg['zone_id'],'owner'=>$tag,'write_attempted'=>false]);
        $matches=$this->records($cfg,$host);$existing=null;
        foreach($matches as $record){if(strtolower($record['name']??'')!==$host){continue;}if(($record['comment']??'')!==$tag){if(!in_array($record['type']??'', ['A','AAAA','CNAME'],true)&&$cfg['type']!=='CNAME'){continue;}throw new Failure('dns_owned_elsewhere','O endereço '.$host.' já possui um registro que não pertence a este recurso.');}if($existing){throw new Failure('dns_duplicate','Há mais de um registro administrado para este endereço. Revise a zona.');}$existing=$record;}
        if(!empty($r['data']['record_id'])&&$existing&&$existing['id']!==$r['data']['record_id']){throw new Failure('dns_record_changed','O identificador do registro DNS foi alterado fora do módulo.');}
        $body=['type'=>$cfg['type'],'name'=>$host,'content'=>$target,'ttl'=>$cfg['proxied']?1:(int)$cfg['ttl'],'proxied'=>(bool)$cfg['proxied'],'comment'=>$tag];
        $same=$existing&&$existing['type']===$body['type']&&$existing['content']===$body['content']&&(int)($existing['ttl']??0)===$body['ttl']&&(bool)($existing['proxied']??false)===$body['proxied'];
        $data=$r['data'];$data['write_attempted']=true;
        if(!$same){$ledger->update($key,$data,'creating');}
        try{$record=$same?$existing:($existing?$this->api($cfg,'PATCH','/dns_records/'.$this->id($existing['id']),$body):$this->api($cfg,'POST','/dns_records',$body));}
        catch(Failure $e){$status=$e->diagnostic['http_status']??0;if(!$existing&&!isset($data['record_id'])&&$status>=400&&$status<500){$data['write_attempted']=false;$ledger->update($key,$data,'reserved');}throw $e;}
        $data=['host'=>$host,'zone_id'=>$cfg['zone_id'],'record_id'=>$this->id($record['id']??''),'owner'=>$tag,'type'=>$cfg['type'],'target'=>$target,'ttl'=>$body['ttl'],'proxied'=>$body['proxied'],'write_attempted'=>true];$ledger->update($key,$data);
        if(!$same){(new ExecutionLog($this->app))->record('dns.'.($existing?'updated':'created'),'done',$actor,$company,$org,$execution,['host'=>$host,'record_id'=>$data['record_id'],'type'=>$cfg['type']]);}return $data;
    }
    private function uncreated(array $r): bool
    {
        if(!empty($r['data']['record_id'])){return false;}
        if(array_key_exists('write_attempted',$r['data'])){return !$r['data']['write_attempted'];}
        // Compatibility with old reservations: require the logged initial GET failure of this exact DNS plan.
        if($r['status']!=='reserved'){return false;}
        foreach($this->app->db->all("SELECT execution_id,details FROM pme_execution_logs WHERE organization_id=? AND status='failed' AND stage='impact.failed' AND created_at>=? ORDER BY created_at LIMIT 100",[$r['organization_id'],$r['updated_at']]) as $row){
            $d=json_decode($row['details'],true);$getFailure=false;
            foreach($d['trace']??[] as $frame){if(($frame['class']??'')===self::class&&($frame['function']??'')==='records'){$getFailure=true;}}
            if(!$getFailure){continue;}
            $confirmed=$this->app->db->one("SELECT details FROM pme_execution_logs WHERE execution_id=? AND stage='impact.confirmed'",[$row['execution_id']]);$plan=json_decode($confirmed['details']??'{}',true);
            if(($plan['operation']??'')!=='dns'){continue;}
            foreach($plan['impact']['dns_records']??[] as $record){if(($record['name']??'')===$r['data']['host']){return true;}}
        }return false;
    }
    public function remove(array $cfg,array $resource,?string $actor=null,?string $execution=null): void
    {
        $d=$resource['data'];if($resource['kind']!=='dns'){throw new Failure('dns_scope','Recurso DNS inválido.');}
        $lock=new AdvisoryLock($this->app,$resource['resource_key']);
        if(!empty($cfg['dry_run'])&&!$this->uncreated($resource)){throw new Failure('dns_dry_run','Desative a simulação para excluir registros DNS, ou escolha preservar o DNS nesta exclusão.');}
        if(!$this->uncreated($resource)){
            // A changed default zone must not redirect deletion to a different zone.
            $cfg['zone_id']=$d['zone_id'];$records=$this->records($cfg,$d['host']);
            foreach($records as $r){if(strtolower($r['name']??'')!==$d['host']){continue;}
                if(!empty($d['record_id'])&&$r['id']!==$d['record_id']){if(($r['comment']??'')===$d['owner']){throw new Failure('dns_owner','O identificador do registro administrado foi alterado. Revise a zona.');}continue;}
                if(empty($d['record_id'])&&($r['comment']??'')!==$d['owner']){continue;}
                if(($r['comment']??'')!==$d['owner']){throw new Failure('dns_owner','O registro DNS foi modificado fora do módulo; a exclusão foi interrompida.');}
                $this->api($cfg,'DELETE','/dns_records/'.$this->id($r['id']));
            }
        }
        (new Resources($this->app))->update($resource['resource_key'],$d,'deleted');(new ExecutionLog($this->app))->record('dns.deleted','done',$actor,$resource['company_id'],$resource['organization_id'],$execution,['host'=>$d['host'],'record_id'=>$d['record_id']??null]);
    }
    private function id(string $id): string{if(!preg_match('/\A[a-f0-9]{32}\z/',$id)){throw new Failure('cloudflare_id','Identificador DNS inválido.');}return $id;}
}
