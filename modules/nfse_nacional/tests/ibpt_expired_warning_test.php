<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
use NfseNacional\Config;
$checks=0;
function warningCheck(bool $condition,string $label):void{if(!$condition){throw new RuntimeException('FALHOU: '.$label);}++$GLOBALS['checks'];}
warningCheck(Config::defaults()['ibpt_show_expired_warning']==='1','padrão habilitado');
$hidden=Config::sanitize(['ibpt_show_expired_warning'=>'0']);
warningCheck($hidden['ibpt_show_expired_warning']==='0','campo desabilitado é salvo');
warningCheck(Config::merge($hidden)['ibpt_show_expired_warning']==='0','valor desabilitado permanece no reload');
warningCheck(Config::sanitize(['ibpt_show_expired_warning'=>'1'],$hidden)['ibpt_show_expired_warning']==='1','campo reabilitado é salvo');
warningCheck(Config::sanitize([], $hidden)['ibpt_show_expired_warning']==='0','edição independente preserva escolha anterior');
try{Config::sanitize(['ibpt_show_expired_warning'=>'true']);throw new RuntimeException('FALHOU: valor arbitrário aceito');}catch(InvalidArgumentException $e){++$checks;}
$view=file_get_contents(dirname(__DIR__).'/views/manage.php');
$settings=file_get_contents(dirname(__DIR__).'/views/ibpt_table.php');
$controller=file_get_contents(dirname(__DIR__).'/controllers/Nfse_nacional.php');
warningCheck(str_contains($settings, "settings[ibpt_show_expired_warning]") && str_contains($settings,'Exibir aviso discreto'),'configuração apresentada na guia IBPT');
warningCheck(str_contains($view,'role="note"') && str_contains($view,'data-nfse-ibpt-expired-notice'),'aviso não modal na tela Documentos');
warningCheck(!str_contains($view,'data-nfse-confirm="IBPT') && !str_contains($view,'data-nfse-dismiss-ibpt'),'aviso sem confirmação ou fechamento');
warningCheck(str_contains($controller,"['ibpt_show_expired_warning'] ?? '1'") && str_contains($controller,"['status'] ?? '') === 'expired'"),'só consulta a cópia local quando o aviso está habilitado');
echo 'OK: '.$checks.' verificações do aviso IBPT não bloqueante.' . "\n";
