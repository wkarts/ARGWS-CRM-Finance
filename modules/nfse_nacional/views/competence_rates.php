<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';
$canCreate = nfse_nacional_can('rates_create'); $canEdit = nfse_nacional_can('rates_edit'); $canDelete = nfse_nacional_can('rates_delete');
$rateType = \NfseNacional\CompetenceRate::type((string) ($rate_type ?? 'simple'));
$rateLabel = $rateType === 'iss' ? 'Alíquota ISSQN (%)' : 'Alíquota cheia do Simples (%)';
$page = max(1, (int) ($page ?? 1)); $pages = max(1, (int) ($pages ?? 1)); $search = (string) ($search ?? '');
$rateUrl = static fn($p) => admin_url('nfse_nacional/competence_rates') . '?' . http_build_query(['page' => $p, 'search' => $search, 'rate_type' => $rateType]);
init_head();
?>
<div id="wrapper"><div class="content nfse-root"<?php echo $nfseCsrfAttributes(); ?>><div class="panel_s"><div class="panel-body">
<h4>Alíquotas por competência</h4><p class="text-muted">Cadastros separados por competência fiscal. Nas configurações, escolha aplicar ao Simples, ao ISSQN ou a ambos, com percentuais separados ou compartilhados.</p>
<ul class="nav nav-tabs mbot20" aria-label="Tipo de alíquota">
<?php foreach (['simple' => 'Simples Nacional', 'iss' => 'ISSQN'] as $type => $label): ?><li<?php echo $type === $rateType ? ' class="active"' : ''; ?>><a href="<?php echo $e(admin_url('nfse_nacional/competence_rates') . '?rate_type=' . $type); ?>"><?php echo $e($label); ?></a></li><?php endforeach; ?>
</ul>
<?php if (($rate_settings['variable_iss_rate'] ?? '0') === '1' && ($rate_settings['variable_iss_rate_source'] ?? 'iss') === 'simple'): ?><p class="help-block">ISSQN configurado para utilizar o percentual da guia Simples Nacional. Os registros próprios de ISSQN permanecem preservados para uso separado.</p><?php endif; ?>
<div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
<?php if ($canCreate || $canEdit): ?>
<?php echo form_open(admin_url('nfse_nacional/competence_rate_save'), ['data-nfse-form' => 'rate', 'data-nfse-rate-form' => '1', 'data-can-create' => $canCreate ? '1' : '0', 'data-reload' => '1']); ?>
<input type="hidden" name="rate_type" value="<?php echo $e($rateType); ?>"><input type="hidden" name="original_competence" value="">
<div class="row"><div class="col-sm-4"><?php $nfseInput('competence', 'Competência (MM/AAAA)', '', ['pattern' => '(0[1-9]|1[0-2])/[0-9]{4}', 'placeholder' => '11/2026', 'required' => true]); ?></div><div class="col-sm-4"><?php $nfseInput('rate', $rateLabel, '', ['inputmode' => 'decimal', 'required' => true, 'maxlength' => 5]); ?></div><div class="col-sm-4 mtop25"><button class="btn btn-primary" type="submit"<?php echo $canCreate ? '' : ' disabled'; ?>><span data-nfse-rate-save-label><?php echo $canCreate ? 'Incluir alíquota' : 'Selecione uma competência'; ?></span></button> <button class="btn btn-default" type="reset" data-nfse-rate-new><?php echo $canCreate ? 'Novo / cancelar edição' : 'Cancelar edição'; ?></button></div></div>
<?php echo form_close(); ?>
<?php if (!$canCreate): ?><p class="help-block">Selecione Editar na linha da competência para alterar o percentual. Seu acesso não permite incluir novos meses.</p><?php endif; ?>
<?php endif; ?>
<form action="<?php echo $e(admin_url('nfse_nacional/competence_rates')); ?>" method="get" class="mbot15"><div class="row"><div class="col-sm-8"><input type="hidden" name="rate_type" value="<?php echo $e($rateType); ?>"><input class="form-control" type="search" name="search" value="<?php echo $e($search); ?>" placeholder="Pesquisar competência ou alíquota" aria-label="Pesquisar competência ou alíquota"></div><div class="col-sm-4"><button type="submit" class="btn btn-default">Pesquisar</button></div></div></form>
<div class="table-responsive"><table class="table table-striped table-hover nfse-rates-table" data-nfse-rates-grid><thead><tr><th>Competência</th><th><?php echo $e($rateType === 'iss' ? 'Alíquota ISSQN' : 'Alíquota cheia do Simples'); ?></th><th>Atualizada em</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($rates as $rate): $month = substr($rate['competence'], 5, 2) . '/' . substr($rate['competence'], 0, 4); ?>
<tr><td><?php echo $e($month); ?></td><td><?php echo $e(str_replace('.', ',', $rate['rate'])); ?>%</td><td><?php echo $e($rate['updated_at']); ?></td><td>
<?php if ($canEdit): ?><button type="button" class="btn btn-default btn-sm" data-nfse-rate-edit data-month="<?php echo $e($month); ?>" data-rate="<?php echo $e($rate['rate']); ?>">Editar</button><?php endif; ?>
<?php if ($canDelete): ?><?php echo form_open(admin_url('nfse_nacional/competence_rate_delete'), ['data-nfse-form' => 'rate-delete', 'data-reload' => '1', 'style' => 'display:inline-block']); ?><input type="hidden" name="rate_type" value="<?php echo $e($rateType); ?>"><input type="hidden" name="competence" value="<?php echo $e($rate['competence']); ?>"><button type="submit" class="btn btn-danger btn-sm">Remover</button><?php echo form_close(); ?><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$rates): ?><tr><td colspan="4" class="text-muted">Nenhuma alíquota encontrada para esta empresa e pesquisa.</td></tr><?php endif; ?>
</tbody></table></div>
<nav aria-label="Páginas das alíquotas"><ul class="pagination"><?php if ($page > 1): ?><li><a href="<?php echo $e($rateUrl($page - 1)); ?>">Anterior</a></li><?php endif; ?><li class="active"><span><?php echo $page; ?> / <?php echo $pages; ?></span></li><?php if ($page < $pages): ?><li><a href="<?php echo $e($rateUrl($page + 1)); ?>">Próxima</a></li><?php endif; ?></ul></nav>
<p class="help-block">Alterações aplicam-se às próximas emissões. Documentos autorizados e DPS já assinadas preservam seus valores.</p>
</div></div></div></div><?php init_tail(); ?>
