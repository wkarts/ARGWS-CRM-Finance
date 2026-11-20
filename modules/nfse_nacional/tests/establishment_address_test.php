<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Reuse the existing bounded in-memory invoice/model/service harness. Its baseline
// runs once here with output suppressed; only the new address assertions are counted.
ob_start();
try { require __DIR__ . '/service_defaults_test.php'; }
finally { ob_end_clean(); }
$checks = 0;

use NfseNacional\Config;
use NfseNacional\DpsBuilder;
use NfseNacional\DraftPreviewDocument;
use NfseNacional\Service;

$address = ['zip' => '40030-170', 'street' => 'Rua do Estabelecimento', 'number' => '0',
    'complement' => 'Sala São José', 'district' => 'Centro', 'city' => 'Salvador', 'state' => 'ba', 'municipality_code' => ''];
$settings = Config::sanitize(['provider' => $address + ['tax_id' => '11222333000181', 'name' => 'Emitente do teste']]);
check($settings['provider']['zip'] === '40030170' && $settings['provider']['state'] === 'BA'
    && $settings['provider']['municipality_code'] === '2927408', 'CEP e UF normalizados, com IBGE resolvido pelo município do estabelecimento');
check($settings['provider']['number'] === '0' && $settings['provider']['complement'] === 'Sala São José',
    'número zero e complemento com acentos são preservados');
$partial = Config::sanitize(['provider' => ['email' => 'novo@example.invalid']], $settings);
check(array_intersect_key($partial['provider'], $address) === array_intersect_key($settings['provider'], $address),
    'salvar outra seção não apaga campos de endereço omitidos');
$cleared = Config::sanitize(['provider' => ['complement' => '']], $settings);
check($cleared['provider']['complement'] === '' && $cleared['provider']['street'] === $settings['provider']['street'],
    'campo de endereço pode ser limpo explicitamente sem limpar o restante');
check(Config::sanitize(['provider' => ['street' => 'Endereço ainda incompleto']])['provider']['zip'] === '',
    'configuração parcial pode ser salva sem inventar CEP');
check(Config::sanitize([])['provider']['street'] === '' && Config::merge(['provider' => ['name' => 'Legado']])['provider']['zip'] === '',
    'configuração antiga recebe novos campos vazios sem migração destrutiva');
$limits = ['street' => 255, 'number' => 60, 'complement' => 156, 'district' => 60, 'city' => 100];
foreach ($limits as $field => $limit) {
    check(Config::sanitize(['provider' => [$field => str_repeat('á', $limit)]])['provider'][$field] === str_repeat('á', $limit),
        'limite de caracteres é preservado em UTF-8: ' . $field);
    refuses(fn() => Config::sanitize(['provider' => [$field => str_repeat('á', $limit + 1)]]), 'excesso não é truncado silenciosamente: ' . $field);
}
foreach ([['zip' => '4003A170'], ['zip' => '4003017'], ['state' => 'ZZ'], ['municipality_code' => '292740'],
    ['municipality_code' => '3550308', 'state' => 'BA'],
    ['municipality_code' => '2910800', 'state' => 'BA', 'city' => 'Salvador'], ['street' => ['endereço']]] as $invalid) {
    refuses(fn() => Config::sanitize(['provider' => $invalid]), 'endereço ou município inconsistente é recusado: ' . implode(',', array_keys($invalid)));
}
$protected = Config::sanitize(['api_url' => 'https://example.invalid', 'provider' => ['api_url' => 'https://example.invalid']], $settings);
check(!isset($protected['api_url'], $protected['provider']['api_url']), 'novos campos não permitem configurar destino externo por formulário');

$model = new DefaultsModel('address_a_');
$model->configuration = Config::sanitize(['provider' => $settings['provider']], $model->configuration);
$model->saveProfile(7, ['district' => 'Sé']);
$id = $model->prepare(41);
$doc = $model->document($id); $draft = $model::decode($doc['draft_json']);
check($draft['provider'] === $model->configuration['provider'], 'preparação pela fatura inclui endereço completo no snapshot do emitente');
$preview = new DraftPreviewDocument($draft, $doc);
foreach (['street' => 'end/xLgr', 'number' => 'end/nro', 'complement' => 'end/xCpl', 'district' => 'end/xBairro',
    'zip' => 'end/endNac/CEP', 'municipality_code' => 'end/endNac/cMun'] as $field => $path) {
    check($preview->dpsText('prest/' . $path) === $draft['provider'][$field], 'prévia utiliza o endereço salvo do emitente: ' . $field);
}
check($preview->accessKey() === '' && $preview->number() === '' && $preview->consultationUrl() === '',
    'endereço local não fabrica chave, número ou QR de autorização');
$model->configuration['provider']['street'] = 'Rua revisada após preparar';
check($model->prepare(41) === $id && $model->document($id)['draft_json'] === $doc['draft_json'],
    'alterar configuração não reescreve rascunho ao abri-lo novamente pela fatura');
$service = new Service($model);
$service->saveDraft($id, [], $doc['draft_hash'], false);
$updatedDoc = $model->document($id); $updatedDraft = $model::decode($updatedDoc['draft_json']);
check($updatedDraft['provider']['street'] === 'Rua revisada após preparar' && $updatedDraft['provider']['zip'] === '40030170',
    'salvar rascunho ainda sem DPS atualiza seu endereço a partir dos parâmetros da empresa');

$builder = new DpsBuilder();
$updatedDraft['issued_at'] = '2026-11-10T10:00:00-03:00';
foreach (['1', '2'] as $environment) {
    $updatedDraft['environment'] = $environment;
    $xml = $builder->build($updatedDraft, '1', '1');
    $dom = new DOMDocument(); $dom->loadXML($xml, LIBXML_NONET); $xp = new DOMXPath($dom); $xp->registerNamespace('n', DpsBuilder::NS);
    check($xp->evaluate('string(/n:DPS/n:infDPS/n:tpEmit)') === '1'
        && $xp->evaluate('count(/n:DPS/n:infDPS/n:prest/n:end)') === 0.0
        && $xp->evaluate('count(/n:DPS/n:infDPS/n:prest/n:xNome)') === 0.0,
        'DPS tpEmit=1 respeita E0121/E0128 e não envia nome/endereço local do prestador no ambiente ' . $environment);
    check($xp->evaluate('string(/n:DPS/n:infDPS/n:cLocEmi)') === '2927408'
        && $xp->evaluate('string(/n:DPS/n:infDPS/n:toma/n:end/n:endNac/n:cMun)') === '3550308',
        'IBGE do estabelecimento e endereço do tomador permanecem distintos no ambiente ' . $environment);
}

$model->updateDocument($id, ['status' => 'rejected', 'dps_id' => DpsBuilder::identifier($updatedDraft['provider'], '1', '1'),
    'dps_series' => '1', 'dps_number' => '1']);
$reservedDoc = $model->document($id);
$reservedProvider = $model::decode($reservedDoc['draft_json'])['provider'];
$model->configuration['provider']['street'] = 'Outro endereço depois da reserva';
$service->saveDraft($id, [], $reservedDoc['draft_hash'], false);
check($model::decode($model->document($id)['draft_json'])['provider'] === $reservedProvider,
    'DPS já reservada conserva snapshot do emitente após alteração de endereço nos parâmetros');
$model->updateDocument($id, ['status' => 'authorized']); $authorizedDoc = $model->document($id);
refuses(fn() => $service->saveDraft($id, [], $authorizedDoc['draft_hash'], false), 'documento autorizado não permite alterar endereço ou regravar rascunho');
check($model->document($id) === $authorizedDoc, 'tentativa de editar autorizado preserva integralmente o documento histórico');

$other = new DefaultsModel('address_b_'); $other->db = $model->db;
$other->configuration['provider'] = array_replace($other->configuration['provider'], [
    'street' => 'Avenida da Empresa B', 'number' => '85', 'zip' => '01001000', 'city' => 'São Paulo', 'state' => 'SP']);
$otherId = $other->prepare(41); $otherDraft = $other::decode($other->document($otherId)['draft_json']);
check($otherDraft['provider']['street'] === 'Avenida da Empresa B' && $otherDraft['provider']['municipality_code'] === '3550308'
    && $model->document($id) === $authorizedDoc, 'outra empresa prepara seu endereço sem acessar ou modificar o histórico da primeira');

echo PHP_EOL . $checks . " verificações de endereço do estabelecimento aprovadas. Sem transmissão ou banco externo.\n";
