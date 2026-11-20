<?php
defined('BASEPATH') or exit('No direct script access allowed');
$document_tab = in_array($document_tab ?? '', ['crm', 'issued', 'received', 'accounting'], true) ? $document_tab : 'crm';
$documentTabsEnvironment = isset($filters['environment']) && in_array((string) $filters['environment'], ['1', '2'], true)
    ? (string) $filters['environment'] : '';
$documentTabsQuery = $documentTabsEnvironment !== '' ? '?environment=' . $documentTabsEnvironment : '';
$documentTabs = [];
foreach (['crm' => ['No CRM', 'nfse_nacional'], 'issued' => ['Emitidas no ambiente nacional', 'nfse_nacional/national/issued'], 'received' => ['Recebidas', 'nfse_nacional/national/received'], 'accounting' => ['Pacote mensal', 'nfse_nacional/accounting']] as $type => $tab) {
    if (nfse_nacional_can(\NfseNacional\Permissions::reportCapability($type))) { $documentTabs[$type] = ['label' => $tab[0], 'url' => admin_url($tab[1]) . $documentTabsQuery]; }
}
?>
<nav aria-label="Documentos da NFS-e">
    <ul class="nav nav-tabs mbot20">
        <?php foreach ($documentTabs as $documentTabKey => $documentTab): ?>
        <li<?php echo $document_tab === $documentTabKey ? ' class="active"' : ''; ?>><a href="<?php echo $e($documentTab['url']); ?>"<?php echo $document_tab === $documentTabKey ? ' aria-current="page"' : ''; ?>><?php echo $e($documentTab['label']); ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>
