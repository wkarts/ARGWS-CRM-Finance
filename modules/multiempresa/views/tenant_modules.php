<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
<h4 class="tw-mt-0 tw-font-semibold"><?= html_escape($title) ?></h4>
<p class="text-muted">Ative ou desative os módulos disponíveis para sua empresa.</p>
<?php if($this->input->get('changed')==='1'): ?><div class="alert alert-success">Situação do módulo atualizada.</div><?php endif; ?>
<?php if($this->input->get('failed')==='1'): ?><div class="alert alert-warning">Não foi possível concluir a alteração. Tente novamente. Se o problema persistir, entre em contato com o suporte.</div><?php endif; ?>
<div class="panel_s"><div class="panel-body"><div class="table-responsive"><table class="table dt-table" data-order-col="0" data-order-type="asc">
<thead><tr><th>Módulo</th><th>Situação</th><th>Ação</th></tr></thead><tbody>
<?php foreach($modules as $item): $info=$item['native']; ?>
<tr><td><strong><?= html_escape($info['headers']['module_name']??$item['name']) ?></strong><br><small class="text-muted"><?= html_escape($info['headers']['description']??'') ?></small></td>
<td><span class="label label-<?= $item['active']?'success':'default' ?>"><?= $item['active']?'Ativo':'Desativado' ?></span></td>
<td><form method="post" action="<?= html_escape(admin_url('modules/'.($item['active']?'deactivate':'activate').'/'.$item['name'])) ?>">
<input type="hidden" name="csrf" value="<?= html_escape($ctx->principal['csrf']) ?>">
<button class="btn btn-default btn-sm" type="submit"><?= $item['active']?'Desativar':'Ativar' ?></button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
