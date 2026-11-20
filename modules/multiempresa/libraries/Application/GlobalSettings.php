<?php
declare(strict_types=1);
namespace Multiempresa\Application;

use Multiempresa\Domain\{Failure,Id};
use Multiempresa\Infrastructure\AdvisoryLock;

/** One encrypted configuration; secrets are never returned to the browser. */
final class GlobalSettings
{
    public function __construct(private App $app) {}
    public static function defaults(): array
    {
        return ['users'=>['global_access'=>'explicit','initial_admin'=>'new','grant_creator_default'=>false,'central_staff_primary_default'=>false,'tenant_new_staff_access'=>true,'tenant_staff_management'=>true],'modules'=>['defaults'=>[]],'database'=>['operation'=>'manual','isolation'=>'database','host'=>'127.0.0.1','port'=>3306,'username'=>'','password'=>'','shared_database'=>'','database_pattern'=>'org_{org}_emp_{company}','user_host'=>'localhost'],
            'dns'=>['enabled'=>false,'manual_confirmed'=>false,'token'=>'','zone_id'=>'','base_domain'=>'','organization_pattern'=>'{org}','company_pattern'=>'{company}-{org}','address_mode'=>'organization','type'=>'A','target'=>'','ttl'=>1,'proxied'=>false,'per_organization_target'=>false,'api_base_url'=>'https://api.cloudflare.com/client/v4','zone_name'=>'','dry_run'=>false,'managed_wildcard_dns'=>false,'managed_wildcard_target'=>'','custom_hostname_enabled'=>false,'custom_hostname_origin'=>'','custom_hostname_ca'=>'auto']];
    }
    public function get(bool $safe=false): array
    {
        $row=$this->app->db->one("SELECT value FROM pme_settings WHERE name='global_configuration'");
        $value=self::defaults();foreach($row?$this->app->vault->open($row['value']):[] as $section=>$fields){if(isset($value[$section])&&is_array($fields)){$value[$section]=array_replace($value[$section],$fields);}}
        if($safe){foreach(['database'=>['password'],'dns'=>['token']] as $section=>$keys){foreach($keys as $key){$value[$section][$key.'_set']=$value[$section][$key]!=='';unset($value[$section][$key]);}}}
        $value['runtime']=\Multiempresa\Perfex\Installer::runtimeStatus($this->app);return $value;
    }
    public function save(array $actor,array $input): array
    {
        if(empty($actor['platform_admin'])){throw new Failure('platform_admin','Somente o administrador global pode alterar integrações.',403);}
        $lock=new AdvisoryLock($this->app,'global-settings');$old=$this->get();$new=$old;
        foreach(self::defaults() as $section=>$fields){foreach($fields as $key=>$default){if(array_key_exists($key,$input[$section]??[])){$new[$section][$key]=$input[$section][$key];}}}
        foreach(['database'=>['password'],'dns'=>['token']] as $section=>$keys){foreach($keys as $key){if(($new[$section][$key]??'')==='' && empty($input[$section]['clear_'.$key])){$new[$section][$key]=$old[$section][$key];}}}
        $new['modules']['defaults']=Modules::selection($new['modules']['defaults']);
        if(array_key_exists('defaults',$input['modules']??[])){
            $available=array_column((new CompanyManager($this->app))->modules(),null,'name');
            foreach($new['modules']['defaults'] as $name){if(empty($available[$name]['compatible'])){throw new Failure('module_missing','Módulo padrão indisponível ou incompatível: '.$name);}}
        }
        foreach(['global_access'=>['all','explicit'],'initial_admin'=>['new','current']] as $key=>$allowed){if(!in_array($new['users'][$key],$allowed,true)){throw new Failure('user_policy','Política de usuários inválida.');}}
        $new['users']['global_access']='explicit'; // Retired bypass; retained key for older clients.
        foreach(['grant_creator_default','central_staff_primary_default','tenant_new_staff_access','tenant_staff_management'] as $key){$value=filter_var($new['users'][$key],FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE);if($value===null){throw new Failure('user_policy','Política de usuários inválida.');}$new['users'][$key]=$value;}
        $db=&$new['database'];$dns=&$new['dns'];
        foreach([[$db['operation'],['manual','automatic']],[$db['isolation'],['database','prefix']],[$dns['address_mode'],['organization','company']],[$dns['type'],['A','AAAA','CNAME']]] as [$value,$allowed]){if(!in_array($value,$allowed,true)){throw new Failure('configuration_value','Opção global inválida.');}}
        $db['port']=(int)$db['port'];if($db['port']<1||$db['port']>65535){throw new Failure('port','Porta MySQL inválida.');}
        if(!preg_match('/\A[a-zA-Z0-9_.:-]+\z/',$db['host'])){throw new Failure('host','Host MySQL inválido.');}
        if(!preg_match('/\A[a-zA-Z0-9_{}]+\z/',$db['database_pattern'])){throw new Failure('database_pattern','Use letras, números, sublinhado e {org}/{company} no nome do banco.');}
        foreach(['enabled','manual_confirmed','proxied','per_organization_target','dry_run','managed_wildcard_dns','custom_hostname_enabled'] as $k){$dns[$k]=filter_var($dns[$k],FILTER_VALIDATE_BOOL);}
        $dns['base_domain']=strtolower(trim($dns['base_domain']));$dns['ttl']=(int)$dns['ttl'];
        if($dns['ttl']!==1 && ($dns['ttl']<60||$dns['ttl']>86400)){throw new Failure('dns_ttl','TTL deve ser automático (1) ou entre 60 e 86400.');}
        $dns['token']=trim(preg_replace('/\ABearer\s+/i','',trim((string)$dns['token'])));
        if($dns['token']===''&&empty($input['dns']['clear_token'])){$dns['token']=$old['dns']['token'];}
        $dns['zone_id']=strtolower(trim((string)$dns['zone_id']));$dns['zone_name']=strtolower(rtrim(trim((string)$dns['zone_name']),'.'));
        $dns['api_base_url']=rtrim(trim((string)$dns['api_base_url']),'/');
        if($dns['api_base_url']!=='https://api.cloudflare.com/client/v4'){throw new Failure('cloudflare_endpoint','O token só pode ser enviado à API oficial HTTPS da Cloudflare.');}
        if(preg_match('/[\r\n]/',$dns['token'])){throw new Failure('cloudflare_credentials','O token contém uma quebra de linha. Cole apenas o token da API.');}
        if($dns['zone_name']!==''&&(!filter_var($dns['zone_name'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($dns['zone_name'],'.'))){throw new Failure('dns_zone_name','Nome da zona inválido.');}
        if($dns['enabled']){$dns['manual_confirmed']=false;}
        if(!in_array($dns['custom_hostname_ca'],['auto','lets_encrypt','google','digicert','ssl_com'],true)){throw new Failure('dns_ca','Autoridade de certificado inválida.');}
        $dns['custom_hostname_origin']=strtolower(rtrim(trim($dns['custom_hostname_origin']),'.'));
        if($dns['custom_hostname_origin']!==''&&(!filter_var($dns['custom_hostname_origin'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($dns['custom_hostname_origin'],'.')||filter_var($dns['custom_hostname_origin'],FILTER_VALIDATE_IP))){throw new Failure('dns_origin','Informe o hostname da origem HTTPS, sem protocolo ou caminho.');}
        if(self::customDns($dns)){
            if($dns['enabled']&&(!preg_match('/\A[a-f0-9]{32}\z/',$dns['zone_id'])||$dns['token']==='')){throw new Failure('cloudflare_credentials','Informe a zona e o token da Cloudflare.');}
            if(!filter_var($dns['base_domain'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($dns['base_domain'],'.')){throw new Failure('dns_domain','Domínio base inválido.');}
            foreach(['organization_pattern','company_pattern'] as $k){if(!str_contains($dns[$k],'{org}')||!preg_match('/\A[a-z0-9{}-]+\z/',$dns[$k])){throw new Failure('dns_pattern','O padrão DNS deve conter {org}; use letras minúsculas, números, hífen e identificadores.');}}
            if($dns['address_mode']==='company' && !str_contains($dns['company_pattern'],'{company}')){throw new Failure('dns_pattern','O endereço individual deve incluir {company}.');}
            if($dns['zone_name']!==''&&$dns['base_domain']!==$dns['zone_name']&&!str_ends_with($dns['base_domain'],'.'.$dns['zone_name'])){throw new Failure('dns_zone_name','O domínio base não pertence ao nome de zona informado.');}
            if(!$dns['per_organization_target']){$dns['target']=self::target($dns['type'],$dns['target']);}
            if($dns['managed_wildcard_dns']){if($dns['per_organization_target']){throw new Failure('dns_wildcard_target','Um wildcard compartilhado usa um único destino. Desative o destino por organização.');}$dns['managed_wildcard_target']=self::target($dns['type'],$dns['managed_wildcard_target']?:$dns['target']);}

        }
        if(isset($input['runtime'])){\Multiempresa\Perfex\Installer::configureRuntime($this->app,$input['runtime']);}
        unset($new['runtime']);
        $this->app->db->transaction(function()use($new){$this->app->db->exec("DELETE FROM pme_settings WHERE name='global_configuration'");$this->app->db->exec('INSERT INTO pme_settings(name,value) VALUES(?,?)',['global_configuration',$this->app->vault->seal($new)]);});
        $this->app->audit->log('settings.updated',$actor['id'],null,['sections'=>array_keys($new)]);return $this->get(true);
    }
    /** Read-only connection test: a blank secret keeps the saved token, edits are not persisted. */
    public function dnsForTest(array $input): array
    {
        $dns=$this->get()['dns'];
        foreach(['token','zone_id','zone_name','base_domain','api_base_url'] as $key){
            if(!array_key_exists($key,$input)){continue;}
            if(!is_string($input[$key])||strlen($input[$key])>8192){throw new Failure('cloudflare_configuration','Campo de conexão Cloudflare inválido.');}
            $value=trim($input[$key]);
            if($key==='token'&&$value===''){continue;}
            $dns[$key]=$key==='token'?$value:strtolower($value);
        }
        $dns['zone_name']=rtrim($dns['zone_name'],'.');$dns['base_domain']=rtrim($dns['base_domain'],'.');
        if(!filter_var($dns['base_domain'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($dns['base_domain'],'.')){throw new Failure('dns_domain','Informe o domínio base para testar a conexão.');}
        return $dns;
    }
    /** Retire obsolete lookup credentials without changing database/DNS preferences. */
    public function removeLegacyLookupSettings(): void
    {
        $lock=new AdvisoryLock($this->app,'global-settings');
        $row=$this->app->db->one("SELECT value FROM pme_settings WHERE name='global_configuration'");
        if(!$row){return;}
        $stored=$this->app->vault->open($row['value']);
        if(array_key_exists('lookups',$stored)){
            unset($stored['lookups']);
            $this->app->db->exec("UPDATE pme_settings SET value=? WHERE name='global_configuration'",[$this->app->vault->seal($stored)]);
        }
    }
    public static function customDns(array $cfg): bool { return !empty($cfg['enabled']) || !empty($cfg['manual_confirmed']); }
    public static function target(string $type,string $target): string
    {
        $target=trim($target);
        $valid=$type==='A'?filter_var($target,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4):($type==='AAAA'?filter_var($target,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6):filter_var($target,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME));
        if(!$valid){throw new Failure('dns_target','Destino DNS incompatível com o tipo de registro.');}return $target;
    }
}
