<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/version.php';
$destination=$argv[1] ?? dirname($root).'/multiempresa-module-'.$version.'.zip';
if (!str_ends_with($destination,'.zip') || file_exists($destination)) { throw new RuntimeException('Informe um ZIP novo.'); }
$parent=realpath(dirname($destination));
if (!$parent || $parent===$root || str_starts_with($parent,$root.DIRECTORY_SEPARATOR)) { throw new RuntimeException('Grave o ZIP fora da pasta do módulo.'); }
$zip=new ZipArchive();
if ($zip->open($destination,ZipArchive::CREATE|ZipArchive::EXCL)!==true) { throw new RuntimeException('Não foi possível criar o pacote.'); }
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $entry) {
    if($entry->isLink()) { throw new RuntimeException('Links simbólicos não são distribuídos.'); }
    $relative=substr($entry->getPathname(),strlen($root)+1);
    if (preg_match('~(?:^|/)(?:\.git|node_modules|__pycache__|runtime|state)(?:/|$)~',$relative)) { continue; }
    if($entry->isFile()) {$zip->addFile($entry->getPathname(),'multiempresa/'.$relative);}
}
if (!$zip->close()) { throw new RuntimeException('Falha ao concluir ZIP.'); }
echo $destination."\n";
