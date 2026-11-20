<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';
init_head();
?>
<div id="wrapper"><div class="content">
    <div class="row mbot20"><div class="col-sm-8"><h3 class="no-margin">NFS-e do cliente</h3><p class="text-muted mtop5"><?php echo $e(is_object($client) ? ($client->company ?? '') : ($client['company'] ?? '')); ?></p></div><div class="col-sm-4 text-right"><a class="btn btn-default" href="<?php echo $e(admin_url('nfse_nacional')); ?>"><i class="fa fa-arrow-left" aria-hidden="true"></i> Voltar</a></div></div>
    <div class="panel_s"><div class="panel-body"><?php require __DIR__ . '/client_tab.php'; ?></div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
