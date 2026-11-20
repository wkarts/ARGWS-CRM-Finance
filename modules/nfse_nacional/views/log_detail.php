<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';

$record = is_array($record ?? null) ? $record : [];
$context = $nfseArray($record['context'] ?? []);
$filters = is_array($filters ?? null) ? $filters : [];
$operations = is_array($operations ?? null) ? $operations : [];
$query = [];
foreach (['date_from', 'date_to', 'level', 'operation', 'http_status', 'environment', 'search', 'correlation_id', 'page'] as $key) {
    if (isset($filters[$key]) && is_scalar($filters[$key]) && (string) $filters[$key] !== '') { $query[$key] = (string) $filters[$key]; }
}
$backUrl = admin_url('nfse_nacional/logs') . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
$correlation = (string) ($record['correlation_id'] ?? '');
$level = (string) ($record['level'] ?? 'info');
$operation = (string) ($record['operation'] ?? '');
$environment = (string) ($record['environment'] ?? '');
$levelLabels = ['info' => 'Informação', 'warning' => 'Aviso', 'error' => 'Erro', 'debug' => 'Diagnóstico'];
$alertClass = ['error' => 'alert-danger', 'warning' => 'alert-warning'][$level] ?? 'alert-info';
$httpStatus = (int) ($context['http_status'] ?? 0);
$duration = $context['duration_ms'] ?? null;
$operationStatus = is_string($context['operation_status'] ?? null) ? $context['operation_status'] : '';
$operationStatusLabels = ['started' => 'Iniciada', 'success' => 'Concluída', 'error' => 'Falha', 'uncertain' => 'Resultado incerto', 'not_found' => 'Não encontrado', 'partial' => 'Resultado parcial'];
$operationStatusClasses = ['started' => 'label-default', 'success' => 'label-success', 'error' => 'label-danger', 'uncertain' => 'label-warning', 'not_found' => 'label-default', 'partial' => 'label-warning'];
$logDate = static function ($value) use ($nfseDate): string {
    if (!is_scalar($value) || trim((string) $value) === '') { return '—'; }
    try { $value = (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'); }
    catch (Throwable $error) { return '—'; }
    return $nfseDate($value, true);
};
$json = static function ($value): string {
    $result = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($result) ? $result : '{}';
};
init_head();
?>
<div id="wrapper"><div class="content nfse-root">
<div class="row"><div class="col-md-12">
    <div class="row mbot15">
        <div class="col-sm-8"><h4 class="no-mtop">Detalhes do log da NFS-e</h4><p class="text-muted">Diagnóstico da operação registrada nesta empresa.</p></div>
        <div class="col-sm-4 text-right"><a class="btn btn-default" href="<?php echo $e($backUrl); ?>"><i class="fa fa-arrow-left" aria-hidden="true"></i> Voltar aos logs</a></div>
    </div>
    <div class="panel_s"><div class="panel-body">
        <div class="alert <?php echo $e($alertClass); ?>" role="status" style="overflow-wrap:anywhere"><strong><?php echo $e($levelLabels[$level] ?? $level); ?>:</strong> <?php echo $e($record['message'] ?? ''); ?></div>
        <div class="row">
            <div class="col-sm-6 col-md-3"><p class="text-muted mbot5">Data e hora</p><p><?php echo $e($logDate($record['created_at'] ?? '')); ?><span class="small text-muted"> · <?php echo $e(date_default_timezone_get()); ?></span></p></div>
            <div class="col-sm-6 col-md-3"><p class="text-muted mbot5">Operação</p><p style="overflow-wrap:anywhere"><?php echo $e($operations[$operation] ?? $operation); ?></p><?php if (isset($operationStatusLabels[$operationStatus])): ?><p><span class="label <?php echo $e($operationStatusClasses[$operationStatus]); ?>"><?php echo $e($operationStatusLabels[$operationStatus]); ?></span></p><?php endif; ?></div>
            <div class="col-sm-6 col-md-3"><p class="text-muted mbot5">Ambiente</p><p><?php echo $environment === '1' ? 'Produção' : ($environment === '2' ? 'Testes' : 'Não se aplica'); ?></p></div>
            <div class="col-sm-6 col-md-3"><p class="text-muted mbot5">HTTP / duração</p><p><?php echo $httpStatus > 0 ? $httpStatus : '—'; ?><?php if (is_numeric($duration)): ?> · <?php echo $e(number_format(max(0, (float) $duration), 0, ',', '.')); ?> ms<?php endif; ?></p></div>
        </div>
        <div class="row">
            <div class="col-sm-6"><p class="text-muted mbot5">Referência da operação</p><p style="overflow-wrap:anywhere"><code><?php echo $e($correlation !== '' ? $correlation : '—'); ?></code></p></div>
            <div class="col-sm-6"><p class="text-muted mbot5">Identificador do registro</p><p style="overflow-wrap:anywhere"><code><?php echo $e($record['id'] ?? ''); ?></code></p></div>
        </div>
        <?php if (!empty($record['document_id']) || !empty($record['invoice_id']) || !empty($record['staff_id'])): ?>
        <div class="row">
            <?php foreach (['document_id' => 'Documento', 'invoice_id' => 'Fatura', 'staff_id' => 'Colaborador'] as $key => $label): ?>
            <?php if (!empty($record[$key])): ?><div class="col-sm-4"><p class="text-muted mbot5"><?php echo $e($label); ?></p><p>#<?php echo (int) $record[$key]; ?></p></div><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($correlation !== ''): ?>
        <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/logs') . '?' . http_build_query(['correlation_id' => $correlation], '', '&', PHP_QUERY_RFC3986)); ?>">Ver registros da mesma operação</a>
        <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/logs_diagnostic') . '?' . http_build_query(['event_id' => (string) ($record['id'] ?? '')], '', '&', PHP_QUERY_RFC3986)); ?>"><i class="fa fa-download" aria-hidden="true"></i> Baixar diagnóstico desta operação</a>
        <?php endif; ?>
        <hr>
        <h4>Dados do diagnóstico</h4>
        <p class="text-muted">Informações técnicas tratadas para acompanhar a requisição e o retorno. Dados sigilosos e documentos completos não são incluídos.</p>
        <?php if ($context): ?>
        <pre style="white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;max-width:100%;max-height:580px;overflow:auto"><?php echo $e($json($context)); ?></pre>
        <?php else: ?>
        <p class="text-muted">Este registro não contém detalhes adicionais.</p>
        <?php endif; ?>
    </div></div>
</div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
