<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\DistributionClient;

/**
 * Entirely offline contract fixtures, not captured fiscal documents or real A1s.
 * Envelopes follow ADN taxpayer OpenAPI and the contributor manual 03/11/2026.
 * XML fixtures exercise structure/correlation, not ICP-Brasil chain validation.
 * Every client has a transport double: this file cannot contact the national API.
 */
$checks = 0;
function distributionCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['checks'];
}
function distributionFails(callable $operation, string $message, ?callable $predicate = null): Throwable
{
    try { $operation(); }
    catch (Throwable $error) {
        distributionCheck($predicate === null || $predicate($error), $message);
        return $error;
    }
    throw new RuntimeException('FALHOU: ' . $message);
}
function distributionCertificate(string $taxId): Certificate
{
    $config = tempnam(sys_get_temp_dir(), 'nfse_distribution_test_');
    if ($config === false) { throw new RuntimeException('Não foi possível preparar o certificado sintético.'); }
    try {
        file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:" . $taxId . "\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:' . $taxId], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'digest_alg' => 'sha256']);
        $pfx = '';
        if (!openssl_pkcs12_export($cert, $pfx, $key, 'offline-distribution')) { throw new RuntimeException('Falha no certificado sintético.'); }
        return Certificate::fromPkcs12($pfx, 'offline-distribution', $taxId);
    } finally { if (is_file($config)) { unlink($config); } }
}
function distributionNfse(string $key, string $environment = '2'): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key . '">'
        . '<nNFSe>42</nNFSe><dhProc>2026-11-10T12:01:00-03:00</dhProc>'
        . '<emit><CNPJ>11222333000181</CNPJ><xNome>EMITENTE SINTÉTICO</xNome></emit>'
        . '<DPS versao="1.01"><infDPS Id="DPS355030821122233300018100001000000000000042">'
        . '<tpAmb>' . $environment . '</tpAmb><dhEmi>2026-11-10T12:00:00-03:00</dhEmi><dCompet>2026-11-01</dCompet>'
        . '<tpEmit>1</tpEmit><prest><CNPJ>11222333000181</CNPJ></prest>'
        . '<toma><CPF>52998224725</CPF><xNome>TOMADOR SINTÉTICO</xNome></toma>'
        . '<serv><cServ><cTribNac>010101</cTribNac><xDescServ>TESTE OFFLINE</xDescServ></cServ></serv>'
        . '<valores><vServPrest><vServ>100.00</vServ></vServPrest></valores>'
        . '</infDPS></DPS></infNFSe></NFSe>';
}
function distributionEvent(string $key, string $environment = '2'): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infEvento Id="EVT' . $key . '101101001">'
        . '<verAplic>FIXTURE OFFLINE</verAplic><ambGer>2</ambGer><nSeqEvento>001</nSeqEvento>'
        . '<dhProc>2026-11-10T12:01:00-03:00</dhProc><nDFSe>42</nDFSe>'
        . '<pedRegEvento versao="1.01"><infPedReg Id="PRE' . $key . '101101">'
        . '<tpAmb>' . $environment . '</tpAmb><verAplic>FIXTURE OFFLINE</verAplic>'
        . '<dhEvento>2026-11-10T12:00:00-03:00</dhEvento><CNPJAutor>11222333000181</CNPJAutor>'
        . '<chNFSe>' . $key . '</chNFSe><e101101><xDesc>EVENTO SINTÉTICO</xDesc>'
        . '<cMotivo>1</cMotivo><xMotivo>TESTE OFFLINE</xMotivo></e101101>'
        . '</infPedReg></pedRegEvento></infEvento>'
        . '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignatureValue>RklYVFVSRSBPRkZMSU5F</SignatureValue></Signature></evento>';
}
function distributionItem(string $xml, string $nsu, string $key, string $kind = 'NFSE'): array
{
    return ['NSU' => $nsu, 'ChaveAcesso' => $key, 'TipoDocumento' => $kind,
        'TipoEvento' => $kind === 'EVENTO' ? 'CANCELAMENTO' : null,
        'ArquivoXml' => base64_encode(gzencode($xml)), 'DataHoraGeracao' => '2026-11-10T12:01:00.1234567-03:00'];
}
function distributionEnvelope(array $items = [], string $environment = '2', string $status = 'DOCUMENTOS_LOCALIZADOS', array $errors = []): array
{
    return ['StatusProcessamento' => $status, 'LoteDFe' => $items, 'Erros' => $errors, 'Alertas' => [],
        'TipoAmbiente' => $environment === '1' ? 'PRODUCAO' : 'HOMOLOGACAO',
        'TipoConsulta' => 'InscricaoCertificado', 'VersaoAplicativo' => 'FIXTURE OFFLINE',
        'DataHoraProcessamento' => '2026-11-10T12:01:00-03:00'];
}
function distributionResponse(array $data, int $status = 200, array $headers = []): array
{
    return ['status' => $status, 'body' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'headers' => $headers];
}
function distributionRun(Certificate $certificate, array $response, string $last = '0'): array
{
    return (new DistributionClient('2', $certificate, static fn() => $response))->distribute($last, '11222333000181');
}
function distributionRejects(Certificate $certificate, array $response, string $message, string $last = '0'): void
{
    $calls = 0; $logs = [];
    $client = new DistributionClient('2', $certificate,
        static function () use (&$calls, $response): array { ++$calls; return $response; },
        static function (array $entry) use (&$logs): void { $logs[] = $entry; });
    distributionFails(fn() => $client->distribute($last, '11222333000181'), $message,
        static fn(Throwable $error): bool => $error instanceof ApiException);
    distributionCheck($calls === 1 && count($logs) === 1 && in_array($logs[0]['operation_status'], ['error', 'uncertain'], true),
        $message . ' — uma requisição, falha observada, sem repetição');
}

$certificate = distributionCertificate('11222333000181');
$cpfCertificate = distributionCertificate('52998224725');
$key = str_repeat('1', 50);
$xml = distributionNfse($key);
$eventXml = distributionEvent($key);
$item = distributionItem($xml, '1', $key);
$success = distributionEnvelope([$item]);

// Fixed hosts, explicit taxpayer scope, both national environments, mixed batches.
foreach (['1' => 'https://adn.nfse.gov.br/contribuintes', '2' => 'https://adn.producaorestrita.nfse.gov.br/contribuintes'] as $environment => $base) {
    $environment = (string) $environment;
    $nfse = distributionNfse($key, $environment);
    $event = distributionEvent($key, $environment);
    $dps = '<DPS xmlns="http://www.sped.fazenda.gov.br/nfse"><infDPS><tpAmb>' . $environment . '</tpAmb></infDPS></DPS>';
    $response = distributionResponse(distributionEnvelope([
        distributionItem($nfse, '41', $key), distributionItem($event, '42', $key, 'EVENTO'),
        distributionItem($dps, '43', '', 'DPS'),
    ], $environment));
    $calls = []; $logs = [];
    $client = new DistributionClient($environment, $certificate,
        static function ($method, $url, $body, $usedCertificate) use (&$calls, $response): array { $calls[] = [$method, $url, $body, $usedCertificate]; return $response; },
        static function (array $entry) use (&$logs): void { $logs[] = $entry; });
    $result = $client->distribute('00040', '11.222.333/0001-81');
    distributionCheck(count($calls) === 1 && $calls[0] === ['GET', $base . '/DFe/40?lote=true&cnpjConsulta=11222333000181', null, $certificate], 'método, base, sequência e CNPJ completos no ambiente ' . $environment);
    distributionCheck(array_column($result['items'], 'nsu') === ['41', '42', '43'] && $result['next_nsu'] === '43', 'cursor preserva strings e só acompanha o lote validado/' . $environment);
    distributionCheck(array_column($result['items'], 'type') === ['nfse', 'event', 'other'] && $result['items'][0]['xml'] === $nfse && $result['items'][1]['xml'] === $event, 'tipos e XMLs originais preservados/' . $environment);
    distributionCheck($result['items'][1]['event_type'] === '101101' && $result['items'][2]['event_type'] === '', 'evento registrado distinto de DPS, tipo fiscal extraído do XML/' . $environment);
    distributionCheck($result['has_more'] && !$result['empty'] && $result['retry_after'] === 0, 'lote não vazio permite próxima consulta sem inventar maxNSU/' . $environment);
    distributionCheck(count($logs) === 1 && $logs[0]['operation'] === 'api.distribution' && $logs[0]['service'] === 'adn_contribuintes'
        && $logs[0]['environment'] === $environment && $logs[0]['operation_status'] === 'success'
        && $logs[0]['endpoint'] === $base . '/DFe/{NSU}?lote=true&cnpjConsulta={inscricao}', 'observação identifica serviço com endpoint mascarado/' . $environment);
    $serialized = json_encode($logs);
    distributionCheck(strpos($serialized, '11222333000181') === false && strpos($serialized, '52998224725') === false
        && strpos($serialized, $key) === false && strpos($serialized, 'ArquivoXml') === false && strpos($serialized, '<NFSe') === false,
        'observação não inclui CPF/CNPJ, chave ou XML/' . $environment);
}
// A historical event inside a mixed batch must not block later years/NSUs.
$historicalEvent = str_replace(['versao="1.01"', 'nDFSe'], ['versao="1.00"', 'nDFe'], $eventXml);
$historicalBatch = distributionRun($certificate, distributionResponse(distributionEnvelope([
    distributionItem($xml, '41', $key), distributionItem($historicalEvent, '42', $key, 'EVENTO'),
    distributionItem($xml, '43', $key),
])), '40');
distributionCheck($historicalBatch['next_nsu'] === '43' && count($historicalBatch['items']) === 3
    && $historicalBatch['has_more'], 'evento histórico nDFe permite avançar lote misto sem saltar NSU');
distributionCheck($historicalBatch['items'][1]['xml'] === $historicalEvent
    && $historicalBatch['items'][1]['event_type'] === '101101', 'evento histórico preserva XML assinado e tipo fiscal');

$cpfCalls = [];
$cpfClient = new DistributionClient('2', $cpfCertificate,
    static function ($method, $url, $body) use (&$cpfCalls): array { $cpfCalls[] = [$method, $url, $body]; return distributionResponse(distributionEnvelope([], '2', 'NENHUM_DOCUMENTO_LOCALIZADO')); });
$cpfClient->distribute('0', '529.982.247-25');
distributionCheck($cpfCalls === [['GET', 'https://adn.producaorestrita.nfse.gov.br/contribuintes/DFe/0?lote=true&cpfConsulta=52998224725', null]], 'consulta eCPF usa somente cpfConsulta explícito');

// NSU uses decimal arithmetic, including values beyond JavaScript's safe integer.
distributionCheck(DistributionClient::normalizeNsu('0000000000000000000') === '0' && DistributionClient::normalizeNsu('00042') === '42', 'normalização canônica da sequência');
distributionCheck(DistributionClient::compareNsu('9', '10') === -1 && DistributionClient::compareNsu('9007199254740993', '9007199254740992') === 1
    && DistributionClient::compareNsu('00010', '10') === 0, 'comparação numérica sem float');
distributionCheck(DistributionClient::normalizeNsu('9223372036854775807') === '9223372036854775807', 'limite int64 permanece string');
$bigResponse = distributionResponse(distributionEnvelope([array_replace($item, ['NSU' => '9007199254740993'])]));
$bigResponse['body'] = str_replace('"NSU":"9007199254740993"', '"NSU":9007199254740993', $bigResponse['body']);
$bigResult = distributionRun($certificate, $bigResponse, '9007199254740992');
distributionCheck($bigResult['next_nsu'] === '9007199254740993' && $bigResult['items'][0]['nsu'] === '9007199254740993', 'NSU numérico JSON acima de 2^53 mantém todos os dígitos');

$preflightCalls = 0;
$guard = new DistributionClient('2', $certificate, static function () use (&$preflightCalls): array { ++$preflightCalls; throw new RuntimeException('Transporte proibido neste teste.'); });
foreach (['', '-1', '+1', '1.0', '1e3', '1/../../', ' 1', '9223372036854775808', '99999999999999999999'] as $badNsu) {
    distributionFails(fn() => $guard->distribute($badNsu, '11222333000181'), 'sequência inválida bloqueada antes da rede', static fn(Throwable $e): bool => $e instanceof InvalidArgumentException);
}
foreach (['', '11222333000180', '11222333000181&cpfConsulta=52998224725', '12345678000195', '11222333000262'] as $badTaxId) {
    distributionFails(fn() => $guard->distribute('0', $badTaxId), 'CPF/CNPJ inválido ou de outra empresa bloqueado antes da rede', static fn(Throwable $e): bool => $e instanceof InvalidArgumentException);
}
distributionCheck($preflightCalls === 0, 'certificado da matriz não amplia automaticamente a consulta para outra filial');
distributionFails(fn() => new DistributionClient('3', $certificate, static fn() => []), 'ambiente desconhecido recusado', static fn(Throwable $e): bool => $e instanceof InvalidArgumentException);

// Explicit absence is the only end-of-cycle signal; generic 404/204 are inconclusive.
foreach (['REJEICAO', 'NENHUM_DOCUMENTO_LOCALIZADO'] as $processing) {
    $empty = distributionEnvelope([], '2', $processing, [['Codigo' => 'E2220', 'Descricao' => 'Não existem documentos a partir do NSU.']]);
    $result = distributionRun($certificate, distributionResponse($empty, 404), '99');
    distributionCheck($result === ['items' => [], 'next_nsu' => '99', 'has_more' => false, 'empty' => true, 'retry_after' => 3600], 'E2220 com ' . $processing . ' preserva cursor e aguarda uma hora');
}
$empty = distributionEnvelope([], '2', 'NENHUM_DOCUMENTO_LOCALIZADO');
$empty['LoteDFe'] = null; $empty['Erros'] = null; $empty['Alertas'] = null;
distributionCheck(distributionRun($certificate, distributionResponse($empty))['empty'], 'ausência explícita HTTP 200 com coleções nulas aceita');
distributionCheck(distributionRun($certificate, distributionResponse($empty, 200, ['Retry-After' => '7200']))['retry_after'] === 7200, 'fim de ciclo respeita intervalo maior solicitado pelo servidor');
$empty['Alertas'] = [['Codigo' => 'E2220']];
distributionCheck(distributionRun($certificate, distributionResponse($empty, 404))['empty'], 'E2220 também é reconhecido nos alertas oficiais');
foreach ([
    ['status' => 404, 'body' => '<html>Route not found</html>'],
    ['status' => 404, 'body' => '{}'],
    ['status' => 204, 'body' => ''],
    distributionResponse(distributionEnvelope([], '2', 'NENHUM_DOCUMENTO_LOCALIZADO'), 404),
    distributionResponse(distributionEnvelope([], '1', 'NENHUM_DOCUMENTO_LOCALIZADO', [['Codigo' => 'E2220']]), 404),
    distributionResponse(distributionEnvelope([], '2', 'REJEICAO', [['Codigo' => 'E2241']]), 404),
    distributionResponse(distributionEnvelope([], '2', 'REJEICAO', [['Codigo' => 'E2230']]), 404),
    distributionResponse(distributionEnvelope([], '2', 'REJEICAO', [['Codigo' => 'E2220'], ['Codigo' => 'E2241']]), 404),
    distributionResponse(distributionEnvelope([], '2', 'NENHUM_DOCUMENTO_LOCALIZADO', [['Codigo' => 'E2220']]), 503),
] as $index => $failure) { distributionRejects($certificate, $failure, 'ausência não é inferida de falha/ambiente/escopo inconsistente #' . $index); }

// Rate limiting is a pause, never a missing-document result, and never loops.
foreach ([
    [['Retry-After' => '5'], 5],
    [['Retry-After' => '0'], 1],
    [['Retry-After' => '120'], 120],
    [['retry-after' => ['5', '180']], 180],
    [["Retry-After: 90\r\n"], 90],
    [[], 60],
    [['Retry-After' => '-1'], 60],
] as [$headers, $delay]) {
    $result = distributionRun($certificate, ['status' => 429, 'body' => '<html>Throttled</html>', 'headers' => $headers], '99');
    distributionCheck($result === ['items' => [], 'next_nsu' => '99', 'has_more' => false, 'empty' => false, 'retry_after' => $delay], 'HTTP 429 retorna pausa e cursor preservado/' . $delay);
}
$future = gmdate('D, d M Y H:i:s \G\M\T', time() + 600);
$timedPause = distributionRun($certificate, ['status' => 429, 'body' => '', 'headers' => ['Retry-After' => $future]]);
distributionCheck($timedPause['retry_after'] >= 598 && $timedPause['retry_after'] <= 600, 'Retry-After aceita data HTTP sem repetir requisição');
distributionRejects($certificate, distributionResponse(distributionEnvelope([], '1', 'REJEICAO'), 429), 'HTTP 429 com envelope fiscal de outro ambiente não é aceito');

// Production ADN metadata is not a fiscal authorization field. A valid XML/NSU
// must synchronize even if DataHoraGeracao is absent or in a non-RFC3339 shape.
foreach ([
    ['missing field', null, true],
    ['invalid calendar date', '2026-02-30T12:00:00Z', true],
    ['timestamp without timezone', '2026-11-10T12:01:00.1234567', true],
    ['timestamp as Unix epoch', 1794312060, true],
    ['invalid type', ['untrusted' => 'shape'], true],
    ['empty field', '', true],
    ['9-digit fractional timezone', '2026-11-10T12:01:00.123456789-03:00', false],
] as [$label, $metadata, $expectFallback]) {
    $itemWithDate = $item;
    if ($label === 'missing field') { unset($itemWithDate['DataHoraGeracao']); }
    else { $itemWithDate['DataHoraGeracao'] = $metadata; }
    $observer = [];
    $client = new DistributionClient('2', $certificate,
        static fn() => distributionResponse(distributionEnvelope([$itemWithDate])),
        static function (array $entry) use (&$observer): void { $observer[] = $entry; });
    $result = $client->distribute('0', '11222333000181');
    distributionCheck($result['next_nsu'] === '1' && count($result['items']) === 1
        && $result['items'][0]['xml'] === $xml && $result['items'][0]['access_key'] === $key,
        $label . ': XML, chave, NSU e documento preservados');
    distributionCheck($observer[0]['operation_status'] === 'success'
        && $observer[0]['generation_date_fallbacks'] === ($expectFallback ? 1 : 0),
        $label . ': metadados recuperados sem falha na consulta');
    distributionCheck($result['items'][0]['generated_at'] === ($expectFallback
        ? '2026-11-10T12:01:00-03:00' : $metadata),
        $label . ': data de origem autenticada corretamente preservada');
}

// The same production response must not be treated as a transport or fiscal error.
$prodXml = distributionNfse($key, '1');
$prodItem = distributionItem($prodXml, '79', $key);
$prodItem['DataHoraGeracao'] = '2026-11-10T12:01:00';
$prodEvents = [];
$prodClient = new DistributionClient('1', $certificate,
    static fn() => distributionResponse(distributionEnvelope([$prodItem], '1')),
    static function (array $event) use (&$prodEvents): void { $prodEvents[] = $event; });
$prodBatch = $prodClient->distribute('78', '11222333000181');
distributionCheck($prodBatch['next_nsu'] === '79' && $prodBatch['items'][0]['xml'] === $prodXml
    && $prodEvents[0]['http_status'] === 200 && $prodEvents[0]['operation_status'] === 'success'
    && $prodEvents[0]['generation_date_fallbacks'] === 1,
    'produção: HTTP 200 e data sem timezone não impedem confirmar XML e NSU');

// An invalid date in the ADN envelope must not mask an invalid XML, access key
// or NSU: the existing security checks still reject the entire batch.
$metadataAndBadKey = array_replace($item, ['ChaveAcesso' => str_repeat('2', 50), 'DataHoraGeracao' => '2026-02-30T12:00:00Z']);
distributionRejects($certificate, distributionResponse(distributionEnvelope([$metadataAndBadKey])),
    'envelope com data auxiliar incorreta não flexibiliza correlação da chave XML');
$metadataAndBadNsu = array_replace($item, ['NSU' => '0', 'DataHoraGeracao' => '2026-02-30T12:00:00Z']);
distributionRejects($certificate, distributionResponse(distributionEnvelope([$metadataAndBadNsu])),
    'envelope com data auxiliar incorreta não flexibiliza sequência NSU');

// Batch validation is atomic: one invalid document rejects the entire proposal.
$malformedEnvelopes = [
    array_replace($success, ['TipoAmbiente' => 'PRODUCAO']),
    array_replace($success, ['TipoAmbiente' => 2]),
    array_replace($success, ['StatusProcessamento' => 'UNKNOWN']),
    array_replace($success, ['LoteDFe' => []]),
    array_replace($success, ['LoteDFe' => new stdClass()]),
    array_replace($success, ['Erros' => [['Codigo' => 'E2245']]]),
    array_replace($success, ['Alertas' => [['Codigo' => 'E2220']]]),
    array_replace($success, ['Erros' => new stdClass()]),
    array_replace($success, ['LoteDFe' => [$item, $item]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['NSU' => '3']), array_replace($item, ['NSU' => '2'])]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['NSU' => '0'])]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['NSU' => 1.25])]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['NSU' => '9223372036854775808'])]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['ArquivoXml' => null])]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['ArquivoXml' => '%%%not-base64'])]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['ArquivoXml' => base64_encode($xml)])]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['ChaveAcesso' => str_repeat('2', 50)])]]),
    array_replace($success, ['LoteDFe' => [array_replace($item, ['TipoDocumento' => 'EVENTO'])]]),
    array_replace($success, ['LoteDFe' => [$item, array_replace($item, ['NSU' => '2', 'ArquivoXml' => 'bad'])]]),
];
foreach ($malformedEnvelopes as $index => $envelope) { distributionRejects($certificate, distributionResponse($envelope), 'lote inconsistente recusado integralmente #' . $index); }
foreach ([
    distributionNfse($key, '1'),
    str_replace('http://www.sped.fazenda.gov.br/nfse', 'https://attacker.invalid/nfse', $xml),
    str_replace('<tpAmb>2</tpAmb>', '<tpAmb>2</tpAmb><tpAmb>2</tpAmb>', $xml),
    str_replace('<tpAmb>2</tpAmb>', '', $xml),
    '<!DOCTYPE NFSe [<!ENTITY external SYSTEM "file:///not-to-be-read">]>' . preg_replace('/<\?xml[^>]*\?>/', '', $xml),
    '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><broken>',
] as $index => $invalidXml) {
    distributionRejects($certificate, distributionResponse(distributionEnvelope([distributionItem($invalidXml, '1', $key)])), 'XML inseguro ou sem correlação fiscal recusado #' . $index);
}
$unregistered = '<pedRegEvento xmlns="http://www.sped.fazenda.gov.br/nfse"><infPedReg><tpAmb>2</tpAmb><chNFSe>' . $key . '</chNFSe></infPedReg></pedRegEvento>';
distributionRejects($certificate, distributionResponse(distributionEnvelope([distributionItem($unregistered, '1', $key, 'EVENTO')])), 'pedido de evento não é tratado como cancelamento registrado');
$other = distributionRun($certificate, distributionResponse(distributionEnvelope([distributionItem($unregistered, '1', $key, 'PEDIDO_REGISTRO_EVENTO')])));
distributionCheck($other['items'][0]['type'] === 'other' && $other['items'][0]['event_type'] === '', 'pedido declarado permanece documento não autorizador');
distributionRejects($certificate, distributionResponse(distributionEnvelope([distributionItem(distributionEvent($key, '1'), '1', $key, 'EVENTO')])), 'evento de produção não altera notas de homologação');

// Bounded JSON, single gzip expansion, aggregate XML and count protect worker memory.
distributionRejects($certificate, ['status' => 200, 'body' => str_repeat(' ', 16777217)], 'corpo JSON acima do limite recusado');
distributionRejects($certificate, distributionResponse(distributionEnvelope([distributionItem(str_repeat('x', 5242881), '1', $key)])), 'expansão GZip acima do limite interrompida');
distributionRejects($certificate, distributionResponse(distributionEnvelope(array_fill(0, 101, $item))), 'quantidade excessiva de documentos recusada');
$largeXml = str_replace('</infNFSe>', '<!--' . str_repeat('x', 5000000) . '--></infNFSe>', $xml);
$largeItem = distributionItem($largeXml, '1', $key); unset($largeXml);
$largeBatch = [];
for ($i = 1; $i <= 7; ++$i) { $largeBatch[] = array_replace($largeItem, ['NSU' => (string) $i]); }
distributionRejects($certificate, distributionResponse(distributionEnvelope($largeBatch)), 'limite agregado evita lote de expansões pequenas acumuladas');
unset($largeBatch, $largeItem);

// Remote error text must never leak personal identifiers, XML or credentials.
$privateText = 'CPF 52998224725 CNPJ 11222333000181 <NFSe>PRIVATE_XML</NFSe> senha=PRIVATE_PASSWORD ' . $key;
$privateEnvelope = distributionEnvelope([], '2', 'REJEICAO', [['Codigo' => 'E2245', 'Descricao' => $privateText, 'Complemento' => $privateText, 'Parametros' => [$privateText]]]);
$privacyLogs = [];
$privacyClient = new DistributionClient('2', $certificate, static fn() => distributionResponse($privateEnvelope, 400),
    static function (array $entry) use (&$privacyLogs): void { $privacyLogs[] = $entry; });
$privacyError = distributionFails(fn() => $privacyClient->distribute('0', '11222333000181'), 'procuração recusada permanece erro explícito',
    static fn(Throwable $error): bool => $error instanceof ApiException && $error->httpStatus() === 400 && !$error->isUncertain());
$publicText = json_encode($privacyLogs) . $privacyError->getMessage();
distributionCheck(strpos($publicText, '52998224725') === false && strpos($publicText, '11222333000181') === false
    && strpos($publicText, 'PRIVATE_XML') === false && strpos($publicText, 'PRIVATE_PASSWORD') === false && strpos($publicText, $key) === false
    && strpos($publicText, 'E2245') !== false, 'erros e observação preservam código útil sem texto pessoal ou XML externo');
$transportLogs = [];
$broken = new DistributionClient('2', $certificate, static function (): array { throw new RuntimeException('https://private:SECRET@host.invalid/11222333000181'); },
    static function (array $entry) use (&$transportLogs): void { $transportLogs[] = $entry; });
$brokenError = distributionFails(fn() => $broken->distribute('0', '11222333000181'), 'falha de transporte é inconclusiva', static fn(Throwable $e): bool => $e instanceof ApiException && $e->isUncertain());
distributionCheck(strpos($brokenError->getMessage() . json_encode($transportLogs), 'SECRET') === false, 'mensagem de transporte não revela URL autenticada');
$observerCalls = 0;
$observerFailure = new DistributionClient('2', $certificate,
    static function () use (&$observerCalls, $success): array { ++$observerCalls; return distributionResponse($success); },
    static function (): void { throw new RuntimeException('Falha de auditoria simulada.'); });
distributionCheck($observerFailure->distribute('0', '11222333000181')['next_nsu'] === '1' && $observerCalls === 1, 'observador não muda lote aceito nem repete consulta');

echo "OK — {$checks} verificações de distribuição ADN, isolamento, limites e retomada (sem rede).\n";
