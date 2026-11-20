<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<nav class="pme-navigation mbot20" aria-label="Multiempresa"><ul class="nav nav-tabs">
<?php foreach(['companies'=>'Empresas','organizations'=>'Organizações','settings'=>'Configurações globais','logs'=>'Logs','access'=>'Usuários e acessos','modules'=>'Módulos','integrations'=>'Integrações','events'=>'Eventos','updates'=>'Atualizações e backups','audit'=>'Auditoria'] as $key=>$label): ?>
<li class="<?= $key===($tab??'companies')?'active':'' ?>"><a href="?tab=<?= $key ?>" <?= $key===($tab??'companies')?'aria-current="page"':'' ?>><?= $label ?></a></li>
<?php endforeach; ?></ul></nav>
