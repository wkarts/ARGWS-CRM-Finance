<?php
declare(strict_types=1);
namespace Multiempresa\Perfex;
use Multiempresa\Application\App;
use Multiempresa\Infrastructure\Files;
use Multiempresa\Domain\{Failure,Id};

/** Track native extension files installed by a module inside one company's private application. */
final class ModuleExtensions
{
    public function __construct(private App $app) {}
    private function runtime(string $company,array $release): string {return $this->app->state('companies/'.$company.'/releases/'.$release['id'].'/application');}
    public function snapshot(string $company,array $release): array
    {
        $root=$this->runtime($company,$release);$files=[];
        foreach(['controllers','libraries'] as $section){if(!is_dir($root.'/'.$section)){continue;}
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$section,\FilesystemIterator::SKIP_DOTS)) as $file){if($file->isFile()&&!$file->isLink()){$files[substr($file->getPathname(),strlen($root)+1)]=hash_file('sha256',$file->getPathname());}}
        }return $files;
    }
    public function capture(string $company,array $release,string $module,array $before): void
    {
        Id::identifier($module);$root=$this->runtime($company,$release);$base=$this->app->state('companies/'.$company.'/extensions');$index=$base.'/index.json';$owners=is_file($index)?json_decode(file_get_contents($index),true,64,JSON_THROW_ON_ERROR):[];
        foreach($this->snapshot($company,$release) as $relative=>$hash){
            if($relative==='controllers/admin/Mods.php'||($before[$relative]??null)===$hash||str_ends_with($relative,'/.pme-private-native')){continue;}
            $native=$release['root'].'/application/'.$relative;
            if(!isset($before[$relative])&&is_file($native)&&hash_file('sha256',$native)===$hash){continue;}
            if(isset($owners[$relative])&&$owners[$relative]['module']!==$module){throw new Failure('module_extension_conflict','Dois módulos tentaram alterar a mesma extensão nativa. Revise a seleção antes de continuar.');}
            Files::write($base.'/'.$module.'/'.$relative,file_get_contents($root.'/'.$relative));$owners[$relative]=['module'=>$module,'hash'=>$hash];
        }
        if($owners){Files::write($index,json_encode($owners,JSON_THROW_ON_ERROR));}
    }
    public function reconcile(string $company,array $release,array $active): void
    {
        $root=$this->runtime($company,$release);$base=$this->app->state('companies/'.$company.'/extensions');$index=$base.'/index.json';if(!is_file($index)){return;}
        $owners=json_decode(file_get_contents($index),true,64,JSON_THROW_ON_ERROR);
        foreach($owners as $relative=>$owner){
            if(!preg_match('~\A(?:controllers|libraries)/[A-Za-z0-9_./-]+\z~',$relative)||str_contains($relative,'/../')){throw new Failure('module_extension_path','Extensão privada contém caminho inválido.');}
            $module=Id::identifier($owner['module']);$target=$root.'/'.$relative;
            if(in_array($module,$active,true)){
                $native=$release['root'].'/application/'.$relative;
                if(!is_file($target)||(is_file($native)&&!is_link($target)&&hash_file('sha256',$target)===hash_file('sha256',$native))){Files::write($target,file_get_contents($base.'/'.$module.'/'.$relative));}
            }elseif(is_file($target)&&!is_link($target)&&hash_file('sha256',$target)===$owner['hash']){
                $native=$release['root'].'/application/'.$relative;
                if(is_file($native)){Files::write($target,file_get_contents($native));}else{unlink($target);}
            }
        }
    }
}
