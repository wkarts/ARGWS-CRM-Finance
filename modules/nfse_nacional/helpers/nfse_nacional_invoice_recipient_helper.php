<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Fiscal recipient is a module preference, never a column of the native invoice. */
function nfse_nacional_invoice_recipient_can_write($updating)
{
    return (nfse_nacional_can('create') || nfse_nacional_can('edit'))
        && ((function_exists('is_admin') && is_admin())
            || nfse_nacional_native_can('invoices', $updating ? 'edit' : 'create'));
}

/** The observed Perfex form is #invoice-form with #clientid inside .f_client_id. */
function nfse_nacional_render_invoice_recipient_template()
{
    $ci = &get_instance();
    $uri = isset($ci->uri) ? (string) $ci->uri->uri_string() : '';
    if (preg_match('~(?:^|/)invoices(?:/|$)~', $uri) !== 1) { return; }
    if (!nfse_nacional_invoice_recipient_can_write(false) && !nfse_nacional_invoice_recipient_can_write(true)) { return; }
    try {
        $scope = nfse_nacional_scope();
        get_instance()->load->view('nfse_nacional/invoice_recipient_field', [
            'recipient_options_url' => admin_url('nfse_nacional/invoice_recipient_options'),
            'recipient_company_scope' => $scope,
            'invoice_automation' => nfse_nacional_model()->settings()['automations'],
        ]);
    } catch (Throwable $error) {
        if (function_exists('log_message')) { log_message('error', 'NFS-e Nacional: não foi possível carregar o campo de tomador da fatura.'); }
    }
}

/** Request-local handoff; absent fields never reuse a previous failed write. */
function &nfse_nacional_invoice_recipient_state()
{
    static $state = ['create' => [], 'update' => []];
    return $state;
}

/** Native before/after hooks run within the same Invoices_model::add frame.
 * Keeping nested add calls separate also covers other modules creating invoices
 * inside callbacks. No synthetic invoice ID or unverified database field is used.
 */
function nfse_nacional_invoice_recipient_creation_depth()
{
    $depth = 0;
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64) as $frame) {
        if (strtolower((string) ($frame['class'] ?? '')) === 'invoices_model'
            && strtolower((string) ($frame['function'] ?? '')) === 'add') { ++$depth; }
    }
    return $depth;
}

function nfse_nacional_invoice_recipient_id($value)
{
    return (is_int($value) || is_string($value))
        && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
        ? (int) $value : 0;
}

/** Strip module-only inputs from both current and legacy hook payload formats. */
function nfse_nacional_invoice_recipient_extract(&$hook)
{
    $wrapped = isset($hook['data']) && is_array($hook['data']);
    $data = $wrapped ? $hook['data'] : $hook;
    $present = array_key_exists('nfse_nacional_recipient', $data);
    $selection = $data['nfse_nacional_recipient'] ?? null;
    $scope = $data['nfse_nacional_recipient_scope'] ?? null;
    unset($data['nfse_nacional_recipient'], $data['nfse_nacional_recipient_scope'], $data['nfse_nacional_automatic']);
    if ($wrapped) {
        $hook['data'] = $data;
        // Wrapper metadata must not leave a second copy of a submitted value.
        unset($hook['nfse_nacional_recipient'], $hook['nfse_nacional_recipient_scope'], $hook['nfse_nacional_automatic']);
    } else { $hook = $data; }
    return [$data, $present, $selection, $scope];
}

function nfse_nacional_invoice_recipient_validate($data, $selection, $submittedScope, $invoiceId = 0)
{
    if (!nfse_nacional_invoice_recipient_can_write($invoiceId > 0)) {
        throw new RuntimeException('Você não tem permissão para definir o tomador da NFS-e nesta fatura.');
    }
    $scope = nfse_nacional_scope();
    if (!is_string($submittedScope) || !hash_equals($scope, $submittedScope)) {
        throw new RuntimeException('A empresa da fatura mudou ou o formulário expirou. Reabra a fatura e selecione novamente o tomador.');
    }
    if (!is_string($selection)) { throw new RuntimeException('Selecione um tomador válido para a NFS-e.'); }
    if (!get_instance()->db->table_exists(db_prefix() . 'nfse_nacional_invoice_preferences')) {
        throw new RuntimeException('Atualize o banco do módulo NFS-e Nacional antes de salvar o tomador da fatura.');
    }
    if ($invoiceId > 0 && !nfse_nacional_invoice_allowed($invoiceId)) {
        throw new RuntimeException('Você não tem acesso a esta fatura.');
    }
    $clientId = nfse_nacional_invoice_recipient_id($data['clientid'] ?? null);
    if (!$clientId && $invoiceId > 0 && !array_key_exists('clientid', $data)) {
        $row = get_instance()->db->select('clientid')->where('id', $invoiceId)->get(db_prefix() . 'invoices')->row_array();
        $clientId = nfse_nacional_invoice_recipient_id($row['clientid'] ?? null);
    }
    if (!$clientId || !nfse_nacional_client_allowed($clientId)) {
        throw new RuntimeException('Selecione um cliente permitido antes de definir o tomador da NFS-e.');
    }
    $selection = nfse_nacional_model()->validateInvoiceRecipientSelection($clientId, $selection);
    return ['client_id' => $clientId, 'selection' => $selection, 'scope' => $scope];
}

function nfse_nacional_invoice_recipient_reject(Throwable $error, $written = false)
{
    $message = ($written ? 'Não foi possível concluir a preferência fiscal. Confira se a fatura foi gravada antes de tentar novamente. ' : '') . $error->getMessage();
    if (function_exists('show_error')) {
        show_error(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $written ? 503 : 422, 'Tomador da NFS-e');
    }
    throw new RuntimeException($message, 0, $error);
}

function nfse_nacional_before_invoice_added($hook)
{
    if (!is_array($hook)) { return $hook; }
    $state = &nfse_nacional_invoice_recipient_state();
    $depth = nfse_nacional_invoice_recipient_creation_depth();
    unset($state['create'][$depth]);
    [$data, $present, $selection, $scope] = nfse_nacional_invoice_recipient_extract($hook);
    if (!$present) { return $hook; }
    try { $state['create'][$depth] = nfse_nacional_invoice_recipient_validate($data, $selection, $scope); }
    catch (Throwable $error) { nfse_nacional_invoice_recipient_reject($error); }
    return $hook;
}

function nfse_nacional_before_invoice_updated($hook, $id = null)
{
    if (!is_array($hook)) { return $hook; }
    $invoiceId = nfse_nacional_invoice_recipient_id($hook['id'] ?? $hook['invoice_id'] ?? $id);
    [$data, $present, $selection, $scope] = nfse_nacional_invoice_recipient_extract($hook);
    if (!$invoiceId) {
        if ($present) { nfse_nacional_invoice_recipient_reject(new RuntimeException('A fatura não pôde ser identificada. Reabra o formulário e tente novamente.')); }
        return $hook;
    }
    $state = &nfse_nacional_invoice_recipient_state();
    unset($state['update'][$invoiceId]);
    try {
        $state['update'][$invoiceId] = $present
            ? nfse_nacional_invoice_recipient_validate($data, $selection, $scope, $invoiceId)
            : ['sync' => true, 'scope' => nfse_nacional_scope()];
    } catch (Throwable $error) { nfse_nacional_invoice_recipient_reject($error); }
    return $hook;
}

function nfse_nacional_after_invoice_added($id)
{
    $invoiceId = nfse_nacional_invoice_recipient_id($id);
    $state = &nfse_nacional_invoice_recipient_state();
    $depth = nfse_nacional_invoice_recipient_creation_depth();
    $pending = $state['create'][$depth] ?? null;
    unset($state['create'][$depth]);
    if (!$invoiceId || !$pending) { return; }
    try {
        if (!hash_equals($pending['scope'], nfse_nacional_scope())) { throw new RuntimeException('A empresa da fatura mudou durante a gravação. Reabra a fatura para revisar o tomador.'); }
        nfse_nacional_model()->saveInvoiceRecipientSelection($invoiceId, $pending['client_id'], $pending['selection']);
    } catch (Throwable $error) { nfse_nacional_invoice_recipient_reject($error, true); }
}

function nfse_nacional_after_invoice_updated($hook)
{
    $invoiceId = nfse_nacional_invoice_recipient_id(is_array($hook) ? ($hook['invoice_id'] ?? $hook['id'] ?? null) : $hook);
    $state = &nfse_nacional_invoice_recipient_state();
    $pending = $state['update'][$invoiceId] ?? null;
    unset($state['update'][$invoiceId]);
    if (!$invoiceId || !$pending) { return; }
    try {
        if (!hash_equals($pending['scope'], nfse_nacional_scope())) { throw new RuntimeException('A empresa da fatura mudou durante a gravação. Reabra a fatura para revisar o tomador.'); }
        if (!empty($pending['sync'])) {
            nfse_nacional_model()->syncInvoiceRecipientClient($invoiceId);
        } else {
            nfse_nacional_model()->saveInvoiceRecipientSelection($invoiceId, $pending['client_id'], $pending['selection']);
            // Modern Perfex passes this entry by reference so a preference-only
            // edit also receives the native success message.
            if (is_array($hook) && array_key_exists('updated', $hook)) { $hook['updated'] = true; }
        }
    } catch (Throwable $error) { nfse_nacional_invoice_recipient_reject($error, true); }
}
