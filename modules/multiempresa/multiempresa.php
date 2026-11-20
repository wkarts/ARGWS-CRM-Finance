<?php
defined('BASEPATH') or exit('No direct script access allowed');
/*
Module Name: Multiempresa
Description: Gestão multiempresa com assistente de implantação, ambientes isolados, módulos por empresa, controle de acessos e backups.
Version: 1.2.8
Requires at least: 3.2.0
Author: WWSoft -> Wallace Kleiton
Author URI: https://t.me/wkartspro
*/
define('MULTIEMPRESA_MODULE_NAME', 'multiempresa');
require_once __DIR__.'/autoload.php';
require_once __DIR__.'/helpers/multiempresa_helper.php';
register_language_files(MULTIEMPRESA_MODULE_NAME, [MULTIEMPRESA_MODULE_NAME]);
register_activation_hook(MULTIEMPRESA_MODULE_NAME, 'multiempresa_activate');
register_deactivation_hook(MULTIEMPRESA_MODULE_NAME, 'multiempresa_deactivate');
\Multiempresa\Perfex\StaffAccessBridge::register();
hooks()->add_filter('admin_body_class',static fn($classes)=>array_merge($classes,['pme-managed-header']));
hooks()->add_action('app_admin_head',static function(){echo '<link rel="stylesheet" href="'.htmlspecialchars(module_dir_url(MULTIEMPRESA_MODULE_NAME,'assets/tenant.css?v='.\Multiempresa\Perfex\Version::current()),ENT_QUOTES,'UTF-8').'">';});
hooks()->add_action('admin_init', 'multiempresa_admin_init');
hooks()->add_action('before_render_aside_menu', 'multiempresa_selector');
hooks()->add_action('before_staff_logout', 'multiempresa_logout');
hooks()->add_action('after_cron_run', 'multiempresa_cron');
hooks()->add_action('pre_uninstall_module', 'multiempresa_guard_uninstall');
function multiempresa_activate() { require __DIR__.'/install.php'; }
function multiempresa_deactivate() {
    if (defined('PME_CONTEXT_READY')) { throw new RuntimeException('Gerencie o módulo na instalação principal.'); }
    if (is_file(\Multiempresa\Perfex\Installer::configPath())) {
        $app=multiempresa_app();
        $app->db->exec('DELETE FROM pme_sessions');
        $app->db->exec('DELETE FROM pme_tickets');
        $app->audit->log('module.deactivated',null);
    }
}
