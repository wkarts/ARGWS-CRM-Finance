<?php
declare(strict_types=1);
namespace Multiempresa\Perfex;

/** Shared native sidebar component; authorization is enforced again at the POST endpoint. */
final class CompanySelector
{
    public static function render(string $assets,string $name,string $organization,string $action,string $csrfName,string $csrf,array $companies,string $current,bool $configuration=false,string $central=''): void
    {
        $e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
        echo '<link rel="stylesheet" href="'.$e($assets.'assets/selector.css?v='.Version::current()).'">';
        echo '<li id="pme-company-selector" class="pme-company-selector"><div class="dropdown"><button type="button" class="dropdown-toggle btn btn-default pme-company-toggle" data-toggle="dropdown" aria-expanded="false" aria-label="Empresa atual: '.$e($name).'"><span class="pme-company-icon">'.self::icon().'</span><span class="pme-company-title"><strong title="'.$e($name).'">'.$e($name).'</strong><small class="text-muted">Empresa atual</small></span>'.self::icon('chevron').'</button><ul class="dropdown-menu" aria-label="Trocar empresa"><li class="dropdown-header">'.$e($organization?:'Instalação atual').'</li>';
        foreach($companies as $company){
            $active=$company['id']===$current;
            echo '<li><form action="'.$e($action).'" method="post"><input type="hidden" name="'.$e($csrfName).'" value="'.$e($csrf).'"><button class="pme-company-option" name="company_id" value="'.$e($company['id']).'"'.($active?' disabled aria-current="true"':'').'>'.self::icon($active?'check':'building').'<span>'.$e($company['name']).'</span></button></form></li>';
        }
        if(!$companies){echo '<li class="dropdown-header">Nenhuma outra empresa autorizada</li>';}
        if($central!==''){echo '<li role="separator" class="divider"></li><li><a href="'.$e($central).'">Administração central</a></li>';}
        if($configuration){echo '<li role="separator" class="divider"></li><li><a href="'.$e(dirname(rtrim($assets,'/'),2).'/admin/multiempresa?tab=settings#pme-installation-settings').'">Vincular esta instalação a uma organização</a></li>';}
        echo '</ul></div></li><script src="'.$e($assets.'assets/selector.js?v='.Version::current()).'"></script>';
    }
    private static function icon(string $kind='building'): string
    {
        $path=match($kind){
            'check'=>'<path d="m5 12 4 4L19 6"/>',
            'chevron'=>'<path d="m6 9 6 6 6-6"/>',
            default=>'<path d="M4 21V3h12v18M16 9h4v12M2 21h20M9 21v-4h2v4M7 7h1m4 0h1M7 11h1m4 0h1M7 15h1m4 0h1"/>',
        };
        return '<svg class="pme-company-symbol" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$path.'</svg>';
    }

}
