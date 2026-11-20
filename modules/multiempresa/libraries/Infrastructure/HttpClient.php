<?php
declare(strict_types=1);
namespace Multiempresa\Infrastructure;
use Multiempresa\Domain\Failure;

/** Transport injection is available only to server-side adapters/tests, never to HTTP input. */
final class HttpClient
{
    public function __construct(private $transport=null) {}
    public static function validateUrl(string $url): void
    {
        $u=parse_url($url);$host=$u['host']??'';
        if(($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||isset($u['port'])&&$u['port']!==443||!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($host,'.')||filter_var($host,FILTER_VALIDATE_IP)||preg_match('/(?:\A|\.)(?:localhost|local|internal|test)\z/i',$host)){throw new Failure('http_url','Use uma URL HTTPS pública sem usuário, senha ou porta alternativa.');}
    }
    public function json(string $method,string $url,array $headers=[],?array $payload=null,int $timeout=10): array
    {
        self::validateUrl($url);
        if($this->transport){return ($this->transport)($method,$url,$headers,$payload,$timeout);}
        if(!function_exists('curl_init')){throw new Failure('curl_required','A extensão PHP cURL deve estar habilitada para as integrações.');}
        $host=parse_url($url,PHP_URL_HOST);$records=@dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];
        foreach($records?:[] as $record){$ip=$record['ip']??$record['ipv6']??null;if($ip){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){throw new Failure('http_private','O provedor não pode apontar para uma rede privada.');}$ips[]=$ip;}}
        if(!$ips){throw new Failure('http_dns','Não foi possível resolver o endereço do provedor.');}
        $body='';$ch=curl_init($url);$h=['Accept: application/json','Content-Type: application/json'];
        foreach($headers as $key=>$value){if(preg_match('/[\r\n]/',(string)$value)){throw new Failure('http_header','Credencial do provedor contém caracteres inválidos.');}$h[]=$key.': '.$value;}
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$h,CURLOPT_CONNECTTIMEOUT=>min(5,$timeout),CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':443:'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])],CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>2097152){return 0;}$body.=$chunk;return strlen($chunk);}]);
        if($payload!==null){curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));}
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
        if($ok===false){throw new Failure('http_transport','Falha de comunicação com o provedor (cURL '.$errno.').',503);}
        return self::decode($status,$body);
    }
    private static function decode(int $status,string $body): array
    {
        if($status<200||$status>299){$decoded=json_decode($body,true);$codes=[];foreach(is_array($decoded)?($decoded['errors']??[]):[] as $error){if(is_array($error)&&is_numeric($error['code']??null)){$codes[]=(int)$error['code'];}}throw new Failure('http_'.$status,'O provedor respondeu HTTP '.$status.'.',503,['http_status'=>$status,'provider_codes'=>array_slice(array_unique($codes),0,10)]);}
        try{$json=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Failure('http_json','O provedor retornou uma resposta inválida.',503);}
        if(!is_array($json)){throw new Failure('http_json','Resposta JSON inválida.',503);}return $json;
    }
}
