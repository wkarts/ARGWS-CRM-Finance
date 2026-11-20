<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
use NfseNacional\{Config, CompetenceRate, ValidationException};
$n = 0;
function verifyDual($condition, $label) { global $n; if (!$condition) { throw new RuntimeException($label); } ++$n; }
$draft = ['competence' => '2026-11-30', 'values' => ['simple_rate' => '2.00', 'iss_rate' => '2.50', 'total_tax_mode' => 'ibpt', 'retention' => '2'], 'ibscbs' => ['enabled' => '1']];
$rows = ['simple' => ['rate' => '11.41'], 'iss' => ['rate' => '3.25']];
$lookup = static fn($month, $type) => $month === '2026-11' ? ($GLOBALS['rows'][$type] ?? null) : null;
foreach (['0', '1'] as $simple) { foreach (['0', '1'] as $iss) {
 $settings = Config::defaults(); $settings['variable_simple_rate'] = $simple; $settings['variable_iss_rate'] = $iss;
 $r = CompetenceRate::apply($draft, $settings, $lookup, true);
 verifyDual($r['values']['simple_rate'] === ($simple === '1' ? '11.41' : '2.00'), 'independent Simples toggle');
 verifyDual($r['values']['iss_rate'] === ($iss === '1' ? '3.25' : '2.50'), 'independent ISS toggle');
 verifyDual($r['values']['total_tax_mode'] === 'ibpt' && $r['values']['retention'] === '2' && $r['ibscbs']['enabled'] === '1', 'other fiscal choices remain independent');
 if ($simple === '1') { verifyDual($r['simple_rate_snapshot']['competence'] === '2026-11', 'Simples snapshot'); }
 if ($iss === '1') { verifyDual($r['iss_rate_snapshot']['source'] === 'iss', 'separate ISS snapshot'); }
}}
$settings['variable_iss_rate_source'] = 'simple'; $rows['simple']['rate'] = '4.25';
$r = CompetenceRate::apply($draft, $settings, $lookup, true);
verifyDual($r['values']['simple_rate'] === '4.25' && $r['values']['iss_rate'] === '4.25', 'both fields share full value without conversion');
$settings['variable_simple_rate'] = '0'; $r = CompetenceRate::apply($draft, $settings, $lookup, true);
verifyDual($r['values']['simple_rate'] === '2.00' && $r['values']['iss_rate'] === '4.25', 'ISS alone can use Simples monthly register');
unset($rows['simple']);$r = CompetenceRate::apply($draft,$settings,$lookup);
verifyDual($r['values']['iss_rate'] === '' && isset($r['iss_rate_error']), 'shared missing rate does not use ISS or fixed fallback');
try { CompetenceRate::apply($draft,$settings,$lookup,true); throw new LogicException('missing allowed'); } catch(ValidationException $e) { verifyDual(str_contains($e->getMessage(),'11/2026'), 'required missing month blocks'); }
$settings['variable_iss_rate_source'] = 'iss';unset($rows['iss']);$r = CompetenceRate::apply($draft,$settings,$lookup);
verifyDual(isset($r['iss_rate_error']) && $r['values']['iss_rate'] === '', 'independent missing rate is required');
$rows['simple']=['rate'=>'11.41'];$settings['variable_iss_rate_source']='simple';
try { CompetenceRate::apply($draft,$settings,$lookup,true);throw new LogicException('invalid shared allowed'); } catch(ValidationException $e) { verifyDual(str_contains($e->getMessage(),'superior a 5%'), 'shared full Simples rate preserves existing ISS validation'); }
$fixed=Config::defaults();$fixed['tax']['simple_rate']='2.00';$fixed['tax']['iss_rate']='2.50';
$r=Config::sanitize(['variable_simple_rate'=>'1','variable_iss_rate'=>'1','variable_iss_rate_source'=>'simple','tax'=>['simple_rate'=>'4.25','iss_rate'=>'4.25','total_tax_mode'=>'ibpt']],$fixed);
verifyDual($r['tax']['simple_rate']==='2.00'&&$r['tax']['iss_rate']==='2.50'&&$r['tax']['total_tax_mode']==='ibpt','saving monthly displays preserves both fixed rates');
foreach(['variable_iss_rate'=>'wrong','variable_iss_rate_source'=>'wrong'] as $key=>$value) { try{Config::sanitize([$key=>$value]);throw new LogicException('invalid option allowed');}catch(InvalidArgumentException $e){verifyDual(true,'new settings are allowlisted');} }
$rows['simple']['rate']='4.25';$r=CompetenceRate::apply($draft,$settings,$lookup,true);CompetenceRate::assertSnapshots($r,$settings);verifyDual($r['iss_rate_snapshot']['source']==='simple','shared snapshot keeps source');
unset($r['iss_rate_snapshot']);try{CompetenceRate::assertSnapshots($r,$settings);throw new LogicException('missing snapshot allowed');}catch(ValidationException $e){verifyDual(str_contains($e->getMessage(),'ISSQN'),'reserved DPS without ISS snapshot blocked');}
verifyDual(Config::merge(['variable_simple_rate'=>'1'])['variable_iss_rate']==='0','upgrade does not enable ISS sharing silently');
echo "OK: $n verificações de Simples/ISS independentes, compartilhamento, competência e configurações.\n";
