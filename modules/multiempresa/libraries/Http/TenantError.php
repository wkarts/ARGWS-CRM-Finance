<?php
declare(strict_types=1);
namespace Multiempresa\Http;
use Multiempresa\Domain\Failure;

/** Public company screens receive plain guidance; diagnostics stay in administrative logs. */
final class TenantError
{
    public static function message(int $status): string
    {
        return match($status){
            401=>'Sua sessão expirou. Entre novamente para continuar.',
            403=>'Você não tem permissão para realizar esta ação. Se precisar de ajuda, entre em contato com o suporte.',
            404=>'A página solicitada não foi encontrada.',
            405=>'Esta ação não está disponível.',
            421=>'Este endereço não está disponível. Entre em contato com o suporte.',
            429=>'Aguarde um momento e tente novamente.',
            default=>'Não foi possível concluir a solicitação. Tente novamente. Se o problema persistir, entre em contato com o suporte.',
        };
    }
    public static function render(\Throwable $e): void
    {
        $status=$e instanceof Failure?$e->status:503;
        $reason=$e instanceof Failure?$e->reason:'module_unavailable';
        $message=self::message($status);
        $html=str_contains(strtolower($_SERVER['HTTP_ACCEPT']??''),'text/html')
            &&strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')!=='xmlhttprequest';
        $route=$GLOBALS['pme_context']->route??($_GET['__pme_route']??($_SERVER['REQUEST_URI']??''));
        if(is_string($route)&&preg_match('~(?:^|/)api/v1(?:/|$)~',$route)){$html=false;}
        if(!headers_sent()){
            http_response_code($status);header('Cache-Control: no-store');
            header('Content-Type: '.($html?'text/html':'application/json').'; charset=utf-8');
        }
        if($html){require dirname(__DIR__,2).'/views/tenant_error.php';return;}
        // Preserve API error codes while using the same safe, user-facing message.
        echo json_encode(['error'=>$reason,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
