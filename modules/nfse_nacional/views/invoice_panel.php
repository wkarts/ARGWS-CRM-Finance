<?php

defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';

$nfseContext = ['invoice' => $invoice ?? null, 'documents' => $documents ?? [],
    'environment' => $environment ?? '2', 'can' => $can ?? []];
$nfseInvoice = $nfseArray($nfseContext['invoice'] ?? []);
$nfseInvoiceId = (int) ($nfseInvoice['id'] ?? 0);
if ($nfseInvoiceId < 1 || !nfse_nacional_invoice_allowed($nfseInvoiceId)) { return; }
$nfseEnvironment = (string) ($nfseContext['environment'] ?? '2');
if (!in_array($nfseEnvironment, ['1', '2'], true)) { return; }
$nfseCapabilities = is_array($nfseContext['can'] ?? null) ? $nfseContext['can'] : [];
$nfseCan = static function (string $capability) use ($nfseCapabilities): bool {
    return !empty($nfseCapabilities[$capability]) && nfse_nacional_can($capability);
};
$nfseDocuments = [];
foreach (($nfseContext['documents'] ?? []) as $item) {
    $row = $nfseArray($item);
    if ((int) ($row['invoice_id'] ?? 0) !== $nfseInvoiceId || (int) ($row['client_id'] ?? 0) !== (int) ($nfseInvoice['clientid'] ?? 0) || (int) ($row['id'] ?? 0) < 1
        || !in_array((string) ($row['environment'] ?? ''), ['1', '2'], true)) { continue; }
    $nfseDocuments[] = $row;
}
$nfseCurrent = null;
foreach ($nfseDocuments as $row) {
    if ((string) $row['environment'] === $nfseEnvironment) { $nfseCurrent = $row; break; }
}
$nfseInvoiceOperational = empty($history_conflict) && !in_array((int) ($nfseInvoice['status'] ?? 0), [5, 6], true);
$nfseCanPrepare = $nfseCan('create') && $nfseInvoiceOperational;
$nfseClientId = (int) ($nfseInvoice['clientid'] ?? 0);
$nfseRecipientContext = $nfseArray($recipient_context ?? []);
$nfseRecipientOptions = [];
$nfseRecipientSelection = is_string($nfseRecipientContext['selection'] ?? null) ? $nfseRecipientContext['selection'] : '';
if ((int) ($nfseRecipientContext['invoice_id'] ?? 0) === $nfseInvoiceId
    && (int) ($nfseRecipientContext['client_id'] ?? 0) === $nfseClientId
    && is_array($nfseRecipientContext['options'] ?? null)) {
    foreach ($nfseRecipientContext['options'] as $option) {
        if (!is_array($option) || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null)
            || (!in_array($option['value'], ['', 'client', 'primary_contact'], true) && !preg_match('/^recipient:[1-9][0-9]*$/D', $option['value']))) { continue; }
        $nfseRecipientOptions[$option['value']] = $option['label'];
    }
}
$nfseRecipientSelectable = !$nfseCurrent && $nfseCanPrepare && array_key_exists('', $nfseRecipientOptions)
    && array_key_exists($nfseRecipientSelection, $nfseRecipientOptions);
?>
<section class="nfse-root nfse-invoice-panel panel_s mtop15" id="nfse-invoice-panel-<?php echo $nfseInvoiceId; ?>" data-nfse-invoice-id="<?php echo $nfseInvoiceId; ?>" data-nfse-context-url="<?php echo $e(admin_url('nfse_nacional/invoice_context/' . $nfseInvoiceId)); ?>"<?php echo $nfseCsrfAttributes(); ?> aria-labelledby="nfse-invoice-title-<?php echo $nfseInvoiceId; ?>">
    <div class="panel-body">
        <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
        <div class="row mbot15">
            <div class="col-sm-7"><h4 class="no-margin" id="nfse-invoice-title-<?php echo $nfseInvoiceId; ?>"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i> NFS-e Nacional</h4><p class="text-muted mtop5">Emissão vinculada a esta fatura.</p></div>
            <div class="col-sm-5 text-right"><span class="label <?php echo $nfseEnvironment === '1' ? 'label-success nfse-production' : 'label-default nfse-test'; ?> nfse-environment"><?php echo $nfseEnvironment === '1' ? 'Produção — validade fiscal' : 'Testes — sem validade fiscal'; ?></span></div>
        </div>
        <?php if (!empty($history_conflict)): ?><div class="alert alert-warning">O cliente desta fatura mudou após a criação de seu histórico fiscal. A nova emissão está bloqueada até a revisão desse vínculo.</div><?php endif; ?>
        <?php if (!$nfseCurrent): ?>
            <?php if ($nfseRecipientSelectable): ?>
            <div class="row"><div class="col-sm-8">
                <div class="form-group">
                    <label class="control-label" for="nfse-prepare-recipient-<?php echo $nfseInvoiceId; ?>">Tomador da NFS-e</label>
                    <select name="recipient_selection" id="nfse-prepare-recipient-<?php echo $nfseInvoiceId; ?>" class="selectpicker" data-width="100%" data-live-search="true" data-nfse-prepare-recipient aria-describedby="nfse-prepare-recipient-help-<?php echo $nfseInvoiceId; ?>">
                        <?php foreach ($nfseRecipientOptions as $value => $label): ?><option value="<?php echo $e($value); ?>"<?php echo $value === $nfseRecipientSelection ? ' selected' : ''; ?>><?php echo $e($label); ?></option><?php endforeach; ?>
                    </select>
                    <p class="help-block" id="nfse-prepare-recipient-help-<?php echo $nfseInvoiceId; ?>">O tomador selecionado será usado neste novo documento. O cliente responsável pela cobrança permanece o mesmo.</p>
                </div>
            </div></div>
            <?php endif; ?>
            <div class="nfse-actions text-right">
                <?php if ($nfseCanPrepare): ?>
                    <button type="button" class="btn btn-default nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/prepare/' . $nfseInvoiceId)); ?>" data-nfse-navigate="1"<?php echo $nfseRecipientSelectable ? ' data-nfse-use-recipient="1"' : ''; ?>><i class="fa fa-pencil" aria-hidden="true"></i> Rascunho</button>
                    <?php if ($nfseCan('transmit')): ?>
                        <button type="button" class="btn btn-primary nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/emit_invoice/' . $nfseInvoiceId)); ?>" data-nfse-emission="1" data-nfse-refresh="1"<?php echo $nfseRecipientSelectable ? ' data-nfse-use-recipient="1"' : ''; ?>><i class="fa fa-paper-plane" aria-hidden="true"></i> Emitir NFS-e</button>
                    <?php endif; ?>
                <?php else: ?><span class="text-muted"><?php echo in_array((int) ($nfseInvoice['status'] ?? 0), [5, 6], true) ? 'Conclua o rascunho da fatura ou reveja seu cancelamento antes de emitir.' : 'Seu acesso permite consultar as notas vinculadas.'; ?></span><?php endif; ?>
            </div>
        <?php elseif (!empty($nfseCurrent['recipient_name'])): ?>
            <p class="mbot5"><strong>Tomador da NFS-e:</strong> <?php echo $e($nfseCurrent['recipient_name']); ?></p>
            <p class="help-block"><?php echo in_array((string) ($nfseCurrent['status'] ?? ''), ['draft', 'ready', 'rejected'], true)
                ? 'O rascunho mantém o tomador salvo. Para revisar essa escolha, abra o rascunho.' : 'O tomador é preservado no documento fiscal.'; ?></p>
        <?php endif; ?>
        <?php if ($nfseDocuments): ?>
            <div class="table-responsive mtop15"><table class="table nfse-table"><thead><tr><th>Ambiente</th><th>NFS-e / rascunho</th><th>Situação</th><th><span class="sr-only">Ações</span></th></tr></thead><tbody>
                <?php foreach ($nfseDocuments as $row): $docId = (int) $row['id']; $status = (string) ($row['status'] ?? ''); $docEnvironment = (string) $row['environment']; $editable = in_array($status, ['draft', 'ready', 'rejected'], true); ?>
                <tr>
                    <td><span class="label <?php echo $docEnvironment === '1' ? 'label-success nfse-production' : 'label-default nfse-test'; ?> nfse-environment"><?php echo $docEnvironment === '1' ? 'Produção' : 'Testes'; ?></span><?php if ($docEnvironment === '2'): ?><div class="small nfse-subline">Sem validade fiscal</div><?php endif; ?></td>
                    <td><?php echo !empty($row['nfse_number']) ? 'NFS-e ' . $e($row['nfse_number']) : 'Rascunho #' . $docId; ?><?php if (!empty($row['recipient_name'])): ?><div class="small text-muted nfse-subline">Tomador: <?php echo $e($row['recipient_name']); ?></div><?php endif; ?></td>
                    <td><span class="label <?php echo $e($nfseStatusClass($status)); ?> nfse-status nfse-status-<?php echo $e(in_array($status, ['draft', 'ready', 'processing', 'authorized', 'cancelled', 'rejected', 'uncertain'], true) ? $status : 'unknown'); ?>"><?php echo $e(nfse_nacional_status_label($status)); ?></span></td>
                    <td class="text-right"><div class="nfse-actions">
                        <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/preview/' . $docId)); ?>" target="_blank" rel="noopener noreferrer" data-nfse-preview><i class="fa fa-eye" aria-hidden="true"></i> Pré-visualizar DANFSe</a>
                        <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/document/' . $docId)); ?>"><?php echo $editable ? 'Continuar rascunho' : 'Abrir NFS-e'; ?></a>
                        <?php if ($nfseCan('transmit') && $editable && $nfseInvoiceOperational): ?>
                            <button type="button" class="btn btn-primary btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/transmit/' . $docId)); ?>" data-nfse-emission="1" data-reload="1">Emitir NFS-e</button>
                        <?php elseif ($nfseCan('view') && in_array($status, ['processing', 'uncertain'], true)): ?>
                            <button type="button" class="btn btn-default btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/reconcile/' . $docId)); ?>" data-reload="1"><i class="fa fa-refresh" aria-hidden="true"></i> Consultar situação</button>
                        <?php endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="help-block mtop15">Nenhuma NFS-e vinculada a esta fatura.</p><?php endif; ?>
        <?php if ($nfseClientId > 0): ?><a href="<?php echo $e(admin_url('clients/client/' . $nfseClientId . '?group=nfse_nacional') . '#nfse-client-recipients'); ?>" class="btn btn-link btn-sm"><i class="fa fa-address-card" aria-hidden="true"></i> Tomadores do cliente</a><?php endif; ?>
    </div>
</section>
