<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="row mbot15">
    <div class="col-sm-8">
        <h4 class="no-margin">Tomadores</h4>
        <p class="text-muted mtop5">Cadastre os destinatários fiscais e dependentes vinculados a este cliente.</p>
    </div>
    <div class="col-sm-4 text-right">
        <?php if ($editProfile): ?>
            <button type="button" class="btn btn-primary" data-nfse-new-recipient><i class="fa fa-plus" aria-hidden="true"></i> Novo tomador</button>
        <?php endif; ?>
    </div>
</div>
<p class="help-block mbot20">Cada tomador mantém seu próprio documento, endereço, e-mail e perfil de emissão. Defina o tomador padrão na aba Dados para emissão.</p>
<?php if (!$recipients): ?>
    <div class="alert alert-info">Nenhum tomador adicional cadastrado. O próprio cliente e o contato principal continuam disponíveis na aba Dados para emissão.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table nfse-table">
            <caption class="sr-only">Tomadores fiscais vinculados a este cliente</caption>
            <thead><tr><th scope="col">Tomador</th><th scope="col">CPF / CNPJ</th><th scope="col">E-mail</th><th scope="col">Perfil de emissão</th><?php if ($editProfile): ?><th scope="col" class="text-right">Ações</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($recipients as $recipient):
                $row = $nfseRecipientData($recipient);
                $row['emission_profile'] = \NfseNacional\EmissionProfile::fromRecord($row, true);
                $nfseRecipientProfileService = $row['emission_profile']['service'] ?? [];
                $nfseRecipientOwnService = array_key_exists('national_code', $nfseRecipientProfileService);
                $nfseRecipientServiceCode = $nfseRecipientOwnService
                    ? (string) $nfseRecipientProfileService['national_code']
                    : (string) ($nfseRecipientEmissionDefaults['service']['national_code'] ?? '');
                $nfseRecipientCustomFields = 0;
                foreach ($row['emission_profile'] as $nfseRecipientProfileGroup => $nfseRecipientProfileFields) {
                    foreach ($nfseRecipientProfileFields as $nfseRecipientProfileKey => $_) {
                        if ($nfseRecipientProfileGroup !== 'ibscbs' || !in_array($nfseRecipientProfileKey, ['destination_indicator', 'purpose'], true)) { ++$nfseRecipientCustomFields; }
                    }
                }
            ?>
                <tr>
                    <td>
                        <strong><?php echo $e($row['label'] ?? $row['name'] ?? ''); ?></strong>
                        <div class="small text-muted nfse-subline"><?php echo $e($row['name'] ?? ''); ?></div>
                    </td>
                    <td><?php echo $e($row['tax_id'] ?? ''); ?></td>
                    <td><?php echo $e($row['email'] ?? ''); ?></td>
                    <td class="nfse-service-summary">
                        <span class="label label-default"><?php echo $nfseRecipientCustomFields > 0 ? 'Personalizado' : 'Padrão do cliente / empresa'; ?></span>
                        <?php if ($nfseRecipientServiceCode !== ''): $recipientService = \NfseNacional\ServiceCatalog::find($nfseRecipientServiceCode); ?>
                            <div class="small nfse-subline"><strong><?php echo $e($nfseRecipientServiceCode); ?></strong><?php echo $nfseRecipientOwnService ? ' · próprio' : ' · padrão'; ?></div>
                            <div class="small text-muted nfse-subline"><?php echo $e($recipientService['description'] ?? 'Código salvo anteriormente'); ?></div>
                        <?php else: ?>
                            <div class="small text-muted nfse-subline">Serviço não informado</div>
                        <?php endif; ?>
                    </td>
                    <?php if ($editProfile): ?>
                        <td class="text-right nfse-no-wrap">
                            <button type="button" class="btn btn-default btn-sm" data-nfse-edit-recipient="<?php echo $e(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?>">Editar</button>
                            <button type="button" class="btn btn-default btn-sm" data-nfse-clone-recipient title="Criar uma cópia editável deste tomador">Clonar</button>
                            <button type="button" class="btn btn-default btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/delete_recipient/' . $clientId . '/' . (int) ($row['id'] ?? 0))); ?>" data-nfse-confirm="Excluir este tomador do cadastro do cliente? As notas já emitidas serão preservadas." data-reload="1" aria-label="Excluir tomador"><i class="fa fa-trash" aria-hidden="true"></i></button>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
