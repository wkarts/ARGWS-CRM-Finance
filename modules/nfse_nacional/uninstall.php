<?php

defined('BASEPATH') or exit('No direct script access allowed');

if (!function_exists('is_admin') || !is_admin()) {
    throw new RuntimeException('Somente o administrador pode desinstalar NFS-e Nacional.');
}
require_once __DIR__ . '/helpers/nfse_nacional_helper.php';
nfse_nacional_scope();
$CI = &get_instance();
$nfseUninstallPrefix = db_prefix();
$nfseUninstallDebug = $CI->db->db_debug;
$CI->db->db_debug = false;
try {
    // Native explicit uninstall removes only this module's data in the active company.
    // Deactivation never calls this file and retains all fiscal records.
    foreach (['sync_runs', 'automation_jobs', 'automation_targets', 'competence_rates', 'competence_iss_rates', 'fiscal_requests', 'accounting_deliveries', 'national_events', 'national_documents', 'national_sync', 'events', 'documents', 'sequences', 'invoice_preferences', 'recipients', 'profiles', 'settings'] as $nfseUninstallSuffix) {
        $nfseUninstallTable = $nfseUninstallPrefix . 'nfse_nacional_' . $nfseUninstallSuffix;
        if ($CI->db->query("DROP TABLE IF EXISTS `{$nfseUninstallTable}`") === false) {
            throw new RuntimeException('A remoção das tabelas de NFS-e Nacional não foi concluída. Verifique as permissões do banco antes de repetir a desinstalação.');
        }
    }
} finally {
    $CI->db->db_debug = $nfseUninstallDebug;
}
unset($nfseUninstallPrefix, $nfseUninstallDebug, $nfseUninstallSuffix, $nfseUninstallTable);
