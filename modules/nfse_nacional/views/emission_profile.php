<?php
defined('BASEPATH') or exit('No direct script access allowed');
// Inputs supplied by the caller: prefix, sparse saved values, effective defaults and edit permission.
$nfseProfilePrefix = (string) $nfseProfilePrefix;
$nfseProfileValues = is_array($nfseProfileValues ?? null) ? $nfseProfileValues : [];
$nfseProfileReadonly = (bool) ($nfseProfileReadonly ?? false);
$nfseProfileBaseline = \NfseNacional\EmissionProfile::fields();
foreach ($nfseProfileBaseline as $nfseProfileGroup => $nfseProfileFields) {
    $nfseProfileBaseline[$nfseProfileGroup] = array_replace(
        $nfseProfileFields,
        array_intersect_key(is_array($nfseProfileDefaults[$nfseProfileGroup] ?? null) ? $nfseProfileDefaults[$nfseProfileGroup] : [], $nfseProfileFields)
    );
}
$nfseProfileBaseId = 'nfse-emission-profile-' . $nfseProfilePrefix;
$nfseProfileDefaultTitle = $nfseProfilePrefix === 'recipient' ? 'Padrão do cliente / empresa' : 'Padrão da empresa';
$nfseProfileGroups = [
    'service' => ['label' => 'Serviço', 'rows' => [
        ['national_code'], ['municipality_code', 'municipal_code', 'nbs'], ['description'],
    ]],
    'tax' => ['label' => 'ISSQN e tributos', 'rows' => [
        ['iss_taxation', 'retention'], ['iss_rate', 'total_tax_mode'],
        ['total_federal', 'total_state', 'total_municipal'], ['simple_rate'],
    ]],
    'ibscbs' => ['label' => 'IBS e CBS', 'rows' => [
        ['enabled', 'final_consumer'], ['operation_indicator', 'cst', 'class_code'],
    ]],
];
$nfseProfileControls = [
    'service' => [
        'national_code' => ['label' => 'Código de tributação nacional', 'kind' => 'catalog'],
        'municipality_code' => ['label' => 'Município da prestação — código IBGE', 'attributes' => ['maxlength' => 7, 'inputmode' => 'numeric', 'pattern' => '[0-9]{7}'], 'help' => 'Em branco, usa o município do emitente.'],
        'municipal_code' => ['label' => 'Código municipal do serviço', 'attributes' => ['maxlength' => 3, 'inputmode' => 'numeric', 'pattern' => '[0-9]{3}'], 'help' => 'Em branco, não informa código municipal próprio.'],
        'nbs' => ['label' => 'NBS', 'kind' => 'nbs', 'attributes' => ['maxlength' => 9, 'inputmode' => 'numeric', 'pattern' => '[0-9]{9}'], 'help' => 'Quando houver uma tabela IBPT instalada, pesquise pelo código ou descrição. Em branco, não informa NBS próprio.'],
        'description' => ['label' => 'Descrição da prestação do serviço', 'kind' => 'textarea', 'attributes' => ['maxlength' => 2000], 'help' => 'Em branco, usa os itens da fatura.'],
    ],
    'tax' => [
        'iss_taxation' => ['label' => 'Tributação do ISSQN', 'kind' => 'select', 'options' => ['1' => 'Operação tributável', '4' => 'Não incidência']],
        'retention' => ['label' => 'Retenção do ISSQN', 'kind' => 'select', 'options' => ['1' => 'Não retido', '2' => 'Retido pelo tomador', '3' => 'Retido pelo intermediário']],
        'iss_rate' => ['label' => 'Alíquota do ISSQN (%)', 'attributes' => ['type' => 'number', 'min' => 0, 'max' => '9.99', 'step' => '0.01', 'inputmode' => 'decimal'], 'help' => 'De 0 a 9,99%. Em branco, não informa alíquota própria.'],
        'total_tax_mode' => ['label' => 'Tributos aproximados', 'kind' => 'select', 'options' => ['none' => 'Não informar estimativa', 'ibpt' => 'Calcular automaticamente pelo IBPT (NBS)', 'amount' => 'Informar valores', 'simple' => 'Percentual do Simples Nacional'], 'help' => 'O IBPT usa o NBS deste perfil e a UF do emitente. A fonte, a chave e a vigência ficam registradas na nota.'],
        'total_federal' => ['label' => 'Tributos federais (R$)', 'attributes' => ['type' => 'number', 'min' => 0, 'step' => '0.01', 'inputmode' => 'decimal', 'required' => true], 'help' => 'Usado no modo Informar valores. Zero é um valor próprio.'],
        'total_state' => ['label' => 'Tributos estaduais (R$)', 'attributes' => ['type' => 'number', 'min' => 0, 'step' => '0.01', 'inputmode' => 'decimal', 'required' => true], 'help' => 'Usado no modo Informar valores. Zero é um valor próprio.'],
        'total_municipal' => ['label' => 'Tributos municipais (R$)', 'attributes' => ['type' => 'number', 'min' => 0, 'step' => '0.01', 'inputmode' => 'decimal', 'required' => true], 'help' => 'Usado no modo Informar valores. Zero é um valor próprio.'],
        'simple_rate' => ['label' => 'Percentual do Simples Nacional (%)', 'attributes' => ['type' => 'number', 'min' => 0, 'max' => '99.99', 'step' => '0.01', 'inputmode' => 'decimal'], 'help' => 'Para optante ME/EPP, no modo Percentual do Simples Nacional ou no texto da estimativa IBPT. Em branco, não informa percentual próprio.'],
    ],
    'ibscbs' => [
        'enabled' => ['label' => 'Informar IBS e CBS', 'kind' => 'select', 'options' => ['0' => 'Não', '1' => 'Sim']],
        'final_consumer' => ['label' => 'Consumidor final', 'kind' => 'select', 'options' => ['' => 'Não informar', '0' => 'Não', '1' => 'Sim']],
        'operation_indicator' => ['label' => 'Indicador da operação', 'attributes' => ['maxlength' => 6, 'inputmode' => 'numeric', 'pattern' => '[0-9]{6}'], 'help' => 'Informe os seis dígitos do indicador aplicável à operação.'],
        'cst' => ['label' => 'CST do IBS e da CBS', 'attributes' => ['maxlength' => 3, 'inputmode' => 'numeric', 'pattern' => '[0-9]{3}']],
        'class_code' => ['label' => 'Classificação tributária', 'attributes' => ['maxlength' => 6, 'inputmode' => 'numeric', 'pattern' => '[0-9]{6}']],
    ],
];
?>
<div data-nfse-emission-profile data-nfse-profile-prefix="<?php echo $e($nfseProfilePrefix); ?>" data-nfse-profile-defaults="<?php echo $e(json_encode($nfseProfileBaseline, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?>"<?php echo $nfseProfileReadonly ? ' data-nfse-profile-readonly="1"' : ''; ?>>
    <input type="hidden" name="<?php echo $e($nfseProfilePrefix); ?>[emission_profile_present]" value="1">
    <p class="text-muted">Mantenha <strong>Usar padrão</strong> marcado para acompanhar as configurações <?php echo $nfseProfilePrefix === 'recipient' ? 'do cliente e da empresa' : 'da empresa'; ?>. Desmarque apenas os campos que este cadastro deve personalizar.</p>
    <ul class="nav nav-tabs" role="tablist" aria-label="Grupos do perfil de emissão">
        <?php foreach ($nfseProfileGroups as $nfseProfileGroup => $nfseProfileGroupInfo): $nfseProfilePaneId = $nfseProfileBaseId . '-' . $nfseProfileGroup; ?>
            <li role="presentation"<?php echo $nfseProfileGroup === 'service' ? ' class="active"' : ''; ?>><a id="<?php echo $e($nfseProfilePaneId); ?>-tab" href="#<?php echo $e($nfseProfilePaneId); ?>" aria-controls="<?php echo $e($nfseProfilePaneId); ?>" role="tab" data-toggle="tab"><?php echo $e($nfseProfileGroupInfo['label']); ?></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content mtop20">
        <?php foreach ($nfseProfileGroups as $nfseProfileGroup => $nfseProfileGroupInfo): $nfseProfilePaneId = $nfseProfileBaseId . '-' . $nfseProfileGroup; ?>
        <div role="tabpanel" class="tab-pane<?php echo $nfseProfileGroup === 'service' ? ' active' : ''; ?>" id="<?php echo $e($nfseProfilePaneId); ?>" aria-labelledby="<?php echo $e($nfseProfilePaneId); ?>-tab">
            <?php if ($nfseProfileGroup === 'ibscbs'): ?><p class="text-muted mbot20">Destinatário: próprio tomador · Finalidade: emissão normal.</p><?php endif; ?>
            <?php foreach ($nfseProfileGroupInfo['rows'] as $nfseProfileRow): ?>
            <div class="row">
                <?php foreach ($nfseProfileRow as $nfseProfileKey):
                    $nfseProfileControl = $nfseProfileControls[$nfseProfileGroup][$nfseProfileKey];
                    $nfseProfileSavedGroup = is_array($nfseProfileValues[$nfseProfileGroup] ?? null) ? $nfseProfileValues[$nfseProfileGroup] : [];
                    $nfseProfileInherited = !array_key_exists($nfseProfileKey, $nfseProfileSavedGroup);
                    $nfseProfileDefault = (string) $nfseProfileBaseline[$nfseProfileGroup][$nfseProfileKey];
                    $nfseProfileValue = $nfseProfileInherited ? $nfseProfileDefault : (string) $nfseProfileSavedGroup[$nfseProfileKey];
                    $nfseProfileName = $nfseProfilePrefix . '[emission_profile][' . $nfseProfileGroup . '][' . $nfseProfileKey . ']';
                    $nfseProfileFieldId = $nfseProfileBaseId . '-' . $nfseProfileGroup . '-' . $nfseProfileKey;
                    $nfseProfileDefaultText = $nfseProfileDefault;
                    if (array_key_exists($nfseProfileDefault, $nfseProfileControl['options'] ?? [])) {
                        $nfseProfileDefaultText = $nfseProfileControl['options'][$nfseProfileDefault];
                    } elseif (($nfseProfileControl['kind'] ?? '') === 'catalog' && $nfseProfileDefault !== '') {
                        $nfseProfileCatalogItem = \NfseNacional\ServiceCatalog::find($nfseProfileDefault);
                        $nfseProfileDefaultText .= $nfseProfileCatalogItem ? ' — ' . $nfseProfileCatalogItem['description'] : '';
                    }
                    if ($nfseProfileDefaultText === '') { $nfseProfileDefaultText = 'Não informado'; }
                    $nfseProfileAttributes = $nfseProfileControl['attributes'] ?? [];
                    $nfseProfileAttributes['aria-describedby'] = $nfseProfileFieldId . '-default';
                    $nfseProfileColumn = count($nfseProfileRow) === 3 ? 4 : (count($nfseProfileRow) === 2 || $nfseProfileKey === 'simple_rate' ? 6 : 12);
                ?>
                <div class="col-sm-<?php echo $nfseProfileColumn; ?> mbot15" data-nfse-profile-field="<?php echo $e($nfseProfileKey); ?>" data-nfse-profile-group="<?php echo $e($nfseProfileGroup); ?>">
                    <fieldset id="<?php echo $e($nfseProfileFieldId); ?>-value" data-nfse-profile-value aria-describedby="<?php echo $e($nfseProfileFieldId); ?>-default"<?php echo $nfseProfileReadonly || $nfseProfileInherited ? ' disabled' : ''; ?>>
                        <?php if (($nfseProfileControl['kind'] ?? '') === 'catalog') {
                            $nfseServiceSelect($nfseProfileName, $nfseProfileControl['label'], $nfseProfileValue, 'Selecione o serviço');
                        } elseif (($nfseProfileControl['kind'] ?? '') === 'nbs') {
                            $nfseNbsSelect($nfseProfileName, $nfseProfileControl['label'], $nfseProfileValue);
                        } elseif (($nfseProfileControl['kind'] ?? '') === 'select') {
                            $nfseSelect($nfseProfileName, $nfseProfileControl['label'], $nfseProfileValue, $nfseProfileControl['options'], $nfseProfileAttributes);
                        } elseif (($nfseProfileControl['kind'] ?? '') === 'textarea') {
                            $nfseTextArea($nfseProfileName, $nfseProfileControl['label'], $nfseProfileValue, $nfseProfileAttributes);
                        } else {
                            $nfseInput($nfseProfileName, $nfseProfileControl['label'], $nfseProfileValue, $nfseProfileAttributes);
                        } ?>
                    </fieldset>
                    <div class="checkbox checkbox-primary no-mtop mbot5">
                        <input type="checkbox" id="<?php echo $e($nfseProfileFieldId); ?>-inherit" data-nfse-profile-inherit aria-controls="<?php echo $e($nfseProfileFieldId); ?>-value"<?php echo $nfseProfileInherited ? ' checked' : ''; ?><?php echo $nfseProfileReadonly ? ' disabled' : ''; ?>>
                        <label for="<?php echo $e($nfseProfileFieldId); ?>-inherit">Usar padrão<span class="sr-only"> — <?php echo $e($nfseProfileControl['label']); ?></span></label>
                    </div>
                    <p class="help-block no-mtop" id="<?php echo $e($nfseProfileFieldId); ?>-default"><?php echo $e($nfseProfileDefaultTitle); ?>: <span data-nfse-profile-default-label title="<?php echo $e($nfseProfileDefaultText); ?>"><?php echo $e(mb_strlen($nfseProfileDefaultText) > 180 ? mb_substr($nfseProfileDefaultText, 0, 177) . '…' : $nfseProfileDefaultText); ?></span></p>
                    <?php if (isset($nfseProfileControl['help'])): ?><p class="help-block no-mtop"><?php echo $e($nfseProfileControl['help']); ?></p><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
