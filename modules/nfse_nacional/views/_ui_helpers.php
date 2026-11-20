<?php
defined('BASEPATH') or exit('No direct script access allowed');

$e = static function ($value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$nfseArray = static function ($value): array {
    if (is_object($value)) { return (array) $value; }
    return is_array($value) ? $value : [];
};
$nfseValue = static function (array $data, string $key, $default = '') {
    return array_key_exists($key, $data) ? $data[$key] : $default;
};
$nfseStatusClass = static function (string $status): string {
    return ['draft' => 'label-default', 'ready' => 'label-info', 'authorized' => 'label-success', 'cancelled' => 'label-danger', 'rejected' => 'label-danger', 'processing' => 'label-warning', 'uncertain' => 'label-warning'][$status] ?? 'label-default';
};
$nfseMoney = static function ($value): string {
    if ($value === null || $value === '') { return '—'; }
    if (function_exists('app_format_money')) { return (string) app_format_money($value, 'BRL'); }
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
};
$nfseDate = static function ($value, bool $withTime = false): string {
    $value = trim((string) ($value ?? ''));
    if ($value === '' || str_starts_with($value, '0000-00-00')) { return '—'; }
    if ($withTime && function_exists('_dt')) { return (string) _dt($value); }
    if (!$withTime && function_exists('_d')) { return (string) _d($value); }
    try { return (new DateTimeImmutable($value))->format($withTime ? 'd/m/Y H:i' : 'd/m/Y'); }
    catch (Throwable $error) { return $value; }
};
$nfseInput = static function (string $name, string $label, $value = '', array $attributes = []) use ($e): void {
    $id = 'nfse_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    $type = (string) ($attributes['type'] ?? 'text');
    $lookup = in_array($attributes['lookup'] ?? '', ['cnpj', 'cep'], true) ? $attributes['lookup'] : '';
    $lookupPrefix = (string) ($attributes['lookup_prefix'] ?? '');
    unset($attributes['type'], $attributes['lookup'], $attributes['lookup_prefix']);
    echo '<div class="form-group"><label for="' . $e($id) . '">' . $e($label) . '</label>';
    if ($lookup !== '' && $lookupPrefix !== '') { echo '<div class="input-group">'; }
    echo '<input class="form-control" id="' . $e($id) . '" name="' . $e($name) . '" type="' . $e($type) . '" value="' . $e($value) . '"';
    foreach ($attributes as $key => $attribute) {
        if ($attribute === false || $attribute === null) { continue; }
        if ($attribute === true) { echo ' ' . $e($key); }
        else { echo ' ' . $e($key) . '="' . $e($attribute) . '"'; }
    }
    echo '>';
    if ($lookup !== '' && $lookupPrefix !== '') {
        echo '<span class="input-group-btn"><button type="button" class="btn btn-default" data-nfse-lookup="' . $e($lookup) . '" data-nfse-lookup-url="' . $e(admin_url('nfse_nacional/lookup/' . $lookup)) . '" data-nfse-lookup-prefix="' . $e($lookupPrefix) . '" title="Consultar ' . strtoupper($lookup) . '" aria-controls="' . $e($id) . '_lookup_feedback"><i class="fa fa-search" aria-hidden="true"></i> Consultar</button></span></div>';
        echo '<div id="' . $e($id) . '_lookup_feedback" class="help-block" data-nfse-lookup-feedback data-nfse-lookup-prefix="' . $e($lookupPrefix) . '" role="status" aria-live="polite" hidden></div>';
    }
    echo '</div>';
};
$nfseSelect = static function (string $name, string $label, $selected, array $options, array $attributes = []) use ($e): void {
    $id = 'nfse_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    echo '<div class="form-group"><label for="' . $e($id) . '">' . $e($label) . '</label><select class="form-control" id="' . $e($id) . '" name="' . $e($name) . '"';
    foreach ($attributes as $key => $attribute) {
        if ($attribute === false || $attribute === null) { continue; }
        if ($attribute === true) { echo ' ' . $e($key); }
        else { echo ' ' . $e($key) . '="' . $e($attribute) . '"'; }
    }
    echo '>';
    foreach ($options as $key => $text) {
        echo '<option value="' . $e($key) . '"' . ((string) $selected === (string) $key ? ' selected' : '') . '>' . $e($text) . '</option>';
    }
    echo '</select></div>';
};
$nfseServiceSelect = static function (string $name, string $label, $selected = '', string $blankLabel = 'Selecione o serviço') use ($e): void {
    $id = 'nfse_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    $selected = (string) $selected;
    $services = \NfseNacional\ServiceCatalog::all();
    echo '<div class="form-group nfse-service-field"><label for="' . $e($id) . '">' . $e($label) . '</label>';
    echo '<select class="form-control selectpicker nfse-service-catalog" id="' . $e($id) . '" name="' . $e($name) . '" data-nfse-service-catalog data-live-search="true" data-live-search-normalize="true" data-width="100%" data-size="8" data-none-results-text="Nenhum serviço encontrado para {0}" aria-describedby="' . $e($id) . '_help">';
    echo '<option value=""' . ($selected === '' ? ' selected' : '') . '>' . $e($blankLabel) . '</option>';
    $found = false;
    foreach ($services as $serviceItem) {
        $code = (string) $serviceItem['code'];
        $formatted = substr($code, 0, 2) . '.' . substr($code, 2, 2) . '.' . substr($code, 4, 2);
        $testOnly = isset($serviceItem['environments']) && !in_array('1', $serviceItem['environments'], true);
        $isSelected = $selected === $code;
        $found = $found || $isSelected;
        echo '<option value="' . $e($code) . '" data-tokens="' . $e($code . ' ' . $formatted . ' ' . $serviceItem['description']) . '"' . ($testOnly ? ' data-nfse-test-service="1"' : '') . ($isSelected ? ' selected' : '') . '>' . $e($formatted . ' — ' . $serviceItem['description'] . ($testOnly ? ' (somente testes)' : '')) . '</option>';
    }
    // Preserve a historical value for review; never silently replace an existing fiscal code.
    if ($selected !== '' && !$found) {
        echo '<option value="' . $e($selected) . '" selected data-nfse-legacy-service>' . $e($selected . ' — Código salvo anteriormente, não localizado no catálogo') . '</option>';
    }
    echo '</select><p class="help-block" id="' . $e($id) . '_help">Pesquise pelo código ou pela descrição do serviço no catálogo nacional.</p></div>';
};
$nfseNbsSelect = static function (string $name, string $label, $selected = '') use ($e, $nfseInput): void {
    // The table is already linked to the issuer's UF on installation. No UF picker or API call.
    static $services = null;
    if ($services === null) {
        $services = [];
        try {
            $settings = nfse_nacional_model()->settings();
            $uf = \NfseNacional\DraftTax::state($settings['provider'] ?? []);
            $services = \NfseNacional\IbptTable::current()->services($uf);
        } catch (\Throwable $ignored) {
            // A missing/invalid table must never block forms or fiscal work.
            $services = [];
        }
    }
    $selected = (string) $selected;
    $id = 'nfse_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    if ($services === []) {
        $nfseInput($name, $label, $selected, ['maxlength' => 9, 'inputmode' => 'numeric', 'pattern' => '[0-9]{9}']);
        echo '<p class="help-block nfse-nbs-help">Sem tabela IBPT instalada: informe manualmente o NBS de 9 dígitos. A lista ficará disponível após a importação ou atualização da tabela.</p>';
        return;
    }
    echo '<div class="form-group nfse-service-field nfse-nbs-field"><label for="' . $e($id) . '">' . $e($label) . '</label>';
    echo '<select class="form-control selectpicker nfse-nbs-catalog" id="' . $e($id) . '" name="' . $e($name) . '" data-nfse-nbs-catalog data-live-search="true" data-live-search-normalize="true" data-width="100%" data-size="8" data-none-results-text="Nenhum NBS encontrado para {0}" aria-describedby="' . $e($id) . '_help">';
    echo '<option value=""' . ($selected === '' ? ' selected' : '') . '>Selecione o NBS (opcional)</option>';
    $found = false;
    foreach ($services as $service) {
        $code = (string) $service['code'];
        $description = (string) $service['description'];
        $isSelected = $code === $selected;
        $found = $found || $isSelected;
        echo '<option value="' . $e($code) . '" data-tokens="' . $e($code . ' ' . $description) . '"'
            . ($isSelected ? ' selected' : '') . '>' . $e($code . ' — ' . $description) . '</option>';
    }
    // Retain existing settings/drafts if the installed table was replaced by another edition.
    if ($selected !== '' && !$found) {
        echo '<option value="' . $e($selected) . '" selected data-nfse-legacy-service>'
            . $e($selected . ' — NBS salvo anteriormente, não localizado nesta tabela IBPT') . '</option>';
    }
    echo '</select><p class="help-block" id="' . $e($id) . '_help">'
        . count($services) . ' serviços NBS da tabela IBPT instalada. Pesquise pelo código ou pela descrição.</p></div>';
};
$nfseTextArea = static function (string $name, string $label, $value = '', array $attributes = []) use ($e): void {
    $id = 'nfse_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    echo '<div class="form-group"><label for="' . $e($id) . '">' . $e($label) . '</label><textarea class="form-control" rows="3" id="' . $e($id) . '" name="' . $e($name) . '"';
    foreach ($attributes as $key => $attribute) {
        if ($attribute === true) { echo ' ' . $e($key); }
        elseif ($attribute !== false && $attribute !== null) { echo ' ' . $e($key) . '="' . $e($attribute) . '"'; }
    }
    echo '>' . $e($value) . '</textarea></div>';
};
$nfseCheckbox = static function (string $name, string $label, $checked = false, array $attributes = []) use ($e): void {
    $id = 'nfse_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    echo '<input type="hidden" name="' . $e($name) . '" value="0"><div class="checkbox checkbox-primary"><input type="checkbox" id="' . $e($id) . '" name="' . $e($name) . '" value="1"' . ($checked ? ' checked' : '');
    foreach ($attributes as $key => $attribute) {
        if ($attribute === true) { echo ' ' . $e($key); }
        elseif ($attribute !== false && $attribute !== null) { echo ' ' . $e($key) . '="' . $e($attribute) . '"'; }
    }
    echo '><label for="' . $e($id) . '">' . $e($label) . '</label></div>';
};
$nfseRecipientData = static function ($row) use ($nfseArray): array {
    $row = $nfseArray($row);
    $details = [];
    if (isset($row['data']) && is_array($row['data'])) { $details = $row['data']; }
    elseif (isset($row['data_json']) && is_string($row['data_json'])) { $details = json_decode($row['data_json'], true) ?: []; }
    return array_merge($row, $details);
};
$nfseAddress = static function (string $prefix, array $data) use ($nfseInput, $nfseValue): void {
    echo '<div class="row">';
    echo '<div class="col-sm-6">'; $nfseInput($prefix . '[name]', 'Nome / razão social', $nfseValue($data, 'name'), ['maxlength' => 150, 'required' => true]); echo '</div>';
    echo '<div class="col-sm-6">'; $nfseInput($prefix . '[tax_id]', 'CPF / CNPJ', $nfseValue($data, 'tax_id'), ['maxlength' => 20, 'required' => true, 'autocomplete' => 'off', 'lookup' => 'cnpj', 'lookup_prefix' => $prefix]); echo '</div>';
    echo '</div><div class="row">';
    echo '<div class="col-sm-6">'; $nfseInput($prefix . '[email]', 'E-mail para receber a nota', $nfseValue($data, 'email'), ['type' => 'email', 'maxlength' => 80]); echo '</div>';
    echo '<div class="col-sm-6">'; $nfseInput($prefix . '[phone]', 'Telefone', $nfseValue($data, 'phone'), ['maxlength' => 20]); echo '</div>';
    echo '</div><div class="row">';
    echo '<div class="col-sm-6">'; $nfseInput($prefix . '[municipality_code]', 'Código IBGE do município', $nfseValue($data, 'municipality_code'), ['maxlength' => 7, 'inputmode' => 'numeric', 'pattern' => '[0-9]{7}']); echo '</div>';
    echo '<div class="col-sm-6">'; $nfseInput($prefix . '[zip]', 'CEP', $nfseValue($data, 'zip'), ['maxlength' => 9, 'inputmode' => 'numeric', 'lookup' => 'cep', 'lookup_prefix' => $prefix]); echo '</div>';
    echo '</div><div class="row">';
    echo '<div class="col-sm-5">'; $nfseInput($prefix . '[street]', 'Logradouro', $nfseValue($data, 'street'), ['maxlength' => 255]); echo '</div>';
    echo '<div class="col-sm-2">'; $nfseInput($prefix . '[number]', 'Número', $nfseValue($data, 'number'), ['maxlength' => 60]); echo '</div>';
    echo '<div class="col-sm-5">'; $nfseInput($prefix . '[district]', 'Bairro', $nfseValue($data, 'district'), ['maxlength' => 60]); echo '</div>';
    echo '</div>';
    $nfseInput($prefix . '[complement]', 'Complemento', $nfseValue($data, 'complement'), ['maxlength' => 156]);
};
$nfseServiceFields = static function (string $prefix, array $data, bool $defaults = false) use ($nfseInput, $nfseServiceSelect, $nfseNbsSelect, $nfseTextArea, $nfseValue): void {
    $nfseServiceSelect($prefix . '[national_code]', $defaults ? 'Serviço padrão — código de tributação nacional' : 'Serviço — código de tributação nacional', $nfseValue($data, 'national_code'));
    echo '<div class="row">';
    echo '<div class="col-sm-4">'; $nfseInput($prefix . '[municipal_code]', 'Código municipal', $nfseValue($data, 'municipal_code'), ['maxlength' => 3]); echo '</div>';
    echo '<div class="col-sm-4">'; $nfseNbsSelect($prefix . '[nbs]', 'NBS', $nfseValue($data, 'nbs')); echo '</div>';
    echo '<div class="col-sm-4">'; $nfseInput($prefix . '[municipality_code]', 'IBGE da prestação', $nfseValue($data, 'municipality_code'), ['maxlength' => 7, 'inputmode' => 'numeric', 'pattern' => '[0-9]{7}']); echo '</div>';
    echo '</div>';
    $nfseTextArea($prefix . '[description]', $defaults ? 'Descrição padrão da prestação do serviço' : 'Descrição da prestação do serviço na nota', $nfseValue($data, 'description'), ['maxlength' => 2000]);
    echo '<p class="help-block">Descreva a prestação realizada. Este texto será utilizado na NFS-e e pode ser diferente da descrição oficial do catálogo.</p>';
};
$nfseTaxFields = static function (string $prefix, array $data) use ($nfseInput, $nfseSelect, $nfseValue): void {
    $totalTaxMode = (string) $nfseValue($data, 'total_tax_mode', 'none');
    echo '<div class="row">';
    echo '<div class="col-sm-5">'; $nfseSelect($prefix . '[iss_taxation]', 'Tributação do ISSQN', $nfseValue($data, 'iss_taxation', '1'), ['1' => 'Operação tributável', '2' => 'Imunidade', '3' => 'Exportação de serviço', '4' => 'Não incidência']); echo '</div>';
    echo '<div class="col-sm-4">'; $nfseSelect($prefix . '[retention]', 'Retenção do ISSQN', $nfseValue($data, 'retention', '1'), ['1' => 'Não retido', '2' => 'Retido pelo tomador', '3' => 'Retido pelo intermediário']); echo '</div>';
    echo '<div class="col-sm-3">'; $nfseInput($prefix . '[iss_rate]', 'Alíquota ISSQN (%)', $nfseValue($data, 'iss_rate'), ['inputmode' => 'decimal', 'maxlength' => 7, 'placeholder' => 'Quando exigida']); echo '</div>';
    echo '</div><p class="help-block">A alíquota do ISSQN é própria da operação. O percentual municipal do IBPT informa a carga tributária aproximada e não substitui esta alíquota.</p><div class="nfse-tax-group" data-nfse-tax><div class="row"><div class="col-sm-6">';
    $nfseSelect($prefix . '[total_tax_mode]', 'Tributos aproximados', $nfseValue($data, 'total_tax_mode', 'none'), ['none' => 'Não informar estimativa', 'ibpt' => 'Calcular automaticamente pelo IBPT (NBS)', 'amount' => 'Informar valores', 'simple' => 'Percentual do Simples Nacional'], ['data-nfse-tax-mode' => true]);
    echo '</div><div class="col-sm-6" data-nfse-tax-show="simple ibpt"' . (in_array($totalTaxMode, ['simple', 'ibpt'], true) ? '' : ' hidden') . '>';
    $nfseInput($prefix . '[simple_rate]', 'Percentual do Simples Nacional (%)', $nfseValue($data, 'simple_rate'), ['inputmode' => 'decimal', 'maxlength' => 7]);
    echo '<p class="help-block">Para optante ME/EPP. No cálculo pelo IBPT, este percentual também compõe a informação complementar da nota.</p>';
    echo '</div></div><div class="row" data-nfse-tax-show="amount"' . ($totalTaxMode === 'amount' ? '' : ' hidden') . '>';
    foreach (['total_federal' => 'Federais (R$)', 'total_state' => 'Estaduais (R$)', 'total_municipal' => 'Municipais (R$)'] as $field => $label) {
        echo '<div class="col-sm-4">'; $nfseInput($prefix . '[' . $field . ']', $label, $nfseValue($data, $field, '0.00'), ['inputmode' => 'decimal', 'maxlength' => 18]); echo '</div>';
    }
    echo '</div><div data-nfse-tax-show="ibpt"' . ($totalTaxMode === 'ibpt' ? '' : ' hidden') . '><p class="help-block">A tabela é selecionada pela UF do emitente e pelo NBS do serviço. Os tributos federais, estaduais e municipais são calculados ao preparar ou salvar o rascunho, com a chave da fonte e a vigência da tabela. Informe o NBS na seção de serviço.</p></div></div>';
};
$nfseIbsFields = static function (string $prefix, array $data) use ($nfseInput, $nfseSelect, $nfseCheckbox, $nfseValue): void {
    $enabled = (string) $nfseValue($data, 'enabled', '0') === '1';
    echo '<div data-nfse-ibs>';
    $nfseCheckbox($prefix . '[enabled]', 'Informar enquadramento de IBS e CBS nesta operação', $enabled, ['data-nfse-ibs-toggle' => true]);
    echo '<div data-nfse-ibs-fields' . ($enabled ? '' : ' hidden') . '><p class="text-muted">Informe os códigos aplicáveis ao serviço. O módulo valida o leiaute do ambiente selecionado.</p><div class="row">';
    echo '<div class="col-sm-4">'; $nfseInput($prefix . '[operation_indicator]', 'Indicador da operação', $nfseValue($data, 'operation_indicator'), ['maxlength' => 6, 'inputmode' => 'numeric']); echo '</div>';
    echo '<div class="col-sm-4">'; $nfseInput($prefix . '[cst]', 'CST IBS/CBS', $nfseValue($data, 'cst'), ['maxlength' => 3, 'inputmode' => 'numeric']); echo '</div>';
    echo '<div class="col-sm-4">'; $nfseInput($prefix . '[class_code]', 'cClassTrib', $nfseValue($data, 'class_code'), ['maxlength' => 6, 'inputmode' => 'numeric']); echo '</div>';
    echo '</div><div class="row">';
    echo '<div class="col-sm-4">'; $nfseSelect($prefix . '[destination_indicator]', 'Destinatário', $nfseValue($data, 'destination_indicator', '0'), ['0' => 'Próprio tomador']); echo '</div>';
    echo '<div class="col-sm-4">'; $nfseSelect($prefix . '[final_consumer]', 'Consumidor final', $nfseValue($data, 'final_consumer', '1'), ['1' => 'Sim', '0' => 'Não']); echo '</div>';
    echo '<div class="col-sm-4">'; $nfseSelect($prefix . '[purpose]', 'Finalidade', $nfseValue($data, 'purpose', '0'), ['0' => 'Emissão normal']); echo '</div>';
    echo '</div></div></div>';
};
$nfseCsrfAttributes = static function () use ($e): string {
    $ci = &get_instance();
    return ' data-csrf-name="' . $e($ci->security->get_csrf_token_name()) . '" data-csrf-hash="' . $e($ci->security->get_csrf_hash()) . '"';
};
