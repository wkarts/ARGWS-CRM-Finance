<?php
defined('BASEPATH') or exit('No direct script access allowed');
if (!nfse_nacional_can('national_view')) { return; }
require __DIR__ . '/_ui_helpers.php';
$document_tab = 'accounting';
$filters = $nfseArray($filters ?? []);
$recipients = array_values($nfseArray($recipients ?? []));
$history = $nfseArray($history ?? []);
$hasReport = is_array($report ?? null);
$report = $nfseArray($report ?? []);
$canSend = !empty($can_send) && nfse_nacional_can('accounting_send');
$environment = in_array((string) ($filters['environment'] ?? ''), ['1', '2'], true) ? (string) $filters['environment'] : '2';
$month = isset($filters['month']) && is_string($filters['month']) && preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $filters['month']) ? $filters['month'] : '';
$direction = in_array((string) ($filters['direction'] ?? ''), ['issued', 'received', 'both'], true) ? (string) $filters['direction'] : 'both';
$format = in_array((string) ($filters['format'] ?? ''), ['xml', 'pdf', 'both'], true) ? (string) $filters['format'] : 'both';
$filters['environment'] = $environment;
$environmentLabel = $environment === '1' ? 'Produção' : 'Produção restrita (homologação)';
$directionLabels = ['both' => 'Emitidas e recebidas', 'issued' => 'Somente emitidas', 'received' => 'Somente recebidas'];
$formatLabels = ['both' => 'XML e DANFSe PDF', 'xml' => 'Somente XML', 'pdf' => 'Somente DANFSe PDF'];
$fingerprint = is_string($report['fingerprint'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $report['fingerprint']) ? $report['fingerprint'] : '';
$total = max(0, (int) ($report['total'] ?? 0));
$packageReady = $hasReport && $total > 0 && $month !== '' && $fingerprint !== '';
$selectedFilters = ['environment' => $environment, 'month' => $month, 'direction' => $direction, 'format' => $format, 'fingerprint' => $fingerprint];
$hiddenPackageFields = static function () use ($selectedFilters, $e): void {
    foreach ($selectedFilters as $name => $value) { echo '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">'; }
};
$monthLabel = static function ($value): string {
    return is_scalar($value) && preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', (string) $value, $match) ? $match[2] . '/' . $match[1] : '—';
};
$historyDate = static function ($value) use ($nfseDate): string {
    if (!is_scalar($value) || trim((string) $value) === '') { return '—'; }
    try { $value = (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'); }
    catch (Throwable $error) { return '—'; }
    return $nfseDate($value, true);
};
$historyLabels = ['pending' => 'Pendente', 'sending' => 'Enviando', 'sent' => 'Aceito pelo servidor de e-mail', 'failed' => 'Falha no envio', 'uncertain' => 'Envio em verificação'];
$historyClasses = ['pending' => 'label-default', 'sending' => 'label-info', 'sent' => 'label-success', 'failed' => 'label-danger', 'uncertain' => 'label-warning'];
$confirmation = 'Enviar os arquivos fiscais da competência ' . $monthLabel($month) . ', ambiente ' . $environmentLabel . ', aos destinatários selecionados?';
init_head();
?>
<div id="wrapper"><div class="content nfse-root"<?php echo $nfseCsrfAttributes(); ?>>
<div class="row"><div class="col-md-12">
    <h4 class="no-mtop">Documentos</h4>
    <p class="text-muted mbot20">Prepare o pacote mensal de NFS-e para a contabilidade.</p>
    <?php require __DIR__ . '/_document_tabs.php'; ?>
    <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
    <?php if (!empty($page_error)): ?><div class="alert alert-warning"><?php echo $e($page_error); ?></div><?php endif; ?>
    <div class="panel_s"><div class="panel-body">
        <h4 class="no-mtop">Selecionar documentos</h4>
        <form action="<?php echo $e(admin_url('nfse_nacional/accounting')); ?>" method="get" aria-label="Seleção do pacote mensal">
            <div class="row">
                <div class="col-sm-6 col-md-3"><?php $nfseInput('month', 'Competência', $month, ['type' => 'month', 'required' => true]); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseSelect('environment', 'Ambiente', $environment, ['2' => 'Produção restrita (homologação)', '1' => 'Produção']); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseSelect('direction', 'Documentos', $direction, $directionLabels); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseSelect('format', 'Arquivos do pacote', $format, $formatLabels); ?></div>
            </div>
            <div class="text-right"><button type="submit" class="btn btn-primary"><i class="fa fa-search" aria-hidden="true"></i> Conferir documentos</button></div>
        </form>
        <p class="help-block mtop15 no-mbot">A competência seleciona os documentos do pacote e não bloqueia lançamentos. Rascunhos não entram no pacote.</p>
        <p class="help-block no-mbot">Sincronize as notas nacionais antes de preparar o pacote para incluir os documentos disponíveis. <a href="<?php echo $e(admin_url('nfse_nacional/national/issued') . '?environment=' . $environment); ?>">Consultar sincronização</a>.</p>
    </div></div>

    <?php if ($hasReport): ?>
    <div class="panel_s"><div class="panel-body">
        <h4 class="no-mtop">Conferência do pacote — <?php echo $e($monthLabel($month)); ?></h4>
        <p class="text-muted"><?php echo $e($environmentLabel); ?> · <?php echo $e($directionLabels[$direction]); ?> · <?php echo $e($formatLabels[$format]); ?></p>
        <div class="row">
            <?php foreach (['total' => 'Documentos no pacote', 'issued' => 'Emitidas', 'received' => 'Recebidas', 'cancelled' => 'Canceladas'] as $summaryKey => $summaryLabel): ?>
            <div class="col-xs-6 col-sm-3"><dl class="mbot15"><dt class="text-muted"><?php echo $e($summaryLabel); ?></dt><dd><strong><?php echo max(0, (int) ($report[$summaryKey] ?? 0)); ?></strong></dd></dl></div>
            <?php endforeach; ?>
        </div>
        <p class="text-muted small">Documentos do CRM: <?php echo max(0, (int) ($report['local'] ?? 0)); ?> · Documentos do ambiente nacional: <?php echo max(0, (int) ($report['national'] ?? 0)); ?></p>
        <?php if ((int) ($report['missing_competence'] ?? 0) > 0): ?><div class="alert alert-warning" role="status"><?php echo max(0, (int) $report['missing_competence']); ?> <?php echo (int) $report['missing_competence'] === 1 ? 'documento sem competência registrada não foi incluído' : 'documentos sem competência registrada não foram incluídos'; ?>. Confira os documentos antes de encaminhar à contabilidade.</div><?php endif; ?>
        <?php if ($total === 0): ?><p class="text-muted">Nenhum documento encontrado no histórico disponível para esta competência e estes filtros.</p><?php elseif (!$packageReady): ?><div class="alert alert-warning" role="status">Confira novamente os documentos para preparar o pacote.</div><?php endif; ?>
        <?php echo form_open(admin_url('nfse_nacional/accounting_download'), ['class' => 'nfse-accounting-download', 'aria-label' => 'Baixar pacote mensal']); ?>
            <?php $hiddenPackageFields(); ?>
            <button type="submit" class="btn btn-default"<?php echo $packageReady && nfse_nacional_can('report_export') ? '' : ' disabled aria-disabled="true"'; ?>><i class="fa fa-download" aria-hidden="true"></i> Baixar pacote</button>
        <?php echo form_close(); ?>

        <?php if ($canSend): ?>
        <hr>
        <h4 class="no-mtop">Enviar à contabilidade</h4>
        <?php if (!$recipients): ?>
        <p class="text-muted">Cadastre os destinatários da contabilidade nas configurações da NFS-e para habilitar o envio.<?php if (nfse_nacional_can('settings')): ?> <a href="<?php echo $e(nfse_nacional_settings_url()); ?>">Abrir configurações da NFS-e</a>.<?php endif; ?></p>
        <?php else: ?>
        <?php echo form_open(admin_url('nfse_nacional/accounting_send'), ['data-nfse-form' => 'accounting-send', 'data-reload' => '1', 'data-confirm' => $confirmation, 'aria-label' => 'Enviar pacote mensal à contabilidade']); ?>
            <?php $hiddenPackageFields(); ?>
            <fieldset>
                <legend class="h5">Selecione os destinatários</legend>
                <div class="row">
                <?php foreach ($recipients as $recipientIndex => $recipient):
                    $recipient = $nfseArray($recipient);
                    $recipientId = isset($recipient['id']) && is_scalar($recipient['id']) ? (string) $recipient['id'] : '';
                    if ($recipientId === '') { continue; }
                    ?>
                    <div class="col-sm-6"><div class="checkbox checkbox-primary">
                        <input type="checkbox" id="nfse-accounting-recipient-<?php echo $recipientIndex; ?>" name="recipient_ids[]" value="<?php echo $e($recipientId); ?>"<?php echo $packageReady ? '' : ' disabled'; ?>>
                        <label for="nfse-accounting-recipient-<?php echo $recipientIndex; ?>"><?php echo $e($recipient['name'] ?? ''); ?><br><span class="text-muted small"><?php echo $e($recipient['email'] ?? ''); ?></span></label>
                    </div></div>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <p class="help-block">O pacote será enviado aos destinatários selecionados acima. Confira a competência, o ambiente e os arquivos antes de confirmar.</p>
            <button type="submit" class="btn btn-primary"<?php echo $packageReady && nfse_nacional_can('report_export') ? '' : ' disabled aria-disabled="true"'; ?>><i class="fa fa-envelope" aria-hidden="true"></i> Enviar pacote</button>
        <?php echo form_close(); ?>
        <?php endif; ?>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="panel_s"><div class="panel-body">
        <h4 class="no-mtop">Histórico de envio</h4>
        <?php if (!$history): ?><p class="text-muted no-mbot">Nenhum envio registrado para esta consulta.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-hover" aria-label="Histórico de envio à contabilidade">
            <thead><tr><th>Competência</th><th>Documentos / arquivos</th><th>Destinatários</th><th>Situação</th><th>Data e hora</th></tr></thead>
            <tbody>
            <?php foreach ($history as $historyItem):
                $historyItem = $nfseArray($historyItem);
                $historyStatus = (string) ($historyItem['status'] ?? '');
                ?>
                <tr>
                    <td><?php echo $e($monthLabel($historyItem['month'] ?? '')); ?></td>
                    <td><?php echo $e($directionLabels[$historyItem['direction'] ?? ''] ?? '—'); ?><div class="small text-muted mtop5"><?php echo $e($formatLabels[$historyItem['format'] ?? ''] ?? '—'); ?></div></td>
                    <td style="max-width:320px;overflow-wrap:anywhere"><?php echo $e($historyItem['recipient_label'] ?? ''); ?></td>
                    <td><span class="label <?php echo $e($historyClasses[$historyStatus] ?? 'label-default'); ?>"><?php echo $e($historyLabels[$historyStatus] ?? 'Situação a conferir'); ?></span></td>
                    <td class="text-nowrap"><?php echo $e($historyDate($historyItem['sent_at'] ?? $historyItem['created_at'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="help-block no-mbot">A aceitação pelo servidor de e-mail registra o envio, mas não confirma a entrega na caixa do destinatário.</p>
        <?php endif; ?>
    </div></div>
</div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
