<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';
$documents = $documents ?? [];
$filters = $filters ?? [];
$stats = $stats ?? [];
$collection = in_array($filters['collection'] ?? '', ['all', 'authorized', 'pending'], true) ? $filters['collection'] : 'all';
$collectionUrl = static function (string $value) use ($filters): string {
    $params = ['collection' => $value];
    foreach (['search', 'environment'] as $key) { if (!empty($filters[$key])) { $params[$key] = (string) $filters[$key]; } }
    return admin_url('nfse_nacional') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};
$statusOptions = ['' => 'Todas', 'draft' => 'Rascunho', 'ready' => 'Validada', 'processing' => 'Em processamento', 'authorized' => 'Autorizada', 'cancelled' => 'Cancelada', 'rejected' => 'Rejeitada', 'uncertain' => 'A consultar'];
if ($collection === 'authorized') { $statusOptions = ['' => 'Todas as emitidas', 'authorized' => 'Autorizada', 'cancelled' => 'Cancelada']; }
elseif ($collection === 'pending') { unset($statusOptions['authorized'], $statusOptions['cancelled']); }
$canTransmit = nfse_nacional_can('transmit');
init_head();
?>
<div id="wrapper"><div class="content nfse-root"<?php echo $nfseCsrfAttributes(); ?>>
<div class="row"><div class="col-md-12">
    <?php if (nfse_nacional_can('logs')): ?><a class="btn btn-default pull-right" href="<?php echo $e(admin_url('nfse_nacional/logs')); ?>">Ver logs</a><?php endif; ?>
    <h4 class="no-mtop">Documentos</h4>
    <p class="text-muted mbot20">Notas emitidas, rascunhos e pendências de transmissão.</p>
    <?php $document_tab = 'crm'; require __DIR__ . '/_document_tabs.php'; ?>
    <?php if (is_array($ibpt_expired_notice ?? null)):
        $ibptExpiry = (string) ($ibpt_expired_notice['valid_to'] ?? '');
        $ibptExpiry = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $ibptExpiry)
            ? substr($ibptExpiry, 8, 2) . '/' . substr($ibptExpiry, 5, 2) . '/' . substr($ibptExpiry, 0, 4) : '';
    ?>
    <div class="nfse-ibpt-expired-notice" role="note" data-nfse-ibpt-expired-notice>
        <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
        <strong>IBPT vencida</strong><span aria-hidden="true"> · </span>
        <span>UF <?php echo $e($ibpt_expired_notice['uf'] ?? ''); ?><?php if (!empty($ibpt_expired_notice['version'])): ?> · versão <?php echo $e($ibpt_expired_notice['version']); ?><?php endif; ?><?php if ($ibptExpiry !== ''): ?> · vigência encerrada em <?php echo $e($ibptExpiry); ?><?php endif; ?>.</span>
        <span>O cálculo e a emissão continuam disponíveis.</span>
    </div>
    <?php endif; ?>
    <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
    <div class="row">
        <?php foreach ([
            ['Rascunhos', (int) ($stats['draft'] ?? 0) + (int) ($stats['ready'] ?? 0)],
            ['Notas emitidas', (int) ($stats['authorized'] ?? 0) + (int) ($stats['cancelled'] ?? 0)],
            ['Rejeitadas', (int) ($stats['rejected'] ?? 0)],
            ['A consultar', (int) ($stats['processing'] ?? 0) + (int) ($stats['uncertain'] ?? 0)],
        ] as [$summaryLabel, $summaryCount]): ?>
        <div class="col-sm-6 col-lg-3"><div class="panel_s"><div class="panel-body"><span class="text-muted"><?php echo $e($summaryLabel); ?></span> <strong class="pull-right"><?php echo $summaryCount; ?></strong><div class="clearfix"></div></div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="panel_s"><div class="panel-body">
        <ul class="nav nav-tabs mbot20">
            <?php foreach (['all' => 'Todas', 'authorized' => 'Notas emitidas', 'pending' => 'Rascunhos e pendências'] as $key => $label): ?>
            <li<?php echo $collection === $key ? ' class="active"' : ''; ?>><a href="<?php echo $e($collectionUrl($key)); ?>"<?php echo $collection === $key ? ' aria-current="page"' : ''; ?>><?php echo $e($label); ?></a></li>
            <?php endforeach; ?>
        </ul>
        <form action="<?php echo $e(admin_url('nfse_nacional')); ?>" method="get">
            <input type="hidden" name="collection" value="<?php echo $e($collection); ?>">
            <div class="row">
                <div class="col-md-6"><?php $nfseInput('search', 'Nome do cliente', $filters['search'] ?? '', ['maxlength' => 100]); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseSelect('status', 'Situação', array_key_exists($filters['status'] ?? '', $statusOptions) ? ($filters['status'] ?? '') : '', $statusOptions); ?></div>
                <div class="col-sm-6 col-md-3"><?php $nfseSelect('environment', 'Ambiente', $filters['environment'] ?? '', ['' => 'Todos', '2' => 'Testes', '1' => 'Produção']); ?></div>
            </div>
            <div class="text-right"><button type="submit" class="btn btn-default"><i class="fa fa-search" aria-hidden="true"></i> Filtrar</button> <a href="<?php echo $e(admin_url('nfse_nacional') . '?collection=' . $collection); ?>" class="btn btn-link">Limpar</a></div>
        </form>
        <hr>
        <p class="text-muted"><?php echo count($documents); ?> <?php echo count($documents) === 1 ? 'registro exibido' : 'registros exibidos'; ?> · até 200 por consulta</p>
        <?php if (!$documents): ?>
        <div class="text-center mtop20 mbot20"><h4>Nenhum documento neste filtro</h4><p class="text-muted">Abra uma fatura para emitir a NFS-e ou preparar um rascunho.</p><p class="text-muted">Os dados do tomador ficam na guia NFS-e do cliente.</p><a class="btn btn-default" href="<?php echo $e(admin_url('invoices')); ?>">Abrir faturas</a></div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-hover nfse-table nfse-management-table"><thead><tr><th>Documento</th><th>Cliente / fatura</th><th>Situação</th><th class="text-right">Serviços</th><th class="text-right">Ações</th></tr></thead><tbody>
        <?php foreach ($documents as $item):
            $item = $nfseArray($item);
            $status = (string) ($item['status'] ?? 'draft');
            $id = (int) ($item['id'] ?? 0);
            $authorized = $status === 'authorized';
            $issued = $authorized || $status === 'cancelled';
            $orphan = !empty($item['orphan_document']);
            $documentUrl = admin_url('nfse_nacional/document/' . $id);
            $hasClient = isset($item['client_name']) && $item['client_name'] !== '';
            ?>
        <tr>
            <td><a href="<?php echo $e($documentUrl); ?>"><strong><?php echo $issued ? 'NFS-e ' . $e($item['nfse_number'] ?? '') : 'Rascunho #' . $id; ?></strong></a><div class="small nfse-subline"><span class="label <?php echo (string) ($item['environment'] ?? '2') === '1' ? 'label-success nfse-production' : 'label-default nfse-test'; ?> nfse-environment"><?php echo (string) ($item['environment'] ?? '2') === '1' ? 'Produção' : 'Testes'; ?></span></div></td>
            <td><?php if ($hasClient): ?><a href="<?php echo $e(admin_url('clients/client/' . (int) $item['client_id'] . '?group=nfse_nacional')); ?>"><?php echo $e($item['client_name']); ?></a><?php else: ?><span class="text-muted">Cadastro de origem indisponível</span><?php endif; ?><div class="small nfse-subline text-muted">Fatura #<?php echo $e($item['invoice_id'] ?? ''); ?> · <?php echo $e($nfseDate($item['created_at'] ?? '', true)); ?></div></td>
            <td><span class="label <?php echo $e($nfseStatusClass($status)); ?> nfse-status nfse-status-<?php echo $e($status); ?>"><?php echo $e(nfse_nacional_status_label($status)); ?></span>
                <?php if (!empty($item['last_error'])): ?><div class="small nfse-subline nfse-error-summary text-danger" title="<?php echo $e($item['last_error']); ?>"><?php echo $e($item['last_error']); ?></div><?php endif; ?>
                <?php if ($authorized && ($item['email_status'] ?? '') === 'sent'): ?><div class="small nfse-subline text-muted">E-mail enviado</div><?php elseif ($authorized && in_array($item['email_status'] ?? '', ['sending', 'uncertain'], true)): ?><div class="small nfse-subline text-warning">Envio de e-mail em verificação</div><?php endif; ?>
            </td>
            <td class="text-right nfse-amount"><?php echo $e($nfseMoney($item['service_amount'] ?? '0.00')); ?></td>
            <td class="text-right"><div class="nfse-actions">
                <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/preview/' . $id)); ?>" target="_blank" rel="noopener noreferrer" data-nfse-preview><i class="fa fa-eye" aria-hidden="true"></i> Pré-visualizar DANFSe</a>
                <?php if ($issued): ?>
                    <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/download/' . $id . '/pdf')); ?>" title="Baixar DANFSe" aria-label="Baixar DANFSe"><i class="fa fa-download" aria-hidden="true"></i></a> <a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/download/' . $id . '/xml')); ?>">XML</a> <a href="<?php echo $e($documentUrl); ?>" class="btn btn-link btn-sm">Detalhes</a>
                <?php elseif ($orphan): ?>
                    <a class="btn btn-default btn-sm" href="<?php echo $e($documentUrl); ?>">Abrir arquivo</a>
                <?php elseif (in_array($status, ['processing', 'uncertain'], true)): ?>
                    <button type="button" class="btn btn-default btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/reconcile/' . $id)); ?>" data-reload="1"><i class="fa fa-refresh" aria-hidden="true"></i> Consultar</button><a href="<?php echo $e($documentUrl); ?>" class="btn btn-link btn-sm">Detalhes</a>
                <?php else: ?>
                    <a class="btn btn-default btn-sm" href="<?php echo $e($documentUrl); ?>"><?php echo $status === 'rejected' ? 'Revisar retorno' : 'Continuar rascunho'; ?></a>
                    <?php if ($status === 'ready' && $canTransmit && $hasClient && !$orphan): ?><button type="button" class="btn btn-primary btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/transmit/' . $id)); ?>" data-nfse-emission="1" data-reload="1"><i class="fa fa-paper-plane" aria-hidden="true"></i> Emitir NFS-e</button><?php endif; ?>
                <?php endif; ?>
            </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php if (!empty($pagination)): ?><div class="mtop20"><?php echo $pagination; ?></div><?php endif; ?>
        <?php endif; ?>
    </div></div>
</div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
