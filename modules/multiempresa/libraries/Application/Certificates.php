<?php
declare(strict_types=1);
namespace Multiempresa\Application;
use Multiempresa\Domain\Failure;

final class Certificates
{
    public function __construct(private App $app) {}
    public function metadata(string $company): ?array
    {
        $row=$this->app->db->one('SELECT metadata,updated_at FROM pme_certificates WHERE company_id=?',[$company]);return $row?json_decode($row['metadata'],true,32,JSON_THROW_ON_ERROR)+['updated_at'=>(int)$row['updated_at']]:null;
    }
    public function import(array $actor,string $company,string $filename,string $bytes,string $password): array
    {
        $row=(new CompanyManager($this->app))->managed($actor,$company);$orgLock=new \Multiempresa\Infrastructure\AdvisoryLock($this->app,'organization:'.$row['organization_id']);$lock=new \Multiempresa\Infrastructure\AdvisoryLock($this->app,'company:'.$company);
        if(!in_array(strtolower(pathinfo($filename,PATHINFO_EXTENSION)),['pfx','p12'],true)||strlen($bytes)<64||strlen($bytes)>4194304){throw new Failure('certificate_file','Selecione um certificado .pfx ou .p12 com até 4 MB.');}
        if(!function_exists('openssl_pkcs12_read')){throw new Failure('openssl_required','Habilite a extensão OpenSSL do PHP para utilizar certificados.');}
        $certs=[];if(!@openssl_pkcs12_read($bytes,$certs,$password)){while(openssl_error_string()){}throw new Failure('certificate_password','Não foi possível abrir o certificado. Confira o arquivo, a senha e o suporte PKCS#12 do OpenSSL.');}
        $info=openssl_x509_parse($certs['cert']);
        if(!$info||empty($certs['pkey'])||!openssl_x509_check_private_key($certs['cert'],$certs['pkey'])){throw new Failure('certificate_key','O arquivo não contém um certificado e uma chave privada correspondentes.');}
        $meta=['holder'=>(string)($info['subject']['CN']??''),'issuer'=>(string)($info['issuer']['CN']??''),'valid_from'=>(int)$info['validFrom_time_t'],'valid_until'=>(int)$info['validTo_time_t'],'serial'=>(string)($info['serialNumberHex']??''),'fingerprint'=>openssl_x509_fingerprint($certs['cert'],'sha256')];
        if($meta['valid_until']<time()||$meta['valid_from']>time()){throw new Failure('certificate_validity','O certificado está vencido ou ainda não entrou em validade.');}
        $secret=$this->app->vault->seal(['company_id'=>$company,'pkcs12'=>base64_encode($bytes),'password'=>$password]);
        $this->app->db->transaction(function()use($actor,$company,$meta,$secret){$this->app->db->exec('DELETE FROM pme_certificates WHERE company_id=?',[$company]);$this->app->db->exec('INSERT INTO pme_certificates(company_id,secret,metadata,updated_at,actor_id) VALUES(?,?,?,?,?)',[$company,$secret,json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),time(),$actor['id']]);});
        unset($certs,$bytes,$password);$this->app->audit->log('certificate.imported',$actor['id'],$company,['valid_until'=>$meta['valid_until']]);return $meta;
    }
    /** Server-side integrations must present the authenticated organizational administrator. */
    public function credentials(array $actor,string $company): array
    {
        (new CompanyManager($this->app))->managed($actor,$company);$row=$this->app->db->one('SELECT secret FROM pme_certificates WHERE company_id=?',[$company])??throw new Failure('certificate_missing','Certificado não cadastrado.',404);
        $s=$this->app->vault->open($row['secret']);if(!hash_equals($company,$s['company_id'])){throw new Failure('certificate_scope','Certificado pertence a outra empresa.',403);}
        $this->app->audit->log('certificate.accessed',$actor['id'],$company);return ['pkcs12'=>base64_decode($s['pkcs12'],true),'password'=>$s['password']];
    }
    public function remove(array $actor,string $company): void
    {
        $row=(new CompanyManager($this->app))->managed($actor,$company);$orgLock=new \Multiempresa\Infrastructure\AdvisoryLock($this->app,'organization:'.$row['organization_id']);$lock=new \Multiempresa\Infrastructure\AdvisoryLock($this->app,'company:'.$company);$this->app->db->exec('DELETE FROM pme_certificates WHERE company_id=?',[$company]);$this->app->audit->log('certificate.removed',$actor['id'],$company);
    }
}
