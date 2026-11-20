<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\TaxEstimate;

$checks = 0;
function estimateCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException('FALHOU: ' . $message); } ++$GLOBALS['checks']; }
function estimateReject(callable $call, string $message): void {
    try { $call(); } catch (Throwable $error) { estimateCheck(true, $message); return; }
    throw new RuntimeException('FALHOU: ' . $message);
}
$values = ['service_amount'=>'252.00','unconditional_discount'=>'0.00','conditional_discount'=>'10.00',
    'iss_taxation'=>'1','iss_rate'=>'3.00','retention'=>'1','simple_rate'=>'3.00'];
$provider = ['state'=>'BA','simple_status'=>'3'];
$snapshot = ['uf'=>'BA','nbs'=>'101011000','description'=>'Serviço de exemplo','nacionalfederal'=>'13.4500','estadual'=>'0.0000','municipal'=>'5.0000',
    'valid_from'=>'2026-11-01','valid_to'=>'2026-12-31','chave'=>'ABC123','versao'=>'26.2.C','fonte'=>'IBPT/empresometro.com.br',
    'fetched_at'=>'2026-11-11T12:00:00Z','source_hash'=>hash('sha256','fixture')];
$estimate = TaxEstimate::ibpt($values,$provider,$snapshot);
estimateCheck($estimate['base'] === '252.00' && $estimate['total_federal'] === '33.89' && $estimate['total_municipal'] === '12.60', 'exemplo calcula federal e municipal com centavos exatos');
estimateCheck($estimate['total_state'] === '0.00' && $estimate['total'] === '46.49', 'zero estadual é explícito e total soma parcelas arredondadas');
estimateCheck($estimate['text'] === 'Optante do Simples Nacional. Trib. aprox. R$ 33,89 Federal, R$ 0,00 Estadual e R$ 12,60 Municipal. Fonte: IBPT [ABC123]. Alíquota do Simples Nacional: 3,0000%.', 'texto informa valores por esfera, chave real da fonte e percentual configurado do Simples');
estimateCheck($estimate['snapshot'] == $snapshot, 'cálculo não altera os dados do snapshot da fonte');
$regular = TaxEstimate::ibpt($values,array_replace($provider,['simple_status'=>'1']),$snapshot);
estimateCheck(!str_contains($regular['text'],'Simples') && str_contains($regular['text'],'Fonte: IBPT [ABC123]'), 'regime regular não é rotulado como optante do Simples');
$mei = TaxEstimate::ibpt($values,array_replace($provider,['simple_status'=>'2']),$snapshot);
estimateCheck(!str_contains($mei['text'],'Simples'), 'MEI não recebe alíquota de ME/EPP');
$withoutSimple = TaxEstimate::ibpt(array_replace($values,['simple_rate'=>'']),$provider,$snapshot);
estimateCheck(str_starts_with($withoutSimple['text'],'Optante do Simples Nacional.') && !str_contains($withoutSimple['text'],'Alíquota do'), 'alíquota do Simples ausente não é inventada');
$manual = TaxEstimate::ibpt($values + ['total_federal'=>'999','total_state'=>'888','total_municipal'=>'777'],$provider,$snapshot);
estimateCheck($manual === $estimate, 'modo IBPT ignora totais manuais residuais');
$iss = TaxEstimate::iss($values);
estimateCheck($iss === ['base'=>'252.00','rate'=>'3.0000','amount'=>'7.56','retained'=>false], 'ISSQN tem base e alíquota próprias, diferentes do percentual municipal aproximado');
estimateCheck($iss['amount'] !== $estimate['total_municipal'], 'IBPT municipal não substitui ISSQN');
foreach (['2','3'] as $retention) { estimateCheck(TaxEstimate::iss(array_replace($values,['retention'=>$retention]))['retained'] === true, 'retenção indicada sem alterar o valor calculado'); }
estimateCheck(TaxEstimate::iss(array_replace($values,['iss_rate'=>''])) === null, 'alíquota ausente não fabrica ISSQN');
estimateCheck(TaxEstimate::iss(array_replace($values,['iss_taxation'=>'4'])) === null, 'não incidência não é apresentada como ISSQN calculado');
estimateCheck(TaxEstimate::iss(array_replace($values,['iss_rate'=>'0.00']))['amount'] === '0.00', 'zero explicitamente parametrizado é calculado');
estimateReject(fn() => TaxEstimate::iss(array_replace($values,['iss_rate'=>'3.1234'])), 'ISSQN respeita duas casas decimais do leiaute');
estimateReject(fn() => TaxEstimate::iss(array_replace($values,['iss_rate'=>'10.00'])), 'ISSQN não aceita percentual fora da representação do leiaute');
estimateCheck(TaxEstimate::iss(array_replace($values,['unconditional_discount'=>'2.00']))['base'] === '250.00', 'desconto incondicional reduz a base');
estimateCheck(TaxEstimate::iss(array_replace($values,['conditional_discount'=>'200.00']))['base'] === '252.00', 'desconto condicional não reduz a base de ISSQN');
estimateCheck(TaxEstimate::ibpt(array_replace($values,['unconditional_discount'=>'2.00']),$provider,$snapshot)['total_municipal'] === '12.50', 'base IBPT usa serviço menos desconto incondicional');
estimateCheck(TaxEstimate::percentage('0.01','50') === '0.01', 'meio centavo arredonda para cima');
estimateCheck(TaxEstimate::percentage('0.01','49.9999') === '0.00', 'abaixo de meio centavo arredonda para baixo');
estimateCheck(TaxEstimate::percentage('100.00','0.0050') === '0.01', 'quatro casas de percentual são consideradas no arredondamento');
estimateCheck(TaxEstimate::percentage('1.234,56','3,1250') === '38.58', 'entradas decimais brasileiras preservam precisão');
estimateCheck(TaxEstimate::percentage('999999999999.99','100.0000') === '999999999999.99', 'limite de doze dígitos com alíquota 100 não excede inteiro64');
estimateCheck(TaxEstimate::percentage('999999999999.99','0.0001') === '1000000.00', 'valor grande com taxa pequena não perde precisão');
estimateCheck(TaxEstimate::percentage('999999999999.99','33.3333') === '333333000000.00', 'valor grande com taxa fracionária usa arredondamento exato');
estimateCheck(TaxEstimate::percentage('999999999999.99','99.9999') === '999998999999.99', 'produto grande não usa ponto flutuante');
foreach ([['-1.00','3'],['1.001','3'],['1e4','3'],['1000000000000','3'],['1','100.0001'],['1','-0.0001'],['1','2.12345'],['1','NaN'],['1',[]],[true,'3']] as [$base,$rate]) {
    estimateReject(fn() => TaxEstimate::percentage($base,$rate), 'base ou percentual fora do contrato é recusado');
}
foreach ([['service_amount'=>'-1'],['unconditional_discount'=>'-1'],['unconditional_discount'=>'252.01']] as $bad) {
    estimateReject(fn() => TaxEstimate::base(array_replace($values,$bad)), 'base negativa e desconto inválido não produzem estimativa');
}
estimateReject(fn() => TaxEstimate::ibpt($values,array_replace($provider,['state'=>'SP']),$snapshot), 'snapshot de outra UF não pode ser usado');
estimateReject(fn() => TaxEstimate::ibpt($values,$provider,array_replace($snapshot,['chave'=>''])), 'texto sem chave da fonte não é gerado');
// Small products can safely use direct integer arithmetic as an independent oracle.
foreach ([1,49,50,51,101,25200,999999] as $minor) {
    foreach ([0,1,5000,134500,333333,1000000] as $rateUnits) {
        $base = intdiv($minor,100) . '.' . str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);
        $rate = intdiv($rateUnits,10000) . '.' . str_pad((string)($rateUnits%10000),4,'0',STR_PAD_LEFT);
        $expected = intdiv($minor*$rateUnits+500000,1000000);
        $money = intdiv($expected,100) . '.' . str_pad((string)($expected%100),2,'0',STR_PAD_LEFT);
        estimateCheck(TaxEstimate::percentage($base,$rate) === $money, 'oráculo inteiro confere limites e arredondamentos: ' . $base . ' x ' . $rate);
    }
}
echo 'OK: ' . $checks . " verificações de estimativas fiscais.\n";
