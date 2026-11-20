<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\ApiClient;
use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\FiscalEvent;

$checks = 0;
function eventCheck(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$GLOBALS['checks'];
}
function eventThrows(callable $fn, string $label, bool $api = false): void {
    try { $fn(); } catch (Throwable $error) {
        eventCheck(!$api || ($error instanceof ApiException && $error->isUncertain()), $label);
        return;
    }
    eventCheck(false, $label);
}
function eventFixture(string $key, string $type = '101101', string $environment = '2', string $sequence = '001'): string {
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infEvento Id="EVT' . $key . $type . $sequence . '">'
        . '<verAplic>FIXTURE OFFLINE</verAplic><ambGer>2</ambGer><nSeqEvento>' . $sequence . '</nSeqEvento>'
        . '<dhProc>2026-11-10T12:01:00-03:00</dhProc><nDFSe>42</nDFSe>'
        . '<pedRegEvento versao="1.01"><infPedReg Id="PRE' . $key . $type . '">'
        . '<tpAmb>' . $environment . '</tpAmb><verAplic>FIXTURE OFFLINE</verAplic><dhEvento>2026-11-10T12:00:00-03:00</dhEvento>'
        . '<CNPJAutor>11222333000181</CNPJAutor><chNFSe>' . $key . '</chNFSe>'
        . '<e' . $type . '><xDesc>EVENTO SINTÉTICO</xDesc><cMotivo>1</cMotivo><xMotivo>TESTE OFFLINE</xMotivo></e' . $type . '>'
        . '</infPedReg></pedRegEvento></infEvento>'
        // Structural fixture only; no claim of ICP-Brasil signature verification.
        . '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignatureValue>RklYVFVSRSBPRkZMSU5F</SignatureValue></Signature></evento>';
}
function eventEnvelope(string $xml, bool $collection = false): array {
    $encoded = base64_encode(gzencode($xml));
    $base = ['tipoAmbiente' => 2, 'versaoAplicativo' => 'FIXTURE', 'dataHoraProcessamento' => '2026-11-10T12:01:00-03:00'];
    if ($collection) {
        $base['eventos'] = [['arquivoXml' => base64_encode($encoded), 'chaveAcesso' => str_repeat('1', 50),
            'tipoEvento' => 101101, 'numeroPedidoRegistroEvento' => 1]];
    } else { $base['eventoXmlGZipB64'] = $encoded; }
    return $base;
}
$key50 = str_repeat('1', 50);
$xml = eventFixture($key50);
$parsed = FiscalEvent::fromXml($xml, $key50, '2');
eventCheck($parsed['cancellation'] && $parsed['type'] === '101101' && $parsed['sequence'] === 1, 'cancelamento registrado é reconhecido');
eventCheck($parsed['id'] === 'EVT' . $key50 . '101101001' && $parsed['record_number'] === '42'
    && $parsed['protocol'] === '' && $parsed['processed_at'] === '2026-11-10T12:01:00-03:00', 'identificadores oficiais preservados sem inventar protocolo');
foreach (['105102', '105104', '305101'] as $type) {
    eventCheck(FiscalEvent::fromXml(eventFixture($key50, $type), $key50, '2')['cancellation'], 'cancelamento efetivo ' . $type);
}
foreach (['101103', '105105', '202205', '205208', '305102', '305103'] as $type) {
    eventCheck(!FiscalEvent::fromXml(eventFixture($key50, $type), $key50, '2')['cancellation'], 'pedido, indeferimento, manifestação ou bloqueio não cancelam: ' . $type);
}
eventThrows(fn() => FiscalEvent::fromXml($xml, str_repeat('2', 50), '2'), 'chave de outra nota rejeitada');
eventThrows(fn() => FiscalEvent::fromXml($xml, $key50, '1'), 'evento de outro ambiente rejeitado');
foreach ([
    str_replace('<nSeqEvento>001</nSeqEvento>', '<nSeqEvento>002</nSeqEvento>', $xml),
    str_replace('2026-11-10T12:01:00-03:00', '2026-02-30T12:01:00-03:00', $xml),
    str_replace('<nDFSe>42</nDFSe>', '', $xml),
    str_replace('<chNFSe>' . $key50 . '</chNFSe>', '<chNFSe>' . str_repeat('2', 50) . '</chNFSe>', $xml),
    str_replace('<chNFSe>' . $key50 . '</chNFSe>', '<chNFSe>' . $key50 . '</chNFSe><chNFSe>' . $key50 . '</chNFSe>', $xml),
    str_replace('e101101', 'e101103', $xml),
    str_replace('</infPedReg>', '<e105104><cMotivo>1</cMotivo></e105104></infPedReg>', $xml),
    str_replace('PRE' . $key50, 'PRE' . str_repeat('2', 50), $xml),
    str_replace('http://www.w3.org/2000/09/xmldsig#', 'urn:invalid', $xml),
    str_replace('<cMotivo>1</cMotivo>', '<cMotivo>2</cMotivo>', eventFixture($key50, '105104')),
    eventFixture($key50, '101101', '2', '002'),
] as $invalid) { eventThrows(fn() => FiscalEvent::fromXml($invalid, $key50, '2'), 'evento inconsistente não altera situação'); }
// Official archived tiposEventos_v1.00.xsd (July 2022–28/09/2025):
// TCInfEvento/nDFe, subsequently renamed nDFSe. Original signed XML stays intact.
$legacyXml = str_replace(['versao="1.01"', 'nDFSe', 'PRE' . $key50 . '101101"'],
    ['versao="1.00"', 'nDFe', 'PRE' . $key50 . '101101001"'], $xml);
$legacy = FiscalEvent::fromXml($legacyXml, $key50, '2');
eventCheck($legacy['record_number'] === '42' && $legacy['xml'] === $legacyXml
    && $legacy['cancellation'] && $legacy['id'] === $parsed['id'], 'evento histórico nDFe preserva registro, situação e XML original');
foreach ([
    str_replace('<nDFe>42</nDFe>', '', $legacyXml),
    str_replace('<nDFe>42</nDFe>', '<nDFe>42</nDFe><nDFe>42</nDFe>', $legacyXml),
    str_replace('<nDFe>42</nDFe>', '<nDFe>42</nDFe><nDFSe>42</nDFSe>', $legacyXml),
    str_replace('<nDFe>42</nDFe>', '<nDFe xmlns="urn:other">42</nDFe>', $legacyXml),
    str_replace('<nDFe>42</nDFe>', '<nDFe><value>42</value></nDFe>', $legacyXml),
    str_replace('<nDFe>42</nDFe>', '<nDFe>invalid</nDFe>', $legacyXml),
] as $invalidLegacy) {
    eventThrows(fn() => FiscalEvent::fromXml($invalidLegacy, $key50, '2'), 'alias histórico não aceita ausência, duplicidade, conteúdo ou namespace inválido');
}
eventThrows(fn() => FiscalEvent::fromXml($legacyXml, str_repeat('2', 50), '2'), 'evento histórico não troca chave');
eventThrows(fn() => FiscalEvent::fromXml($legacyXml, $key50, '1'), 'evento histórico não troca ambiente');

$d = new DOMDocument(); $d->loadXML($xml);
$requestXml = $d->saveXML($d->getElementsByTagName('pedRegEvento')->item(0));
eventThrows(fn() => FiscalEvent::fromXml($requestXml, $key50, '2'), 'pedido enviado não é recibo de evento registrado');
eventThrows(fn() => FiscalEvent::fromXml('<!DOCTYPE x [<!ENTITY ext SYSTEM "file:///etc/passwd">]><evento>&ext;</evento>', $key50, '2'), 'DTD e entidade externa são recusadas');

$config = tempnam(sys_get_temp_dir(), 'nfse_events_test_');
try {
    file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:11222333000181\n");
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
    $csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:11222333000181'], $private, ['config' => $config, 'digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $private, 1, ['config' => $config, 'digest_alg' => 'sha256']);
    $pfx = ''; openssl_pkcs12_export($cert, $pfx, $private, 'offline');
    $certificate = Certificate::fromPkcs12($pfx, 'offline', '11222333000181');
} finally { if (is_file($config)) { unlink($config); } }

foreach ([false, true] as $collection) {
    $observed = []; $calls = 0;
    $client = new ApiClient('2', $certificate, static function ($method, $url, $body) use ($collection, $xml, $key50, &$calls): array {
        ++$calls;
        eventCheck($method === 'GET' && $body === null
            && $url === 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional/nfse/' . $key50 . '/eventos', 'consulta de eventos faz somente GET na rota oficial');
        return ['status' => 200, 'body' => json_encode(eventEnvelope($xml, $collection))];
    }, static function (array $entry) use (&$observed): void { $observed[] = $entry; });
    $events = $client->getEvents($key50);
    eventCheck(count($events) === 1 && $events[0]['xml'] === $xml && $events[0]['cancellation'] && $calls === 1, 'envelope de evento recebido é decodificado sem retransmissão');
    eventCheck(count($observed) === 1 && $observed[0]['operation'] === 'api.events' && $observed[0]['operation_status'] === 'success'
        && $observed[0]['endpoint'] === 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional/nfse/{chaveAcesso}/eventos'
        && strpos(json_encode($observed), $key50) === false && strpos(json_encode($observed), 'arquivoXml') === false, 'log da consulta omite chave e conteúdo fiscal');
}

$absent = new ApiClient('2', $certificate, fn() => ['status' => 404, 'body' => json_encode(['tipoAmbiente' => 2, 'erro' => ['codigo' => 'E404', 'descricao' => 'Nenhum evento encontrado para a NFS-e.']])]);
eventCheck($absent->getEvents($key50) === [], 'ausência explícita do evento no ambiente certo retorna lista vazia');
$unknownBodies = [
    ['status' => 404, 'body' => '<html>Not Found</html>'],
    ['status' => 404, 'body' => '{"message":"Not Found"}'],
    ['status' => 404, 'body' => '{"tipoAmbiente":2,"versaoAplicativo":"SEFIN"}'],
    ['status' => 404, 'body' => '{"tipoAmbiente":2,"erro":{"descricao":"Endpoint de eventos não encontrado"}}'],
    ['status' => 404, 'body' => '{"tipoAmbiente":1,"erro":{"descricao":"Nenhum evento encontrado para a NFS-e"}}'],
    ['status' => 404, 'body' => '{"tipoAmbiente":2,"erro":{"descricao":"NFS-e não encontrada"}}'],
    ['status' => 200, 'body' => '{"tipoAmbiente":2,"eventos":[]}'],
    ['status' => 200, 'body' => '{"tipoAmbiente":2}'],
    ['status' => 200, 'body' => '{"tipoAmbiente":[],"eventos":[]}'],
    ['status' => 200, 'body' => '{"tipoAmbiente":2,"eventoXmlGZipB64":"abc"}'],
    ['status' => 500, 'body' => '{"erro":{"descricao":"Falha temporária"}}'],
];
foreach ($unknownBodies as $response) {
    $observed = []; $calls = 0;
    $client = new ApiClient('2', $certificate, static function () use (&$calls, $response): array { ++$calls; return $response; },
        static function (array $entry) use (&$observed): void { $observed[] = $entry; });
    eventThrows(fn() => $client->getEvents($key50), 'resposta incompleta ou ambígua permanece inconclusiva', true);
    $expectedCalls = $response['status'] === 404 ? 5 : 1;
    eventCheck($calls === $expectedCalls && count($observed) === $expectedCalls
        && count(array_filter($observed, fn($entry) => $entry['operation_status'] === 'uncertain')) === $expectedCalls,
        'erro registra apenas consulta coletiva e alternativas tipadas permitidas');
}
foreach (['chaveAcesso' => str_repeat('2', 50), 'tipoEvento' => 105104, 'numeroPedidoRegistroEvento' => 2,
    'arquivoXml' => base64_encode(base64_encode(base64_encode(gzencode($xml))))] as $field => $value) {
    $response = eventEnvelope($xml, true); $response['eventos'][0][$field] = $value;
    $client = new ApiClient('2', $certificate, fn() => ['status' => 200, 'body' => json_encode($response)]);
    eventThrows(fn() => $client->getEvents($key50), 'metadado divergente ou Base64 excessivo rejeitado: ' . $field, true);
}
$response = eventEnvelope(eventFixture($key50, '101103'), true);
$response['eventos'][0]['tipoEvento'] = 101103;
$client = new ApiClient('2', $certificate, fn() => ['status' => 200, 'body' => json_encode($response)]);
eventCheck($client->getEvents($key50)[0]['cancellation'] === false, 'consulta de solicitação aceita não marca nota cancelada');

$called = false;
$client = new ApiClient('2', $certificate, static function () use (&$called): array { $called = true; return []; });
eventThrows(fn() => $client->getEvents('../../etc/passwd'), 'chave inválida é recusada antes da rede');
eventCheck(!$called, 'chave inválida não chega ao transporte');

// Some SEFIN versions expose the typed GET only. All recovery requests remain GET.
$absentReply = ['status' => 404, 'body' => '{"tipoAmbiente":2,"erro":{"descricao":"Nenhum evento encontrado para a NFS-e"}}'];
foreach ([404, 405] as $routeStatus) {
    $methods = []; $observed = [];
    $client = new ApiClient('2', $certificate, static function ($method, $url, $body) use ($routeStatus, $xml, &$methods): array {
        $methods[] = [$method, $url, $body];
        if (count($methods) === 1) { return ['status' => $routeStatus, 'body' => '<html>Route unavailable</html>']; }
        return ['status' => 200, 'body' => json_encode(eventEnvelope($xml, true))];
    }, static function (array $entry) use (&$observed): void { $observed[] = $entry; });
    $events = $client->getEvents($key50);
    eventCheck(count($methods) === 2 && $events[0]['cancellation'] && $events[0]['type'] === '101101'
        && $methods[0][0] === 'GET' && $methods[1][0] === 'GET' && $methods[0][2] === null && $methods[1][2] === null
        && str_ends_with($methods[1][1], '/eventos/101101/1'), 'HTTP ' . $routeStatus . ' coletivo usa somente GET tipado e interrompe após prova');
    eventCheck(count($observed) === 2 && $observed[1]['operation'] === 'api.events'
        && str_ends_with($observed[1]['endpoint'], '/nfse/{chaveAcesso}/eventos/{tipoEvento}/{numSeqEvento}')
        && strpos(json_encode($observed), $key50) === false, 'alternativa tipada mantém operação de eventos e URL mascarada');
}
$methods = [];
$client = new ApiClient('2', $certificate, static function ($method, $url) use (&$methods, $key50, $absentReply): array {
    $methods[] = [$method, $url];
    if (count($methods) === 1) { return ['status' => 405, 'body' => '{"message":"Method Not Allowed"}']; }
    if (!str_ends_with($url, '/eventos/105104/1')) { return $absentReply; }
    $reply = eventEnvelope(eventFixture($key50, '105104'), true); $reply['eventos'][0]['tipoEvento'] = 105104;
    return ['status' => 200, 'body' => json_encode($reply)];
});
eventCheck($client->getEvents($key50)[0]['type'] === '105104' && count($methods) === 4
    && array_column($methods, 0) === ['GET', 'GET', 'GET', 'GET'], 'cancelamento deferido é localizado após ausências explícitas, sem quinta consulta');

$methods = [];
$client = new ApiClient('2', $certificate, static function ($method, $url) use (&$methods, $absentReply): array {
    $methods[] = [$method, $url];
    return count($methods) === 1 ? ['status' => 404, 'body' => '<html>Not Found</html>'] : $absentReply;
});
eventCheck($client->getEvents($key50) === [] && count($methods) === 5
    && count(array_unique(array_column($methods, 1))) === 5
    && str_ends_with($methods[4][1], '/eventos/305101/1'), 'somente quatro ausências fiscais explícitas concluem alternativa vazia');

foreach ([500, 401, 403, 429] as $httpStatus) {
    $calls = 0;
    $client = new ApiClient('2', $certificate, static function () use (&$calls, $httpStatus): array {
        ++$calls; return ['status' => $httpStatus, 'body' => '{"erro":{"descricao":"Acesso ou serviço indisponível"}}'];
    });
    eventThrows(fn() => $client->getEvents($key50), 'HTTP ' . $httpStatus . ' não autoriza consulta alternativa');
    eventCheck($calls === 1, 'HTTP ' . $httpStatus . ' preserva uma única chamada');
}
$calls = 0;
$client = new ApiClient('2', $certificate, static function () use (&$calls): array { ++$calls; throw new RuntimeException('Timeout sintético.'); });
eventThrows(fn() => $client->getEvents($key50), 'timeout coletivo não inicia alternativa', true);
eventCheck($calls === 1, 'timeout termina após uma tentativa');

foreach ([404, 405] as $ambiguousStatus) {
    $methods = [];
    $client = new ApiClient('2', $certificate, static function ($method, $url) use (&$methods, $ambiguousStatus, $absentReply): array {
        $methods[] = [$method, $url];
        if (count($methods) <= 2) { return ['status' => $ambiguousStatus, 'body' => '<html>No route</html>']; }
        return $absentReply;
    });
    eventThrows(fn() => $client->getEvents($key50), 'ausência genérica em um tipo não é compensada pelas demais ausências: ' . $ambiguousStatus, true);
    eventCheck(count($methods) === 5 && count(array_unique(array_column($methods, 1))) === 5,
        'recuperação inconclusiva consulta cada alternativa uma única vez');
}
$methods = [];
$client = new ApiClient('2', $certificate, static function ($method, $url) use (&$methods, $key50): array {
    $methods[] = [$method, $url];
    if (count($methods) <= 2) { return ['status' => 404, 'body' => '<html>No route</html>']; }
    return ['status' => 200, 'body' => json_encode(eventEnvelope(eventFixture($key50, '105102')))];
});
eventCheck($client->getEvents($key50)[0]['type'] === '105102' && count($methods) === 3,
    'prova positiva correlacionada confirma cancelamento mesmo após uma alternativa inconclusiva');

foreach ([
    'tipo diferente' => eventFixture($key50, '101103'),
    'sequencial diferente' => eventFixture($key50, '101101', '2', '002'),
    'chave diferente' => eventFixture(str_repeat('2', 50)),
    'ambiente diferente' => eventFixture($key50, '101101', '1'),
] as $label => $wrongXml) {
    $calls = 0;
    $client = new ApiClient('2', $certificate, static function () use (&$calls, $wrongXml): array {
        ++$calls;
        return $calls === 1 ? ['status' => 404, 'body' => '<html>No route</html>']
            : ['status' => 200, 'body' => json_encode(eventEnvelope($wrongXml))];
    });
    eventThrows(fn() => $client->getEvents($key50), 'resposta unitária inválida é inconclusiva: ' . $label, true);
    eventCheck($calls === 2, 'HTTP200 inconsistente na alternativa não dispara outras consultas: ' . $label);
}
$calls = 0;
$client = new ApiClient('2', $certificate, static function () use (&$calls): array {
    ++$calls;
    return $calls === 1 ? ['status' => 404, 'body' => '<html>No route</html>']
        : ['status' => 500, 'body' => '{"erro":{"descricao":"Indisponível"}}'];
});
eventThrows(fn() => $client->getEvents($key50), 'falha do servidor na consulta tipada interrompe recuperação', true);
eventCheck($calls === 2, 'não há quatro repetições de falha de servidor');
echo 'OK: ' . $checks . " verificações de eventos fiscais e consulta de cancelamento, todas offline.\n";
