<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Requirements are checked before any table or certificate is written. */
function nfse_nacional_requirements()
{
    $missing = [];
    if (PHP_VERSION_ID < 80100) {
        $missing[] = 'Este módulo requer PHP 8.1 ou superior.';
    }
    if (PHP_INT_SIZE < 8) { $missing[] = 'Este módulo requer PHP de 64 bits para preservar valores e numeração fiscal.'; }
    foreach (['openssl', 'curl', 'dom', 'zlib', 'mbstring', 'json'] as $extension) {
        if (!extension_loaded($extension)) {
            $missing[] = 'Habilite a extensão PHP ' . $extension . '.';
        }
    }
    if (extension_loaded('openssl') && !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        $missing[] = 'O OpenSSL precisa disponibilizar AES-256-GCM.';
    }
    if (!defined('APP_ENC_KEY') || !is_string(APP_ENC_KEY) || strlen(APP_ENC_KEY) < 16) {
        $missing[] = 'Configure uma chave APP_ENC_KEY válida no Perfex antes da ativação.';
    }
    return $missing;
}

/** Every permission requires a native authenticated staff session. */
function nfse_nacional_can($capability)
{
    $background = \NfseNacional\AutomationContext::current();
    if ($background !== null) { return in_array($capability, ($background['kind'] ?? '') === 'national_sync' ? ['view', 'national_view', 'national_sync'] : ['view', 'create', 'transmit'], true); }
    if (!in_array($capability, ['view', 'create', 'edit', 'transmit', 'send', 'settings', 'logs', 'national_view', 'national_sync', 'accounting_send', 'cancel', 'substitute', 'manifest', 'rates_view', 'rates_create', 'rates_edit', 'rates_delete', 'automation_view', 'automation_manage', 'report_crm', 'report_issued', 'report_received', 'report_package', 'report_export'], true)) {
        return false;
    }
    if (!function_exists('is_staff_logged_in') || !is_staff_logged_in()) {
        return false;
    }
    if (function_exists('is_admin') && is_admin()) {
        return true;
    }
    $check = static function ($action) {
        if (function_exists('staff_can')) {
            return (bool) staff_can($action, 'nfse_nacional');
        }
        return function_exists('has_permission') && (bool) has_permission('nfse_nacional', '', $action);
    };
    if ($capability === 'national_view') {
        return $check('view') && ($check('national_view') || $check('report_issued') || $check('report_received') || $check('report_package') || $check('national_sync'));
    }
    return $check('view') && ($capability === 'view' || $check($capability));
}

function nfse_nacional_native_can($feature, $capability)
{
    if (function_exists('staff_can')) {
        return (bool) staff_can($capability, $feature);
    }
    return function_exists('has_permission') && (bool) has_permission($feature, '', $capability);
}

/** Resolve IDs only through the currently selected company database. */
function nfse_nacional_client_allowed($id)
{
    if (!nfse_nacional_can('view') || !is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) {
        return false;
    }
    $ci = &get_instance();
    $client = $ci->db->select('userid')->where('userid', (int) $id)->get(db_prefix() . 'clients')->row_array();
    if (!$client) {
        return false;
    }
    $background = \NfseNacional\AutomationContext::current();
    if ($background !== null) { return (int) $background['client_id'] === (int) $id; }
    if ((function_exists('is_admin') && is_admin()) || nfse_nacional_native_can('customers', 'view')) {
        return true;
    }
    if (function_exists('is_customer_admin')) {
        return (bool) is_customer_admin((int) $id);
    }
    if (!function_exists('get_staff_user_id')) {
        return false;
    }
    $table = db_prefix() . 'customer_admins';
    if (!$ci->db->table_exists($table)) {
        return false;
    }
    return (bool) $ci->db->where('customer_id', (int) $id)->where('staff_id', (int) get_staff_user_id())->limit(1)->get($table)->row_array();
}

/** Fiscal permissions do not grant access to otherwise hidden invoices. */
function nfse_nacional_invoice_allowed($invoiceId, $invoice = null)
{
    if (!is_scalar($invoiceId) || !ctype_digit((string) $invoiceId) || (int) $invoiceId < 1 || !nfse_nacional_can('view')) {
        return false;
    }
    $ci = &get_instance();
    // Re-read the row to prevent caller-supplied client IDs from widening scope.
    $row = $ci->db->where('id', (int) $invoiceId)->get(db_prefix() . 'invoices')->row_array();
    if (!$row || !nfse_nacional_client_allowed($row['clientid'])) {
        return false;
    }
    $background = \NfseNacional\AutomationContext::current();
    if ($background !== null) { return (int) $background['invoice_id'] === (int) $invoiceId; }
    if (function_exists('is_admin') && is_admin()) {
        return true;
    }
    if (function_exists('user_can_view_invoice')) {
        return (bool) user_can_view_invoice((int) $invoiceId);
    }
    if (nfse_nacional_native_can('invoices', 'view')) {
        return true;
    }
    if (!function_exists('get_staff_user_id')) {
        return false;
    }
    $staffId = (int) get_staff_user_id();
    if ($staffId < 1) {
        return false;
    }
    if (nfse_nacional_native_can('invoices', 'view_own') && (int) ($row['addedfrom'] ?? 0) === $staffId) {
        return true;
    }
    return function_exists('get_option') && (string) get_option('allow_staff_view_invoices_assigned') === '1'
        && (int) ($row['sale_agent'] ?? 0) === $staffId;
}

function nfse_nacional_model()
{
    $ci = &get_instance();
    if (!isset($ci->nfse_nacional_model)) {
        $ci->load->model('nfse_nacional/nfse_nacional_model');
    }
    return $ci->nfse_nacional_model;
}

function nfse_nacional_client_data($clientId)
{
    if (!nfse_nacional_client_allowed($clientId)) {
        throw new RuntimeException('Você não tem acesso aos dados fiscais deste cliente.');
    }
    return nfse_nacional_model()->clientData((int) $clientId);
}

function nfse_nacional_status_label($status)
{
    $labels = [
        'draft' => 'Rascunho', 'ready' => 'Validada', 'processing' => 'Em transmissão',
        'authorized' => 'Autorizada', 'rejected' => 'Rejeitada', 'cancelled' => 'Cancelada',
        'uncertain' => 'Aguardando consulta',
    ];
    return $labels[$status] ?? 'Situação desconhecida';
}

/** Tenant identity is sourced only from the trusted runtime, never request data. */
function nfse_nacional_scope()
{
    $ci = &get_instance();
    $prefix = (string) db_prefix();
    if (strlen($prefix) > 40 || ($prefix !== '' && !preg_match('/\\A[A-Za-z0-9_]+\\z/', $prefix))) {
        throw new RuntimeException('O prefixo de banco da empresa é inválido.');
    }
    // Native connection and prefix are sufficient, including an empty prefix.
    // Optional legacy tenant identity is retained only for compatibility of existing scopes.
    $companyId = 'standalone';
    $organizationId = '';
    if ((defined('PME_CONTEXT_READY') && PME_CONTEXT_READY === true)) {
        $context = $GLOBALS['pme_context'] ?? null;
        if (!is_object($context) || empty($context->companyId) || !isset($context->prefix)
            || !hash_equals((string) $context->prefix, $prefix)) {
            throw new RuntimeException('O contexto da empresa não está disponível para emissão fiscal.');
        }
        $companyId = (string) $context->companyId;
        $organizationId = (string) ($context->organizationId ?? '');
    }
    return hash('sha256', json_encode([
        'database' => (string) ($ci->db->database ?? ''),
        'hostname' => (string) ($ci->db->hostname ?? ''),
        'prefix' => $prefix, 'company' => $companyId, 'organization' => $organizationId,
    ], JSON_UNESCAPED_SLASHES));
}

function nfse_nacional_is_screen()
{
    if (!nfse_nacional_can('view') && !(function_exists('nfse_nacional_settings_allowed') && nfse_nacional_settings_allowed())) {
        return false;
    }
    $ci = &get_instance();
    $uri = isset($ci->uri) ? (string) $ci->uri->uri_string() : '';
    return preg_match('~(?:^|/)nfse_nacional(?:/|$)~', $uri) === 1
        // The native invoice preview is loaded by AJAX after the page footer.
        || preg_match('~(?:^|/)invoices(?:/|$)~', $uri) === 1
        // Customer tabs can load invoice previews and fiscal forms after the footer.
        || preg_match('~(?:^|/)clients/client/[1-9][0-9]*(?:/|$)~', $uri) === 1
        // Older module versions registered a wrapper whose basename became
        // "settings_group" in the native menu. Keep its assets available while
        // upgrading, without activating the settings bridge on unrelated pages.
        || (preg_match('~(?:^|/)admin/settings/?$~', $uri) === 1
            && isset($ci->input)
            && in_array($ci->input->get('group'), ['nfse_nacional', 'settings_group'], true));
}

function nfse_nacional_guard_invoice_deletion($id)
{
    nfse_nacional_guard_linked_deletion('invoice_id', $id, 'Esta fatura possui registro no módulo NFS-e. Um rascunho sem DPS pode ser excluído no módulo; documentos transmitidos precisam permanecer no histórico fiscal.');
}

function nfse_nacional_guard_client_deletion($id)
{
    nfse_nacional_guard_linked_deletion('client_id', $id, 'Este cliente possui registro no módulo NFS-e. Preserve seu vínculo com os documentos fiscais; descarte apenas rascunhos ainda sem DPS pelo módulo.');
}

function nfse_nacional_guard_linked_deletion($column, $id, $message)
{
    if (!in_array($column, ['invoice_id', 'client_id'], true) || !is_scalar($id) || !ctype_digit((string) $id)) { return; }
    $ci = &get_instance();
    nfse_nacional_scope();
    $table = db_prefix() . 'nfse_nacional_documents';
    if (!$ci->db->table_exists($table)) { return; }
    $query = $ci->db->select('id')->where($column, (int) $id)->limit(1)->get($table);
    if (!$query) { show_error('Não foi possível conferir os vínculos fiscais. A exclusão foi interrompida; verifique o banco de dados.', 503); }
    if ($query->row_array()) { show_error($message, 409, 'Documento vinculado à NFS-e'); }
}
