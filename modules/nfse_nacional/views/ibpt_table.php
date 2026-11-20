<?php
defined('BASEPATH') or exit('No direct script access allowed');

$ibptTable = $nfseArray($ibpt_table ?? []);
$ibptText = static fn($value): string => is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
$ibptDate = static function ($value): string {
    if (!is_string($value) || $value === '') { return '—'; }
    $isDay = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) === 1;
    $format = $isDay ? '!Y-m-d' : '!Y-m-d\TH:i:s\Z';
    $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
    if (!$date || $date->format($isDay ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z') !== $value) { return '—'; }
    return $isDay ? $date->format('d/m/Y') : $date->setTimezone(new DateTimeZone('America/Bahia'))->format('d/m/Y H:i:s');
};
$ibptClasses = ['current' => 'label-success', 'expired' => 'label-warning', 'future' => 'label-info',
    'missing' => 'label-default', 'invalid' => 'label-warning', 'mixed' => 'label-warning'];
$ibptStatus = is_string($ibptTable['status'] ?? null) && isset($ibptClasses[$ibptTable['status']]) ? $ibptTable['status'] : 'missing';
$ibptUf = is_string($ibptTable['uf'] ?? null) && preg_match('/\A[A-Z]{2}\z/D', $ibptTable['uf']) ? $ibptTable['uf'] : '';
$ibptCanRefresh = $ibptUf !== '' && !empty($ibptTable['can_refresh']);
$ibptMessage = is_string($ibptTable['message'] ?? null) ? $ibptTable['message'] : 'Salve a UF do emitente para consultar a tabela.';
$ibptFields = ['version' => 'Versão da tabela', 'key' => 'Chave da fonte', 'source' => 'Fonte',
    'valid_from' => 'Início da vigência', 'valid_to' => 'Fim da vigência', 'fetched_at' => 'Última atualização',
    'last_attempt_at' => 'Última tentativa de atualização', 'next_auto_check_at' => 'Renovação no próximo uso a partir de',
    'service_count' => 'Serviços por NBS disponíveis', 'key_origin_label' => 'Cadastro da chave IBPT'];
$ibptDates = ['valid_from', 'valid_to', 'fetched_at', 'last_attempt_at', 'next_auto_check_at'];
?>
<section aria-labelledby="nfse-ibpt-table-title" data-nfse-ibpt-manager
    data-nfse-ibpt-uf="<?php echo $e($ibptUf); ?>"
    data-nfse-ibpt-provider-state="<?php echo $e($provider['state'] ?? ''); ?>"
    data-nfse-ibpt-provider-municipality="<?php echo $e($provider['municipality_code'] ?? ''); ?>"
    data-nfse-ibpt-allow-older="<?php echo (string) ($settings['ibpt_allow_older_table'] ?? '0') === '1' ? '1' : '0'; ?>"
    data-nfse-ibpt-can-refresh="<?php echo $ibptCanRefresh ? '1' : '0'; ?>"
    data-nfse-ibpt-next-manual-at="<?php echo $e(is_string($ibptTable['next_manual_at'] ?? null) ? $ibptTable['next_manual_at'] : ''); ?>">
    <h4 id="nfse-ibpt-table-title" class="no-margin">Tabela IBPT do emitente</h4>
    <?php $nfseCheckbox('settings[ibpt_show_expired_warning]', 'Exibir aviso discreto de IBPT vencida na tela Documentos', (string) ($settings['ibpt_show_expired_warning'] ?? '1') === '1'); ?>
    <p class="help-block">Habilitado por padrão. Esta opção controla somente a tarja informativa em Documentos; não altera o cálculo, a validação nem a transmissão.</p>
    <?php $nfseCheckbox('settings[ibpt_allow_older_table]', 'Permitir instalar tabela IBPT de versão anterior (API ou CSV)', (string) ($settings['ibpt_allow_older_table'] ?? '0') === '1', ['data-nfse-ibpt-allow-older-toggle' => true]); ?>
    <p class="help-block">Desabilitado por padrão. Ao habilitar, salve as configurações antes de atualizar pela API ou importar CSV. Uma versão anterior escolhida fica protegida contra substituição automática, até a próxima atualização manual.</p>
    <p class="text-muted mtop5 mbot20">A UF é identificada pelo cadastro salvo da empresa emitente. Esta consulta não altera o cadastro nem os documentos já emitidos.</p>
    <div class="row">
        <div class="col-sm-4"><dl><dt>UF do emitente</dt><dd data-nfse-ibpt-table-field="uf"><?php echo $e($ibptText($ibptUf)); ?></dd></dl></div>
        <div class="col-sm-4"><dl><dt>Situação da tabela local</dt><dd><span class="label <?php echo $ibptClasses[$ibptStatus]; ?>" data-nfse-ibpt-table-status><?php echo $e($ibptText($ibptTable['status_label'] ?? 'Ainda não obtida')); ?></span></dd></dl></div>
        <div class="col-sm-4"><dl><dt>Obtenção da tabela</dt><dd data-nfse-ibpt-table-field="acquisition_label"><?php echo $e($ibptText($ibptTable['acquisition_label'] ?? null)); ?></dd></dl></div>
    </div>
    <p class="help-block" data-nfse-ibpt-table-message><?php echo $e($ibptMessage); ?></p>
    <div class="row mtop15">
        <?php foreach ($ibptFields as $ibptField => $ibptLabel):
            $ibptValue = $ibptTable[$ibptField] ?? null;
            $ibptDisplay = in_array($ibptField, $ibptDates, true) ? $ibptDate($ibptValue) : $ibptText($ibptValue);
            if ($ibptField === 'next_auto_check_at' && $ibptDisplay === '—') { $ibptDisplay = !empty($ibptTable['auto_refresh_paused']) ? 'Suspensa para preservar a tabela anterior selecionada' : 'Na próxima emissão com IBPT, se necessário'; }
        ?>
        <div class="col-sm-6 col-md-4"><dl><dt><?php echo $e($ibptLabel); ?></dt><dd class="nfse-preserve-text" data-nfse-ibpt-table-field="<?php echo $e($ibptField); ?>"><?php echo $e($ibptDisplay); ?></dd></dl></div>
        <?php endforeach; ?>
    </div>
    <p class="help-block">Horários de Brasília. A data de atualização informa quando a tabela foi obtida; a vigência informa o período declarado pela fonte. Uma tabela vencida permanece disponível para cálculo e emissão sob responsabilidade do emitente.</p>
    <hr>
    <h4 class="no-mtop">Atualização automática e manual</h4>
    <p>A tabela vigente fica armazenada e é reutilizada nas emissões. A renovação é automática ao preparar ou salvar um rascunho com IBPT quando a tabela estiver ausente ou vencida. Em caso de falha, uma nova tentativa automática será feita no próximo uso após uma hora.</p>
    <p class="help-block">Abrir esta guia não baixa a tabela novamente. Para buscar uma nova versão antes do vencimento, use o botão abaixo.</p>
    <p class="help-block" data-nfse-ibpt-auto-policy><?php echo $e((string) ($ibptTable['auto_policy'] ?? '')); ?></p>
    <div class="alert alert-warning" role="status" data-nfse-ibpt-unsaved hidden>Salve as alterações da UF, município ou permissão de versões anteriores antes de atualizar/importar a tabela.</div>
    <button type="button" class="btn btn-default" data-nfse-ibpt-refresh
        data-nfse-ibpt-refresh-url="<?php echo $e(admin_url('nfse_nacional/ibpt_refresh')); ?>"
        aria-controls="nfse-ibpt-update-feedback"<?php echo $ibptCanRefresh ? '' : ' disabled'; ?>>Atualizar tabela agora</button>
    <p class="help-block" data-nfse-ibpt-manual-wait<?php echo $ibptCanRefresh ? ' hidden' : ''; ?>><?php echo $e($ibptUf === '' ? 'Confira e salve a UF do emitente na guia Emitente.' : 'Aguarde o intervalo entre atualizações antes de tentar novamente.'); ?></p>
    <hr>
    <h4 class="no-mtop">Enviar tabela manualmente</h4>
    <p class="help-block" id="nfse-ibpt-file-help">Selecione o CSV da tabela IBPT. A UF será conferida no conteúdo ou, quando ausente, no nome original. Limite: 8 MB. Tabelas vencidas estruturalmente válidas também podem ser importadas e utilizadas, sob responsabilidade do emitente.</p>
    <div class="form-group">
        <label for="nfse-ibpt-file">Arquivo da tabela IBPT (.csv)</label>
        <input type="file" id="nfse-ibpt-file" class="form-control" accept=".csv,text/csv" data-nfse-ibpt-file
            aria-describedby="nfse-ibpt-file-help nfse-ibpt-file-message"<?php echo $ibptUf === '' ? ' disabled' : ''; ?>>
        <p id="nfse-ibpt-file-message" class="help-block" data-nfse-ibpt-file-message hidden></p>
    </div>
    <div class="form-group">
        <label for="nfse-ibpt-import-key">Cadastrar chave IBPT junto com a tabela (se ausente no CSV)</label>
        <input type="text" id="nfse-ibpt-import-key" class="form-control" maxlength="32" autocomplete="off" spellcheck="false"
            data-nfse-ibpt-import-key aria-describedby="nfse-ibpt-key-help nfse-ibpt-key-message"<?php echo $ibptUf === '' ? ' disabled' : ''; ?>>
        <p id="nfse-ibpt-key-help" class="help-block">Se o arquivo não contiver chave, informe-a aqui. Ela será cadastrada junto da tabela, UF e versão importadas, sem reutilizar a chave de outra tabela. Se o CSV já informar uma chave, o valor digitado deverá ser idêntico.</p>
        <p id="nfse-ibpt-key-message" class="help-block text-danger" data-nfse-ibpt-key-message hidden></p>
    </div>
    <button type="button" class="btn btn-default" data-nfse-ibpt-import
        data-nfse-ibpt-import-url="<?php echo $e(admin_url('nfse_nacional/ibpt_import')); ?>"
        aria-controls="nfse-ibpt-update-feedback" disabled>Importar tabela</button>
    <div id="nfse-ibpt-update-feedback" class="alert mtop15" role="status" aria-live="polite" data-nfse-ibpt-update-feedback hidden></div>
</section>
