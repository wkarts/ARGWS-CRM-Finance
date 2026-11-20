<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';

$report = is_array($report ?? null) ? $report : [];
$rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
$filters = is_array($filters ?? null) ? $filters : [];
$operations = is_array($operations ?? null) ? $operations : [];
$page = max(1, (int) ($report['page'] ?? 1));
$pages = max(1, (int) ($report['pages'] ?? 1));
$total = max(0, (int) ($report['total'] ?? count($rows)));
$hasMore = (bool) ($report['has_more'] ?? ($page < $pages));
$storageAvailable = (bool) ($report['storage_available'] ?? (($report['storage'] ?? 'ok') !== 'unavailable'));
$levelLabels = ['info' => 'Informação', 'warning' => 'Aviso', 'error' => 'Erro', 'debug' => 'Diagnóstico'];
$levelClasses = ['info' => 'label-info', 'warning' => 'label-warning', 'error' => 'label-danger', 'debug' => 'label-default'];
$operationStatusLabels = ['started' => 'Iniciada', 'success' => 'Concluída', 'error' => 'Falha', 'uncertain' => 'Resultado incerto', 'not_found' => 'Não encontrado', 'partial' => 'Resultado parcial'];
$operationStatusClasses = ['started' => 'label-default', 'success' => 'label-success', 'error' => 'label-danger', 'uncertain' => 'label-warning', 'not_found' => 'label-default', 'partial' => 'label-warning'];
$logDate = static function ($value) use ($nfseDate): string {
    if (!is_scalar($value) || trim((string) $value) === '') { return '—'; }
    try { $value = (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'); }
    catch (Throwable $error) { return '—'; }
    return $nfseDate($value, true);
};
$filterKeys = ['date_from', 'date_to', 'level', 'operation', 'http_status', 'environment', 'search', 'correlation_id'];
$query = [];
foreach ($filterKeys as $key) {
    if (isset($filters[$key]) && is_scalar($filters[$key]) && (string) $filters[$key] !== '') { $query[$key] = (string) $filters[$key]; }
}
$logUrl = static function (string $route, array $extra = []) use ($query): string {
    $params = array_merge($query, $extra);
    return admin_url('nfse_nacional/' . $route) . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
};
$operationOptions = ['' => 'Todas'];
foreach ($operations as $key => $label) {
    if (is_string($key) && is_scalar($label)) { $operationOptions[$key] = (string) $label; }
}
if (!empty($query['operation']) && !array_key_exists($query['operation'], $operationOptions)) { $operationOptions[$query['operation']] = $query['operation']; }
init_head();
?>
<div id="wrapper"><div class="content nfse-root">
<div class="row"><div class="col-md-12">
    <div class="row mbot15">
        <div class="col-sm-7">
            <h4 class="no-mtop">Logs da NFS-e</h4>
            <p class="text-muted">Histórico das operações desta empresa, com diagnóstico e referência para acompanhamento.</p>
        </div>
        <div class="col-sm-5 text-right">
            <a class="btn btn-default mbot5" href="<?php echo $e($logUrl('logs', ['page' => $page])); ?>"><i class="fa fa-refresh" aria-hidden="true"></i> Atualizar</a>
            <a class="btn btn-default mbot5" href="<?php echo $e($logUrl('logs_download')); ?>"><i class="fa fa-download" aria-hidden="true"></i> Baixar logs filtrados</a>
            <a class="btn btn-default mbot5" href="<?php echo $e($logUrl('logs_diagnostic')); ?>"><i class="fa fa-file-code-o" aria-hidden="true"></i> Baixar diagnóstico detalhado (JSON)</a>
        </div>
    </div>
    <div class="panel_s"><div class="panel-body">
        <form action="<?php echo $e(admin_url('nfse_nacional/logs')); ?>" method="get" aria-label="Filtros dos logs da NFS-e">
            <div class="row">
                <div class="col-sm-6 col-md-3"><?php $nfseInput('date_from', 'Data inicial', $query['date_from'] ?? '', ['type' => 'date']); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseInput('date_to', 'Data final', $query['date_to'] ?? '', ['type' => 'date']); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseSelect('level', 'Nível', $query['level'] ?? '', ['' => 'Todos'] + $levelLabels); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseSelect('environment', 'Ambiente', $query['environment'] ?? '', ['' => 'Todos', '2' => 'Testes', '1' => 'Produção']); ?></div>
            </div>
            <div class="row">
                <div class="col-sm-6 col-md-4"><?php $nfseSelect('operation', 'Operação', $query['operation'] ?? '', $operationOptions); ?></div>
                <div class="col-sm-6 col-md-2"><?php $nfseInput('http_status', 'Status HTTP', $query['http_status'] ?? '', ['maxlength' => 3, 'inputmode' => 'numeric', 'pattern' => '[0-9]{3}', 'placeholder' => 'Ex.: 404']); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseInput('search', 'Pesquisar no diagnóstico', $query['search'] ?? '', ['maxlength' => 120]); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseInput('correlation_id', 'Referência da operação', $query['correlation_id'] ?? '', ['maxlength' => 64]); ?></div>
            </div>
            <div class="text-right">
                <button type="submit" class="btn btn-primary"><i class="fa fa-search" aria-hidden="true"></i> Filtrar</button>
                <a class="btn btn-link" href="<?php echo $e(admin_url('nfse_nacional/logs')); ?>">Limpar filtros</a>
            </div>
        </form>
        <hr>
        <?php if (!$storageAvailable): ?>
        <div class="alert alert-warning" role="alert">Não foi possível ler o histórico de operações. Verifique a permissão de armazenamento do módulo no servidor. A falha no registro de diagnóstico não deve interromper a emissão.</div>
        <?php endif; ?>
        <?php if (!empty($report['truncated'])): ?>
        <div class="alert alert-info" role="status">O limite de leitura desta consulta foi atingido. Restrinja o período ou os filtros para localizar outros registros. Os totais abaixo correspondem aos registros que puderam ser examinados.</div>
        <?php endif; ?>
        <div class="row mbot15">
            <div class="col-sm-7"><p class="text-muted no-margin"><?php echo count($rows); ?> <?php echo count($rows) === 1 ? 'registro exibido' : 'registros exibidos'; ?> de <?php echo $total; ?> encontrados · Página <?php echo $page; ?><?php if ($pages > 1): ?> de <?php echo $pages; ?><?php endif; ?></p></div>
            <div class="col-sm-5 text-right"><p class="text-muted no-margin">Retenção máxima: <?php echo max(1, (int) ($report['retention_days'] ?? 30)); ?> dias</p></div>
        </div>
        <?php if (!$rows): ?>
        <div class="text-center mtop20 mbot20">
            <h4>Nenhum registro neste filtro</h4>
            <p class="text-muted">Após uma nova consulta ou operação da NFS-e, atualize esta tela para acompanhar o resultado.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover" aria-label="Histórico das operações da NFS-e">
                <thead><tr><th>Data e hora</th><th>Operação</th><th>Resultado</th><th>HTTP / duração</th><th>Referência</th><th class="text-right">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $item):
                    $item = $nfseArray($item);
                    $context = $nfseArray($item['context'] ?? []);
                    $level = (string) ($item['level'] ?? 'info');
                    $operation = (string) ($item['operation'] ?? '');
                    $correlation = (string) ($item['correlation_id'] ?? '');
                    $httpStatus = (int) ($context['http_status'] ?? 0);
                    $duration = $context['duration_ms'] ?? null;
                    $operationStatus = is_string($context['operation_status'] ?? null) ? $context['operation_status'] : '';
                    $environment = (string) ($item['environment'] ?? '');
                    $detailUrl = $logUrl('log_detail', ['event_id' => (string) ($item['id'] ?? ''), 'page' => $page]);
                    ?>
                    <tr>
                        <td class="text-nowrap"><?php echo $e($logDate($item['created_at'] ?? '')); ?></td>
                        <td style="min-width:150px;max-width:240px;overflow-wrap:anywhere">
                            <strong><?php echo $e($operationOptions[$operation] ?? $operation); ?></strong>
                            <?php if (in_array($environment, ['1', '2'], true)): ?><div class="small text-muted mtop5"><?php echo $environment === '1' ? 'Produção' : 'Testes'; ?></div><?php endif; ?>
                            <?php if (!empty($item['document_id'])): ?><div class="small text-muted">Documento #<?php echo (int) $item['document_id']; ?></div><?php endif; ?>
                            <?php if (!empty($item['invoice_id'])): ?><div class="small text-muted">Fatura #<?php echo (int) $item['invoice_id']; ?></div><?php endif; ?>
                        </td>
                        <td style="min-width:240px;max-width:520px;overflow-wrap:anywhere">
                            <span class="label <?php echo $e($levelClasses[$level] ?? 'label-default'); ?>"><?php echo $e($levelLabels[$level] ?? $level); ?></span>
                            <?php if (isset($operationStatusLabels[$operationStatus])): ?> <span class="label <?php echo $e($operationStatusClasses[$operationStatus]); ?>"><?php echo $e($operationStatusLabels[$operationStatus]); ?></span><?php endif; ?>
                            <p class="mtop5 no-mbot"><?php echo $e($item['message'] ?? ''); ?></p>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($httpStatus > 0): ?><span class="label <?php echo $httpStatus >= 400 ? 'label-danger' : 'label-default'; ?>"><?php echo $httpStatus; ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            <?php if (is_numeric($duration)): ?><div class="small text-muted mtop5"><?php echo $e(number_format(max(0, (float) $duration), 0, ',', '.')); ?> ms</div><?php endif; ?>
                        </td>
                        <td style="max-width:180px;overflow-wrap:anywhere">
                            <?php if ($correlation !== ''): ?><a href="<?php echo $e($logUrl('logs', ['correlation_id' => $correlation, 'page' => 1])); ?>" title="Consultar registros desta referência"><code><?php echo $e($correlation); ?></code></a><?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-right text-nowrap">
                            <a class="btn btn-default btn-sm" href="<?php echo $e($detailUrl); ?>">Detalhes</a>
                            <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/logs_diagnostic') . '?' . http_build_query(['event_id' => (string) ($item['id'] ?? '')], '', '&', PHP_QUERY_RFC3986)); ?>" title="Baixar diagnóstico da operação (JSON)" aria-label="Baixar diagnóstico da operação"><i class="fa fa-download" aria-hidden="true"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <nav class="text-right" aria-label="Páginas dos logs da NFS-e">
            <ul class="pagination no-mbot">
                <li<?php echo $page <= 1 ? ' class="disabled"' : ''; ?>><?php if ($page > 1): ?><a href="<?php echo $e($logUrl('logs', ['page' => $page - 1])); ?>" rel="prev">Anterior</a><?php else: ?><span aria-disabled="true">Anterior</span><?php endif; ?></li>
                <li class="active"><span aria-current="page"><?php echo $page; ?></span></li>
                <li<?php echo !$hasMore ? ' class="disabled"' : ''; ?>><?php if ($hasMore): ?><a href="<?php echo $e($logUrl('logs', ['page' => $page + 1])); ?>" rel="next">Próxima</a><?php else: ?><span aria-disabled="true">Próxima</span><?php endif; ?></li>
            </ul>
        </nav>
        <?php endif; ?>
        <hr>
        <p class="text-muted small no-margin">O histórico e os downloads (JSONL e diagnóstico estruturado em JSON) contêm apenas diagnósticos tratados. Senhas, certificados, chaves privadas, tokens e o conteúdo integral dos documentos fiscais não são exibidos. <?php if (!empty($export_limit)): ?>O download inclui até <?php echo max(1, (int) $export_limit); ?> registros por arquivo, conforme os filtros.<?php else: ?>O download respeita o limite de registros da consulta.<?php endif; ?> Horários em <?php echo $e(date_default_timezone_get()); ?>.</p>
    </div></div>
</div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
