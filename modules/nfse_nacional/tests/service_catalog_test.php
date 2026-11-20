<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\ServiceCatalog;

$checks = 0;
function catalogCheck(bool $result, string $label): void
{
    if (!$result) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}

$all = ServiceCatalog::all();
$production = ServiceCatalog::all('1');
$restricted = ServiceCatalog::all('2');
catalogCheck(count($all) === 345, 'união completa dos códigos publicados e extensão de testes');
catalogCheck(count($production) === 338, '338 folhas de produção do Anexo B, sem cabeçalhos');
catalogCheck(count($restricted) === 345, '338 códigos comuns mais sete novos fatos geradores em testes');
$codes = array_column($all, 'code');
catalogCheck(count(array_unique($codes)) === count($codes), 'nenhum código duplicado');
$sorted = $codes; sort($sorted, SORT_STRING);
catalogCheck($codes === $sorted, 'ordem nacional e zeros à esquerda preservados');
catalogCheck(count(array_filter($all, static fn(array $s): bool => is_string($s['code']) && preg_match('/^[0-9]{6}$/D', $s['code']) && trim($s['description']) !== '')) === count($all), 'todos os registros têm identificação canônica e descrição completa');
catalogCheck(ServiceCatalog::find('010101')['description'] === 'Análise e desenvolvimento de sistemas.', 'atividade de informática com código que começa por zero');
catalogCheck(ServiceCatalog::find('171901')['description'] === 'Contabilidade, inclusive serviços técnicos e auxiliares.', 'atividade contábil pesquisável');
catalogCheck(str_contains(ServiceCatalog::find('110501')['description'], 'monitoramento e rastreamento a distância'), 'monitoramento incluído além da página simplificada antiga');
catalogCheck(ServiceCatalog::find('141403')['description'] === 'Guincho intramunicipal em construção civil.', 'desdobro nacional de guincho em construção civil incluído');
catalogCheck(ServiceCatalog::find('141404')['description'] === 'Guindaste e içamento em construção civil.', 'desdobro nacional de guindaste em construção civil incluído');
catalogCheck(strlen(ServiceCatalog::find('070201')['description']) > 400, 'descrições extensas não foram truncadas pela prévia da fonte');
$testOnly = array_values(array_diff($codes, array_column($production, 'code')));
catalogCheck($testOnly === ['990201', '990301', '990302', '990303', '990304', '990305', '990401'], 'diferença exata entre produção e testes');
catalogCheck(!ServiceCatalog::has('990301', '1') && ServiceCatalog::has('990301', '2'), 'locação de imóveis nunca é habilitada em produção por engano');
catalogCheck(ServiceCatalog::has('990101', '1') && ServiceCatalog::has('990101', '2'), '99.01.01 preservado em ambos ambientes');
catalogCheck(ServiceCatalog::find('990304')['description'] === 'Servidão, Cessão de Uso ou de Espaço de Bens Imóveis (quando não caracterizem operações tributáveis pelo ISSQN)', 'descrição da NT007 extraída sem perder condição da operação');
catalogCheck(!ServiceCatalog::has('010100') && !ServiceCatalog::has('010000'), 'subitem e item não são confundidos com folhas cTribNac');
catalogCheck(!ServiceCatalog::has('000000') && !ServiceCatalog::has('999999'), 'valores fictícios são recusados');
catalogCheck(!ServiceCatalog::has('115021000') && !ServiceCatalog::has('6201501'), 'NBS e CNAE não são aceitos como cTribNac');
catalogCheck(!ServiceCatalog::has('10101') && !ServiceCatalog::has('01.01.01'), 'lookup é exato e não tenta adivinhar o código');
catalogCheck(ServiceCatalog::normalize('01.01.01') === '010101' && ServiceCatalog::normalize(' 010101 ') === '010101', 'normalização explícita aceita apenas formatos documentados');
catalogCheck(ServiceCatalog::normalize('1.01.01') === null && ServiceCatalog::normalize('0101') === null && ServiceCatalog::normalize('010101x') === null, 'não completa dígitos faltantes nem descarta caracteres arbitrários');
catalogCheck(ServiceCatalog::normalize('') === null, 'seleção vazia permanece vazia para a regra de herança');
try { ServiceCatalog::all('production'); $invalidEnvironment = false; }
catch (InvalidArgumentException $exception) { $invalidEnvironment = true; }
catalogCheck($invalidEnvironment, 'ambiente inválido não retorna a união permissiva');
$copy = ServiceCatalog::find('010101'); $copy['description'] = 'ALTERADO';
catalogCheck(ServiceCatalog::find('010101')['description'] !== 'ALTERADO', 'um chamador não modifica o catálogo estático');
$metadata = json_decode(file_get_contents(dirname(__DIR__) . '/resources/servicos-source.json'), true, 32, JSON_THROW_ON_ERROR);
catalogCheck($metadata['data_sha256'] === hash_file('sha256', dirname(__DIR__) . '/resources/servicos.json'), 'proveniência confere com o arquivo distribuído');
catalogCheck($metadata['counts']['production'] === count($production) && $metadata['counts']['restricted_only'] === count($testOnly), 'totais documentados correspondem aos ambientes do catálogo');
echo 'OK: ' . $checks . " verificações do catálogo nacional de serviços.\n";
