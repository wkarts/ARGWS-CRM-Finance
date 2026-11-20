<?php
declare(strict_types=1);
namespace Multiempresa\Http;

use Multiempresa\Application\App;
use Multiempresa\Domain\Failure;

/** Direct PHP entry works without PATH_INFO or additional web-server rules. */
final class Entry
{
    public static function url(App $app, string $url): string
    {
        $base=rtrim($app->config['base_url'],'/');
        if(!str_starts_with($url,$base.'/')){return $url;}
        return $base.'?__pme_route='.substr($url,strlen($base));
    }
    public static function path(string $url): string
    {
        $parts=parse_url($url);parse_str($parts['query']??'',$q);
        return isset($q['__pme_route'])?($parts['path']??'').explode('?',(string)$q['__pme_route'],2)[0]:($parts['path']??'/');
    }
    public static function normalize(App $app): void
    {
        if(!array_key_exists('__pme_route',$_GET)){return;}
        $route=$_GET['__pme_route'];
        if(!is_string($route)||strlen($route)>8192||!str_starts_with($route,'/')||str_starts_with($route,'//')||preg_match('/[\x00-\x1f\x7f]/',$route)){throw new Failure('uri','Rota empresarial inválida.',400);}
        unset($_GET['__pme_route']);
        // Native JS concatenates admin_url + route + '?filter'. Preserve that query too.
        [$path,$query]=array_pad(explode('?',$route,2),2,'');parse_str($query,$inner);
        if(isset($inner['__pme_route'])){throw new Failure('uri','Rota empresarial inválida.',400);}
        $_GET=array_replace($inner,$_GET);$_REQUEST=array_replace($_GET,$_POST);
        $_SERVER['QUERY_STRING']=http_build_query($_GET,'','&',PHP_QUERY_RFC3986);
        $_SERVER['REQUEST_URI']=rtrim(parse_url($app->config['base_url'],PHP_URL_PATH)??'','/').$path.($_SERVER['QUERY_STRING']!==''?'?'.$_SERVER['QUERY_STRING']:'');
    }
    public static function nativeUrl($uri='', $protocol=null, bool $site=false): string
    {
        $ci=get_instance();$app=$GLOBALS['pme_app'];$path=is_array($uri)?implode('/',$uri):(string)$uri;
        // A virtual company directory does not exist in the web server's document root.
        // An extensionless route reaches PHP even when Nginx handles image suffixes itself.
        if(defined('PME_CONTEXT_READY')&&isset($GLOBALS['pme_context'])&&str_starts_with(ltrim($path,'/'),'uploads/company/')){
            $filename=rawurldecode(substr(explode('?',ltrim($path,'/'),2)[0],16));
            if($filename!==''&&!preg_match('~[/\\\\\x00-\x1f\x7f]~',$filename)&&$filename!=='.'&&$filename!=='..'){
                static $versions=[];
                $key=$GLOBALS['pme_context']->companyId.'/'.$filename;
                if(!isset($versions[$key])){
                    try{$file=\Multiempresa\Infrastructure\Files::inside($app->state('companies/'.$GLOBALS['pme_context']->companyId.'/uploads/company'),$filename);$versions[$key]=substr(hash_file('sha256',$file)?:'missing',0,20);}
                    catch(Failure){$versions[$key]='missing';}
                }
                return $GLOBALS['pme_context']->baseUrl.'__pme/branding?'.http_build_query(['file'=>$filename,'v'=>$versions[$key]],'','&',PHP_QUERY_RFC3986);
            }
        }
        // Shared public assets keep their physical URL so relative CSS fonts/images work.
        if(preg_match('~\A/?(?:assets/|modules/[a-zA-Z0-9_]+/assets/)~',$path)||(defined('PME_PRIMARY_ROUTE')&&preg_match('~\A/?(?:uploads/|media/|favicon\.)~',$path))){
            return rtrim($app->config['native_url'],'/').'/'.ltrim($path,'/');
        }
        return $site?$ci->config->site_url($uri,$protocol):$ci->config->base_url($uri,$protocol);
    }
}
