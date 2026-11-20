<?php
defined('BASEPATH') or exit('No direct script access allowed');
if (!nfse_nacional_can('national_view')) { return; }
require __DIR__ . '/_ui_helpers.php';
$direction = ($direction ?? '') === 'received' ? 'received' : 'issued';
$document_tab = $direction;
$filters = $nfseArray($filters ?? []);
$report = $nfseArray($report ?? []);
$rows = $nfseArray($report['rows'] ?? []);
$sync = $nfseArray($sync ?? []);
$run = $nfseArray($sync_run ?? []);
$runLabels = ['pending' => 'Agendada', 'running' => 'Em andamento', 'waiting' => 'Aguardando próxima consulta', 'completed' => 'Concluída', 'failed' => 'Falha — retomar'];
$configurationReady = !empty($configuration_ready);
$canSync = !empty($can_sync);
$environment = in_array((string) ($filters['environment'] ?? ''), ['1', '2'], true) ? (string) $filters['environment'] : '2';
$environmentLabel = $environment === '1' ? 'Produção' : 'Produção restrita (homologação)';
$filters['environment'] = $environment;
$page = max(1, (int) ($report['page'] ?? 1));
$pages = max(1, (int) ($report['pages'] ?? 1));
$total = max(0, (int) ($report['total'] ?? count($rows)));
$query = ['environment' => $environment];
foreach (['date_from', 'date_to', 'search', 'status'] as $filterKey) {
    if (isset($filters[$filterKey]) && is_scalar($filters[$filterKey]) && (string) $filters[$filterKey] !== '') { $query[$filterKey] = (string) $filters[$filterKey]; }
}
$nationalUrl = static function (array $extra = []) use ($direction, $query): string {
    return admin_url('nfse_nacional/national/' . $direction) . '?' . http_build_query(array_merge($query, $extra), '', '&', PHP_QUERY_RFC3986);
};
$nationalDate = static function ($value, bool $withTime = false) use ($nfseDate): string {
    if (!is_scalar($value) || trim((string) $value) === '') { return '—'; }
    try { $value = (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d'); }
    catch (Throwable $error) { return '—'; }
    return $nfseDate($value, $withTime);
};
$nextAllowed = null;
if (!empty($sync['next_allowed_at']) && is_scalar($sync['next_allowed_at'])) {
    try { $nextAllowed = new DateTimeImmutable((string) $sync['next_allowed_at']); } catch (Throwable $error) { $nextAllowed = null; }
}
$waiting = $nextAllowed !== null && $nextAllowed->getTimestamp() > time();
$syncEnabled = $canSync && $configurationReady && !$waiting;
$lastSuccess = isset($sync['last_success_at']) && is_scalar($sync['last_success_at']) && trim((string) $sync['last_success_at']) !== '';
$counterpartyLabel = $direction === 'issued' ? 'Tomador' : 'Prestador';
init_head();
?>
<div id="wrapper"><div class="content nfse-root"<?php echo $nfseCsrfAttributes(); ?>>
<div class="row"><div class="col-md-12">
    <?php if (nfse_nacional_can('logs')): ?><a class="btn btn-default pull-right" href="<?php echo $e(admin_url('nfse_nacional/logs')); ?>">Ver logs</a><?php endif; ?>
    <h4 class="no-mtop">Documentos</h4>
    <p class="text-muted mbot20"><?php echo $direction === 'issued' ? 'NFS-e emitidas pela empresa, inclusive fora do CRM, disponibilizadas no ambiente nacional.' : 'NFS-e recebidas pela empresa e disponibilizadas no ambiente nacional.'; ?></p>
    <?php require __DIR__ . '/_document_tabs.php'; ?>
    <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
    <div class="panel_s"><div class="panel-body">
        <div class="row">
            <div class="col-sm-8">
                <h4 class="no-mtop">Sincronização dos documentos</h4>
                <p><span class="label <?php echo $environment === '1' ? 'label-success' : 'label-default'; ?>"><?php echo $e($environmentLabel); ?></span></p>
                <p class="text-muted mbot5">Última sincronização concluída: <strong><?php echo $lastSuccess ? $e($nationalDate($sync['last_success_at'], true)) : 'Ainda não realizada'; ?></strong></p>
                <?php if (!empty($sync['last_attempt_at'])): ?><p class="text-muted small mbot5">Última tentativa: <?php echo $e($nationalDate($sync['last_attempt_at'], true)); ?></p><?php endif; ?>
                <p class="help-block no-mbot">A sincronização atualiza as notas emitidas e recebidas da empresa no ambiente selecionado.</p>
            </div>
            <div class="col-sm-4 text-right">
                <?php if ($canSync): ?>
                <?php echo form_open(admin_url('nfse_nacional/national_sync'), ['class' => 'nfse-form', 'data-nfse-form' => 'national-sync', 'data-reload' => '1']); ?>
                    <input type="hidden" name="environment" value="<?php echo $e($environment); ?>">
                    <?php $nfseSelect('sync_mode', 'Sincronizar', $run['mode'] ?? 'full', ['full' => 'Todo o histórico disponível', 'period' => 'Período de emissão']); ?>
                    <?php $nfseInput('sync_date_from', 'Data inicial (por período)', $run['date_from'] ?? '', ['type' => 'date']); ?>
                    <?php $nfseInput('sync_date_to', 'Data final (por período)', $run['date_to'] ?? '', ['type' => 'date']); ?>
                    <button type="submit" class="btn btn-primary"<?php echo $configurationReady ? '' : ' disabled'; ?>><i class="fa fa-refresh" aria-hidden="true"></i> <?php echo in_array($run['status'] ?? '', ['failed', 'waiting', 'running', 'pending'], true) ? 'Retomar sincronização' : 'Iniciar sincronização'; ?></button>
                <?php echo form_close(); ?>
                <?php endif; ?>
                <?php if ($waiting): ?><p class="help-block mtop10">Próxima consulta disponível a partir de <?php echo $e($nationalDate($sync['next_allowed_at'], true)); ?>. A rotina continuará automaticamente após esse horário, com o cron ativo.</p><?php endif; ?>
            </div>
        </div>
        <p class="help-block mtop15">O ADN distribui por NSU e não oferece filtro de data neste endpoint. Para um período, a rotina percorre os lotes necessários e arquiva o acervo; consulte o intervalo nos filtros abaixo. A disponibilidade e a retenção do histórico dependem do serviço nacional. Nenhum corte em 2024 é aplicado.</p>
        <div data-nfse-sync-progress data-process-url="<?php echo $e(admin_url('nfse_nacional/national_sync_process')); ?>" data-can-process="<?php echo $canSync && $configurationReady ? '1' : '0'; ?>" data-environment="<?php echo $e($environment); ?>" data-progress-url="<?php echo $e(admin_url('nfse_nacional/national_sync_progress') . '?environment=' . $environment); ?>" data-status="<?php echo $e($run['status'] ?? ''); ?>">
            <p><strong>Execução:</strong> <span data-sync-status><?php echo $e($runLabels[$run['status'] ?? ''] ?? 'Não iniciada'); ?></span> · <span data-sync-count><?php echo (int) ($run['processed'] ?? 0); ?></span> itens processados · <span data-sync-batches><?php echo (int) ($run['batches'] ?? 0); ?></span> lotes</p>
            <p class="text-muted" data-sync-message><?php echo $e(($run['last_error'] ?? '') ?: (!empty($run['worker_stalled']) ? 'A fila aguarda execução há mais de três minutos. Use Retomar para processar agora e verifique o cron do CRM para continuar com a tela fechada.' : 'A busca continua pelo cron do CRM. Com esta tela aberta, lotes adicionais também são processados automaticamente. O cursor confirmado é preservado.')); ?></p>
            <p class="text-muted small">Último processamento: <span data-sync-worker><?php echo !empty($run['last_worker_at']) ? $e($nationalDate(str_replace(' ', 'T', $run['last_worker_at']) . 'Z', true)) : 'Nenhum lote processado nesta execução'; ?></span> · Próxima tentativa: <span data-sync-next><?php echo !empty($run['next_attempt_at']) ? $e($nationalDate(str_replace(' ', 'T', $run['next_attempt_at']) . 'Z', true)) : '—'; ?></span></p>
            <?php if (($run['mode'] ?? '') === 'period'): ?><p class="help-block">Período solicitado: <?php echo $e($run['date_from']); ?> a <?php echo $e($run['date_to']); ?>. <a href="<?php echo $e($nationalUrl(['date_from' => $run['date_from'], 'date_to' => $run['date_to']])); ?>">Ver documentos do período</a></p><?php endif; ?>
        </div>
        <?php if (!$configurationReady): ?><div class="alert alert-warning mtop15 no-mbot" role="status">Configure os dados do emitente e um certificado A1 válido para sincronizar os documentos desta empresa.<?php if (nfse_nacional_can('settings')): ?> <a href="<?php echo $e(nfse_nacional_settings_url()); ?>">Abrir configurações da NFS-e</a>.<?php endif; ?></div><?php endif; ?>
        <?php if (!empty($sync['last_error']) && is_scalar($sync['last_error'])): ?><div class="alert alert-warning mtop15 no-mbot" role="status"><strong>A última sincronização não foi concluída.</strong> <?php echo $e($sync['last_error']); ?> Os documentos já sincronizados permanecem disponíveis.</div><?php endif; ?>
        <?php if (!empty($sync['has_more'])): ?><p class="text-info mtop15 no-mbot">O último lote recebido permite continuar a consulta. A busca só termina quando o ADN confirmar que não há novos documentos; a data das notas não encerra o histórico.</p><?php endif; ?>
    </div></div>
    <div class="panel_s"><div class="panel-body">
        <form action="<?php echo $e(admin_url('nfse_nacional/national/' . $direction)); ?>" method="get" aria-label="Filtros dos documentos nacionais">
            <div class="row">
                <div class="col-sm-6 col-md-4"><?php $nfseSelect('environment', 'Ambiente', $environment, ['2' => 'Produção restrita (homologação)', '1' => 'Produção']); ?></div>
                <div class="col-sm-6 col-md-4"><?php $nfseInput('date_from', 'Emissão a partir de', $query['date_from'] ?? '', ['type' => 'date']); ?></div>
                <div class="col-sm-6 col-md-4"><?php $nfseInput('date_to', 'Emissão até', $query['date_to'] ?? '', ['type' => 'date']); ?></div>
            </div>
            <div class="row">
                <div class="col-sm-8"><?php $nfseInput('search', 'Pesquisar por nome, CPF/CNPJ, número ou chave', $query['search'] ?? '', ['maxlength' => 150]); ?></div>
                <div class="col-sm-4"><?php $nfseSelect('status', 'Situação', $query['status'] ?? '', ['' => 'Todas', 'authorized' => 'Autorizada', 'cancelled' => 'Cancelada']); ?></div>
            </div>
            <div class="text-right"><button type="submit" class="btn btn-default"><i class="fa fa-search" aria-hidden="true"></i> Filtrar</button> <a class="btn btn-link" href="<?php echo $e(admin_url('nfse_nacional/national/' . $direction) . '?environment=' . $environment); ?>">Limpar filtros</a></div>
        </form>
        <hr>
        <p class="text-muted"><?php echo count($rows); ?> <?php echo count($rows) === 1 ? 'registro exibido' : 'registros exibidos'; ?> de <?php echo $total; ?> no histórico sincronizado · Página <?php echo $page; ?> de <?php echo $pages; ?></p>
        <?php if (!$rows): ?>
        <div class="text-center mtop20 mbot20">
            <h4><?php echo $lastSuccess ? 'Nenhum documento sincronizado corresponde aos filtros' : 'Documentos ainda não sincronizados'; ?></h4>
            <p class="text-muted">Sincronize para consultar documentos disponibilizados para esta empresa no ambiente nacional. A disponibilidade do histórico depende do ambiente nacional.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-hover" aria-label="<?php echo $direction === 'issued' ? 'NFS-e emitidas no ambiente nacional' : 'NFS-e recebidas'; ?>">
            <thead><tr><th>Documento</th><th><?php echo $e($counterpartyLabel); ?></th><th>Emissão / competência</th><th>Situação</th><th class="text-right">Serviços</th><th class="text-right">Ações</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                $row = $nfseArray($row);
                $rowId = max(0, (int) ($row['id'] ?? 0));
                $rowStatus = (string) ($row['status'] ?? '');
                $rowStatusLabel = ['authorized' => 'Autorizada', 'cancelled' => 'Cancelada'][$rowStatus] ?? 'Situação a conferir';
                $counterparty = $direction === 'issued' ? 'recipient' : 'provider';
                ?>
                <tr>
                    <td style="min-width:160px;max-width:260px;overflow-wrap:anywhere"><strong>NFS-e <?php echo $e($row['nfse_number'] ?? ''); ?></strong><div class="small text-muted mtop5">Chave: <?php echo $e($row['access_key'] ?? ''); ?></div></td>
                    <td style="min-width:180px;max-width:300px;overflow-wrap:anywhere"><?php echo $e($row[$counterparty . '_name'] ?? ''); ?><div class="small text-muted mtop5"><?php echo $e($row[$counterparty . '_tax_id'] ?? ''); ?></div></td>
                    <td class="text-nowrap"><?php echo $e($nationalDate($row['issued_at'] ?? '', true)); ?><div class="small text-muted mtop5">Competência: <?php echo $e($nationalDate($row['competence'] ?? '')); ?></div></td>
                    <td><span class="label <?php echo $e($nfseStatusClass($rowStatus)); ?>"><?php echo $e($rowStatusLabel); ?></span></td>
                    <td class="text-right text-nowrap"><?php echo $e($nfseMoney($row['service_amount'] ?? '0.00')); ?></td>
                    <td class="text-right"><?php if ($rowId > 0 && nfse_nacional_can('report_export')): ?><div class="nfse-actions">
                        <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/national_preview/' . $rowId)); ?>" target="_blank" rel="noopener noreferrer"><i class="fa fa-eye" aria-hidden="true"></i> Ver DANFSe</a>
                        <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/national_download/' . $rowId . '/pdf')); ?>" aria-label="Baixar DANFSe PDF"><i class="fa fa-download" aria-hidden="true"></i> PDF</a>
                        <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/national_download/' . $rowId . '/xml')); ?>" aria-label="Baixar XML da NFS-e">XML</a>
                        <?php if (nfse_nacional_can('view') && in_array($rowStatus, ['authorized', 'cancelled'], true)): ?><a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/fiscal_events/national/' . $rowId)); ?>">Eventos fiscais</a><?php endif; ?>
                    </div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <nav class="text-right" aria-label="Páginas dos documentos nacionais"><ul class="pagination no-mbot">
            <li<?php echo $page <= 1 ? ' class="disabled"' : ''; ?>><?php if ($page > 1): ?><a href="<?php echo $e($nationalUrl(['page' => $page - 1])); ?>" rel="prev">Anterior</a><?php else: ?><span aria-disabled="true">Anterior</span><?php endif; ?></li>
            <li class="active"><span aria-current="page"><?php echo $page; ?></span></li>
            <li<?php echo $page >= $pages ? ' class="disabled"' : ''; ?>><?php if ($page < $pages): ?><a href="<?php echo $e($nationalUrl(['page' => $page + 1])); ?>" rel="next">Próxima</a><?php else: ?><span aria-disabled="true">Próxima</span><?php endif; ?></li>
        </ul></nav>
        <hr>
        <p class="text-muted small no-margin">Os documentos emitidos pelo CRM continuam vinculados às respectivas faturas na guia No CRM. A sincronização de documentos externos não cria faturas.</p>
    </div></div>
</div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
