<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="pme-profile">
  <details class="pme-advanced"><summary>Dados cadastrais e endereço (opcional)</summary><p class="text-muted pme-help">Informe o CNPJ ou CEP para preencher os dados automaticamente. Você pode revisar e completar o cadastro manualmente.</p>
  <div class="pme-form-grid">
  <?php foreach(['cnpj'=>'CNPJ','legal_name'=>'Razão social','trade_name'=>'Nome fantasia','state_registration'=>'Inscrição estadual','municipal_registration'=>'Inscrição municipal','email'=>'E-mail','phone'=>'Telefone','cep'=>'CEP','street'=>'Logradouro','number'=>'Número','complement'=>'Complemento','district'=>'Bairro','city'=>'Município','state'=>'UF','ibge'=>'Código IBGE','country'=>'País','cnae'=>'CNAE principal','legal_nature'=>'Natureza jurídica','registration_status'=>'Situação cadastral','opening_date'=>'Data de abertura'] as $field=>$label): ?>
    <label class="form-group pme-field"><?= $label ?><input class="form-control" name="profile[<?= $field ?>]" data-profile="<?= $field ?>" <?= $field==='email'?'type="email"':'' ?> maxlength="<?= $field==='cnpj'?18:($field==='cep'?9:250) ?>" <?= in_array($field,['cnpj','cep'],true)?'data-lookup="'.$field.'"':'' ?> autocomplete="off"><?php if(in_array($field,['cnpj','cep'],true)): ?><small class="text-muted" data-lookup-status="<?= $field ?>" aria-hidden="true" hidden>Consultando…</small><?php endif; ?></label>
  <?php endforeach; ?>
  </div><div class="pme-lookup-result text-muted small" role="status" aria-live="polite" hidden></div>
  </details><details class="pme-advanced"><summary>Certificado digital A1 (opcional)</summary><p class="text-muted pme-help">Importe um arquivo PFX/P12 válido. O arquivo e a senha ficam cifrados e não são exibidos depois da importação.</p>
  <div class="pme-certificate-summary"></div><button type="button" class="btn btn-link pme-text-button pme-danger-text pme-remove-certificate" hidden>Remover certificado</button>
  <div class="pme-form-grid"><label class="form-group pme-field">Arquivo .pfx ou .p12 <input type="file" name="certificate_file" accept=".pfx,.p12"></label><label class="form-group pme-field">Senha do certificado <input class="form-control" type="password" name="certificate_password" autocomplete="new-password"></label></div>
</details></div>
