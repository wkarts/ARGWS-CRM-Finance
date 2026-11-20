<?php
defined('BASEPATH') or exit('No direct script access allowed');
if (!nfse_nacional_can('view')) { return; }
require __DIR__ . '/_ui_helpers.php';
$fiscalAction = $nfseArray($fiscal_action ?? []);
$origin = (string) ($fiscalAction['origin'] ?? '');
$documentId = (int) ($fiscalAction['id'] ?? 0);
if (!in_array($origin, ['local', 'national'], true) || $documentId < 1) { return; }
$environment = (string) ($fiscalAction['environment'] ?? '2');
$environmentLabel = $environment === '1' ? 'Produção — validade fiscal' : 'Produção restrita (homologação) — sem validade fiscal';
$number = (string) ($fiscalAction['nfse_number'] ?? '');
$noteStatus = (string) ($fiscalAction['status'] ?? '');
$actions = $nfseArray($fiscalAction['actions'] ?? []);
$history = $nfseArray($fiscalAction['history'] ?? []);
$nationalDirection = 'issued';
foreach ($actions as $candidate) {
    $candidate = $nfseArray($candidate);
    if (($candidate['role'] ?? '') === 'toma') { $nationalDirection = 'received'; break; }
}
$backUrl = admin_url($origin === 'local' ? 'nfse_nacional/document/' . $documentId
    : 'nfse_nacional/national/' . $nationalDirection) . ($origin === 'national' ? '?environment=' . ($environment === '1' ? '1' : '2') : '');
$eventLabels = ['registered' => 'Registro confirmado', 'processing' => 'Confirmação pendente', 'uncertain' => 'Confirmação pendente', 'rejected' => 'Pedido rejeitado'];
$eventClasses = ['registered' => 'label-success', 'processing' => 'label-warning', 'uncertain' => 'label-warning', 'rejected' => 'label-danger'];
$descriptions = [
    '101101' => 'A situação da nota será atualizada somente após a confirmação do cancelamento pelo ambiente nacional.',
    '101103' => 'O pedido será analisado pelo município. Registrar a solicitação não confirma o cancelamento da nota.',
    '203202' => 'Registra a confirmação, pelo tomador, da NFS-e recebida.',
    '203206' => 'Registra a rejeição pelo tomador e o motivo informado. Essa manifestação não cancela a nota.',
];
init_head();
?>
<div id="wrapper"><div class="content nfse-root"<?php echo $nfseCsrfAttributes(); ?>>
    <div class="row mbot20">
        <div class="col-sm-8"><h3 class="no-margin">Eventos fiscais</h3><p class="text-muted mtop10">Consulte e acompanhe os pedidos relacionados à NFS-e <?php echo $e($number); ?>.</p></div>
        <div class="col-sm-4 text-right"><a class="btn btn-default" href="<?php echo $e($backUrl); ?>"><i class="fa fa-arrow-left" aria-hidden="true"></i> <?php echo $origin === 'local' ? 'Voltar à NFS-e' : 'Voltar aos documentos'; ?></a></div>
    </div>
    <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
    <div class="panel_s"><div class="panel-body">
        <h4 class="no-mtop">NFS-e <?php echo $e($number); ?></h4>
        <p><span class="label <?php echo $environment === '1' ? 'label-success' : 'label-default'; ?>"><?php echo $e($environmentLabel); ?></span> <span class="label <?php echo $e($nfseStatusClass($noteStatus)); ?>"><?php echo $e(nfse_nacional_status_label($noteStatus)); ?></span></p>
        <p class="nfse-access-key"><strong>Chave de acesso</strong><br><?php echo $e($fiscalAction['access_key'] ?? ''); ?></p>
        <p class="text-muted no-margin">Pedidos de análise fiscal e manifestações do tomador não equivalem ao cancelamento da NFS-e. A situação muda somente quando o respectivo evento fiscal é confirmado.</p>
        <?php if ($noteStatus === 'cancelled'): ?><p class="text-danger mtop10 no-mbot">Esta NFS-e está cancelada. Os eventos registrados continuam disponíveis para consulta e download.</p><?php endif; ?>
    </div></div>

    <?php if (!$actions): ?>
        <div class="panel_s"><div class="panel-body"><p class="text-muted no-margin">Nenhum evento está disponível para o papel desta empresa nesta NFS-e.</p></div></div>
    <?php else: ?>
        <?php foreach ($actions as $type => $action):
            $type = (string) $type;
            if (!preg_match('/^[0-9]{6}$/D', $type)) { continue; }
            $action = $nfseArray($action);
            $label = (string) ($action['label'] ?? 'Evento fiscal');
            $requestStatus = (string) ($action['request_status'] ?? '');
            $pending = in_array($requestStatus, ['processing', 'uncertain'], true);
            $registered = $requestStatus === 'registered';
            $permission = (string) ($action['permission'] ?? '');
            $canWrite = $permission !== '' && nfse_nacional_can($permission);
            $canSubmit = !empty($action['can_submit']) && $canWrite && $noteStatus === 'authorized' && !$pending && !$registered;
            $canRetry = !empty($action['can_retry']) && $canWrite && $pending && $noteStatus === 'authorized';
            $reasons = $nfseArray($action['reasons'] ?? []);
            $reasonRequired = !empty($action['reason_required']);
            $baseRoute = $origin . '/' . $documentId;
            $confirmation = $label . ' para a NFS-e ' . $number . ' no ambiente ' . $environmentLabel . '? O pedido será enviado ao ambiente nacional.';
            if ($type === '101103') { $confirmation .= ' A solicitação depende da análise do município e não confirma o cancelamento.'; }
            elseif (in_array($type, ['203202', '203206'], true)) { $confirmation .= ' A manifestação não cancela a nota.'; }
            ?>
            <section class="panel_s" aria-labelledby="nfse-event-title-<?php echo $e($type); ?>"><div class="panel-body">
                <div class="row">
                    <div class="col-sm-8"><h4 class="no-mtop" id="nfse-event-title-<?php echo $e($type); ?>"><?php echo $e($label); ?></h4><p class="text-muted"><?php echo $e($descriptions[$type] ?? 'Consulte o registro deste evento no ambiente nacional.'); ?></p></div>
                    <div class="col-sm-4 text-right"><?php if ($requestStatus !== ''): ?><span class="label <?php echo $e($eventClasses[$requestStatus] ?? 'label-default'); ?>"><?php echo $e($eventLabels[$requestStatus] ?? 'Situação a conferir'); ?></span><?php endif; ?></div>
                </div>
                <?php if ($canSubmit): ?>
                    <?php echo form_open(admin_url('nfse_nacional/fiscal_event_submit/' . $baseRoute), ['id' => 'nfse-event-submit-' . $type, 'data-nfse-form' => 'fiscal-event-submit', 'data-reload' => '1', 'data-nfse-confirm' => $confirmation]); ?>
                        <input type="hidden" name="event_type" value="<?php echo $e($type); ?>">
                        <?php if ($reasons): ?>
                            <div class="row">
                                <div class="col-sm-5"><div class="form-group"><label for="nfse-event-<?php echo $e($type); ?>-reason-code">Motivo</label><select class="form-control" id="nfse-event-<?php echo $e($type); ?>-reason-code" name="reason_code" required><option value="">Selecione o motivo</option><?php foreach ($reasons as $code => $reasonLabel): ?><option value="<?php echo $e($code); ?>"><?php echo $e($reasonLabel); ?></option><?php endforeach; ?></select></div></div>
                                <div class="col-sm-7"><div class="form-group"><label for="nfse-event-<?php echo $e($type); ?>-reason">Justificativa<?php echo $reasonRequired ? '' : ' (opcional)'; ?></label><textarea class="form-control" rows="3" id="nfse-event-<?php echo $e($type); ?>-reason" name="reason" minlength="15" maxlength="255"<?php echo $reasonRequired ? ' required' : ''; ?> aria-describedby="nfse-event-<?php echo $e($type); ?>-reason-help"></textarea><p class="help-block" id="nfse-event-<?php echo $e($type); ?>-reason-help"><?php echo $reasonRequired ? 'Descreva o motivo com 15 a 255 caracteres.' : 'Quando preenchida, use de 15 a 255 caracteres. Para Outros, descreva o motivo da rejeição.'; ?></p></div></div>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn <?php echo $type === '101101' ? 'btn-danger' : 'btn-primary'; ?>"><?php echo $e($label); ?></button>
                    <?php echo form_close(); ?>
                <?php elseif ($pending): ?><p class="text-warning">Existe um pedido sem confirmação. Consulte o resultado antes de solicitar o reenvio.</p>
                <?php elseif ($registered): ?><p class="text-success">O registro deste evento foi confirmado. O mesmo pedido não será enviado novamente.</p>
                <?php elseif ($noteStatus !== 'authorized'): ?><p class="text-muted">A situação atual da nota não permite enviar este pedido.</p>
                <?php else: ?><p class="text-muted">Seu acesso permite consultar este evento. O envio exige a permissão correspondente.</p><?php endif; ?>
                <div class="nfse-actions mtop15">
                    <?php echo form_open(admin_url('nfse_nacional/fiscal_event_consult/' . $baseRoute), ['class' => 'no-margin', 'data-nfse-form' => 'fiscal-event-consult', 'data-reload' => '1']); ?>
                        <input type="hidden" name="event_type" value="<?php echo $e($type); ?>"><button type="submit" class="btn btn-default"><i class="fa fa-refresh" aria-hidden="true"></i> Consultar registro</button>
                    <?php echo form_close(); ?>
                    <?php if ($canRetry): ?>
                        <?php echo form_open(admin_url('nfse_nacional/fiscal_event_retry/' . $baseRoute), ['class' => 'no-margin', 'data-nfse-form' => 'fiscal-event-retry', 'data-reload' => '1', 'data-nfse-confirm' => 'Reenviar exatamente o mesmo pedido de ' . $label . ' para a NFS-e ' . $number . ' no ambiente ' . $environmentLabel . '? O registro será consultado antes do reenvio; os dados do pedido original serão preservados.']); ?>
                            <input type="hidden" name="event_type" value="<?php echo $e($type); ?>"><button type="submit" class="btn btn-default">Reenviar o mesmo pedido</button>
                        <?php echo form_close(); ?>
                    <?php endif; ?>
                    <?php if ($registered): ?><a class="btn btn-default" href="<?php echo $e(admin_url('nfse_nacional/fiscal_event_xml/' . $baseRoute . '/' . $type)); ?>"><i class="fa fa-download" aria-hidden="true"></i> XML do evento</a><?php endif; ?>
                </div>
            </div></section>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="panel_s"><div class="panel-body">
        <h4 class="no-mtop">Pedidos e registros de eventos</h4>
        <?php if (!$history): ?><p class="text-muted no-margin">Nenhum pedido de evento registrado neste módulo para esta nota. Use Consultar registro para verificar um evento no ambiente nacional.</p>
        <?php else: ?><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Data</th><th>Evento</th><th>Situação</th><th>Registro fiscal</th></tr></thead><tbody>
            <?php foreach ($history as $item):
                $item = $nfseArray($item);
                $eventStatus = (string) ($item['status'] ?? '');
                $confirmed = $eventStatus === 'registered';
                ?>
                <tr>
                    <td class="text-nowrap"><?php echo $e($nfseDate($item['updated_at'] ?? $item['created_at'] ?? '', true)); ?><div class="small text-muted mtop5">Tentativas: <?php echo max(0, (int) ($item['attempts'] ?? 0)); ?></div></td>
                    <td><?php echo $e($item['label'] ?? 'Evento fiscal'); ?><?php if (!empty($item['last_error'])): ?><p class="text-warning nfse-preserve-text mtop5 mbot0"><?php echo $e($item['last_error']); ?></p><?php endif; ?></td>
                    <td><span class="label <?php echo $e($eventClasses[$eventStatus] ?? 'label-default'); ?>"><?php echo $e($eventLabels[$eventStatus] ?? 'Situação a conferir'); ?></span></td>
                    <td><?php if ($confirmed): ?><?php if (!empty($item['event_id'])): ?><p class="nfse-access-key no-mtop"><strong>Identificação do evento</strong><br><?php echo $e($item['event_id']); ?></p><?php endif; ?><?php if (!empty($item['record_number'])): ?><p><strong>Número do registro:</strong> <?php echo $e($item['record_number']); ?></p><?php endif; ?><?php if (!empty($item['processed_at'])): ?><p class="no-mbot"><strong>Processamento:</strong> <?php echo $e($nfseDate($item['processed_at'], true)); ?></p><?php endif; ?><?php else: ?><span class="text-muted">Sem registro confirmado</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
