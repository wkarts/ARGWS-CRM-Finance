<?php
defined('BASEPATH') or exit('No direct script access allowed');
function multiempresa_app() {
    static $app;
    return $app ??= \Multiempresa\Application\App::environment();
}
function multiempresa_admin_init() {
    register_staff_capabilities('multiempresa',['capabilities'=>['view'=>'Visualizar painel multiempresa','create'=>'Cadastrar empresas','edit'=>'Gerenciar empresas, acessos e integrações','delete'=>'Remover empresas']],'Multiempresa');
    if (defined('PME_CONTEXT_READY')) { return; }
    // Native module migrations run after package replacement, without a separate database action.
    if(is_admin()){
        $modules=get_instance()->app_modules;$module=$modules->get('multiempresa');
        if(!empty($module['installed_version'])&&version_compare(\Multiempresa\Perfex\Version::current(),$module['installed_version'],'>')){
            try{
                if($modules->upgrade_database('multiempresa')!==true){throw new RuntimeException('Module migration incomplete');}
            }catch(Throwable $e){log_message('error','Multiempresa: atualização automática pendente ('.get_class($e).').');}
        }
    }
    multiempresa_guard_native_context();
    if (is_admin() || staff_can('view','multiempresa')) {
        get_instance()->app_menu->add_sidebar_menu_item('multiempresa',['name'=>'Multiempresa','href'=>admin_url('multiempresa'),'icon'=>'fa fa-building','position'=>5]);
    }
}
function multiempresa_selector() {
    if (defined('PME_CONTEXT_READY') || !is_staff_logged_in()) { return; }
    try {
        $app=multiempresa_app();$actor=$app->auth->syncNative((int)get_staff_user_id());$service=new \Multiempresa\Application\Installation($app);$installation=$service->get();
        if(!$service->canAccess($actor,$installation)){return;}
        $companies=$installation['organization_id']&&$installation['active']?$app->catalog->accessible($actor['id'],$installation['organization_id']):[];
        array_unshift($companies,['id'=>\Multiempresa\Application\Installation::ID,'name'=>$installation['name']]);
        \Multiempresa\Perfex\CompanySelector::render($app->config['native_url'].'modules/multiempresa/',$installation['name'],$installation['organization_name'],admin_url('multiempresa/switch_company'),get_instance()->security->get_csrf_token_name(),get_instance()->security->get_csrf_hash(),$companies,\Multiempresa\Application\Installation::ID,!$installation['organization_id']&&(int)$actor['platform_admin']);
    } catch (Throwable $e) { log_message('error','Multiempresa: seletor indisponível.'); }
}
function multiempresa_guard_native_context() {
    \Multiempresa\Perfex\NativeAccess::register();
}
function multiempresa_logout($staffId=null) {
    if (defined('PME_CONTEXT_READY')) { return; }
    $app=multiempresa_app();
    $u=$app->db->one('SELECT * FROM pme_users WHERE native_staff_id=?',[(int)($staffId ?: get_staff_user_id())]);
    if ($u) { $app->auth->logout($u); }
}
function multiempresa_cron() {
    if (defined('PME_CONTEXT_READY')) { return; }
    try { (new \Multiempresa\Application\Scheduler(multiempresa_app()))->tick(); }
    catch (Throwable $e) { log_message('error','Multiempresa cron: '.get_class($e)); }
}
function multiempresa_guard_uninstall($module) {
    if (($module['system_name'] ?? '')==='multiempresa') {
        if(!defined('PME_UNINSTALL_APPROVED')){redirect(admin_url('multiempresa/uninstall'));exit;}
    }
}
