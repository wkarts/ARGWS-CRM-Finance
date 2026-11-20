<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';
$clientId = (int) (is_object($client ?? null) ? ($client->userid ?? 0) : (($client['userid'] ?? 0)));
if ($clientId < 1 || !nfse_nacional_client_allowed($clientId) || !nfse_nacional_can('view')) {
    echo '<div class="alert alert-info">Salve o cliente e confira sua permissão para acessar os dados fiscais.</div>';
    return;
}
if (!isset($profile, $recipients, $invoices, $documents)) {
    $nfseClientData = nfse_nacional_client_data($clientId);
    $profile = $nfseClientData['profile'] ?? [];
    $recipients = $nfseClientData['recipients'] ?? [];
    $contacts = $nfseClientData['contacts'] ?? [];
    $invoices = $nfseClientData['invoices'] ?? [];
    $documents = $nfseClientData['documents'] ?? [];
    $emission_environment = $nfseClientData['emission_environment'] ?? '2';
    $emission_defaults = $nfseClientData['emission_defaults'] ?? \NfseNacional\EmissionProfile::fields();
    $recipient_emission_defaults = $nfseClientData['recipient_emission_defaults'] ?? \NfseNacional\EmissionProfile::fields();
}
$profile = $nfseArray($profile);
$nfseClientEmissionProfile = \NfseNacional\EmissionProfile::fromRecord($profile);
$nfseEmissionDefaults = $nfseArray($emission_defaults ?? \NfseNacional\EmissionProfile::fields());
$nfseRecipientEmissionDefaults = $nfseArray($recipient_emission_defaults ?? \NfseNacional\EmissionProfile::resolve($nfseEmissionDefaults, $profile));
$recipientSource = in_array((string) ($profile['source'] ?? 'client'), ['client', 'primary_contact', 'recipient'], true)
    ? (string) ($profile['source'] ?? 'client') : 'client';
$recipients = $recipients ?? [];
$contacts = $contacts ?? [];
$invoices = $invoices ?? [];
$documents = $documents ?? [];
$nfseClientDanfeStatus = is_array($danfe_status ?? null) ? $danfe_status : null;
$emission_environment = (string) ($emission_environment ?? '2');
$clientRow = $nfseArray($client);
$recipientOptions = ['' => 'Selecione um tomador'];
foreach ($recipients as $recipient) {
    $row = $nfseRecipientData($recipient);
    $recipientOptions[(int) ($row['id'] ?? 0)] = ($row['label'] ?? $row['name'] ?? 'Tomador') . (!empty($row['name']) && ($row['label'] ?? '') !== $row['name'] ? ' — ' . $row['name'] : '');
}
$primaryContact = null;
foreach ($contacts as $contact) { $contact = $nfseArray($contact); if (!empty($contact['is_primary'])) { $primaryContact = $contact; break; } }
$editProfile = nfse_nacional_can('edit');
?>
<div class="nfse-root nfse-client-tab"<?php echo $nfseCsrfAttributes(); ?>>
    <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
    <ul class="nav nav-tabs" role="tablist" aria-label="Cadastro fiscal do cliente" data-nfse-client-tabs>
        <li role="presentation" class="active"><a id="nfse-client-emission-tab" href="#nfse-client-emission" aria-controls="nfse-client-emission" role="tab" data-toggle="tab">Dados para emissão</a></li>
        <li role="presentation"><a id="nfse-client-recipients-tab" href="#nfse-client-recipients" aria-controls="nfse-client-recipients" role="tab" data-toggle="tab">Tomadores <span class="badge"><?php echo count($recipients); ?></span></a></li>
        <li role="presentation"><a id="nfse-client-documents-tab" href="#nfse-client-documents" aria-controls="nfse-client-documents" role="tab" data-toggle="tab">Faturas e NFS-e</a></li>
    </ul>
    <div class="tab-content mtop20">
    <div role="tabpanel" class="tab-pane active" id="nfse-client-emission" aria-labelledby="nfse-client-emission-tab">
    <h4 class="no-margin">Dados para emissão</h4>
    <p class="text-muted mtop5 mbot20">Defina quem recebe o serviço. O responsável pelo boleto continua vinculado à fatura.</p>
    <?php echo form_open(admin_url('nfse_nacional/save_profile/' . $clientId), ['id' => 'nfse-profile-form', 'data-nfse-form' => 'profile', 'data-reload' => '1']); ?>
    <fieldset<?php echo !$editProfile ? ' disabled' : ''; ?>>
        <div class="row"><div class="col-md-5">
            <?php $nfseSelect('profile[source]', 'Emitir em nome de', $recipientSource, ['client' => 'Próprio cliente', 'primary_contact' => 'Contato principal', 'recipient' => 'Tomador cadastrado / dependente'], ['data-nfse-recipient-source' => true]); ?>
            <div class="well well-sm" data-nfse-source-show="client"<?php echo $recipientSource === 'client' ? '' : ' hidden'; ?>><p><strong><?php echo $e($clientRow['company'] ?? ''); ?></strong><br><?php echo $e($clientRow['vat'] ?? 'CPF / CNPJ não informado no cliente'); ?></p><p class="text-muted no-margin"><small>Nome, documento e endereço partem do cadastro do cliente. Revise o rascunho antes de emitir.</small></p></div>
            <div class="well well-sm" data-nfse-source-show="primary_contact"<?php echo $recipientSource === 'primary_contact' ? '' : ' hidden'; ?>><p><strong><?php echo $primaryContact ? $e(trim(($primaryContact['firstname'] ?? '') . ' ' . ($primaryContact['lastname'] ?? ''))) : 'Contato principal não cadastrado'; ?></strong><br><?php echo $e($primaryContact['email'] ?? ''); ?></p><p class="text-muted no-margin"><small>Informe o CPF / CNPJ do contato abaixo. O documento do cliente não substitui o documento do tomador.</small></p></div>
            <div data-nfse-source-show="primary_contact"<?php echo $recipientSource === 'primary_contact' ? '' : ' hidden'; ?>><?php $nfseInput('profile[contact_tax_id]', 'CPF / CNPJ do contato principal', $profile['contact_tax_id'] ?? '', ['maxlength' => 20]); ?></div>
            <div data-nfse-source-show="recipient"<?php echo $recipientSource === 'recipient' ? '' : ' hidden'; ?>><?php $nfseSelect('profile[recipient_id]', 'Tomador padrão', $profile['recipient_id'] ?? '', $recipientOptions); ?><p class="help-block">Inclua ou edite os cadastros na aba Tomadores. A seleção salva será utilizada como padrão nas próximas emissões.</p></div>
        </div><div class="col-md-7">
            <div class="row"><div class="col-sm-5"><?php $nfseInput('profile[municipality_code]', 'Código IBGE do tomador', $profile['municipality_code'] ?? '', ['maxlength' => 7, 'inputmode' => 'numeric', 'pattern' => '[0-9]{7}']); ?></div><div class="col-sm-3"><?php $nfseInput('profile[number]', 'Número', $profile['number'] ?? '', ['maxlength' => 60]); ?></div><div class="col-sm-4"><?php $nfseInput('profile[district]', 'Bairro', $profile['district'] ?? '', ['maxlength' => 60]); ?></div></div>
            <?php $nfseInput('profile[complement]', 'Complemento', $profile['complement'] ?? '', ['maxlength' => 156]); ?>
            <p class="help-block">Esses campos complementam o endereço do cliente ou do contato principal. O tomador cadastrado usa seu próprio endereço.</p>
        </div></div>
        <hr><h4>Perfil de emissão do cliente</h4>
        <?php
            $nfseProfilePrefix = 'profile';
            $nfseProfileValues = $nfseClientEmissionProfile;
            $nfseProfileDefaults = $nfseEmissionDefaults;
            $nfseProfileReadonly = !$editProfile;
            require __DIR__ . '/emission_profile.php';
        ?>
        <p class="help-block">Os novos rascunhos usam este perfil. Cada tomador cadastrado pode personalizar seus próprios campos.</p>
        <?php if (nfse_nacional_can('send')): ?><?php $nfseCheckbox('profile[send_after_authorized]', 'Enviar o XML e o DANFSe ao e-mail do tomador após a autorização', (string) ($profile['send_after_authorized'] ?? '0') === '1'); ?><?php else: ?><p class="text-muted">Envio após autorização: <?php echo !empty($profile['send_after_authorized']) ? 'habilitado' : 'desabilitado'; ?></p><?php endif; ?>
        <p class="help-block">A preferência é copiada para novos rascunhos e pode ser revista antes da transmissão.</p>
    </fieldset>
    <?php if ($editProfile): ?><div class="mtop20"><button type="submit" class="btn btn-primary"><i class="fa fa-check" aria-hidden="true"></i> Salvar dados para emissão</button></div><?php endif; ?>
    <?php echo form_close(); ?>
    </div>

    <div role="tabpanel" class="tab-pane" id="nfse-client-recipients" aria-labelledby="nfse-client-recipients-tab">
        <?php require __DIR__ . '/client_recipients.php'; ?>
    </div>

    <div role="tabpanel" class="tab-pane" id="nfse-client-documents" aria-labelledby="nfse-client-documents-tab">
    <h4 class="no-margin">Faturas para emissão</h4><p class="mtop10"><span class="label nfse-environment <?php echo $emission_environment === '1' ? 'label-success nfse-production' : 'label-default nfse-test'; ?>"><?php echo $emission_environment === '1' ? 'Produção — validade fiscal' : 'Testes — sem validade fiscal'; ?></span></p><p class="text-muted">Com os dados fiscais completos, clique em Emitir NFS-e. Use Rascunho para revisar a operação antes da transmissão.</p>
    <?php if (!$invoices): ?><p class="well well-sm nfse-inline-note">Nenhuma fatura disponível para este cliente.</p><?php else: ?>
    <div class="table-responsive"><table class="table nfse-table"><thead><tr><th>Fatura</th><th>Data</th><th class="text-right">Valor</th><th><span class="sr-only">Ações</span></th></tr></thead><tbody>
    <?php foreach ($invoices as $invoiceItem): $invoiceItem = $nfseArray($invoiceItem); $invoiceDocumentStatus = (string) ($invoiceItem['document_status'] ?? 'draft'); $invoiceDocumentId = (int) ($invoiceItem['document_id'] ?? 0); ?>
        <tr><td><a href="<?php echo $e(admin_url('invoices/list_invoices/' . (int) $invoiceItem['id'])); ?>">#<?php echo $e($invoiceItem['number'] ?? $invoiceItem['id']); ?></a><?php if ($invoiceDocumentId > 0): ?><div class="small nfse-subline"><span class="label <?php echo $e($nfseStatusClass($invoiceDocumentStatus)); ?> nfse-status nfse-status-<?php echo $e($invoiceDocumentStatus); ?>"><?php echo $e(nfse_nacional_status_label($invoiceDocumentStatus)); ?></span><?php if (!empty($invoiceItem['document_number'])): ?> · NFS-e <?php echo $e($invoiceItem['document_number']); ?><?php endif; ?></div><?php endif; ?></td><td><?php echo $e($nfseDate($invoiceItem['date'] ?? '')); ?></td><td class="text-right nfse-amount"><?php echo $e($nfseMoney($invoiceItem['total'] ?? '')); ?></td><td class="text-right">
            <div class="nfse-actions text-right">
            <?php if ($invoiceDocumentId > 0): ?>
                <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/document/' . $invoiceDocumentId)); ?>"><?php echo in_array($invoiceDocumentStatus, ['draft', 'ready', 'rejected'], true) ? 'Continuar rascunho' : 'Abrir NFS-e'; ?></a>
                <?php if ($nfseClientDanfeStatus === null) { $nfseClientDanfeStatus = \NfseNacional\Danfe::diagnostics(); } ?>
                <?php if (!empty($nfseClientDanfeStatus['available'])): ?><a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/preview/' . $invoiceDocumentId)); ?>" target="_blank" rel="noopener noreferrer" data-nfse-preview><i class="fa fa-eye" aria-hidden="true"></i> Pré-visualizar DANFSe</a><?php else: ?><button type="button" class="btn btn-default btn-sm" disabled title="<?php echo $e($nfseClientDanfeStatus['message'] ?? 'Não foi possível carregar os recursos do DANFSe.'); ?>">DANFSe indisponível</button><?php endif; ?>
                <?php if (in_array($invoiceDocumentStatus, ['authorized', 'cancelled'], true) && !empty($nfseClientDanfeStatus['available'])): ?><a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/download/' . $invoiceDocumentId . '/pdf')); ?>" title="Baixar DANFSe" aria-label="Baixar DANFSe"><i class="fa fa-download" aria-hidden="true"></i></a><?php endif; ?>
                <?php if (in_array($invoiceDocumentStatus, ['processing', 'uncertain'], true)): ?><button type="button" class="btn btn-default btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/reconcile/' . $invoiceDocumentId)); ?>" data-reload="1">Consultar situação</button>
                <?php elseif (nfse_nacional_can('transmit') && in_array($invoiceDocumentStatus, ['draft', 'ready', 'rejected'], true)): ?><button type="button" class="btn btn-primary btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/transmit/' . $invoiceDocumentId)); ?>" data-nfse-emission="1" data-reload="1"><i class="fa fa-paper-plane" aria-hidden="true"></i> Emitir NFS-e</button><?php endif; ?>
            <?php elseif (nfse_nacional_can('create') && !in_array((int) ($invoiceItem['status'] ?? 0), [5, 6], true)): ?>
                <button type="button" class="btn btn-default btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/prepare/' . (int) $invoiceItem['id'])); ?>" data-nfse-navigate="1"><i class="fa fa-pencil" aria-hidden="true"></i> Rascunho</button><?php if (nfse_nacional_can('transmit')): ?><button type="button" class="btn btn-primary btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/emit_invoice/' . (int) $invoiceItem['id'])); ?>" data-nfse-emission="1" data-nfse-navigate="1"><i class="fa fa-paper-plane" aria-hidden="true"></i> Emitir NFS-e</button><?php endif; ?>
            <?php else: ?><span class="text-muted">Indisponível para emissão</span><?php endif; ?>
            </div>
        </td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>

    <?php if ($documents): if ($nfseClientDanfeStatus === null) { $nfseClientDanfeStatus = \NfseNacional\Danfe::diagnostics(); } ?><hr><h4>Histórico de NFS-e</h4><div class="table-responsive"><table class="table nfse-table"><thead><tr><th>Fatura / NFS-e</th><th>Ambiente</th><th>Situação</th><th><span class="sr-only">Ações</span></th></tr></thead><tbody>
    <?php foreach ($documents as $doc): $doc = $nfseArray($doc); ?><tr><td>Fatura #<?php echo $e($doc['invoice_id'] ?? ''); ?><div class="small text-muted nfse-subline"><?php echo !empty($doc['nfse_number']) ? 'NFS-e ' . $e($doc['nfse_number']) : 'Rascunho #' . $e($doc['id']); ?></div></td><td><?php echo (string) ($doc['environment'] ?? '2') === '1' ? 'Produção' : 'Testes'; ?></td><td><span class="label <?php echo $e($nfseStatusClass($doc['status'] ?? 'draft')); ?> nfse-status nfse-status-<?php echo $e($doc['status'] ?? 'draft'); ?>"><?php echo $e(nfse_nacional_status_label($doc['status'] ?? 'draft')); ?></span></td><td class="text-right"><a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/document/' . (int) $doc['id'])); ?>">Abrir</a> <?php if (!empty($nfseClientDanfeStatus['available'])): ?><a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/preview/' . (int) $doc['id'])); ?>" target="_blank" rel="noopener noreferrer" data-nfse-preview><i class="fa fa-eye" aria-hidden="true"></i> Pré-visualizar DANFSe</a><?php else: ?><button type="button" class="btn btn-default btn-sm" disabled title="<?php echo $e($nfseClientDanfeStatus['message'] ?? 'Não foi possível carregar os recursos do DANFSe.'); ?>">DANFSe indisponível</button><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    </div>
    </div>

    <?php if ($editProfile): ?>
    <div class="modal fade" id="nfse-recipient-modal" tabindex="-1" role="dialog" aria-labelledby="nfse-recipient-modal-title"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <?php echo form_open(admin_url('nfse_nacional/save_recipient/' . $clientId), ['id' => 'nfse-recipient-form', 'data-nfse-form' => 'recipient', 'data-base-action' => admin_url('nfse_nacional/save_recipient/' . $clientId), 'data-reload' => '1']); ?>
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Fechar"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="nfse-recipient-modal-title">Novo tomador</h4></div>
        <div class="modal-body">
            <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
            <ul class="nav nav-tabs" role="tablist" aria-label="Cadastro do tomador">
                <li role="presentation" class="active"><a id="nfse-recipient-data-tab" href="#nfse-recipient-data" aria-controls="nfse-recipient-data" role="tab" data-toggle="tab">Dados do tomador</a></li>
                <li role="presentation"><a id="nfse-recipient-emission-tab" href="#nfse-recipient-emission" aria-controls="nfse-recipient-emission" role="tab" data-toggle="tab">Perfil de emissão</a></li>
            </ul>
            <div class="tab-content mtop20">
                <div role="tabpanel" class="tab-pane active" id="nfse-recipient-data" aria-labelledby="nfse-recipient-data-tab">
                    <?php $nfseInput('recipient[label]', 'Identificação no cadastro', '', ['maxlength' => 100, 'placeholder' => 'Ex.: dependente ou unidade atendida', 'required' => true]); ?>
                    <?php $nfseAddress('recipient', []); ?>
                </div>
                <div role="tabpanel" class="tab-pane" id="nfse-recipient-emission" aria-labelledby="nfse-recipient-emission-tab">
                    <?php
                        $nfseProfilePrefix = 'recipient';
                        $nfseProfileValues = [];
                        $nfseProfileDefaults = $nfseRecipientEmissionDefaults;
                        $nfseProfileReadonly = false;
                        require __DIR__ . '/emission_profile.php';
                    ?>
                    <p class="help-block">Os campos personalizados deste tomador têm prioridade nas próximas emissões.</p>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button> <button type="submit" class="btn btn-primary"><i class="fa fa-check" aria-hidden="true"></i> Salvar tomador</button></div>
        <?php echo form_close(); ?>
    </div></div></div>
    <?php endif; ?>
</div>
