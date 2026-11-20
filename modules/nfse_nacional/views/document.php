<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';
$document = $nfseArray($document ?? []);
$draft = $nfseArray($draft ?? []);
$provider = $nfseArray($draft['provider'] ?? []);
$recipient = $nfseArray($draft['recipient'] ?? []);
$service = $nfseArray($draft['service'] ?? []);
$values = $nfseArray($draft['values'] ?? []);
$ibscbs = $nfseArray($draft['ibscbs'] ?? []);
$events = $events ?? [];
$recipients = $recipients ?? [];
$can = $can ?? [];
foreach (['view', 'create', 'edit', 'transmit', 'send', 'substitute'] as $permission) { $can[$permission] = isset($can[$permission]) ? (bool) $can[$permission] : nfse_nacional_can($permission); }
$id = (int) ($document['id'] ?? 0);
$status = (string) ($document['status'] ?? 'draft');
$editable = $can['edit'] && in_array($status, ['draft', 'ready', 'rejected'], true);
$transmittable = $can['transmit'] && in_array($status, ['draft', 'ready', 'rejected'], true);
$authorized = $status === 'authorized';
$cancelled = $status === 'cancelled';
$issued = $authorized || $cancelled;
$taxSummary = \NfseNacional\DraftTax::summary($draft);
$issSummary = $issued ? $nfseArray($official_tax_summary ?? []) : $nfseArray($taxSummary['iss'] ?? []);
$ibptEstimate = $nfseArray($taxSummary['ibpt'] ?? []);
$ibptSnapshot = $nfseArray($ibptEstimate['snapshot'] ?? []);
$ibptError = (string) ($taxSummary['ibpt_error'] ?? '');
$ibptInputs = $nfseArray($taxSummary['inputs'] ?? []);
$fiscalStatus = $nfseArray($fiscal_status ?? []);
$danfeAvailable = isset($danfe_status) ? !empty($danfe_status['available']) : true;
$invoice_values_review_required = (bool) ($invoice_values_review_required ?? false);
$invoiceRow = $nfseArray($invoice ?? []);
$emailStatus = (string) ($document['email_status'] ?? 'pending');
$orphan_document = (bool) ($orphan_document ?? false);
$environment = (string) ($document['environment'] ?? $draft['environment'] ?? '2');
$canRecoverByKey = $can['view'] && in_array($status, ['processing', 'uncertain'], true)
    && (isset($can_recover_by_key) ? (bool) $can_recover_by_key : (!empty($document['dps_id']) && (int) ($document['attempts'] ?? 0) > 0));
$substitution = $nfseArray($draft['substitution'] ?? []);
$originalDocumentId = max(0, (int) ($original_document_id ?? $document['replacement_of_id'] ?? 0));
$replacementDocumentId = max(0, (int) ($replacement_document_id ?? 0));
$canPrepareSubstitution = $authorized && !$orphan_document && $replacementDocumentId === 0 && $can['substitute'] && $can['create'] && $can['edit'];
$substitutionReasons = ($substitution || $canPrepareSubstitution) ? \NfseNacional\Substitution::reasons() : [];
$recipientOptions = ['' => 'Manter os dados deste rascunho'];
$recipientMap = [];
foreach ($recipients as $item) {
    $item = $nfseRecipientData($item);
    $selection = (string) ($item['selection'] ?? ('recipient:' . (int) ($item['id'] ?? 0)));
    $recipientOptions[$selection] = ($item['label'] ?? $item['name'] ?? 'Tomador');
    $recipientMap[$selection] = array_intersect_key($item, array_flip(['name', 'tax_id', 'email', 'phone', 'municipality_code', 'zip', 'street', 'number', 'complement', 'district', 'service_defaults', 'emission_defaults']));
}
init_head();
?>
<div id="wrapper"><div class="content nfse-root"<?php echo $nfseCsrfAttributes(); ?>>
    <div class="row mbot20"><div class="col-sm-8"><h3 class="no-margin"><?php echo $issued ? 'NFS-e ' . $e($document['nfse_number'] ?? '') : 'Emissão de NFS-e'; ?></h3><p class="mtop10 nfse-title-line"><span class="label nfse-environment <?php echo $environment === '1' ? 'label-success nfse-production' : 'label-default nfse-test'; ?>"><?php echo $environment === '1' ? 'Produção' : 'Testes — sem validade fiscal'; ?></span> <span class="label <?php echo $e($nfseStatusClass($status)); ?> nfse-status nfse-status-<?php echo $e($status); ?>"><?php echo $e(nfse_nacional_status_label($status)); ?></span></p><p class="text-muted">Fatura #<?php echo $e($document['invoice_id'] ?? ''); ?> · <?php echo $e(is_object($client ?? null) ? ($client->company ?? '') : ($client['company'] ?? '')); ?></p></div><div class="col-sm-4 text-right"><a class="btn btn-default" href="<?php echo $e(admin_url($orphan_document ? 'nfse_nacional' : 'clients/client/' . (int) ($document['client_id'] ?? 0) . '?group=nfse_nacional')); ?>"><i class="fa fa-arrow-left" aria-hidden="true"></i> <?php echo $orphan_document ? 'Voltar às notas' : 'Voltar ao cliente'; ?></a></div></div>
    <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
    <?php if ($orphan_document): ?><div class="alert alert-info"><strong>Cadastro de origem indisponível.</strong> O documento fiscal foi preservado para recuperação pelo administrador. Novas emissões e alterações deste rascunho estão bloqueadas.</div><?php endif; ?>
    <?php if ($status === 'uncertain' || $status === 'processing'): ?><div class="alert alert-warning"><strong>Confirmação da transmissão pendente.</strong> A solicitação pode ter sido autorizada mesmo sem retorno da conexão. Consulte a transmissão para recuperar a chave e o XML oficial usando a DPS original, sem gerar nova numeração.<div class="mtop10 nfse-actions"><?php if ($can['view']): ?><button type="button" class="btn btn-warning nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/reconcile/' . $id)); ?>" data-reload="1"><i class="fa fa-refresh" aria-hidden="true"></i> Consultar transmissão</button><?php endif; ?><?php if ($status === 'uncertain' && $can['transmit']): ?><button type="button" class="btn btn-default nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/retry_same/' . $id)); ?>" data-nfse-emission="1" data-reload="1">Reenviar a mesma DPS</button><?php endif; ?></div><?php if ($status === 'uncertain' && $can['transmit']): ?><p class="help-block mtop10">O reenvio consulta o resultado antes de repetir a mesma DPS, preservando sua numeração.</p><?php endif; ?></div><?php endif; ?>
    <?php if (!empty($document['last_error']) && (string) $document['last_error'] !== (string) ($fiscalStatus['check_error'] ?? '')): ?><div class="alert alert-danger"><strong>Último retorno</strong><p class="nfse-preserve-text"><?php echo $e($document['last_error']); ?></p></div><?php endif; ?>
    <?php if ($substitution): ?><div class="alert alert-info"><h4 class="no-mtop"><?php echo $issued ? 'Documento de substituição' : 'Rascunho de substituição'; ?></h4><p class="nfse-access-key"><strong>Chave da NFS-e original:</strong><br><?php echo $e($substitution['access_key'] ?? ''); ?></p><p><strong>Motivo:</strong> <?php echo $e($substitutionReasons[(string) ($substitution['reason_code'] ?? '')] ?? 'Motivo a conferir'); ?></p><p class="nfse-preserve-text"><?php echo $e($substitution['reason'] ?? ''); ?></p><p><?php echo $issued ? 'Consulte a nota original vinculada para acompanhar o registro fiscal da substituição.' : 'Preparar este rascunho não cancela a nota original. A substituição será efetivada pelo autorizador após a autorização da nova NFS-e.'; ?></p><?php if ($can['view'] && $originalDocumentId > 0): ?><a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/document/' . $originalDocumentId)); ?>">Abrir NFS-e original</a><?php endif; ?></div>
    <?php elseif ($can['view'] && $originalDocumentId > 0): ?><div class="alert alert-info">Este documento está vinculado a uma substituição. <a href="<?php echo $e(admin_url('nfse_nacional/document/' . $originalDocumentId)); ?>">Abrir NFS-e original</a></div><?php endif; ?>
    <?php if ($can['view'] && $replacementDocumentId > 0): ?><div class="alert alert-info"><strong>Documento de substituição vinculado.</strong> Acompanhe o rascunho e sua situação fiscal no documento correspondente. <a class="btn btn-default btn-sm mtop5" href="<?php echo $e(admin_url('nfse_nacional/document/' . $replacementDocumentId)); ?>">Abrir substituição</a></div><?php endif; ?>
    <?php require __DIR__ . '/_fiscal_status.php'; ?>
    <?php if ($canPrepareSubstitution): ?><div class="panel_s"><div class="panel-body"><details><summary>Preparar substituição desta NFS-e</summary><p class="help-block mtop15">Será criado um rascunho vinculado à nota original e à mesma fatura, para revisão antes da emissão. Esta ação não transmite a nova nota nem cancela a original.</p>
        <?php echo form_open(admin_url('nfse_nacional/substitute/' . $id), ['data-nfse-form' => 'substitute', 'data-nfse-navigate' => '1', 'data-nfse-confirm' => 'Preparar um rascunho de substituição da NFS-e ' . (string) ($document['nfse_number'] ?? '') . ' no ambiente ' . ($environment === '1' ? 'Produção — validade fiscal' : 'Produção restrita (homologação) — sem validade fiscal') . '? Esta ação apenas prepara o rascunho: não transmite a nova nota nem cancela a original.']); ?>
            <div class="row"><div class="col-sm-5"><?php $nfseSelect('reason_code', 'Motivo da substituição', '', ['' => 'Selecione o motivo'] + $substitutionReasons, ['required' => true]); ?></div><div class="col-sm-7"><?php $nfseTextArea('reason', 'Justificativa da substituição', '', ['minlength' => 15, 'maxlength' => 255, 'required' => true]); ?><p class="help-block">Descreva a justificativa com 15 a 255 caracteres.</p></div></div>
            <button type="submit" class="btn btn-default">Preparar rascunho de substituição</button>
        <?php echo form_close(); ?>
    </details></div></div><?php endif; ?>
    <?php if ($authorized && in_array($emailStatus, ['sending', 'uncertain'], true)): ?><div class="alert alert-info"><strong>O envio do e-mail está em verificação.</strong> Confira os registros do servidor SMTP e o recebimento pelo destinatário antes de solicitar novo envio.<?php if ($can['send']): ?><div class="mtop10"><button type="button" class="btn btn-default nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/retry_email/' . $id)); ?>" data-nfse-confirm="Você conferiu o registro do servidor de e-mail e deseja enviar novamente os mesmos documentos? O destinatário pode receber uma cópia repetida." data-reload="1"<?php echo $danfeAvailable ? '' : ' disabled'; ?>>Reenviar após conferência</button></div><?php endif; ?></div><?php endif; ?>
    <div class="panel_s"><div class="panel-body">
        <div class="well well-sm"><div class="row"><div class="col-sm-5"><p class="text-muted">Emitente</p><p><strong><?php echo $e($provider['name'] ?? ''); ?></strong><br><small><?php echo $e($provider['tax_id'] ?? ''); ?></small></p></div><div class="col-sm-4"><p class="text-muted">Tomador</p><p><strong><?php echo $e($recipient['name'] ?? ''); ?></strong><br><small><?php echo $e($recipient['tax_id'] ?? ''); ?></small></p></div><div class="col-sm-3"><p class="text-muted">Serviços</p><p><strong><?php echo $e($nfseMoney($values['service_amount'] ?? $document['service_amount'] ?? '0.00')); ?></strong><br><small>Competência <?php echo $e($nfseDate($draft['competence'] ?? '')); ?></small></p></div></div></div>
        <?php echo form_open(admin_url('nfse_nacional/save/' . $id), ['id' => 'nfse-document-form', 'data-nfse-form' => 'document', 'data-document-id' => $id, 'data-can-save' => $editable ? '1' : '0', 'novalidate' => 'novalidate']); ?>
        <input type="hidden" name="draft_hash" value="<?php echo $e($document['draft_hash'] ?? ''); ?>">
        <?php if ($invoice_values_review_required): ?><div class="alert alert-warning"><strong>Confira os valores fiscais desta operação.</strong><p>A fatura foi alterada ou contém valores que precisam de revisão antes da emissão.</p><p>Subtotal da fatura: <?php echo $e($nfseMoney($invoiceRow['subtotal'] ?? null)); ?> · Desconto: <?php echo $e($nfseMoney($invoiceRow['discount_total'] ?? '0.00')); ?> · Total da fatura: <?php echo $e($nfseMoney($invoiceRow['total'] ?? null)); ?>.</p><?php if ($editable): ?><?php $nfseCheckbox('draft[acknowledge_invoice_values]', 'Conferi o valor dos serviços, os descontos e a tributação deste rascunho.', false, ['required' => true]); ?><?php else: ?><p>Um usuário com permissão para editar deve revisar e salvar o rascunho.</p><?php endif; ?></div><?php endif; ?>
        <ul class="nav nav-tabs" role="tablist"><li role="presentation" class="active"><a href="#nfse-doc-service" aria-controls="nfse-doc-service" role="tab" data-toggle="tab"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i> Serviço e valores</a></li><li role="presentation"><a href="#nfse-doc-recipient" aria-controls="nfse-doc-recipient" role="tab" data-toggle="tab"><i class="fa fa-user" aria-hidden="true"></i> Tomador</a></li><li role="presentation"><a href="#nfse-doc-tax" aria-controls="nfse-doc-tax" role="tab" data-toggle="tab"><i class="fa fa-percent" aria-hidden="true"></i> Tributação</a></li></ul>
        <fieldset<?php echo !$editable ? ' disabled' : ''; ?> class="mtop20">
            <?php if ($substitution): ?><fieldset class="well well-sm"<?php echo !$can['substitute'] ? ' disabled' : ''; ?>><h4 class="no-mtop">Justificativa da substituição</h4><div class="row"><div class="col-sm-5"><?php $nfseSelect('draft[substitution][reason_code]', 'Motivo', $substitution['reason_code'] ?? '', ['' => 'Selecione o motivo'] + $substitutionReasons, ['required' => true]); ?></div><div class="col-sm-7"><?php $nfseTextArea('draft[substitution][reason]', 'Justificativa', $substitution['reason'] ?? '', ['minlength' => 15, 'maxlength' => 255, 'required' => true]); ?></div></div><p class="help-block no-margin">A chave da nota original e o vínculo com a fatura são preservados. A justificativa pode ser revisada antes da transmissão.</p></fieldset><?php endif; ?>
        <div class="tab-content">
            <div class="tab-pane active" role="tabpanel" id="nfse-doc-service">
                <div class="row"><div class="col-sm-3"><?php $nfseInput('draft[competence]', 'Data da competência', $draft['competence'] ?? '', ['type' => 'date', 'required' => true, 'readonly' => (bool) $substitution]); ?></div><div class="col-sm-3"><?php $nfseInput('draft[values][service_amount]', 'Valor dos serviços (R$)', $values['service_amount'] ?? '', ['inputmode' => 'decimal', 'maxlength' => 18, 'required' => true]); ?></div><div class="col-sm-3"><?php $nfseInput('draft[values][unconditional_discount]', 'Desconto incondicionado (R$)', $values['unconditional_discount'] ?? '0.00', ['inputmode' => 'decimal', 'maxlength' => 18]); ?></div><div class="col-sm-3"><?php $nfseInput('draft[values][conditional_discount]', 'Desconto condicionado (R$)', $values['conditional_discount'] ?? '0.00', ['inputmode' => 'decimal', 'maxlength' => 18]); ?></div></div>
                <?php if ($substitution): ?><p class="help-block">A competência da nota original é preservada na substituição.</p><?php endif; ?>
                <?php if (!$issued): ?><p class="help-block">Confira o valor dos serviços e os descontos da operação. Os tributos e encargos financeiros da fatura precisam corresponder ao tratamento fiscal informado.</p><?php endif; ?>
                <section class="well well-sm" data-nfse-iss-summary data-nfse-iss-official="<?php echo $issued ? '1' : '0'; ?>" aria-labelledby="nfse-iss-summary-title">
                    <h4 class="no-mtop" id="nfse-iss-summary-title"><?php echo $issued ? 'ISSQN apurado' : 'ISSQN calculado — prévia'; ?></h4>
                    <div class="row">
                        <div class="col-sm-3"><p class="text-muted">Base de cálculo</p><p><strong data-nfse-iss-base><?php echo $e($nfseMoney($issSummary['base'] ?? null)); ?></strong></p></div>
                        <div class="col-sm-3"><p class="text-muted"><?php echo $issued ? 'Alíquota aplicada' : 'Alíquota informada'; ?></p><p><strong data-nfse-iss-rate><?php echo isset($issSummary['rate']) && $issSummary['rate'] !== '' ? $e(str_replace('.', ',', (string) $issSummary['rate'])) . '%' : '—'; ?></strong></p></div>
                        <div class="col-sm-3"><p class="text-muted">Valor do ISSQN</p><p><strong data-nfse-iss-amount><?php echo $e($nfseMoney($issSummary['amount'] ?? null)); ?></strong></p></div>
                        <div class="col-sm-3"><p class="text-muted">Retenção</p><p><strong data-nfse-iss-retention><?php echo $e(['1' => 'Não retido', '2' => 'Retido pelo tomador', '3' => 'Retido pelo intermediário'][(string) ($issued ? ($issSummary['retention'] ?? '') : ($values['retention'] ?? ''))] ?? '—'); ?></strong></p></div>
                    </div>
                    <p class="help-block no-margin" data-nfse-iss-note><?php echo $issued ? 'Valores oficiais do XML autorizado no ambiente nacional.' : 'A prévia usa a alíquota informada na guia Tributação, sobre serviços menos desconto incondicionado. O valor autorizado depende do regime e da apuração municipal.'; ?></p>
                </section>
                <?php $nfseServiceFields('draft[service]', $service); ?>
                <?php if (!empty($document['asaas_payment_id'])): ?><p class="help-block">Cobrança Asaas vinculada: <?php echo $e($document['asaas_payment_id']); ?></p><?php endif; ?>
            </div>
            <div class="tab-pane" role="tabpanel" id="nfse-doc-recipient">
                <?php if ($editable && !$substitution && count($recipientOptions) > 1): ?><?php $nfseSelect('recipient_selection', 'Selecionar o tomador desta NFS-e', '', $recipientOptions, ['data-nfse-draft-recipient' => true]); ?><div data-nfse-recipients="<?php echo $e(json_encode($recipientMap, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?>" hidden></div><p class="help-block">A seleção preenche o cadastro, o serviço e o perfil de tributação do tomador. Revise os campos antes de salvar.</p><?php endif; ?>
                <?php if ($substitution): ?><?php require __DIR__ . '/_substitution_recipient.php'; ?><?php else: ?><?php $nfseAddress('draft[recipient]', $recipient); ?><?php endif; ?>
            </div>
            <div class="tab-pane" role="tabpanel" id="nfse-doc-tax"><h4 class="nfse-section-title">ISSQN e tributos aproximados</h4><?php foreach (['simple_rate_error', 'iss_rate_error'] as $rateError): ?><?php if (!empty($draft[$rateError])): ?><p class="text-danger" role="status"><?php echo $e($draft[$rateError]); ?></p><?php endif; ?><?php endforeach; ?><?php if (isset($draft['simple_rate_snapshot'])): ?><p class="help-block">Alíquota cheia do Simples por competência: <?php echo $e($draft['simple_rate_snapshot']['competence'] ?? ''); ?> · <?php echo $e($draft['simple_rate_snapshot']['rate'] ?? ''); ?>%. O cadastro do mês é conferido antes de emitir.</p><?php endif; ?><?php if (isset($draft['iss_rate_snapshot'])): ?><p class="help-block">ISSQN por competência: <?php echo $e($draft['iss_rate_snapshot']['competence'] ?? ''); ?> · <?php echo $e($draft['iss_rate_snapshot']['rate'] ?? ''); ?>% (<?php echo ($draft['iss_rate_snapshot']['source'] ?? 'iss') === 'simple' ? 'percentual compartilhado do Simples' : 'cadastro separado'; ?>).</p><?php endif; ?>
<?php if (!empty($draft['simple_rate_error'])): ?><p class="text-warning"><?php echo $e($draft['simple_rate_error']); ?></p><?php endif; ?>
<?php $nfseTaxFields('draft[values]', $values); ?>
                <section class="mtop15" data-nfse-ibpt-summary data-nfse-ibpt-inputs="<?php echo $e(json_encode($ibptInputs, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?>" aria-labelledby="nfse-ibpt-summary-title"<?php echo ($values['total_tax_mode'] ?? '') === 'ibpt' ? '' : ' hidden'; ?>>
                    <h4 class="nfse-section-title" id="nfse-ibpt-summary-title">Tributos aproximados — IBPT</h4>
                    <p class="alert alert-warning" data-nfse-ibpt-pending<?php echo $ibptEstimate ? ' hidden' : ''; ?>><?php echo $e($ibptError !== '' ? $ibptError : 'Informe o NBS do serviço e salve o rascunho para obter a estimativa.'); ?></p>
                    <div data-nfse-ibpt-calculated<?php echo $ibptEstimate ? '' : ' hidden'; ?>>
                        <p><strong data-nfse-ibpt-field="source">Fonte: IBPT [<?php echo $e($ibptSnapshot['chave'] ?? ''); ?>]</strong> · UF <span data-nfse-ibpt-field="uf"><?php echo $e($ibptSnapshot['uf'] ?? ''); ?></span> · NBS <span data-nfse-ibpt-field="nbs"><?php echo $e($ibptSnapshot['nbs'] ?? ''); ?></span></p>
                        <p class="help-block">Vigência: <span data-nfse-ibpt-field="validity"><?php echo $e($nfseDate($ibptSnapshot['valid_from'] ?? '') . ' a ' . $nfseDate($ibptSnapshot['valid_to'] ?? '')); ?></span> · Versão <span data-nfse-ibpt-field="version"><?php echo $e($ibptSnapshot['versao'] ?? ''); ?></span> · Base: <span data-nfse-ibpt-field="base"><?php echo $e($nfseMoney($ibptEstimate['base'] ?? null)); ?></span></p>
                        <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Tributos</th><th class="text-right">Percentual aproximado</th><th class="text-right">Valor aproximado</th></tr></thead><tbody>
                        <?php foreach (['federal' => ['Federais', 'nacionalfederal'], 'state' => ['Estaduais', 'estadual'], 'municipal' => ['Municipais', 'municipal']] as $taxGroup => $taxColumn): ?>
                            <tr><td><?php echo $e($taxColumn[0]); ?></td><td class="text-right" data-nfse-ibpt-field="<?php echo $e($taxGroup); ?>_rate"><?php echo $e(str_replace('.', ',', (string) ($ibptSnapshot[$taxColumn[1]] ?? ''))) . '%'; ?></td><td class="text-right" data-nfse-ibpt-field="<?php echo $e($taxGroup); ?>_amount"><?php echo $e($nfseMoney($ibptEstimate['total_' . $taxGroup] ?? null)); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody><tfoot><tr><th colspan="2">Total aproximado</th><th class="text-right" data-nfse-ibpt-field="total"><?php echo $e($nfseMoney($ibptEstimate['total'] ?? null)); ?></th></tr></tfoot></table></div>
                        <p class="text-muted">Informação complementar da nota</p><p class="nfse-preserve-text" data-nfse-ibpt-field="text"><?php echo $e($ibptEstimate['text'] ?? ''); ?></p>
                    </div>
                    <p class="help-block">A estimativa informa a carga tributária aproximada. O ISSQN da operação é calculado separadamente conforme sua alíquota.</p>
                </section>
                <hr><h4 class="nfse-section-title">IBS e CBS</h4><?php $nfseIbsFields('draft[ibscbs]', $ibscbs); ?></div>
        </div></fieldset>
        <?php if (!$issued): ?><div class="well well-sm nfse-emission-preferences">
            <?php if ($editable && $can['send']): ?><?php $nfseCheckbox('send_after_authorized', 'Enviar XML e DANFSe ao e-mail do tomador após a autorização', !empty($document['send_after_authorized'])); ?><?php else: ?><p class="text-muted">Envio após autorização: <?php echo !empty($document['send_after_authorized']) ? 'habilitado' : 'desabilitado'; ?></p><?php endif; ?>
            <p class="help-block">O envio ocorre após a autorização. O rascunho não é enviado ao destinatário.</p>
        </div><?php endif; ?>
        <?php if ($editable || $transmittable): ?><div class="mtop20 nfse-actions nfse-document-actions">
            <?php if ($editable): ?><button type="submit" class="btn btn-default"><i class="fa fa-save" aria-hidden="true"></i> Salvar rascunho</button><?php endif; ?><?php if ($transmittable): ?><button type="button" class="btn btn-default" data-nfse-document-action="validate" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/validate/' . $id)); ?>"><i class="fa fa-check" aria-hidden="true"></i> Validar dados</button><?php endif; ?>
            <?php if ($transmittable): ?><button type="button" class="btn <?php echo $environment === '1' ? 'btn-success' : 'btn-primary'; ?>" data-nfse-document-action="transmit" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/transmit/' . $id)); ?>"><i class="fa fa-paper-plane" aria-hidden="true"></i> Emitir NFS-e</button><?php endif; ?>
            <?php if ($editable && empty($document['dps_id']) && (int) ($document['attempts'] ?? 0) === 0): ?><button type="button" class="btn btn-link text-danger nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/delete_draft/' . $id)); ?>" data-nfse-navigate="1" data-nfse-confirm="Excluir este rascunho e seu histórico local? Nenhuma DPS foi transmitida. A fatura será preservada.">Excluir rascunho</button><?php endif; ?>
            <p class="help-block">A emissão valida, assina e transmite a DPS.</p>
        </div><?php endif; ?>
        <?php echo form_close(); ?>
    </div></div>
    <?php if ($events): ?><div class="panel_s"><div class="panel-body"><details class="nfse-history"><summary>Histórico do documento <span class="badge"><?php echo count($events); ?></span></summary><div class="table-responsive mtop15"><table class="table table-striped"><thead><tr><th>Data</th><th>Ocorrência</th></tr></thead><tbody><?php foreach ($events as $event): $event = $nfseArray($event); ?><tr><td><time><?php echo $e($nfseDate($event['created_at'] ?? '', true)); ?></time></td><td class="nfse-preserve-text"><?php echo $e($event['message'] ?? $event['event_type'] ?? ''); ?></td></tr><?php endforeach; ?></tbody></table></div></details></div></div><?php endif; ?>
</div></div>
<?php init_tail(); ?>
</body></html>
