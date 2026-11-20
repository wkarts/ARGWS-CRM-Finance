<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\Failure;

final class CompanyProfile
{
    public const FIELDS=['cnpj','legal_name','trade_name','state_registration','municipal_registration','email','phone','cep','street','number','complement','district','city','state','ibge','country','cnae','legal_nature','registration_status','opening_date'];
    public function __construct(private App $app) {}
    public static function cnpj(string $value): string
    {
        $v=strtoupper(preg_replace('/[.\/\s-]/','',trim($value)));
        if(!preg_match('/\A[A-Z0-9]{12}[0-9]{2}\z/',$v)||preg_match('/\A(.)\1{13}\z/',$v)){throw new Failure('cnpj','CNPJ inválido. Confira os 14 caracteres e os dígitos verificadores.');}
        $base=substr($v,0,12);
        foreach([[5,4,3,2,9,8,7,6,5,4,3,2],[6,5,4,3,2,9,8,7,6,5,4,3,2]] as $weights){$sum=0;foreach($weights as $i=>$w){$sum+=(ord($base[$i])-48)*$w;}$r=$sum%11;$base.=($r<2?0:11-$r);}
        if(!hash_equals($base,$v)){throw new Failure('cnpj','Dígitos verificadores do CNPJ inválidos.');}return $v;
    }
    public static function normalize(array $input): array
    {
        $out=[];foreach(self::FIELDS as $key){if(array_key_exists($key,$input)){if(!is_scalar($input[$key])){throw new Failure('profile','Campo cadastral inválido.');}$out[$key]=trim((string)$input[$key]);if(mb_strlen($out[$key])>250){throw new Failure('profile_length','Campo cadastral muito extenso: '.$key);}}}
        if(!empty($out['cnpj'])){$out['cnpj']=self::cnpj($out['cnpj']);}
        if(!empty($out['cep'])){$out['cep']=preg_replace('/[\s-]/','',$out['cep']);if(!preg_match('/\A[0-9]{8}\z/',$out['cep'])){throw new Failure('cep','CEP deve conter 8 dígitos.');}}
        if(!empty($out['email'])&&!filter_var($out['email'],FILTER_VALIDATE_EMAIL)){throw new Failure('email','E-mail cadastral inválido.');}
        if(!empty($out['state'])){$out['state']=strtoupper($out['state']);if(!in_array($out['state'],explode(' ','AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'),true)){throw new Failure('state','UF inválida.');}}
        return $out;
    }
    public function get(string $company): array
    {
        $r=$this->app->db->one('SELECT data FROM pme_profiles WHERE company_id=?',[$company]);return $r?json_decode($r['data'],true,32,JSON_THROW_ON_ERROR):[];
    }
    public function save(array $actor,string $company,array $input): array
    {
        (new CompanyManager($this->app))->managed($actor,$company);$data=array_replace($this->get($company),self::normalize($input));
        $this->app->db->transaction(function()use($company,$data){$this->app->db->exec('DELETE FROM pme_profiles WHERE company_id=?',[$company]);$this->app->db->exec('INSERT INTO pme_profiles(company_id,data,updated_at) VALUES(?,?,?)',[$company,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time()]);});
        $this->app->audit->log('company.profile_updated',$actor['id'],$company,['fields'=>array_keys($input)]);return $data;
    }
}
