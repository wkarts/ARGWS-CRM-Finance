<?php
defined('BASEPATH') or exit('No direct script access allowed');
// A substitution keeps the original recipient identity. Other document forms
// continue using the shared address component with their normal selection flow.
?>
<p class="help-block" id="nfse-substitution-recipient-lock">O tomador da nota original é preservado. A troca do CPF/CNPJ não está disponível na substituição.</p>
<div class="row">
    <div class="col-sm-6"><?php $nfseInput('draft[recipient][name]', 'Nome / razão social', $recipient['name'] ?? '', ['maxlength' => 150, 'required' => true]); ?></div>
    <div class="col-sm-6"><?php $nfseInput('draft[recipient][tax_id]', 'CPF / CNPJ', $recipient['tax_id'] ?? '', ['maxlength' => 20, 'required' => true, 'readonly' => true, 'aria-describedby' => 'nfse-substitution-recipient-lock']); ?></div>
</div>
<div class="row">
    <div class="col-sm-6"><?php $nfseInput('draft[recipient][email]', 'E-mail para receber a nota', $recipient['email'] ?? '', ['type' => 'email', 'maxlength' => 80]); ?></div>
    <div class="col-sm-6"><?php $nfseInput('draft[recipient][phone]', 'Telefone', $recipient['phone'] ?? '', ['maxlength' => 20]); ?></div>
</div>
<div class="row">
    <div class="col-sm-6"><?php $nfseInput('draft[recipient][municipality_code]', 'Código IBGE do município', $recipient['municipality_code'] ?? '', ['maxlength' => 7, 'inputmode' => 'numeric', 'pattern' => '[0-9]{7}']); ?></div>
    <div class="col-sm-6"><?php $nfseInput('draft[recipient][zip]', 'CEP', $recipient['zip'] ?? '', ['maxlength' => 9, 'inputmode' => 'numeric', 'lookup' => 'cep', 'lookup_prefix' => 'draft[recipient]']); ?></div>
</div>
<div class="row">
    <div class="col-sm-5"><?php $nfseInput('draft[recipient][street]', 'Logradouro', $recipient['street'] ?? '', ['maxlength' => 255]); ?></div>
    <div class="col-sm-2"><?php $nfseInput('draft[recipient][number]', 'Número', $recipient['number'] ?? '', ['maxlength' => 60]); ?></div>
    <div class="col-sm-5"><?php $nfseInput('draft[recipient][district]', 'Bairro', $recipient['district'] ?? '', ['maxlength' => 60]); ?></div>
</div>
<?php $nfseInput('draft[recipient][complement]', 'Complemento', $recipient['complement'] ?? '', ['maxlength' => 156]); ?>
