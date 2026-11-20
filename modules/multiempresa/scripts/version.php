<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/autoload.php';
use Multiempresa\Perfex\Version;
$root=dirname(__DIR__);$version=Version::current();$number=Version::number($version);
if (($argv[1] ?? '--check')==='--next') {
    if ($number>=999) { throw new RuntimeException('Limite nativo de migrations sequenciais atingido.'); }
    $number++; $next=intdiv($number,100).'.'.intdiv($number%100,10).'.'.($number%10);
    $file=$root.'/migrations/'.$number.'_version_'.$number.'.php';
    if (file_exists($file)) { throw new RuntimeException('A migration já existe; não será sobrescrita.'); }
    file_put_contents($file,"<?php\ndefined('BASEPATH') or exit('No direct script access allowed');\nclass Migration_Version_".$number." extends App_module_migration\n{\n    public function up() {\n        // Sem mudança de esquema nesta versão. Acrescente aqui a evolução necessária.\n    }\n}\n");
    file_put_contents($root.'/multiempresa.php',str_replace('Version: '.$version,'Version: '.$next,file_get_contents($root.'/multiempresa.php')));
    $manifest=json_decode(file_get_contents($root.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);$manifest['version']=$next;
    file_put_contents($root.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    $version=$next;
}
$manifest=json_decode(file_get_contents($root.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);
if ($manifest['version']!==$version) { throw new RuntimeException('Manifesto e cabeçalho divergentes.'); }
for ($i=100;$i<=$number;$i++) {
    $file=$root.'/migrations/'.$i.'_version_'.$i.'.php';
    if (!is_file($file) || !str_contains(file_get_contents($file),'class Migration_Version_'.$i.' extends App_module_migration')) { throw new RuntimeException('Migration ausente ou inválida: '.$i); }
}
echo 'OK '.$version.' / migrations 100..'.$number."\n";
