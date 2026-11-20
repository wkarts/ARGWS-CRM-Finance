<?php

defined('BASEPATH') or exit('No direct script access allowed');

function nfse_nacional_settings_url()
{
    return admin_url('settings?group=nfse_nacional');
}

/** The native Settings controller keeps its own access boundary. */
function nfse_nacional_settings_allowed()
{
    return nfse_nacional_can('settings')
        && ((function_exists('is_admin') && is_admin()) || nfse_nacional_native_can('settings', 'view'));
}

/** Keep old navigation links working without replaying submitted settings. */
function nfse_nacional_redirect_legacy_settings()
{
    $ci = &get_instance();
    if (!isset($ci->input, $ci->uri) || $ci->input->get('group') !== 'settings_group'
        || preg_match('~(?:^|/)admin/settings/?$~', (string) $ci->uri->uri_string()) !== 1
        || !in_array(strtoupper((string) $ci->input->method()), ['GET', 'HEAD'], true)
        || !nfse_nacional_settings_allowed()) {
        return false;
    }
    redirect(nfse_nacional_settings_url(), 'location', 302);
    return true;
}

/** Register inside Setup > Settings; never create a configuration submenu in operations. */
function nfse_nacional_register_settings()
{
    if (!nfse_nacional_settings_allowed()) { return false; }
    $ci = &get_instance();
    $tab = [
        'slug' => 'nfse_nacional', 'name' => 'NFS-e Nacional',
        // Modern Perfex derives the group from the view basename. Keep it equal
        // to the public slug so the native menu and module assets use one URL.
        'view' => 'nfse_nacional/nfse_nacional', 'position' => 1,
        'icon' => 'fa-solid fa-file-invoice',
    ];
    if (isset($ci->app) && method_exists($ci->app, 'add_settings_section') && is_callable([$ci->app, 'add_settings_section'])) {
        $ci->app->add_settings_section('documentos_fiscais', [
            'slug' => 'documentos_fiscais', 'name' => 'Documentos Fiscais',
            'title' => 'Documentos Fiscais', 'position' => 65, 'children' => [$tab],
        ]);
        return true;
    }
    // Public module API before the settings sections introduced in Perfex 3.2.
    if (isset($ci->app_tabs) && method_exists($ci->app_tabs, 'add_settings_tab') && is_callable([$ci->app_tabs, 'add_settings_tab'])) {
        $tab['position'] = 65;
        $ci->app_tabs->add_settings_tab('nfse_nacional', $tab);
        return true;
    }
    if (function_exists('log_message')) { log_message('error', 'NFS-e Nacional: API nativa de seções de Configurações indisponível.'); }
    return false;
}

/** Administrative preview hook. The hook payload supplies only an ID, never trusted fiscal data. */
function nfse_nacional_preview_invoice_id($invoice)
{
    if (!is_object($invoice) || !isset($invoice->id)
        || filter_var($invoice->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        || !nfse_nacional_can('view')) {
        return 0;
    }
    $invoiceId = (int) $invoice->id;
    try {
        nfse_nacional_scope();
        return nfse_nacional_invoice_allowed($invoiceId) ? $invoiceId : 0;
    } catch (Throwable $error) { return 0; }
}

/** Native invoice More menu; also supports themes lacking the final preview hook. */
function nfse_nacional_render_invoice_shortcut($invoice)
{
    $invoiceId = nfse_nacional_preview_invoice_id($invoice);
    if (!$invoiceId) { return; }
    $url = htmlspecialchars(admin_url('nfse_nacional/invoice_context/' . $invoiceId), ENT_QUOTES, 'UTF-8');
    echo '<li><a href="#nfse-invoice-panel-' . $invoiceId . '" data-nfse-open-invoice="' . $invoiceId
        . '" data-nfse-context-url="' . $url . '"><i class="fa fa-file-text" aria-hidden="true"></i> NFS-e Nacional</a></li>';
}

/**
 * Native DataTable filter: keep its column indexes/ordering and add an always
 * visible action below the invoice number, outside hover-only row-options.
 * Opening the panel reads the latest context; it never prepares or transmits.
 */
function nfse_nacional_invoice_table_row($row, $invoiceRow)
{
    if (!is_array($row) || !is_array($invoiceRow) || !isset($invoiceRow['id'])
        || filter_var($invoiceRow['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        || !nfse_nacional_can('view')) { return $row; }
    $invoiceId = (int) $invoiceRow['id'];
    try {
        nfse_nacional_scope();
        // Reload access through the selected company's tables. Ignore client IDs
        // and ownership fields supplied by another table filter or caller.
        if (!nfse_nacional_invoice_allowed($invoiceId)) { return $row; }
    } catch (Throwable $error) { return $row; }

    $marker = 'data-nfse-grid-action="' . $invoiceId . '"';
    $cell = null;
    foreach ($row as $index => $html) {
        if (!is_int($index) || !is_string($html)) { continue; }
        if (str_contains($html, $marker)) { return $row; }
        if ($cell === null && preg_match('~(?:invoices/(?:list_invoices|invoice)/' . $invoiceId
            . '(?:[/?&#"\x27]|$)|init_invoice\(\s*' . $invoiceId . '\s*[,\)])~', $html)) {
            $cell = $index;
        }
    }
    // The native table starts with the invoice-number cell. Some themes prepend
    // a checkbox; keep that checkbox intact and use the following text cell.
    if ($cell === null) {
        foreach ($row as $index => $html) {
            if (!is_int($index) || !is_string($html) || preg_match('~<input\b[^>]*\btype\s*=\s*["\x27]?checkbox~i', $html)) { continue; }
            $cell = $index;
            break;
        }
    }
    if ($cell === null) { return $row; }

    $canEmit = nfse_nacional_can('create') && nfse_nacional_can('transmit')
        && !in_array((int) ($invoiceRow['status'] ?? 0), [5, 6], true);
    $label = $canEmit ? 'Emitir NFS-e' : 'Consultar NFS-e';
    $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $contextUrl = $escape(admin_url('nfse_nacional/invoice_context/' . $invoiceId));
    $fallbackUrl = $escape(admin_url('invoices/list_invoices/' . $invoiceId) . '#nfse-invoice-panel-' . $invoiceId);
    $row[$cell] .= '<div class="nfse-invoice-grid-action-wrap"><a class="btn btn-default btn-xs nfse-invoice-grid-action" href="'
        . $fallbackUrl . '" ' . $marker . ' data-nfse-open-invoice="' . $invoiceId
        . '" data-nfse-context-url="' . $contextUrl . '" aria-haspopup="dialog"><i class="fa fa-file-text" aria-hidden="true"></i> '
        . $label . '</a></div>';
    return $row;
}

function nfse_nacional_render_invoice_panel($invoice)
{
    $invoiceId = nfse_nacional_preview_invoice_id($invoice);
    if (!$invoiceId) { return; }
    try {
        $context = nfse_nacional_model()->invoiceContext($invoiceId);
        $current = $context['invoice'] ?? null;
        if (!is_object($current) || (int) ($current->id ?? 0) !== $invoiceId) {
            throw new RuntimeException('Contexto da fatura inválido.');
        }
        get_instance()->load->view('nfse_nacional/invoice_panel', $context);
    } catch (Throwable $error) {
        // Keep the native financial screen usable without exposing fiscal or database details.
        if (function_exists('log_message')) { log_message('error', 'NFS-e Nacional: painel da fatura indisponível (' . get_class($error) . ').'); }
        echo '<div class="alert alert-warning mtop15">Não foi possível carregar os dados da NFS-e. Atualize a página; se persistir, confira a instalação do módulo.</div>';
    }
}
