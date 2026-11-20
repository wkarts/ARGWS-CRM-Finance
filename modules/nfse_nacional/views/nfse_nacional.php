<?php

defined('BASEPATH') or exit('No direct script access allowed');

// Native Settings resolves this view by its basename (the public group slug).
// Its form belongs to Perfex; the module handles only the fiscal fields inside.
if (!function_exists('nfse_nacional_settings_allowed') || !nfse_nacional_settings_allowed()) {
    echo '<div class="alert alert-warning">Você não tem permissão para configurar a emissão de NFS-e.</div>';
    return;
}
try {
    nfse_nacional_scope();
    $nfseSettingsData = nfse_nacional_model()->settingsData();
    get_instance()->load->view('nfse_nacional/settings', $nfseSettingsData);
} catch (Throwable $error) {
    if (function_exists('log_message')) { log_message('error', 'NFS-e Nacional: configurações indisponíveis (' . get_class($error) . ').'); }
    echo '<div class="alert alert-warning">Não foi possível carregar as configurações da NFS-e. Confira a instalação e as permissões do módulo.</div>';
}
