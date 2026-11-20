<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';
init_head();
?>
<div id="wrapper"><div class="content nfse-root"><div class="panel_s"><div class="panel-body">
<h4>Relatórios da NFS-e</h4><p class="text-muted">Relatórios liberados para o seu usuário.</p>
<div class="list-group">
<?php foreach (['crm' => ['Documentos do CRM', 'nfse_nacional'], 'issued' => ['NFS-e emitidas no ambiente nacional', 'nfse_nacional/national/issued'], 'received' => ['NFS-e recebidas', 'nfse_nacional/national/received'], 'accounting' => ['Pacote mensal', 'nfse_nacional/accounting']] as $type => $report): ?>
<?php if (nfse_nacional_can(\NfseNacional\Permissions::reportCapability($type))): ?><a class="list-group-item" href="<?php echo $e(admin_url($report[1])); ?>"><?php echo $e($report[0]); ?></a><?php endif; ?>
<?php endforeach; ?></div>
<?php if (function_exists('is_admin') && is_admin()): ?><p class="help-block">Libere cada relatório e as ações de alíquotas nas permissões do funcionário ou da função no CRM. Ao alterar uma função, use a opção nativa de atualizar os funcionários vinculados para aplicar aos usuários existentes.</p><?php endif; ?>
</div></div></div></div><?php init_tail(); ?>
