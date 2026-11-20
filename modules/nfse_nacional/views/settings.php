<?php
defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_ui_helpers.php';
$settings = $nfseArray($settings ?? []);
$provider = $nfseArray($settings['provider'] ?? []);
$service = $nfseArray($settings['service'] ?? []);
$tax = $nfseArray($settings['tax'] ?? []);
$fixedIssRate = (string) ($tax['iss_rate'] ?? '');
$fixedSimpleRate = (string) ($tax['simple_rate'] ?? '');
$rateMonth = (string) ($rate_month ?? (new DateTimeImmutable('now', new DateTimeZone('America/Bahia')))->format('Y-m'));
$monthlyIssRate = is_array($monthly_iss_rate ?? null) ? $monthly_iss_rate : null;
$monthlyRate = is_array($monthly_rate ?? null) ? $monthly_rate : null;
if (($settings['variable_simple_rate'] ?? '0') === '1') { $tax['simple_rate'] = (string) ($monthlyRate['rate'] ?? ''); }
if (($settings['variable_iss_rate'] ?? '0') === '1') { $tax['iss_rate'] = (string) ((($settings['variable_iss_rate_source'] ?? 'iss') === 'simple' ? $monthlyRate : $monthlyIssRate)['rate'] ?? ''); }

$ibscbs = $nfseArray($settings['ibscbs'] ?? []);
$accountingRecipients = array_values($nfseArray($settings['accounting_recipients'] ?? []));
$certificate = $nfseArray($certificate ?? []);
$localCertificate = $nfseArray($local_certificate ?? []);
$certificateState = $nfseArray($certificate_state ?? []);
$authState = $nfseArray($auth_state ?? []);
$certificateAvailable = !empty($certificateState['available']);
$useLocalCertificate = (string) ($settings['use_local_certificate'] ?? '0') === '1';
$diagnostics = $nfseArray($diagnostics ?? []);
$diagnosticWarnings = 0;
foreach ($diagnostics as $diagnostic) { if (!is_array($diagnostic) || empty($diagnostic['ok'])) { ++$diagnosticWarnings; } }
$certificateName = $certificate['name'] ?? $certificate['subject'] ?? '';
$certificateExpiry = $certificate['valid_to'] ?? $certificate['valid_until'] ?? $certificate['expires_at'] ?? '';
?>
<div class="nfse-root nfse-settings" data-nfse-rate-url="<?php echo $e(admin_url('nfse_nacional/competence_rate_lookup')); ?>" data-nfse-rate-month="<?php echo $e($rateMonth); ?>" data-nfse-monthly-rate="<?php echo $e($monthlyRate['rate'] ?? ''); ?>" data-nfse-fixed-rate="<?php echo $e($fixedSimpleRate); ?>" data-nfse-monthly-iss-rate="<?php echo $e($monthlyIssRate['rate'] ?? ''); ?>" data-nfse-fixed-iss-rate="<?php echo $e($fixedIssRate); ?>" data-nfse-settings data-nfse-url="<?php echo $e(admin_url('nfse_nacional/settings')); ?>" data-reload="1"<?php echo $nfseCsrfAttributes(); ?>>
    <p class="text-muted mbot20">Configure a emissão de NFS-e para esta empresa. Os dados salvos serão utilizados nos novos rascunhos.</p>
    <div class="nfse-feedback" role="status" aria-live="polite" hidden></div>
    <ul class="nav nav-tabs" role="tablist" aria-label="Configurações da NFS-e">
        <li role="presentation" class="active"><a id="nfse-settings-issuer-tab" href="#nfse-settings-issuer" aria-controls="nfse-settings-issuer" role="tab" data-toggle="tab">Emitente</a></li>
        <li role="presentation"><a id="nfse-settings-emission-tab" href="#nfse-settings-emission" aria-controls="nfse-settings-emission" role="tab" data-toggle="tab">Emissão</a></li>
        <li role="presentation"><a id="nfse-settings-service-tab" href="#nfse-settings-service" aria-controls="nfse-settings-service" role="tab" data-toggle="tab">Serviço e tributos</a></li>
        <li role="presentation"><a id="nfse-settings-ibpt-tab" href="#nfse-settings-ibpt" aria-controls="nfse-settings-ibpt" role="tab" data-toggle="tab">Tabela IBPT</a></li>
        <li role="presentation"><a id="nfse-settings-certificate-tab" href="#nfse-settings-certificate" aria-controls="nfse-settings-certificate" role="tab" data-toggle="tab">Autenticação API</a></li>
        <li role="presentation"><a id="nfse-settings-accounting-tab" href="#nfse-settings-accounting" aria-controls="nfse-settings-accounting" role="tab" data-toggle="tab">Destinatários de e-mail</a></li>
    </ul>
    <div class="tab-content mtop20">
        <div role="tabpanel" class="tab-pane active" id="nfse-settings-issuer" aria-labelledby="nfse-settings-issuer-tab">
            <h4 class="no-margin">Dados do emitente</h4>
            <p class="text-muted mtop5 mbot20">Confira o cadastro da empresa responsável pela prestação do serviço.</p>
            <div class="row"><div class="col-sm-6"><?php $nfseInput('settings[provider][tax_id]', 'CPF / CNPJ do emitente', $provider['tax_id'] ?? '', ['maxlength' => 20, 'required' => true, 'lookup' => 'cnpj', 'lookup_prefix' => 'settings[provider]']); ?></div><div class="col-sm-6"><?php $nfseInput('settings[provider][name]', 'Nome / razão social', $provider['name'] ?? '', ['maxlength' => 150, 'required' => true]); ?></div></div>
            <p class="help-block">Consultar atualiza os dados cadastrais disponíveis para o CNPJ informado. Confira a inscrição municipal e o enquadramento tributário antes de salvar.</p>
            <div class="row">
                <div class="col-sm-4"><?php $nfseInput('settings[provider][municipal_registration]', 'Inscrição municipal', $provider['municipal_registration'] ?? '', ['maxlength' => 15]); ?></div>
                <div class="col-sm-4"><?php $nfseInput('settings[provider][email]', 'E-mail do emitente', $provider['email'] ?? '', ['type' => 'email', 'maxlength' => 80]); ?></div>
                <div class="col-sm-4"><?php $nfseInput('settings[provider][phone]', 'Telefone', $provider['phone'] ?? '', ['maxlength' => 20]); ?></div>
            </div>
            <hr>
            <section aria-labelledby="nfse-settings-address-title">
                <h4 id="nfse-settings-address-title" class="no-margin">Endereço do estabelecimento</h4>
                <p class="text-muted mtop5 mbot20">Informe o endereço da empresa emitente. Consulte o CNPJ ou o CEP para preencher os dados disponíveis e confira antes de salvar.</p>
                <div class="row">
                    <div class="col-sm-4"><?php $nfseInput('settings[provider][zip]', 'CEP', $provider['zip'] ?? '', ['maxlength' => 9, 'pattern' => '[0-9]{5}-?[0-9]{3}', 'inputmode' => 'numeric', 'lookup' => 'cep', 'lookup_prefix' => 'settings[provider]']); ?></div>
                    <div class="col-sm-8"><?php $nfseInput('settings[provider][street]', 'Logradouro', $provider['street'] ?? '', ['maxlength' => 255]); ?></div>
                </div>
                <div class="row">
                    <div class="col-sm-3"><?php $nfseInput('settings[provider][number]', 'Número', $provider['number'] ?? '', ['maxlength' => 60, 'placeholder' => 'Número ou S/N']); ?></div>
                    <div class="col-sm-5"><?php $nfseInput('settings[provider][complement]', 'Complemento', $provider['complement'] ?? '', ['maxlength' => 156]); ?></div>
                    <div class="col-sm-4"><?php $nfseInput('settings[provider][district]', 'Bairro', $provider['district'] ?? '', ['maxlength' => 60]); ?></div>
                </div>
                <div class="row">
                    <div class="col-sm-6"><?php $nfseInput('settings[provider][city]', 'Município', $provider['city'] ?? '', ['maxlength' => 100]); ?></div>
                    <div class="col-sm-2"><?php $nfseSelect('settings[provider][state]', 'UF', $provider['state'] ?? '', array_combine(array_merge([''], explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO')), array_merge(['Selecione'], explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO')))); ?></div>
                    <div class="col-sm-4"><?php $nfseInput('settings[provider][municipality_code]', 'Código IBGE do município emissor', $provider['municipality_code'] ?? '', ['maxlength' => 7, 'pattern' => '[0-9]{7}', 'inputmode' => 'numeric']); ?></div>
                </div>
                <p class="help-block">O endereço salvo será usado nos novos rascunhos e nas prévias. O DANFSe autorizado utiliza os dados oficiais retornados pelo ambiente nacional.</p>
            </section>
        </div>
        <div role="tabpanel" class="tab-pane" id="nfse-settings-emission" aria-labelledby="nfse-settings-emission-tab">
            <h4 class="no-mtop mbot20">Emissão de documentos</h4>
            <div data-nfse-production-note class="alert alert-warning"<?php echo (string) ($settings['environment'] ?? '2') === '1' ? '' : ' hidden'; ?>>As notas autorizadas em produção têm validade fiscal. Confira o emitente, o tomador e a tributação antes de transmitir.</div>
            <?php $nfseCheckbox('settings[production_enabled]', 'Habilitar transmissão em produção', (string) ($settings['production_enabled'] ?? '0') === '1'); ?>
            <hr><h4>Automações de emissão</h4>
            <div class="row"><?php foreach (['paid' => 'Após o pagamento integral', 'created' => 'Após a criação da fatura'] as $event => $label): $rule = $settings['automations'][$event]; ?>
            <div class="col-sm-6"><h5><?php echo $e($label); ?></h5>
            <?php $nfseSelect('settings[automations][' . $event . '][mode]', 'Clientes e tomadores', $rule['mode'], ['disabled' => 'Desabilitado', 'all' => 'Habilitado para todos', 'selected' => 'Habilitado para selecionados']); ?>
            <?php $nfseInput('settings[automations][' . $event . '][delay_minutes]', 'Intervalo em minutos (0 = imediatamente)', $rule['delay_minutes'], ['type' => 'number', 'min' => 0, 'max' => 43200, 'step' => 1]); ?>
            </div><?php endforeach; ?></div>
            <p class="help-block">As duas regras funcionam juntas. O padrão após criação é 30 minutos. O cron do CRM processa os agendamentos na primeira execução após o prazo, inclusive os imediatos (sem espera adicional). Uma fatura gera uma única NFS-e.</p>
            <?php if (\NfseNacional\AutomationPolicy::enabled($settings)): ?><a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/automation')); ?>">Gerenciar automações e selecionados</a><?php endif; ?>
            <hr>
            <h4 class="no-mtop mbot20">Numeração da DPS</h4>
            <div class="row"><div class="col-sm-6"><?php $nfseInput('settings[series]', 'Série da DPS', $settings['series'] ?? '1', ['type' => 'number', 'min' => 1, 'max' => 49999, 'step' => 1, 'required' => true]); ?></div><div class="col-sm-6"><?php $nfseInput('settings[next_number]', 'Numeração inicial da DPS', $settings['next_number'] ?? '1', ['maxlength' => 15, 'inputmode' => 'numeric', 'pattern' => '[1-9][0-9]{0,14}', 'required' => true]); ?></div></div>
            <p class="help-block">A numeração inicial é usada ao iniciar uma sequência. Números já reservados são preservados.</p>
        </div>
        <div role="tabpanel" class="tab-pane" id="nfse-settings-service" aria-labelledby="nfse-settings-service-tab">
            <h4 class="no-margin">Serviço padrão</h4>
            <p class="text-muted mtop5 mbot20">Utilizado quando o tomador não possui um serviço padrão. Revise os dados da prestação no rascunho.</p>
            <?php $nfseServiceFields('settings[service]', $service, true); ?>
            <hr><h4 class="no-mtop mbot20">Regime tributário</h4>
            <div class="row"><div class="col-sm-6"><?php $nfseSelect('settings[provider][simple_status]', 'Enquadramento no Simples Nacional', $provider['simple_status'] ?? '1', ['1' => 'Não optante', '2' => 'Optante — MEI', '3' => 'Optante — ME / EPP']); ?></div><div class="col-sm-6"><?php $nfseSelect('settings[provider][special_regime]', 'Regime especial de tributação', $provider['special_regime'] ?? '0', ['0' => 'Nenhum', '1' => 'Ato cooperado', '2' => 'Estimativa', '3' => 'Microempresa municipal', '4' => 'Notário ou registrador', '5' => 'Profissional autônomo', '6' => 'Sociedade de profissionais']); ?></div></div>
            <?php $nfseSelect('settings[provider][simple_apportionment]', 'Apuração de tributos pelo Simples (quando aplicável)', $provider['simple_apportionment'] ?? '', ['' => 'Não informar', '1' => 'Tributos federais e ISSQN pelo Simples', '2' => 'Tributos federais pelo Simples e ISSQN fora do Simples', '3' => 'Tributos federais e ISSQN fora do Simples']); ?>
            <hr><h4 class="no-mtop mbot20">ISSQN e tributos aproximados</h4>
            <?php $nfseCheckbox('settings[variable_simple_rate]', 'Usar alíquota cheia variável do Simples Nacional por competência', ($settings['variable_simple_rate'] ?? '0') === '1', ['data-nfse-variable-rate' => true]); ?>
            <?php $nfseCheckbox('settings[variable_iss_rate]', 'Usar alíquota variável do ISSQN por competência', ($settings['variable_iss_rate'] ?? '0') === '1', ['data-nfse-variable-iss-rate' => true]); ?>
            <div class="checkbox checkbox-primary"><input type="hidden" name="settings[variable_iss_rate_source]" value="iss"><input type="checkbox" name="settings[variable_iss_rate_source]" id="nfse_shared_monthly_rate" value="simple" data-nfse-shared-rate<?php echo ($settings['variable_iss_rate_source'] ?? 'iss') === 'simple' ? ' checked' : ''; ?>><label for="nfse_shared_monthly_rate">Utilizar no ISSQN a mesma alíquota cadastrada para o Simples Nacional</label></div>
            <p class="help-block">Ative Simples e ISSQN individualmente ou em conjunto. Para percentuais separados, deixe o compartilhamento desmarcado e cadastre cada guia em Alíquotas por competência. O mês fiscal do documento determina o percentual; um mês necessário sem cadastro impede a emissão.</p>
            <p class="help-block">O compartilhamento utiliza o valor integral, sem conversão. As validações fiscais do ISSQN permanecem aplicáveis; um percentual acima de 5% exige cadastro separado de ISSQN.</p>
            <?php if (nfse_nacional_can('rates_view')): ?><a href="<?php echo $e(admin_url('nfse_nacional/competence_rates')); ?>" class="btn btn-default btn-sm mbot15" data-nfse-rate-manage<?php echo \NfseNacional\CompetenceRate::enabled($settings) ? '' : ' hidden'; ?>>Cadastrar alíquotas por competência</a><?php endif; ?>
            <div data-nfse-rate-consult<?php echo \NfseNacional\CompetenceRate::enabled($settings) ? '' : ' hidden'; ?>>
                <div class="row"><div class="col-sm-4"><?php $nfseInput('rate_competence_display', 'Competência consultada', $rateMonth, ['type' => 'month', 'data-nfse-settings-rate-month' => true]); ?></div></div>
                <p class="help-block" data-nfse-settings-rate-status role="status"></p>
            </div>
            <?php $nfseTaxFields('settings[tax]', $tax); ?>
            <p class="help-block">Esses padrões podem ser personalizados no cadastro de cada tomador e revisados no rascunho. O valor calculado do ISSQN aparece na emissão conforme a base e a alíquota informadas.</p>
            <hr><h4 class="no-mtop mbot20">IBS e CBS</h4>
            <?php $nfseIbsFields('settings[ibscbs]', $ibscbs); ?>
        </div>
        <div role="tabpanel" class="tab-pane" id="nfse-settings-ibpt" aria-labelledby="nfse-settings-ibpt-tab">
            <?php require __DIR__ . '/ibpt_table.php'; ?>
        </div>
        <div role="tabpanel" class="tab-pane" id="nfse-settings-certificate" aria-labelledby="nfse-settings-certificate-tab">
            <h4 class="no-margin">Autenticação da API</h4>
            <div class="row"><div class="col-sm-8"><?php $nfseSelect('settings[environment]', 'Ambiente de emissão', $settings['environment'] ?? '2', ['2' => 'Produção restrita (homologação) — sem validade fiscal', '1' => 'Produção — validade fiscal'], ['data-nfse-environment' => true]); ?></div></div>

            <p class="help-block">O ambiente ativo utiliza o mesmo certificado e as mesmas credenciais. Trocar de ambiente não altera os cadastros.</p>
            <?php $nfseSelect('settings[auth_method]', 'Método de autenticação', $settings['auth_method'] ?? 'certificate', ['certificate' => 'Certificado digital A1', 'username_password' => 'Usuário e senha — indisponível na API nacional']); ?>
            <p class="help-block">As APIs SEFIN/ADN utilizadas exigem certificado A1 em produção e produção restrita. Usuário e senha do portal não autenticam essas operações. Selecionar senha bloqueia a operação com uma mensagem; o método não é trocado automaticamente.</p>
            <?php if (($settings['auth_certificate_conflict'] ?? '0') === '1'): ?><p class="text-warning">Há certificados anteriores diferentes. Escolha abaixo qual será compartilhado ou envie um novo A1. Os certificados anteriores permanecem preservados.</p><?php endif; ?>
            <?php $savedCertificates = ['' => 'Manter cadastro atual']; foreach (['2' => 'A1 anteriormente usado em produção restrita', '1' => 'A1 anteriormente usado em produção'] as $env => $label) { if (!empty($authState[$env]['certificate_available'])) { $savedCertificates[$env] = $label; } } if ($localCertificate) { $savedCertificates['local'] = 'A1 local já cadastrado'; } if (count($savedCertificates) > 1) { $nfseSelect('auth_saved_certificate', 'Aproveitar certificado anterior', '', $savedCertificates); } ?>
            <?php if (!empty($authState['legacy_profiles_differ'])): ?><p class="help-block">Os métodos anteriores por ambiente foram preservados no histórico protegido. O método do ambiente ativo foi adotado no cadastro único; confira sua escolha antes de salvar.</p><?php endif; ?>
            <h4>Usuário e senha</h4>
            <p class="help-block">Cadastro protegido compartilhado, preservado para serviços que aceitem esse método. Não representa autenticação funcional na API nacional atual.</p>
            <div class="row"><div class="col-sm-6"><?php $nfseInput('auth_username', 'Usuário', '', ['maxlength' => 200, 'autocomplete' => 'off', 'placeholder' => 'Deixe em branco para preservar']); ?></div><div class="col-sm-6"><?php $nfseInput('auth_password', 'Senha', '', ['type' => 'password', 'maxlength' => 1024, 'autocomplete' => 'new-password', 'placeholder' => 'Deixe em branco para preservar']); ?></div></div>
            <?php $savedLogins = ['' => 'Manter cadastro atual']; foreach (['2' => 'Credenciais anteriores de produção restrita', '1' => 'Credenciais anteriores de produção'] as $env => $label) { if (!empty($authState[$env]['legacy_login_configured'])) { $savedLogins[$env] = $label; } } if (count($savedLogins) > 1) { $nfseSelect('auth_saved_login', 'Aproveitar credenciais anteriores', '', $savedLogins); } ?>
            <?php $nfseCheckbox('auth_clear_login', 'Excluir usuário e senha cadastrados', false); ?>
            <hr>
            <h4 class="no-margin">Certificado digital A1</h4>
            <p class="text-muted mtop5 mbot20">O certificado disponível para esta empresa é identificado automaticamente.</p>
            <p><span class="label <?php echo $certificateAvailable ? 'label-success' : 'label-warning'; ?>"><?php echo $certificateAvailable ? 'Certificado disponível' : 'Certificado indisponível'; ?></span></p>
            <?php if (!empty($certificateState['message'])): ?><p class="help-block"><?php echo $e($certificateState['message']); ?></p><?php endif; ?>
            <?php if ($certificateAvailable && $certificate): ?><div class="row mtop15">
                <?php if (is_scalar($certificateName) && $certificateName !== ''): ?><div class="col-sm-6"><dl><dt>Titular</dt><dd class="nfse-preserve-text"><?php echo $e($certificateName); ?></dd></dl></div><?php endif; ?>
                <?php if (!empty($certificate['tax_id']) && is_scalar($certificate['tax_id'])): ?><div class="col-sm-6"><dl><dt>CPF / CNPJ</dt><dd><?php echo $e($certificate['tax_id']); ?></dd></dl></div><?php endif; ?>
                <?php if (is_scalar($certificateExpiry) && $certificateExpiry !== ''): ?><div class="col-sm-6"><dl><dt>Válido até</dt><dd><?php echo $e($nfseDate($certificateExpiry)); ?></dd></dl></div><?php endif; ?>
            </div><?php endif; ?>
            <hr>
            <p class="text-muted mtop5 mbot20">Use o certificado da empresa automaticamente ou cadastre um A1 local com prioridade nos dois ambientes.</p>
            <?php if ($localCertificate): ?><p><span class="label label-info">A1 local cadastrado</span></p><?php endif; ?>
            <fieldset id="nfse-local-certificate-fields" data-nfse-local-certificate-fields>
                <div class="row"><div class="col-sm-6"><?php $nfseInput('certificate', $localCertificate ? 'Substituir certificado (.pfx / .p12)' : 'Arquivo do certificado (.pfx / .p12)', '', ['type' => 'file', 'accept' => '.pfx,.p12', 'data-nfse-certificate' => true]); ?></div><div class="col-sm-6"><?php $nfseInput('certificate_password', 'Senha do arquivo selecionado', '', ['type' => 'password', 'autocomplete' => 'new-password', 'maxlength' => 1024]); ?></div></div>
                <p class="help-block">Selecione o A1 do emitente, com até 4 MB. Informe a senha ao selecionar um arquivo. Ela não será exibida novamente.</p>
            </fieldset>
            <input type="hidden" name="settings[use_local_certificate]" value="1">
            <p class="help-block">O certificado local configurado tem prioridade nos dois ambientes. Sem A1 local, o certificado da empresa é aproveitado automaticamente.</p>
            <?php if ($localCertificate): ?><button type="button" class="btn btn-link text-danger nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/remove_certificate')); ?>" data-nfse-confirm="Remover o certificado A1 local cadastrado? A disponibilidade para novas emissões será verificada novamente." data-reload="1">Remover certificado A1 local</button><?php endif; ?>
        </div>
        <div role="tabpanel" class="tab-pane" id="nfse-settings-accounting" aria-labelledby="nfse-settings-accounting-tab">
            <h4 class="no-margin">Destinatários de e-mail</h4>
            <p class="text-muted mtop5 mbot20">Cadastre os contatos que poderão receber os pacotes e relatórios. Inclua, edite ou remova cada destinatário na grid.</p>
            <div data-nfse-email-grid data-save-url="<?php echo $e(admin_url('nfse_nacional/email_recipient_save')); ?>" data-delete-url="<?php echo $e(admin_url('nfse_nacional/email_recipient_delete')); ?>">
                <div class="row mbot15"><div class="col-sm-6"><input class="form-control" type="search" placeholder="Pesquisar nome ou e-mail" aria-label="Pesquisar destinatários" data-nfse-email-search></div><div class="col-sm-6 text-right"><button type="button" class="btn btn-primary" data-nfse-email-add><i class="fa fa-plus" aria-hidden="true"></i> Novo destinatário</button></div></div>
                <div class="table-responsive"><table class="table table-striped table-hover"><thead><tr><th>Nome</th><th>E-mail</th><th>Ações</th></tr></thead><tbody data-nfse-email-body>
                <?php foreach ($accountingRecipients as $emailRecipient): ?>
                    <tr data-id="<?php echo $e($emailRecipient['id']); ?>"><td data-email-name><?php echo $e($emailRecipient['name']); ?></td><td data-email-address><?php echo $e($emailRecipient['email']); ?></td><td><button type="button" class="btn btn-default btn-sm" data-email-edit title="Editar destinatário"><i class="fa fa-pencil" aria-hidden="true"></i><span class="sr-only">Editar</span></button> <button type="button" class="btn btn-danger btn-sm" data-email-delete title="Remover destinatário"><i class="fa fa-trash" aria-hidden="true"></i><span class="sr-only">Remover</span></button></td></tr>
                <?php endforeach; ?>
                </tbody></table></div><div class="text-right mtop10"><button type="button" class="btn btn-default btn-sm" data-email-page-previous>Anterior</button> <span data-email-page-status class="text-muted" aria-live="polite"></span> <button type="button" class="btn btn-default btn-sm" data-email-page-next>Próxima</button></div>
                <p class="help-block" data-nfse-email-count><?php echo count($accountingRecipients); ?> destinatários cadastrados. As alterações desta grid são salvas individualmente.</p>
            </div>
        </div>
    </div>
    <hr>
    <details class="nfse-technical-details">
        <summary>Verificações da instalação<?php if ($diagnosticWarnings > 0): ?> <span class="text-warning">· <?php echo $diagnosticWarnings; ?> <?php echo $diagnosticWarnings === 1 ? 'item para revisar' : 'itens para revisar'; ?></span><?php endif; ?></summary>
        <?php if ($diagnostics): ?><div class="table-responsive mtop15"><table class="table table-condensed"><caption class="sr-only">Requisitos e versão do módulo de NFS-e desta empresa</caption><thead><tr><th scope="col">Verificação</th><th scope="col">Resultado</th></tr></thead><tbody>
            <?php foreach ($diagnostics as $diagnosticKey => $diagnostic):
                $diagnostic = is_array($diagnostic) ? $diagnostic : ['label' => is_string($diagnosticKey) ? $diagnosticKey : 'Verificação', 'message' => (string) $diagnostic];
                $ok = !empty($diagnostic['ok']); ?>
            <tr class="<?php echo $ok ? 'nfse-diagnostic-ok' : 'nfse-diagnostic-attention'; ?>"><td><i class="fa <?php echo $ok ? 'fa-check-circle text-success' : 'fa-exclamation-circle text-warning'; ?>" aria-hidden="true"></i> <?php echo $e($diagnostic['label'] ?? 'Verificação'); ?></td><td><?php echo $e($diagnostic['message'] ?? ($ok ? 'Disponível' : 'Verifique a configuração.')); ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <div class="mtop15"><button type="button" class="btn btn-default btn-sm nfse-action" data-nfse-url="<?php echo $e(admin_url('nfse_nacional/municipality')); ?>" data-nfse-result="municipality">Consultar convênio do município</button>
            <?php if (nfse_nacional_can('logs')): ?><a class="btn btn-default btn-sm" href="<?php echo $e(admin_url('nfse_nacional/logs')); ?>">Ver logs</a><?php endif; ?>
            <p class="help-block">A consulta usa as configurações já salvas.</p></div>
        <pre id="nfse-municipality-result" class="nfse-json-result" hidden></pre>
    </details>
</div>
