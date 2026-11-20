<?php
declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Domain\Failure;
use Multiempresa\Infrastructure\{AdvisoryLock,HttpClient};

/** Internal public-data lookup: no user settings, credentials or configurable destinations. */
final class Lookups
{
    private const PROVIDERS=['cnpj'=>['brasilapi','cnpjws','receitaws'],'cep'=>['viacep','brasilapi']];
    private const TIMEOUT=4;
    private const BUDGET=12;
    public function __construct(private App $app,private ?HttpClient $http=null){$this->http??=new HttpClient();}

    public function lookup(array $actor,string $type,string $value): array
    {
        if(!in_array($type,['cnpj','cep'],true)){throw new Failure('lookup_type','Consulta inválida.');}
        $value=$type==='cnpj'?CompanyProfile::cnpj($value):preg_replace('/[\s-]/','',$value);
        if($type==='cep'&&!preg_match('/\A[0-9]{8}\z/',$value)){throw new Failure('cep','CEP inválido.');}
        $key=hash('sha256','public-lookup-v1:'.$type.':'.$value);
        $cached=$this->cached($key);
        if($cached!==null){return $this->response($cached);}
        try{$lock=new AdvisoryLock($this->app,'lookup:'.$key);}catch(Failure $e){if($e->reason!=='operation_busy'){throw $e;}return $this->response([]);}
        $cached=$this->cached($key);
        if($cached!==null){return $this->response($cached);}
        $result=[];$attempts=[];$deadline=microtime(true)+self::BUDGET;
        foreach(self::PROVIDERS[$type] as $provider){
            $remaining=(int)floor($deadline-microtime(true));if($remaining<1){break;}
            try{
                // Public CNPJWS/ReceitaWS quotas are per origin, not per logged-in user.
                if(in_array($provider,['cnpjws','receitaws'],true)){$this->app->limits->hit('public-lookup:'.$provider,3,60);}
                $raw=$this->http->json('GET',$this->url($provider,$type,$value),[],null,min(self::TIMEOUT,$remaining));
                if(!empty($raw['erro'])||strtoupper((string)($raw['status']??''))==='ERROR'||is_numeric($raw['status']??null)&&(int)$raw['status']>=400){throw new Failure('lookup_not_found','Cadastro não encontrado.');}
                $data=$this->clean($this->map($provider,$type,$raw));
                if($type==='cnpj'&&isset($data['cnpj'])&&CompanyProfile::cnpj($data['cnpj'])!==$value){throw new Failure('lookup_identity','Resposta cadastral divergente.');}
                if($type==='cep'&&isset($data['cep'])&&$data['cep']!==$value){throw new Failure('lookup_identity','Resposta cadastral divergente.');}
                // An identifier, status message or country alone is not a successful lookup.
                $useful=$type==='cnpj'?['legal_name','trade_name','street','city']:['street','city','district'];
                if(!array_intersect($useful,array_keys($data))){$data=[];}
                foreach($data as $k=>$v){if(!isset($result[$k])){$result[$k]=$v;}}
                $attempts[]=['provider'=>$provider,'status'=>$data?'ok':'empty'];
                if($this->complete($type,$result)){break;}
            }catch(\Throwable $e){$attempts[]=['provider'=>$provider,'status'=>'failed','reason'=>$e instanceof Failure?$e->reason:'provider_error'];}
        }
        if($result){
            $expires=time()+($this->complete($type,$result)?21600:300);
            $this->app->db->transaction(function()use($key,$result,$expires){
                $this->app->db->exec('DELETE FROM pme_lookup_cache WHERE cache_key=? OR expires_at<?',[$key,time()]);
                $this->app->db->exec('INSERT INTO pme_lookup_cache(cache_key,data,expires_at) VALUES(?,?,?)',[$key,$this->app->vault->seal($result),$expires]);
            });
        }
        $this->app->audit->log('lookup.'.$type,$actor['id'],null,['providers'=>$attempts,'result'=>$result?'partial_or_complete':'unavailable']);
        return $this->response($result);
    }
    private function cached(string $key): ?array
    {
        $row=$this->app->db->one('SELECT data FROM pme_lookup_cache WHERE cache_key=? AND expires_at>?',[$key,time()]);
        return $row?$this->app->vault->open($row['data']):null;
    }
    private function response(array $fields): array
    {
        return ['fields'=>$fields,'message'=>$fields?'Dados localizados. Confira o preenchimento.':'Não foi possível consultar agora. Você pode preencher os dados manualmente.'];
    }
    private function complete(string $type,array $data): bool
    {
        $required=$type==='cnpj'?['legal_name','street','city','state','cep']:['street','city','state'];
        return !array_diff($required,array_keys($data));
    }
    private function url(string $provider,string $type,string $value): string
    {
        $v=rawurlencode($value);
        if($type==='cep'){return $provider==='viacep'?'https://viacep.com.br/ws/'.$v.'/json/':'https://brasilapi.com.br/api/cep/v2/'.$v;}
        return match($provider){
            'brasilapi'=>'https://brasilapi.com.br/api/cnpj/v1/'.$v,
            'cnpjws'=>'https://publica.cnpj.ws/cnpj/'.$v,
            'receitaws'=>'https://receitaws.com.br/v1/cnpj/'.$v,
            default=>throw new Failure('provider','Serviço indisponível.')};
    }
    private function clean(array $data): array
    {
        $out=[];
        foreach($data as $key=>$value){
            if(!in_array($key,CompanyProfile::FIELDS,true)||!is_scalar($value)){continue;}
            $value=mb_substr(trim((string)$value),0,250);
            if($value===''){continue;}
            if($key==='cnpj'){$value=CompanyProfile::cnpj($value);}
            if($key==='cep'){$value=preg_replace('/\D/','',$value);if(strlen($value)!==8){continue;}}
            if($key==='state'){$value=strtoupper($value);if(!in_array($value,explode(' ','AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'),true)){continue;}}
            if($key==='email'){
                $valid=array_filter(preg_split('/[;,\s]+/',$value),static fn($v)=>filter_var($v,FILTER_VALIDATE_EMAIL));
                if(!$valid){continue;}$value=array_values($valid)[0];
            }
            if($key==='opening_date'&&preg_match('~\A(\d{2})/(\d{2})/(\d{4})\z~',$value,$m)){$value=$m[3].'-'.$m[2].'-'.$m[1];}
            $out[$key]=$value;
        }
        return $out;
    }
    public function map(string $provider,string $type,array $r): array
    {
        if($type==='cep'){return ['cep'=>$r['cep']??'','street'=>$r['logradouro']??$r['street']??'','complement'=>$r['complemento']??'','district'=>$r['bairro']??$r['neighborhood']??'','city'=>$r['localidade']??$r['city']??'','state'=>$r['uf']??$r['state']??'','ibge'=>$r['ibge']??''];}
        if($provider==='cnpjws'){
            $e=$r['estabelecimento']??[];$ie='';
            foreach($e['inscricoes_estaduais']??[] as $registration){if(!empty($registration['ativo'])&&($registration['estado']['sigla']??'')===($e['estado']['sigla']??'')){$ie=$registration['inscricao_estadual']??'';break;}}
            return ['cnpj'=>$e['cnpj']??'','legal_name'=>$r['razao_social']??'','trade_name'=>$e['nome_fantasia']??'','email'=>$e['email']??'','phone'=>($e['ddd1']??'').($e['telefone1']??''),'cep'=>$e['cep']??'','street'=>trim(($e['tipo_logradouro']??'').' '.($e['logradouro']??'')),'number'=>$e['numero']??'','complement'=>$e['complemento']??'','district'=>$e['bairro']??'','city'=>$e['cidade']['nome']??'','state'=>$e['estado']['sigla']??'','ibge'=>$e['cidade']['ibge_id']??'','country'=>$e['pais']['nome']??'','cnae'=>$e['atividade_principal']['id']??'','legal_nature'=>$r['natureza_juridica']['descricao']??'','registration_status'=>$e['situacao_cadastral']??'','opening_date'=>$e['data_inicio_atividade']??'','state_registration'=>$ie];
        }
        return ['cnpj'=>$r['cnpj']??'','legal_name'=>$r['nome']??$r['razao_social']??'','trade_name'=>$r['fantasia']??$r['nome_fantasia']??'','email'=>$r['email']??'','phone'=>$r['telefone']??$r['ddd_telefone_1']??'','cep'=>$r['cep']??'','street'=>$r['logradouro']??'','number'=>$r['numero']??'','complement'=>$r['complemento']??'','district'=>$r['bairro']??'','city'=>$r['municipio']??'','state'=>$r['uf']??'','ibge'=>$r['codigo_municipio_ibge']??'','country'=>$r['pais']??'','cnae'=>$r['cnae_fiscal']??$r['atividade_principal'][0]['code']??'','legal_nature'=>$r['natureza_juridica']??'','registration_status'=>$r['situacao']??$r['descricao_situacao_cadastral']??'','opening_date'=>$r['abertura']??$r['data_inicio_atividade']??''];
    }
}
