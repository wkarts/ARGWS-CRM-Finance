<?php
declare(strict_types=1);

// The fiscal defaults map deliberately distinguishes inheritance, omission and zero.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\Config;
use NfseNacional\EmissionProfile;

$checks = 0;
function emissionCheck(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
    echo 'OK: ' . $label . PHP_EOL;
}
function emissionRefuses(callable $operation, string $label): void
{
    try { $operation(); } catch (InvalidArgumentException $expected) { emissionCheck(true, $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}

emissionCheck(EmissionProfile::fromRecord([]) === [], 'Cadastro sem preferências fiscais herda todos os campos');
$legacyClient = ['service_code' => '010201', 'municipal_service_code' => '123', 'nbs' => '123456789', 'description' => 'Descrição anterior do cliente'];
$legacy = EmissionProfile::fromRecord($legacyClient);
emissionCheck($legacy['service'] === ['national_code' => '010201', 'municipal_code' => '123', 'nbs' => '123456789', 'description' => 'Descrição anterior do cliente'], 'Campos antigos do cliente são traduzidos sem perder serviço, código municipal, NBS ou descrição');
$legacyRecipient = ['service_code' => '010301', 'description' => 'Descrição anterior do dependente'];
emissionCheck(EmissionProfile::fromRecord($legacyRecipient, true)['service'] === ['national_code' => '010301', 'description' => 'Descrição anterior do dependente'], 'Tomador legado preserva as preferências que já possuía');
emissionCheck(EmissionProfile::fromRecord(array_replace($legacyClient, ['emission_profile' => []])) === [], 'Mapa canônico vazio prevalece sobre campos antigos e volta à herança');
$canonical = ['emission_profile' => ['tax' => ['iss_rate' => '0.00'], 'service' => ['nbs' => '']], 'service_code' => '999999', 'description' => 'Texto antigo inativo'];
emissionCheck(EmissionProfile::fromRecord($canonical) == $canonical['emission_profile'], 'Presença do mapa canônico impede ressuscitar os campos legados');

$sparse = EmissionProfile::sanitize(['tax' => ['iss_rate' => '0'], 'ibscbs' => ['enabled' => '0', 'final_consumer' => '0', 'purpose' => '0']]);
emissionCheck(!isset($sparse['service']) && count($sparse['tax']) === 1 && $sparse['tax']['iss_rate'] === '0.00', 'Sanitização mantém mapa esparso e não materializa campos herdados');
emissionCheck($sparse['ibscbs'] === ['enabled' => '0', 'final_consumer' => '0', 'purpose' => '0'], 'Indicadores zero são escolhas explícitas, inclusive IBS/CBS desabilitado');
$zeroAmounts = EmissionProfile::sanitize(['tax' => ['simple_rate' => '0', 'total_federal' => '0', 'total_state' => '0,00', 'total_municipal' => '0.00']]);
emissionCheck(count(array_filter($zeroAmounts['tax'], static fn($value) => $value === '0.00')) === 4, 'Alíquota do Simples e tributos aproximados preservam zero com precisão decimal');
$omitted = EmissionProfile::sanitize(['service' => ['municipal_code' => '', 'nbs' => ''], 'tax' => ['iss_rate' => '', 'simple_rate' => '']]);
emissionCheck($omitted['service']['municipal_code'] === '' && $omitted['service']['nbs'] === '' && $omitted['tax']['iss_rate'] === '' && $omitted['tax']['simple_rate'] === '', 'Vazio explícito em campo opcional continua registrado para suprimir o valor herdado');
emissionCheck(EmissionProfile::sanitize(['service' => ['national_code' => '01.02.01']])['service']['national_code'] === '010201', 'Perfil utiliza o catálogo nacional e preserva os zeros do código');
emissionCheck(EmissionProfile::sanitize(['tax' => ['iss_rate' => '9.99', 'simple_rate' => '99.99']])['tax'] === ['iss_rate' => '9.99', 'simple_rate' => '99.99'], 'Limites aceitos de alíquota respeitam os formatos XML já suportados');
emissionCheck(EmissionProfile::sanitize(['ibscbs' => ['final_consumer' => '']])['ibscbs']['final_consumer'] === '', 'Indicador opcional pode ser explicitamente omitido');

$previous = ['emission_profile' => ['service' => ['national_code' => '010301'], 'tax' => ['iss_rate' => '0.00'], 'ibscbs' => ['enabled' => '0']]];
emissionCheck(EmissionProfile::fromInput(['label' => 'Alteração cadastral'], $previous, true) === $previous['emission_profile'], 'Edição cadastral sem campos fiscais preserva todo o mapa anterior');
emissionCheck(EmissionProfile::fromInput(['emission_profile_present' => '1'], $previous, true) === [], 'Formulário novo sem override marcado remove o mapa anterior e retorna à herança');
emissionCheck(EmissionProfile::fromInput(['emission_profile' => []], $previous, true) === [], 'Mapa vazio explícito também substitui o anterior sem depender do marcador HTML');
$partialLegacy = EmissionProfile::fromInput(['service_code' => '010201'], $previous, true);
emissionCheck($partialLegacy['service']['national_code'] === '010201' && $partialLegacy['tax'] === $previous['emission_profile']['tax'] && $partialLegacy['ibscbs'] === $previous['emission_profile']['ibscbs'], 'Entrada legada parcial atualiza serviço e preserva tributos próprios previamente salvos');

foreach ([
    ['tax' => '0'],
    ['tax' => ['iss_rate' => ['0']]],
    ['ibscbs' => ['enabled' => false]],
    ['service' => ['national_code' => '999999']],
    ['service' => ['national_code' => '']],
    ['service' => ['municipal_code' => '1234']],
    ['service' => ['nbs' => '12345678']],
    ['tax' => ['iss_rate' => '-1']],
    ['tax' => ['iss_rate' => '10']],
    ['tax' => ['simple_rate' => '100']],
    ['tax' => ['total_federal' => '-0.01']],
    ['tax' => ['retention' => '9']],
    ['tax' => ['total_tax_mode' => 'qualquer']],
    ['ibscbs' => ['enabled' => '2']],
    ['ibscbs' => ['destination_indicator' => '1']],
    ['ibscbs' => ['purpose' => '1']],
] as $index => $invalid) {
    emissionRefuses(static fn() => EmissionProfile::sanitize($invalid), 'Perfil rejeita formato, valor ou opção inválida (' . ($index + 1) . ')');
}
$contextExcluded = false;
try {
    $cleaned = EmissionProfile::sanitize(['provider' => ['tax_id' => '11144477735'], 'environment' => '1', 'series' => '999', 'tax' => ['service_amount' => '0.01'], 'service' => ['national_code' => '010201']]);
    $contextExcluded = !array_intersect(['provider', 'environment', 'series'], array_keys($cleaned)) && !isset($cleaned['tax']['service_amount']);
} catch (InvalidArgumentException $expected) { $contextExcluded = true; }
emissionCheck($contextExcluded, 'Whitelist impede que perfil fiscal altere emitente, ambiente, numeração ou valor da cobrança');

$settings = Config::defaults();
$settings['provider']['municipality_code'] = '3550308';
$settings['service'] = ['municipality_code' => '3304557', 'national_code' => '010101', 'municipal_code' => '111', 'nbs' => '111111111', 'description' => 'Serviço da empresa'];
$settings['tax'] = array_replace($settings['tax'], ['iss_rate' => '5.00', 'total_tax_mode' => 'amount', 'total_federal' => '10.00', 'total_state' => '20.00', 'total_municipal' => '30.00', 'simple_rate' => '4.00']);
$settings['ibscbs'] = array_replace($settings['ibscbs'], ['enabled' => '1', 'operation_indicator' => '100301', 'cst' => '000', 'class_code' => '000001']);
$client = ['emission_profile' => ['service' => ['national_code' => '010201', 'description' => 'Serviço do cliente'], 'tax' => ['iss_rate' => '3.00', 'retention' => '2', 'total_state' => '0.00'], 'ibscbs' => ['final_consumer' => '0']]];
$recipient = ['emission_profile' => ['service' => ['national_code' => '010301', 'municipal_code' => '', 'nbs' => ''], 'tax' => ['iss_rate' => '0.00', 'simple_rate' => '', 'total_municipal' => '0.00'], 'ibscbs' => ['enabled' => '0']]];
$before = serialize([$settings, $client, $recipient]);
$effective = EmissionProfile::resolve($settings, $client, $recipient);
emissionCheck($effective['service']['national_code'] === '010301' && $effective['service']['description'] === 'Serviço do cliente' && $effective['service']['municipality_code'] === '3304557', 'Resolução aplica precedência por campo entre empresa, cliente e tomador');
emissionCheck($effective['service']['municipal_code'] === '' && $effective['service']['nbs'] === '' && $effective['tax']['simple_rate'] === '', 'Omissão explícita do tomador suprime opcionais configurados nos pais');
emissionCheck($effective['tax']['iss_rate'] === '0.00' && $effective['tax']['retention'] === '2' && $effective['tax']['total_state'] === '0.00' && $effective['tax']['total_municipal'] === '0.00' && $effective['tax']['total_federal'] === '10.00', 'Zero explícito coexiste com retenção e valores herdados sem fallback por truthiness');
emissionCheck($effective['ibscbs']['enabled'] === '0' && $effective['ibscbs']['final_consumer'] === '0' && $effective['ibscbs']['cst'] === '000', 'IBS/CBS resolve habilitação e indicadores por campo preservando códigos com zeros');
$complete = true;
foreach (['service', 'tax', 'ibscbs'] as $group) {
    $complete = $complete && array_keys($effective[$group]) === array_keys(Config::defaults()[$group]);
}
emissionCheck(array_keys($effective) === ['service', 'tax', 'ibscbs'] && $complete, 'Resultado efetivo é completo e não inclui emitente, ambiente ou valores de cobrança');
emissionCheck(serialize([$settings, $client, $recipient]) === $before, 'Resolver padrões não altera os cadastros ou a configuração recebidos');

$settings['tax']['total_federal'] = '77.00';
$settings['service']['description'] = 'Descrição atualizada da empresa';
$updated = EmissionProfile::resolve($settings, $client, $recipient);
emissionCheck($updated['tax']['total_federal'] === '77.00' && $updated['service']['description'] === 'Serviço do cliente' && $updated['tax']['iss_rate'] === '0.00', 'Mudança do pai atualiza apenas os campos herdados e preserva escolhas próprias');
$items = [['description' => 'Serviço da fatura', 'long_description' => '<p>Competência mensal</p>']];
$fallback = EmissionProfile::resolve($settings, $client, ['emission_profile' => ['service' => ['municipality_code' => '', 'description' => '']]], $items);
emissionCheck($fallback['service']['municipality_code'] === '3550308', 'Local da prestação vazio resolve o município do emitente');
emissionCheck($fallback['service']['description'] === 'Serviço da fatura Competência mensal', 'Descrição vazia explícita usa os itens da cobrança, sem reativar texto do pai');
$inherited = EmissionProfile::resolve($settings, ['emission_profile' => [], 'service_code' => '010201'], ['emission_profile' => [], 'service_code' => '010301']);
emissionCheck($inherited['service'] === $settings['service'] && $inherited['tax'] === $settings['tax'] && $inherited['ibscbs'] === $settings['ibscbs'], 'Voltar todos os campos à herança elimina os overrides canônicos e legados');

echo PHP_EOL . $checks . " verificações de perfil fiscal e herança aprovadas. Sem transmissão ou PDF.\n";
