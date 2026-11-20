<?php

defined('BASEPATH') or exit('No direct script access allowed');
$nfseRecipientEscape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<template id="nfse-invoice-recipient-template" data-nfse-invoice-form-template data-nfse-options-url="<?php echo $nfseRecipientEscape($recipient_options_url ?? ''); ?>" data-nfse-company-scope="<?php echo $nfseRecipientEscape($recipient_company_scope ?? ''); ?>">
    <div class="form-group" data-nfse-invoice-recipient-field>
        <label for="nfse_nacional_recipient" class="control-label">Tomador da NFS-e</label>
        <select name="nfse_nacional_recipient" id="nfse_nacional_recipient" class="selectpicker" data-width="100%" data-live-search="true" data-nfse-invoice-recipient aria-describedby="nfse-invoice-recipient-status" disabled>
            <option value="">Usar padrão do cliente</option>
        </select>
        <?php if (($invoice_automation['created']['mode'] ?? 'disabled') !== 'disabled' && nfse_nacional_can('transmit')): ?>
        <div data-nfse-auto-create-option><div class="checkbox"><input type="checkbox" id="nfse_nacional_automatic" name="nfse_nacional_automatic" value="1"><label for="nfse_nacional_automatic">Agendar NFS-e ao criar esta fatura</label></div>
        <p class="help-block">Prazo configurado: <?php echo (int) ($invoice_automation['created']['delay_minutes'] ?? 30); ?> minutos. A seleção vale apenas para esta nova fatura.</p></div>
        <?php endif; ?>
        <p class="help-block" data-nfse-automation-summary>Selecione o cliente e tomador para conferir as automações aplicáveis.</p>
        <input type="hidden" name="nfse_nacional_recipient_scope" value="<?php echo $nfseRecipientEscape($recipient_company_scope ?? ''); ?>" data-nfse-invoice-recipient-scope disabled>
        <p class="help-block" id="nfse-invoice-recipient-status" data-nfse-invoice-recipient-status role="status" aria-live="polite">Selecione o cliente para definir quem receberá a NFS-e.</p>
    </div>
</template>
