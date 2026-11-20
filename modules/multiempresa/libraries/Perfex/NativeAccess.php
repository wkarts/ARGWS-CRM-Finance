<?php
declare(strict_types=1);
namespace Multiempresa\Perfex;

use Multiempresa\Application\{App,Installation,StaffAccess,CompanyRoutes};

/** Keep restricted identities outside the primary CRM even when the module is disabled. */
final class NativeAccess
{
    private static bool $registered=false;
    public static function register(): void
    {
        if(self::$registered||defined('PME_CONTEXT_READY')||!is_file(Installer::configPath())){return;}
        self::$registered=true;
        hooks()->add_action('admin_init',[self::class,'guard'],0);
        hooks()->add_action('after_staff_login',[self::class,'afterLogin'],0);
    }
    public static function guard(): void
    {
        if(!is_staff_logged_in()){return;}
        $ci=get_instance();$app=App::environment();$actor=$app->auth->syncNative((int)get_staff_user_id());
        $controller=strtolower($ci->router->fetch_class());$method=strtolower($ci->router->fetch_method());
        if($controller==='authentication'){return;}
        // Only SSO transport is available to a tenant-only identity; the management panel is not.
        if($controller==='multiempresa'&&in_array($method,['authorize','switch_company','return_to_installation'],true)){return;}
        $can=(new Installation($app))->canAccess($actor);
        if(!$can){self::enter($app,$actor);return;}
        if($controller==='multiempresa'||$controller==='mods'){return;}
        $destination=(new StaffAccess($app))->destination($actor);
        if($destination&&$destination!==Installation::ID&&self::enabled($app)){self::enter($app,$actor);return;}
        // Old native links remain usable, with subsequent navigation on the opaque address.
        if(!defined('PME_PRIMARY_ROUTE')&&$ci->input->method()==='get'&&!$ci->input->is_ajax_request()){
            $base=(new CompanyRoutes($app))->url(Installation::ID);
            if($base!==$app->config['native_url']){redirect($base.ltrim($ci->uri->uri_string(),'/').(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:''));}
        }
    }
    public static function afterLogin(): void
    {
        if(!is_staff_logged_in()){return;}$app=App::environment();self::enter($app,$app->auth->syncNative((int)get_staff_user_id()));
    }
    private static function enabled(App $app): bool
    {
        try{Installer::assertEnabled($app);return true;}catch(\Throwable){return false;}
    }
    private static function enter(App $app,array $actor): void
    {
        $ci=get_instance();
        if($ci->input->is_ajax_request()||($ci->input->method()!=='get'&&strtolower($ci->router->fetch_class())!=='authentication')){show_error('A empresa ativa mudou. Recarregue a página antes de continuar.',409);exit;}
        $target=(new StaffAccess($app))->destination($actor);
        if($target!==Installation::ID&&!self::enabled($app)){
            if((new Installation($app))->canAccess($actor)){$target=Installation::ID;}
            else{show_error('O acesso às empresas está temporariamente desativado. Entre em contato com o administrador.',403);exit;}
        }
        if(!$target){show_error('Você não possui acesso a uma empresa disponível. Entre em contato com o administrador.',403);exit;}
        if($target===Installation::ID){redirect((new CompanyRoutes($app))->url($target).'admin');}
        else{redirect($app->catalog->url($app->catalog->company($target)).'__pme/start');}
    }
}
