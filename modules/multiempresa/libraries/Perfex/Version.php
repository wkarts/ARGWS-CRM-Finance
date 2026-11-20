<?php
declare(strict_types=1);
namespace Multiempresa\Perfex;
final class Version
{
    public static function current(): string {
        $text=file_get_contents(dirname(__DIR__,2).'/multiempresa.php');
        if (!preg_match('/^Version:\s*([1-9]\.[0-9]\.[0-9])\s*$/m',$text,$m)) { throw new \RuntimeException('Cabeçalho de versão incompatível com as migrations sequenciais do Perfex.'); }
        return $m[1];
    }
    public static function number(string $version): int {
        if (!preg_match('/^[1-9]\.[0-9]\.[0-9]$/',$version)) { throw new \InvalidArgumentException('Use uma versão normalizada, por exemplo 1.5.9 ou 1.6.0.'); }
        return (int)str_replace('.','',$version);
    }
}
