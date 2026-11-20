<?php
defined('BASEPATH') or exit('No direct script access allowed');

$hasOfficialXml = $issued && (!array_key_exists('has_official_xml', $fiscalStatus) || !empty($fiscalStatus['has_official_xml']));
$fiscalLabel = (string) ($fiscalStatus['label'] ?? nfse_nacional_status_label($status));
$transmissionLabel = (string) ($fiscalStatus['transmission_label'] ?? ($issued ? ($cancelled ? 'Cancelada' : 'Autorizada') : ((int) ($document['attempts'] ?? 0) > 0 ? 'Enviada, sem autorização confirmada' : 'Não enviada')));
$fiscalExplanation = (string) ($fiscalStatus['explanation'] ?? ($issued ? 'O documento foi recebido do ambiente nacional.' : 'A prévia não comprova autorização fiscal.'));
$processedAt = (string) ($fiscalStatus['processed_at'] ?? '');
$checkedAt = (string) ($fiscalStatus['checked_at'] ?? '');
$checkAttemptedAt = (string) ($fiscalStatus['last_check_attempt_at'] ?? '');
$checkError = (string) ($fiscalStatus['check_error'] ?? '');
$protocol = (string) ($fiscalStatus['protocol'] ?? '');
$dpsId = (string) ($fiscalStatus['dps_id'] ?? $document['dps_id'] ?? '');
?>
<section class="panel_s" aria-labelledby="nfse-fiscal-status-title">
    <div class="panel-body">
        <div class="row">
            <div class="col-md-7">
                <h4 class="no-mtop" id="nfse-fiscal-status-title">Situação fiscal e DANFSe</h4>
                <p><strong data-nfse-fiscal-label><?php echo $e($fiscalLabel); ?></strong></p>
                <p>Transmissão ao ambiente nacional: <strong data-nfse-transmission-label><?php echo $e($transmissionLabel); ?></strong></p>
                <p class="text-muted" data-nfse-fiscal-explanation><?php echo $e($fiscalExplanation); ?></p>
                <?php if (!empty($fiscalStatus['last_submitted_at'])): ?><p class="text-muted"><strong>Última tentativa de transmissão:</strong> <?php echo $e($nfseDate($fiscalStatus['last_submitted_at'], true)); ?></p><?php endif; ?>
                <?php if ($environment === '2'): ?><p class="text-warning">Ambiente de testes. Este documento não possui validade fiscal.</p><?php endif; ?>
                <?php if ($issued): ?>
                    <p class="nfse-access-key"><strong>Chave de acesso da NFS-e</strong><br><?php echo $e($fiscalStatus['access_key'] ?? $document['access_key'] ?? ''); ?></p>
                    <?php if ($processedAt !== ''): ?><p><strong>Processamento no autorizador:</strong> <?php echo $e($nfseDate($processedAt, true)); ?></p><?php endif; ?>
                    <p class="nfse-access-key"><strong>Protocolo de transmissão:</strong> <?php echo $e($protocol !== '' ? $protocol : 'Não informado pelo autorizador.'); ?></p>
                <?php endif; ?>
                <?php if ($cancelled): ?>
                    <?php if (!empty($fiscalStatus['cancelled_at'])): ?><p><strong>Cancelamento registrado:</strong> <?php echo $e($nfseDate($fiscalStatus['cancelled_at'], true)); ?></p><?php endif; ?>
                    <?php if (!empty($fiscalStatus['cancellation_event_id'])): ?><p class="nfse-access-key"><strong>Evento de cancelamento:</strong><br><?php echo $e($fiscalStatus['cancellation_event_id']); ?></p><?php endif; ?>
                    <?php if (!empty($fiscalStatus['cancellation_record_number'])): ?><p><strong>Registro do evento:</strong> <?php echo $e($fiscalStatus['cancellation_record_number']); ?></p><?php endif; ?>
                    <?php if (!empty($fiscalStatus['cancellation_protocol'])): ?><p class="nfse-access-key"><strong>Protocolo de cancelamento:</strong> <?php echo $e($fiscalStatus['cancellation_protocol']); ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($checkedAt !== ''): ?><p class="text-muted"><strong>Última consulta fiscal:</strong> <?php echo $e($nfseDate($checkedAt, true)); ?></p><?php endif; ?>
                <?php if ($checkAttemptedAt !== '' && ($checkAttemptedAt !== $checkedAt || $checkError !== '')): ?><p class="text-muted"><strong>Última tentativa de consulta:</strong> <?php echo $e($nfseDate($checkAttemptedAt, true)); ?></p><?php endif; ?>
                <?php if ($checkError !== ''): ?><p class="text-warning nfse-preserve-text"><strong>Consulta não concluída:</strong> <?php echo $e($checkError); ?></p><?php endif; ?>
                <?php if ($dpsId !== ''): ?><details class="mtop10"><summary>Identificação da DPS</summary><p class="nfse-access-key mtop10"><?php echo $e($dpsId); ?></p><p class="help-block">A identificação da DPS não é um protocolo de autorização.</p></details><?php endif; ?>
            </div>
            <div class="col-md-5">
                <div class="nfse-actions">
                    <?php if ($danfeAvailable): ?>
                        <a class="btn btn-default" href="<?php echo $e(admin_url('nfse_nacional/preview/' . $id)); ?>" target="_blank" rel="noopener noreferrer" data-nfse-preview><i class="fa fa-eye" aria-hidden="true"></i> Pré-visualizar DANFSe</a>
                        <a class="btn btn-default" href="<?php echo $e(admin_url('nfse_nacional/download/' . $id . '/pdf')); ?>"><i class="fa fa-download" aria-hidden="true"></i> Baixar DANFSe</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-default" disabled><i class="fa fa-eye" aria-hidden="true"></i> DANFSe indisponível</button>
                    <?php endif; ?>
                    <?php if ($hasOfficialXml): ?><a class="btn btn-default" href="<?php echo $e(admin_url('nfse_nacional/download/' . $id . '/xml')); ?>"><i class="fa fa-code" aria-hidden="true"></i> XML</a><?php endif; ?>
                    <?php if ($issued && $can['view']): ?><button type="button" class="btn btn-default nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/reconcile/' . $id)); ?>" data-reload="1"><i class="fa fa-refresh" aria-hidden="true"></i> Atualizar situação</button><?php endif; ?>
                    <?php if ($issued && $can['view']): ?><a class="btn btn-default" href="<?php echo $e(admin_url('nfse_nacional/fiscal_events/local/' . $id)); ?>">Eventos fiscais</a><?php endif; ?>
                    <?php if ($issued && !empty($consultation_url)): ?><a class="btn btn-default" href="<?php echo $e($consultation_url); ?>" target="_blank" rel="noopener noreferrer">Consulta oficial <i class="fa fa-external-link" aria-hidden="true"></i></a><?php endif; ?>
                    <?php if ($authorized && $can['send'] && !in_array($emailStatus, ['sent', 'sending', 'uncertain'], true)): ?><button type="button" class="btn btn-primary nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/send/' . $id)); ?>" data-reload="1"<?php echo $danfeAvailable ? '' : ' disabled'; ?>><i class="fa fa-envelope" aria-hidden="true"></i> Enviar ao destinatário</button><?php endif; ?>
                </div>
                <?php if ($editable): ?><p class="help-block mtop10">Prévia dos dados salvos. Salve as alterações para atualizar o PDF.</p><?php endif; ?>
                <?php if (!$issued): ?><p class="help-block">O DANFSe exibirá o carimbo da situação atual. Visualizar ou baixar a prévia não transmite a NFS-e.</p><?php endif; ?>
                <?php if (!empty($canRecoverByKey)): ?>
                <details class="mtop15">
                    <summary>Já tenho a chave de acesso</summary>
                    <?php echo form_open(admin_url('nfse_nacional/recover_key/' . $id), ['class' => 'mtop15', 'data-nfse-form' => 'recover-key', 'data-reload' => '1']); ?>
                        <?php $nfseInput('access_key', 'Chave de acesso da NFS-e', '', ['maxlength' => 50, 'minlength' => 50, 'pattern' => '[A-Za-z0-9]{50}', 'required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false']); ?>
                        <p class="help-block">A chave será consultada no ambiente desta emissão. O XML somente será vinculado se corresponder à DPS original e a todos os dados enviados.</p>
                        <button type="submit" class="btn btn-default"><i class="fa fa-search" aria-hidden="true"></i> Recuperar autorização</button>
                    <?php echo form_close(); ?>
                </details>
                <?php endif; ?>
                <?php if ($cancelled): ?><p class="help-block">O DANFSe apresenta o carimbo de cancelamento. O XML original é preservado para consulta.</p><?php endif; ?>
                <?php if ($authorized): ?>
                    <?php if ($emailStatus === 'sent'): ?><p class="text-success"><i class="fa fa-envelope" aria-hidden="true"></i> E-mail enviado<?php echo !empty($document['email_sent_at']) ? ' em ' . $e($nfseDate($document['email_sent_at'], true)) : ''; ?>.</p><?php endif; ?>
                    <p class="help-block mtop10">Destinatário: <?php echo $e($document['recipient_email'] ?? $recipient['email'] ?? 'Não informado'); ?>.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$danfeAvailable): ?><div class="alert alert-warning mtop15 mbot0"><strong>DANFSe indisponível.</strong> <?php echo $e($danfe_status['message'] ?? 'Não foi possível carregar os recursos do DANFSe.'); ?></div><?php endif; ?>
    </div>
</section>
