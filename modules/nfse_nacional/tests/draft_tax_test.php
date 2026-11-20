<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
use NfseNacional\{Config, DraftTax, EmissionProfile, Validator};
$checks = 0;
function taxDraftCheck(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException('FALHOU: ' . $label); } ++$GLOBALS['checks']; }
function taxDraftRefuses(callable $action, string $label): void {
    try { $action(); } catch (Throwable $error) { taxDraftCheck(true, $label); return; }
    throw new RuntimeException('FALHOU: ' . $label);
}
$day = (new DateTimeImmutable('now', new DateTimeZone('America/Bahia')))->format('Y-m-d');
$snapshot = ['uf' => 'BA', 'nbs' => '115011000', 'description' => 'SERVIÇO SINTÉTICO',
    'nacionalfederal' => '13.4500', 'estadual' => '0.0000', 'municipal' => '5.0000',
    'valid_from' => $day, 'valid_to' => $day, 'chave' => 'TEST071', 'versao' => 'TEST.071', 'fonte' => 'IBPT/empresometro.com.br',
    'fetched_at' => gmdate('Y-m-d\TH:i:s\Z'), 'source_hash' => hash('sha256', 'fixture-tax-draft')];
$draft = ['environment' => '2', 'competence' => '2020-01-01',
    'provider' => ['state' => 'BA', 'municipality_code' => '2927408', 'simple_status' => '3'],
    'service' => ['nbs' => '115011000', 'municipality_code' => '3550308', 'description' => 'Descrição preservada'],
    'values' => ['total_tax_mode' => 'ibpt', 'service_amount' => '262.00', 'unconditional_discount' => '10.00', 'conditional_discount' => '20.00',
        'total_federal' => '0.00', 'total_state' => '0.00', 'total_municipal' => '0.00', 'simple_rate' => '3.00', 'iss_rate' => '3.00', 'iss_taxation' => '1', 'retention' => '2']];
$calls = [];
$lookup = static function (string $uf, string $nbs, string $date) use (&$calls, $snapshot): array {
    $calls[] = [$uf, $nbs, $date]; return array_replace($snapshot, ['uf' => $uf, 'nbs' => $nbs]);
};
$ready = DraftTax::refresh($draft, $lookup);
taxDraftCheck($calls === [['BA', '115011000', $day]], 'UF do emitente e dia de emissão controlam a consulta, não a competência nem o município da prestação');
taxDraftCheck($ready['values']['total_federal'] === '33.89' && $ready['values']['total_municipal'] === '12.60' && $ready['values']['total_state'] === '0.00', 'Valores derivados são persistidos em centavos, inclusive zero estadual');
taxDraftCheck($ready['ibpt']['chave'] === 'TEST071' && $ready['ibpt']['versao'] === 'TEST.071' && !isset($ready['ibpt_error']), 'Chave e versão são preservadas por documento');
taxDraftCheck($ready['service'] === $draft['service'] && $ready['provider'] === $draft['provider'], 'Cálculo não modifica descrição, serviço ou emitente');
$same = DraftTax::refresh($ready, $lookup);
taxDraftCheck(count($calls) === 1 && $same === $ready, 'Snapshot válido permite revisão idempotente sem nova requisição');
$changed = $ready; $changed['values']['service_amount'] = '514.00';
$changed = DraftTax::refresh($changed, $lookup);
taxDraftCheck(count($calls) === 1 && $changed['values']['total_federal'] === '67.79' && $changed['values']['total_municipal'] === '25.20', 'Alteração do valor recalcula sem duplicar texto ou baixar novamente a tabela');
$changed['service']['nbs'] = '115012000';
$changed = DraftTax::refresh($changed, $lookup);
taxDraftCheck(count($calls) === 2 && $changed['ibpt']['nbs'] === '115012000', 'Troca de NBS invalida a estimativa do serviço anterior');
$changed['provider']['state'] = 'SP'; $changed['provider']['municipality_code'] = '3550308';
$changed = DraftTax::refresh($changed, $lookup);
taxDraftCheck(count($calls) === 3 && $changed['ibpt']['uf'] === 'SP', 'Troca de estabelecimento não reaproveita alíquotas da UF anterior');
$mismatch = $ready; $mismatch['provider']['state'] = 'SP';
$mismatch = DraftTax::refresh($mismatch, $lookup);
taxDraftCheck(count($calls) === 3 && !isset($mismatch['ibpt']) && str_contains($mismatch['ibpt_error'], 'IBGE'), 'Conflito UF/IBGE bloqueia antes de consultar outra tabela');
$derived = $draft; unset($derived['provider']['state']);
$derived = DraftTax::refresh($derived, $lookup);
taxDraftCheck(isset($derived['ibpt']) && $derived['ibpt']['uf'] === 'BA' && DraftTax::ibpt($derived)['total_federal'] === '33.89', 'Cadastro legado deriva UF do IBGE e calcula sem depender de campo novo');
foreach (['RO'=>'11','AC'=>'12','AM'=>'13','RR'=>'14','PA'=>'15','AP'=>'16','TO'=>'17','MA'=>'21','PI'=>'22','CE'=>'23','RN'=>'24','PB'=>'25','PE'=>'26','AL'=>'27','SE'=>'28','BA'=>'29','MG'=>'31','ES'=>'32','RJ'=>'33','SP'=>'35','PR'=>'41','SC'=>'42','RS'=>'43','MS'=>'50','MT'=>'51','GO'=>'52','DF'=>'53'] as $uf => $prefix) {
    taxDraftCheck(DraftTax::state(['municipality_code' => $prefix . '00000']) === $uf, 'UF ' . $uf . ' suportada sem fixar BA');
}
$bad = $draft; $bad['service']['nbs'] = '';
$bad = DraftTax::refresh($bad, $lookup);
taxDraftCheck(count($calls) === 4 && !isset($bad['ibpt']) && str_contains($bad['ibpt_error'], 'NBS'), 'NBS ausente preserva o rascunho com pendência e não consulta classificação alternativa');
$bad = DraftTax::refresh($draft, static function (): array { throw new RuntimeException('Consulta indisponível.'); });
taxDraftCheck($bad['values'] === $draft['values'] && !isset($bad['ibpt']) && $bad['ibpt_error'] === 'Consulta indisponível.', 'Falha da fonte mantém os dados digitados e não produz estimativa fictícia');
taxDraftRefuses(static fn() => DraftTax::ibpt($bad), 'Rascunho sem consulta confirmada não pode gerar carga fiscal IBPT');
$expired = $ready;
$expired['ibpt']['valid_from'] = '2020-01-01'; $expired['ibpt']['valid_to'] = '2020-01-31';
taxDraftCheck(DraftTax::ibpt($expired)['total_federal'] === '33.89', 'Vencimento não impede cálculo, independentemente da competência');
$expired = DraftTax::refresh($expired, static function () use ($expired): array { return $expired['ibpt']; });
taxDraftCheck(isset($expired['ibpt']) && !isset($expired['ibpt_error']) && $expired['values']['total_federal'] === '33.89', 'Fonte ainda vencida não bloqueia cálculo do rascunho');
$expiredOffline = DraftTax::refresh($expired, static function (): array { throw new RuntimeException('Fonte indisponível'); });
taxDraftCheck($expiredOffline['ibpt']['chave'] === 'TEST071' && $expiredOffline['values']['total_municipal'] === '12.60' && empty($expiredOffline['ibpt_error']), 'Fonte indisponível preserva snapshot vencido e cálculo');
$historical = $ready; $historical['issued_at'] = '2020-01-15T10:00:00-03:00';
$historical['ibpt']['valid_from'] = '2020-01-01'; $historical['ibpt']['valid_to'] = '2020-01-31';
taxDraftCheck(DraftTax::ibpt($historical)['total_federal'] === '33.89', 'Consulta de documento já emitido mantém a tabela válida na emissão original');
$inconsistent = $ready; $inconsistent['ibpt_error'] = 'Consulta pendente.';
taxDraftRefuses(static fn() => DraftTax::ibpt($inconsistent), 'Estado inconsistente não apresenta uma consulta pendente como concluída');
$manual = $ready; $manual['values']['total_tax_mode'] = 'amount';
$manual = DraftTax::refresh($manual, static function (): array { throw new LogicException('Não deve chamar a fonte'); });
taxDraftCheck(!isset($manual['ibpt'], $manual['ibpt_error']) && $manual['values']['total_federal'] === '33.89' && DraftTax::information($manual) === '', 'Troca para preenchimento manual remove atribuição ao IBPT sem destruir os valores revisados');
$summary = DraftTax::summary($ready);
taxDraftCheck($summary['iss']['amount'] === '7.56' && $summary['ibpt']['total_municipal'] === '12.60', 'Resumo separa ISSQN calculado de carga municipal aproximada');
taxDraftCheck($summary['inputs']['nbs'] === '115011000' && $summary['inputs']['service_amount'] === '262.00' && $summary['ibpt_error'] === '', 'Resposta ao editor identifica os valores usados no cálculo');
taxDraftCheck(DraftTax::summary($bad)['ibpt'] === null && DraftTax::summary($bad)['ibpt_error'] !== '', 'Resumo de falha não mostra valores antigos como atuais');
$config = Config::sanitize(['tax' => ['total_tax_mode' => 'ibpt']]);
taxDraftCheck($config['tax']['total_tax_mode'] === 'ibpt', 'Configuração da empresa aceita modo IBPT');
taxDraftRefuses(static fn() => Config::sanitize(['tax' => ['total_tax_mode' => 'provider-url']]), 'Configuração não aceita modo arbitrário');
$profile = EmissionProfile::sanitize(['tax' => ['total_tax_mode' => 'ibpt'], 'service' => ['nbs' => '115011000']]);
taxDraftCheck(EmissionProfile::resolve($config, ['emission_profile' => []], ['emission_profile' => $profile])['tax']['total_tax_mode'] === 'ibpt', 'Tomador pode escolher o IBPT no perfil próprio');
taxDraftCheck(EmissionProfile::resolve($config, ['emission_profile' => []], ['emission_profile' => ['tax' => ['total_tax_mode' => 'amount']]])['tax']['total_tax_mode'] === 'amount', 'Tomador pode sobrescrever o padrão IBPT da empresa');
$validationDraft = $ready;
$validationDraft['values']['total_federal'] = '999.99';
taxDraftCheck(in_array('Salve o rascunho para atualizar os tributos aproximados pelo IBPT após revisar os valores.', Validator::draft($validationDraft), true), 'Validação detecta divergência entre snapshot e totais digitados');
$validationDraft = $ready; $validationDraft['values']['iss_rate'] = '5.01';
taxDraftCheck(in_array('A alíquota do ISSQN não pode ser superior a 5%.', Validator::draft($validationDraft), true), 'Validação local impede ISSQN superior ao limite da SEFIN');
taxDraftRefuses(static fn() => DraftTax::date(['issued_at' => '2026-02-30T10:00:00-03:00']), 'Data impossível não contorna a vigência');
echo 'OK: ' . $checks . " verificações do ciclo fiscal IBPT.\n";
