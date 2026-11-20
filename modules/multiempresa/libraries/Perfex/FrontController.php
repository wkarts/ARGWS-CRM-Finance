<?php
declare(strict_types=1);
namespace Multiempresa\Perfex;

use Multiempresa\Application\App;
use Multiempresa\Infrastructure\Files;
use Multiempresa\Domain\Failure;

/** Small reversible integrations; unrelated native source is preserved byte for byte. */
final class FrontController
{
    private const START='// BEGIN MULTIEMPRESA BOOTSTRAP';
    private const END='// END MULTIEMPRESA BOOTSTRAP';
    public static function installed(App $app): bool
    {
        $file=($app->config['root']??'').'/index.php';
        return is_file($file)&&str_contains((string)file_get_contents($file),self::START);
    }
    public static function install(App $app): void
    {
        self::insert($app,'index.php',"if (is_file(__DIR__.'/modules/multiempresa/routing-bootstrap.php')) { require __DIR__.'/modules/multiempresa/routing-bootstrap.php'; }",true);
        self::insert($app,'application/config/my_hooks.php',"if (is_file(FCPATH.'modules/multiempresa/autoload.php') && !defined('PME_CONTEXT_READY')) {\n    require_once FCPATH.'modules/multiempresa/autoload.php';\n    \$hook['pre_controller_constructor'][] = static function () { \\Multiempresa\\Perfex\\NativeAccess::register(); };\n}",false);
    }
    private static function insert(App $app,string $relative,string $body,bool $first): void
    {
        $file=$app->config['root'].'/'.$relative;$old=is_file($file)?file_get_contents($file):"<?php\n";
        if(str_contains($old,self::START)){return;}
        if(!preg_match('/\A(?:\xEF\xBB\xBF)?<\?php\b/',$old,$match)){throw new Failure('routing_bootstrap','Não foi possível integrar a entrada desta instalação.',503);}
        // Backup is private and never used to overwrite later administrator edits.
        $backup=$app->state('bootstrap/'.str_replace('/','_',$relative));
        if(!is_file($backup)){Files::write($backup,$old);if(!is_file($file)){Files::write($backup.'.created','1');}}
        $block="\n".self::START."\n".$body."\n".self::END."\n";
        if($first){
            $offset=strlen($match[0]);
            if(preg_match('/\G\s*declare\s*\(\s*strict_types\s*=\s*[01]\s*\)\s*;/A',$old,$declaration,0,$offset)){$offset+=strlen($declaration[0]);}
            $new=substr_replace($old,$block,$offset,0);
        }
        else{
            // Insert inside PHP, before a final closing tag when present.
            $end=preg_match('/\?>\s*\z/',$old,$m,PREG_OFFSET_CAPTURE)?$m[0][1]:strlen($old);
            $new=substr_replace($old,$block,$end,0);
        }
        $mode=is_file($file)?(fileperms($file)&0777):0644;
        // Atomic replacement detaches symlinks; it never follows them to another installation.
        Files::write($file,$new);chmod($file,$mode);
    }
    public static function remove(App $app): void
    {
        foreach(['index.php','application/config/my_hooks.php'] as $relative){
            $file=$app->config['root'].'/'.$relative;if(!is_file($file)){continue;}$old=file_get_contents($file);
            $new=preg_replace('~\n'.preg_quote(self::START,'~').'.*?'.preg_quote(self::END,'~').'\n~s','',$old);
            if($new!==$old){
                if(trim($new)==='<?php'&&is_file($app->state('bootstrap/'.str_replace('/','_',$relative).'.created'))){unlink($file);continue;}
                $mode=fileperms($file)&0777;Files::write($file,$new);chmod($file,$mode);
            }
        }
    }
}
