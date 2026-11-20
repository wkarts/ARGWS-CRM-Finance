<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: NFS-e Nacional
Description: Emissão de NFS-e no Emissor Nacional, com A1, rascunhos e tomadores vinculados aos clientes.
Author: WWSoft -> Wallace Kleiton
Author URI: https://t.me/wkartspro
Version: 0.8.9
Requires at least: 3.2.0
*/

define('NFSE_NACIONAL_MODULE_NAME', 'nfse_nacional');
define('NFSE_NACIONAL_VERSION', '0.8.9');

require_once __DIR__ . '/helpers/nfse_nacional_helper.php';
require_once __DIR__ . '/helpers/nfse_nacional_integration_helper.php';
require_once __DIR__ . '/helpers/nfse_nacional_invoice_recipient_helper.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/helpers/nfse_nacional_automation_helper.php';

register_activation_hook(NFSE_NACIONAL_MODULE_NAME, 'nfse_nacional_activate');
if (function_exists('register_uninstall_hook')) {
    register_uninstall_hook(NFSE_NACIONAL_MODULE_NAME, 'nfse_nacional_uninstall');
} else {
    hooks()->add_action('uninstall_nfse_nacional_module', 'nfse_nacional_uninstall');
}

if (function_exists('register_language_files')) {
    register_language_files(NFSE_NACIONAL_MODULE_NAME, [NFSE_NACIONAL_MODULE_NAME]);
}
hooks()->add_action('after_cron_run', 'nfse_nacional_automation_cron');
hooks()->add_action('after_cron_run', 'nfse_nacional_sync_cron');
hooks()->add_filter('before_invoice_added', 'nfse_nacional_automation_before_added', 0);
hooks()->add_action('after_invoice_added', 'nfse_nacional_automation_created', 20);
hooks()->add_action('after_payment_added', 'nfse_nacional_automation_payment', 20);
hooks()->add_action('invoice_marked_as_cancelled', 'nfse_nacional_automation_cancel', 20);
hooks()->add_action('before_invoice_deleted', 'nfse_nacional_automation_cancel', 20);
hooks()->add_action('admin_init', 'nfse_nacional_admin_init');
hooks()->add_action('app_admin_head', 'nfse_nacional_admin_head');
hooks()->add_action('app_admin_footer', 'nfse_nacional_admin_footer');
hooks()->add_action('after_invoice_preview_template_rendered', 'nfse_nacional_render_invoice_panel', 10, 1);
hooks()->add_action('after_invoice_view_as_client_link', 'nfse_nacional_render_invoice_shortcut', 10, 1);
hooks()->add_filter('invoices_table_row_data', 'nfse_nacional_invoice_table_row', 10, 2);
hooks()->add_action('before_invoice_deleted', 'nfse_nacional_guard_invoice_deletion', 1);
hooks()->add_action('before_client_deleted', 'nfse_nacional_guard_client_deletion', 1);
hooks()->add_filter('before_invoice_added', 'nfse_nacional_before_invoice_added', 1);
hooks()->add_action('after_invoice_added', 'nfse_nacional_after_invoice_added', 1);
if (function_exists('do_action_deprecated')) {
    hooks()->add_filter('before_update_invoice', 'nfse_nacional_before_invoice_updated', 1, 2);
    hooks()->add_action('invoice_updated', 'nfse_nacional_after_invoice_updated', 1);
} else {
    hooks()->add_filter('before_invoice_updated', 'nfse_nacional_before_invoice_updated', 1, 2);
    hooks()->add_action('after_invoice_updated', 'nfse_nacional_after_invoice_updated', 1);
}

function nfse_nacional_activate()
{
    $missing = nfse_nacional_requirements();
    if ($missing !== []) {
        throw new RuntimeException('Não foi possível ativar NFS-e Nacional. ' . implode(' ', $missing));
    }
    nfse_nacional_scope();
    require __DIR__ . '/install.php';
    \NfseNacional\Upgrade083::run(nfse_nacional_model());
}

function nfse_nacional_uninstall()
{
    require __DIR__ . '/uninstall.php';
}

function nfse_nacional_admin_init()
{
    if (nfse_nacional_redirect_legacy_settings()) { return; }
    if (function_exists('register_staff_capabilities')) {
        register_staff_capabilities('nfse_nacional', ['capabilities' => [
            'view' => 'Visualizar NFS-e e dados fiscais dos clientes permitidos',
            'create' => 'Preparar rascunhos a partir das faturas permitidas',
            'edit' => 'Editar tomadores, perfis fiscais e rascunhos',
            'transmit' => 'Validar, transmitir e consultar NFS-e',
            'send' => 'Enviar NFS-e autorizada ao destinatário',
            'settings' => 'Configurar emitente, tributação e certificado A1',
            'logs' => 'Visualizar e exportar logs da NFS-e da empresa ativa',
            'national_view' => 'Consultar e baixar documentos nacionais e pacotes mensais da empresa',
            'national_sync' => 'Sincronizar documentos emitidos e recebidos no ambiente nacional',
            'accounting_send' => 'Enviar pacotes mensais aos destinatários cadastrados',
            'cancel' => 'Solicitar cancelamento fiscal de NFS-e emitida pela empresa',
            'substitute' => 'Preparar substituição de NFS-e emitida pela empresa',
            'manifest' => 'Confirmar ou rejeitar NFS-e recebida pela empresa',
        ] + \NfseNacional\Permissions::capabilities()], 'NFS-e Nacional');
    }
    // Settings belong to the native Setup > Settings registry, independently
    // of whether this installation renders an operational sidebar.
    nfse_nacional_register_settings();
    if (!nfse_nacional_can('view')) {
        return;
    }
    $ci = &get_instance();
    if (isset($ci->app_menu) && method_exists($ci->app_menu, 'add_sidebar_menu_item')
        && method_exists($ci->app_menu, 'add_sidebar_children_item')) {
        $ci->app_menu->add_sidebar_menu_item('nfse-nacional', [
            'name' => 'NFSE', 'collapse' => true,
            'position' => 17, 'icon' => 'fa-solid fa-file-invoice',
        ]);
        if (nfse_nacional_can('report_crm')) {
        $ci->app_menu->add_sidebar_children_item('nfse-nacional', [
            'slug' => 'nfse-nacional-documentos', 'name' => 'Documentos',
            'href' => admin_url('nfse_nacional'), 'position' => 1, 'icon' => '',
        ]);
        }
        if (nfse_nacional_can('rates_view') && \NfseNacional\CompetenceRate::enabled(nfse_nacional_model()->settings())) {
            $ci->app_menu->add_sidebar_children_item('nfse-nacional', ['slug' => 'nfse-competence-rates',
                'name' => 'Alíquotas por competência', 'href' => admin_url('nfse_nacional/competence_rates'), 'position' => 3, 'icon' => '']);
        }
        if (nfse_nacional_can('automation_view') && \NfseNacional\AutomationPolicy::enabled(nfse_nacional_model()->settings())) {
            $ci->app_menu->add_sidebar_children_item('nfse-nacional', ['slug' => 'nfse-automation',
                'name' => 'Automações', 'href' => admin_url('nfse_nacional/automation'), 'position' => 4, 'icon' => '']);
        }
        if (\NfseNacional\Permissions::anyReport()) {
            $ci->app_menu->add_sidebar_children_item('nfse-nacional', ['slug' => 'nfse-reports',
                'name' => 'Relatórios', 'href' => admin_url('nfse_nacional/reports'), 'position' => 5, 'icon' => '']);
        }
        if (nfse_nacional_can('logs')) {
            $ci->app_menu->add_sidebar_children_item('nfse-nacional', [
                'slug' => 'nfse-nacional-logs', 'name' => 'Logs',
                'href' => admin_url('nfse_nacional/logs'), 'position' => 2, 'icon' => '',
            ]);
        }
    }
    if (isset($ci->app_tabs) && method_exists($ci->app_tabs, 'add_customer_profile_tab')) {
        $ci->app_tabs->add_customer_profile_tab('nfse_nacional', [
            'name' => 'NFS-e', 'icon' => 'fa-solid fa-file-invoice',
            'view' => 'nfse_nacional/client_tab', 'position' => 45,
        ]);
    }
}

function nfse_nacional_admin_head()
{
    if (!nfse_nacional_is_screen()) {
        return;
    }
    echo '<link rel="stylesheet" href="' . htmlspecialchars(\NfseNacional\Assets::url('style'), ENT_QUOTES, 'UTF-8') . '">';
}

function nfse_nacional_admin_footer()
{
    if (!nfse_nacional_is_screen()) { return; }
    nfse_nacional_render_invoice_recipient_template();
    // Menu and customer-tab icons are rendered by Perfex from their PHP registration.
    // This script only handles module forms and the invoice integration.
    echo '<script src="' . htmlspecialchars(\NfseNacional\Assets::url('script'), ENT_QUOTES, 'UTF-8')
        . '"></script>';
}
