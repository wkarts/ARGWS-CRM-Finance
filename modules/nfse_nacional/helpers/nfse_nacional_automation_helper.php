<?php
defined('BASEPATH') or exit('No direct script access allowed');

function nfse_nacional_automation_cron($manually = false)
{
    try {
        // Runs only in the company selected by Perfex/Multiempresa's cron dispatcher.
        nfse_nacional_scope();
        $model = nfse_nacional_model();
        if (!$model->db->table_exists($model->table('automation_jobs'))) { return; }
        (new \NfseNacional\Automation($model))->run();
    } catch (Throwable $error) {
        if (function_exists('log_message')) { log_message('error', 'NFS-e: verifique a configuração e a fila de automação da empresa.'); }
    }
}
function nfse_nacional_automation_created($id)
{
    $state = &nfse_nacional_automation_form_state();
    $depth = nfse_nacional_invoice_recipient_creation_depth();
    $pending = $state[$depth] ?? null;
    unset($state[$depth]);
    if ($pending !== null && !hash_equals($pending['scope'], nfse_nacional_scope())) { return; }
    nfse_nacional_automation_enqueue((int) $id, 'created', !empty($pending['selected']));
}
function &nfse_nacional_automation_form_state()
{
    static $state = []; return $state;
}
function nfse_nacional_automation_before_added($hook)
{
    if (!is_array($hook)) { return $hook; }
    $wrapped = isset($hook['data']) && is_array($hook['data']);
    $data = $wrapped ? $hook['data'] : $hook;
    $flag = $data['nfse_nacional_automatic'] ?? '0';
    unset($data['nfse_nacional_automatic']);
    if ($wrapped) { $hook['data'] = $data; unset($hook['nfse_nacional_automatic']); } else { $hook = $data; }
    $state = &nfse_nacional_automation_form_state();
    $depth = nfse_nacional_invoice_recipient_creation_depth(); unset($state[$depth]);
    if (!in_array($flag, ['0', '1'], true)) { throw new RuntimeException('Opção de emissão automática inválida.'); }
    if ($flag === '1') {
        if (\NfseNacional\AutomationPolicy::rule(nfse_nacional_model()->settings(), 'created')['mode'] === 'disabled') { throw new RuntimeException('A emissão após criação está desabilitada.'); }
        if (!nfse_nacional_can('transmit') || !nfse_nacional_invoice_recipient_can_write(false)) { throw new RuntimeException('Sem permissão para selecionar a emissão automática.'); }
        if (!hash_equals(nfse_nacional_scope(), (string) ($data['nfse_nacional_recipient_scope'] ?? ''))) { throw new RuntimeException('A empresa da fatura mudou. Reabra o formulário.'); }
        $state[$depth] = ['scope' => nfse_nacional_scope(), 'selected' => true];
    }
    return $hook;
}
function nfse_nacional_automation_payment($id)
{
    $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) { return; }
    $row = get_instance()->db->select('invoiceid')->where('id', $id)->get(db_prefix() . 'invoicepaymentrecords')->row_array();
    if ($row) { nfse_nacional_automation_enqueue((int) $row['invoiceid'], 'paid'); }
}
function nfse_nacional_automation_enqueue($invoiceId, $event, $explicit = false)
{
    try { (new \NfseNacional\Automation(nfse_nacional_model()))->enqueue($invoiceId, $event, $explicit); }
    catch (Throwable $error) { if (function_exists('log_message')) { log_message('error', 'NFS-e: não foi possível agendar a fatura. Consulte a automação.'); } }
}
function nfse_nacional_automation_cancel($id)
{
    try { (new \NfseNacional\Automation(nfse_nacional_model()))->cancelInvoice((int) $id); }
    catch (Throwable $error) { /* The worker rechecks deletion/cancellation immediately before issuance. */ }
}

function nfse_nacional_sync_cron($manually = false)
{
    try {
        nfse_nacional_scope(); $model = nfse_nacional_model();
        if (!$model->db->table_exists(db_prefix() . 'nfse_nacional_sync_runs')) { return; }
        (new \NfseNacional\SyncQueue($model))->run();
    } catch (Throwable $error) {
        if (function_exists('log_message')) { log_message('error', 'NFS-e: verifique a fila de sincronização nacional e os logs.'); }
    }
}
