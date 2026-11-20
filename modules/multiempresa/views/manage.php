<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<link rel="stylesheet" href="<?= module_dir_url('multiempresa','assets/multiempresa.css'); ?>?v=<?= \Multiempresa\Perfex\Version::current(); ?>">
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
<?php $panel->render($notice,$secret); ?>
</div></div></div></div></div></div>
<?php init_tail(); ?>
<script src="<?= module_dir_url('multiempresa','assets/multiempresa.js'); ?>?v=<?= \Multiempresa\Perfex\Version::current(); ?>"></script>
<script src="<?= module_dir_url('multiempresa','assets/companies.js'); ?>?v=<?= \Multiempresa\Perfex\Version::current(); ?>"></script>
<script src="<?= module_dir_url('multiempresa','assets/administration.js'); ?>?v=<?= \Multiempresa\Perfex\Version::current(); ?>"></script>
<script src="<?= module_dir_url('multiempresa','assets/panel.js'); ?>?v=<?= \Multiempresa\Perfex\Version::current(); ?>" data-panel-api="<?= html_escape(admin_url('multiempresa/staff_choices')); ?>"></script>
</body></html>
