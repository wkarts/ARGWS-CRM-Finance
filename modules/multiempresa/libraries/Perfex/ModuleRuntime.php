<?php
declare(strict_types=1);
namespace Multiempresa\Perfex;
use Multiempresa\Application\{App,DatabaseProvisioning};
use Multiempresa\Infrastructure\Files;
use Multiempresa\Domain\Failure;

/** Company-private copies keep installer-generated files out of the shared source; legacy SQL is scoped here. */
final class ModuleRuntime
{
    private const ADAPTER_VERSION = 4;
    public function __construct(private App $app) {}
    public static function ready(string $destination,string $prefix,string $fingerprint): bool
    {
        $marker=$destination.'/.pme-overlay.json';$state=is_file($marker)?json_decode(file_get_contents($marker),true):null;
        return !is_link($destination)&&($state['signature']??null)===['source'=>$fingerprint,'prefix'=>$prefix,'adapter'=>self::ADAPTER_VERSION]&&empty($state['building']);
    }
    public static function rewrite(string $sql,array $tables,bool $fragment=false): string
    {
        // Tokenize comments and string values intact. Only a table name after a SQL table keyword is translated.
        $expect=false;$out='';
        foreach(self::tokens($sql,$fragment) as $token){
            if(ctype_space($token)||str_starts_with($token,'/*')||str_starts_with($token,'--')||str_starts_with($token,'#')){$out.=$token;continue;}
            $upper=strtoupper($token);
            if($expect&&in_array($upper,['IF','NOT','EXISTS','ONLY'],true)){$out.=$token;continue;}
            if($expect){$key=$token[0]==='`'?substr($token,1,-1):$token;if(isset($tables[$key])){$token='`'.$tables[$key].'`';}$expect=false;}
            if(in_array($upper,['INTO','UPDATE','FROM','JOIN','TABLE','REFERENCES'],true)){$expect=true;}
            $out.=$token;
        }
        return $out;
    }
    private static function tokens(string $sql,bool $fragment=false): \Generator
    {
        $length=strlen($sql);
        for($i=0;$i<$length;){
            $start=$i;$char=$sql[$i++];
            if(in_array($char,["'",'"','`'],true)){
                $closed=false;
                while($i<$length){$next=$sql[$i++];if($next==='\\'&&$char!=='`'&&$i<$length){$i++;continue;}if($next===$char){if($i<$length&&$sql[$i]===$char){$i++;continue;}$closed=true;break;}}
                if(!$closed&&!$fragment){throw new Failure('module_sql_string','Arquivo SQL contém um texto incompleto.');}
            }elseif($char==='/'&&($sql[$i]??'')==='*'){$end=strpos($sql,'*/',$i+1);if($end===false&&!$fragment){throw new Failure('module_sql_comment','Comentário SQL incompleto.');}$i=$end===false?$length:$end+2;}
            elseif($char==='#'||($char==='-'&&($sql[$i]??'')==='-'&&ctype_space($sql[$i+1]??' '))){while($i<$length&&!in_array($sql[$i],["\r","\n"],true)){$i++;}}
            elseif(ctype_space($char)){while($i<$length&&ctype_space($sql[$i])){$i++;}}
            elseif(ctype_alpha($char)||$char==='_'){while($i<$length&&(ctype_alnum($sql[$i])||$sql[$i]==='_')){$i++;}}
            yield substr($sql,$start,$i-$start);
        }
    }
    /** Adapt SQL literals in PHP installers/migrations, without executing or replacing arbitrary PHP text. */
    public static function phpSql(string $php,array $tables): string
    {
        $out='';
        foreach(token_get_all($php) as $token){
            $text=is_array($token)?$token[1]:$token;
            if(is_array($token)&&$token[0]===T_CONSTANT_ENCAPSED_STRING){
                $sql=self::literalValue($text);$command=null;
                foreach(self::tokens($sql,true) as $part){
                    if(ctype_space($part)||str_starts_with($part,'/*')||str_starts_with($part,'--')||str_starts_with($part,'#')){continue;}
                    $command=strtoupper($part);break;
                }
                if(in_array($command,['SELECT','INSERT','REPLACE','UPDATE','DELETE','CREATE','ALTER','DROP','TRUNCATE','RENAME','WITH','EXPLAIN'],true)){
                    // A PHP concatenation may end inside an SQL value; leave that value untouched.
                    $adapted=self::rewrite($sql,$tables,true);
                    if($adapted!==$sql){$text=var_export($adapted,true);}
                }
            }
            $out.=$text;
        }
        return $out;
    }
    private static function literalValue(string $literal): string
    {
        // PHP permits b'...' / b"..." as well as ordinary string literals.
        if($literal[0]==='b'||$literal[0]==='B'){$literal=substr($literal,1);}
        $quote=$literal[0];$value=substr($literal,1,-1);
        if($quote==="'"){return strtr($value,["\\\\"=>"\\","\\'"=>"'"]);}
        return preg_replace_callback('/\\\\(?:[0-7]{1,3}|x[0-9a-fA-F]{1,2}|u\{[0-9a-fA-F]+\}|.)/s',static function(array $m):string{
            $escape=substr($m[0],1);
            $simple=['n'=>"\n",'r'=>"\r",'t'=>"\t",'v'=>"\v",'e'=>"\x1b",'f'=>"\f",'\\'=>'\\','"'=>'"','$'=>'$'];
            if(isset($simple[$escape])){return $simple[$escape];}
            if(preg_match('/\A[0-7]{1,3}\z/',$escape)){return chr(octdec($escape)&255);}
            if($escape[0]==='x'&&strlen($escape)>1){return chr(hexdec(substr($escape,1)));}
            if(str_starts_with($escape,'u{')){
                $point=hexdec(substr($escape,2,-1));
                if($point<128){return chr($point);}if($point<2048){return chr(192|($point>>6)).chr(128|($point&63));}
                if($point<65536){return chr(224|($point>>12)).chr(128|(($point>>6)&63)).chr(128|($point&63));}
                return chr(240|($point>>18)).chr(128|(($point>>12)&63)).chr(128|(($point>>6)&63)).chr(128|($point&63));
            }
            return $m[0];
        },$value);
    }
    public static function phpPaths(string $php,string $module): string
    {
        $tokens=token_get_all($php);$out='';$count=count($tokens);
        for($i=0;$i<$count;$i++){
            $token=$tokens[$i];
            if(is_array($token)&&$token[0]===T_DIR){
                $dot=$i+1;while($dot<$count&&is_array($tokens[$dot])&&$tokens[$dot][0]===T_WHITESPACE){$dot++;}
                $literal=$dot+1;while($literal<$count&&is_array($tokens[$literal])&&$tokens[$literal][0]===T_WHITESPACE){$literal++;}
                if(($tokens[$dot]??null)==='.'&&isset($tokens[$literal])&&is_array($tokens[$literal])&&$tokens[$literal][0]===T_CONSTANT_ENCAPSED_STRING){
                    $path=substr($tokens[$literal][1],1,-1);
                    if(preg_match('~^/?(?:\.\./)+modules/('.preg_quote($module,'~').'/[A-Za-z0-9_./-]+)$~',$path,$match)&&!str_contains($match[1],'/../')){
                        $out.='FCPATH . '.var_export('modules/'.$match[1],true);$i=$literal;continue;
                    }
                }
            }
            $out.=is_array($token)?$token[1]:$token;
        }
        return $out;
    }
    public function mount(string $source,string $destination,string $prefix,string $fingerprint): bool
    {
        $signature=['source'=>$fingerprint,'prefix'=>$prefix,'adapter'=>self::ADAPTER_VERSION];
        $marker=$destination.'/.pme-overlay.json';$previous=is_file($marker)?json_decode(file_get_contents($marker),true):null;
        if(!is_link($destination)&&($previous['signature']??null)===$signature&&empty($previous['building'])){return true;}
        if(is_link($destination)){unlink($destination);}elseif(is_dir($destination)&&!$previous){throw new Failure('module_runtime','Diretório de módulo privado sem identificação de propriedade.');}
        $map=[];foreach((new DatabaseProvisioning($this->app))->moduleTables($source) as $suffix){foreach(array_unique(['tbl',$this->app->config['native_prefix']]) as $legacy){if($legacy!==$prefix){$map[$legacy.$suffix]=$prefix.$suffix;}}}
        Files::mkdir($destination);$hashes=[];$old=$previous['files']??[];
        Files::write($marker,json_encode(['signature'=>$signature,'files'=>$old,'building'=>true],JSON_THROW_ON_ERROR));
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $file){
            if($file->isLink()){throw new Failure('source_symlink','O pacote de código contém vínculo simbólico.');}
            $relative=substr($file->getPathname(),strlen($source)+1);$target=$destination.'/'.$relative;
            if($file->isDir()){Files::mkdir($target);continue;}
            $data=file_get_contents($file->getPathname());
            if(strtolower($file->getExtension())==='php'){$data=self::phpSql(self::phpPaths($data,basename($source)),$map);}
            if(strtolower($file->getExtension())==='sql'){
                if($file->getSize()>33554432){throw new Failure('module_sql_size','Arquivo SQL do módulo excede o limite de adaptação (32 MB).');}
                $data=self::rewrite($data,$map);
            }
            $hashes[$relative]=hash('sha256',$data);
            // Preserve locally generated state when the distributed file has not changed.
            if(is_file($target)&&!is_link($target)&&($old[$relative]??null)===$hashes[$relative]){continue;}
            if(is_link($target)){unlink($target);}Files::write($target,$data);
        }
        foreach(array_diff_key($old,$hashes) as $relative=>$hash){$target=$destination.'/'.$relative;if(is_file($target)&&!is_link($target)&&hash_file('sha256',$target)===$hash){unlink($target);}}
        Files::write($marker,json_encode(['signature'=>$signature,'files'=>$hashes],JSON_THROW_ON_ERROR));return true;
    }
    public static function remove(string $path): void
    {
        if(is_link($path)||is_file($path)){if(!unlink($path)){throw new Failure('module_runtime','Não foi possível remover um recurso de execução.');}return;}
        if(is_dir($path)){foreach(new \DirectoryIterator($path) as $item){if(!$item->isDot()){self::remove($item->getPathname());}}if(!rmdir($path)){throw new Failure('module_runtime','Não foi possível remover o diretório de execução.');}}
    }
}
