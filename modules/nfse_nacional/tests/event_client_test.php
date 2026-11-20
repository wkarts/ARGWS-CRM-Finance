<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\EventClient;
use NfseNacional\EventRequest;
use NfseNacional\NfseDocument;

/** Offline only: fresh synthetic certificates and fiscal structures, injected HTTP transport. */
$checks = 0;
function eventClientCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['checks'];
}
function eventClientFails(callable $operation, string $message, ?callable $predicate = null): Throwable
{
    try { $operation(); }
    catch (Throwable $error) { eventClientCheck($predicate === null || $predicate($error), $message); return $error; }
    throw new RuntimeException('FALHOU: ' . $message);
}
function eventClientCertificate(string $id): Certificate
{
    $config = tempnam(sys_get_temp_dir(), 'nfse_event_test_');
    if ($config === false) { throw new RuntimeException('Falha ao preparar fixture de certificado.'); }
    try {
        file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:" . $id . "\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:' . $id], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'digest_alg' => 'sha256']);
        $pfx = '';
        if (!openssl_pkcs12_export($cert, $pfx, $key, 'offline-event')) { throw new RuntimeException('Falha no certificado sintético.'); }
        return Certificate::fromPkcs12($pfx, 'offline-event', $id);
    } finally { if (is_file($config)) { unlink($config); } }
}
function eventClientNote(string $environment): NfseDocument
{
    $key = '35503082211222333000181' . str_repeat('0', 26) . '1';
    $id = 'DPS355030821122233300018100001000000000000042';
    return new NfseDocument('<?xml version="1.0" encoding="UTF-8"?>'
        . '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key . '">'
        . '<nNFSe>42</nNFSe><cStat>100</cStat><dhProc>2026-11-10T12:01:00-03:00</dhProc>'
        . '<emit><CNPJ>11222333000181</CNPJ><xNome>EMITENTE SINTÉTICO</xNome></emit>'
        . '<DPS versao="1.01"><infDPS Id="' . $id . '"><tpAmb>' . $environment . '</tpAmb>'
        . '<dhEmi>2026-11-10T12:00:00-03:00</dhEmi><serie>1</serie><nDPS>42</nDPS><dCompet>2026-11-01</dCompet><tpEmit>1</tpEmit>'
        . '<prest><CNPJ>11222333000181</CNPJ></prest><toma><CPF>52998224725</CPF><xNome>TOMADOR SINTÉTICO</xNome></toma>'
        . '<serv><cServ><cTribNac>010101</cTribNac><xDescServ>TESTE OFFLINE</xDescServ></cServ></serv>'
        . '<valores><vServPrest><vServ>100.00</vServ></vServPrest></valores>'
        . '</infDPS></DPS></infNFSe></NFSe>');
}
function eventClientRegistered(string $request, string $key, string $type, int $sequence = 1): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">'
        . '<infEvento Id="EVT' . $key . $type . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT) . '">'
        . '<verAplic>FIXTURE OFFLINE</verAplic><ambGer>2</ambGer><nSeqEvento>' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT) . '</nSeqEvento>'
        . '<dhProc>2026-11-10T12:02:00-03:00</dhProc><nDFSe>42</nDFSe>'
        . preg_replace('/<\?xml[^>]*\?>\s*/', '', $request) . '</infEvento>'
        . '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignatureValue>RklYVFVSRSBPRkZMSU5F</SignatureValue></Signature></evento>';
}
function eventClientResponse(string $event, string $environment = '2', int $status = 201): array
{
    return ['status' => $status, 'body' => json_encode(['tipoAmbiente' => (int) $environment, 'versaoAplicativo' => 'FIXTURE OFFLINE',
        'dataHoraProcessamento' => '2026-11-10T12:02:00-03:00', 'eventoXmlGZipB64' => base64_encode(gzencode($event))], JSON_THROW_ON_ERROR)];
}
function eventClientError(int $status, string $description, string $code = 'E0000', string $environment = '2'): array
{
    return ['status' => $status, 'body' => json_encode(['tipoAmbiente' => (int) $environment,
        'erros' => [['codigo' => $code, 'descricao' => $description]]], JSON_THROW_ON_ERROR)];
}

$provider = eventClientCertificate('11222333000181');
$recipient = eventClientCertificate('52998224725');
$otherProviderKey = eventClientCertificate('11222333000181');
$occurredAt = '2026-11-10T12:01:30-03:00';
$reason = 'Serviço não prestado conforme conferência da empresa.';
$requests = [];
$note = eventClientNote('2'); $key = $note->accessKey();

foreach (['1' => 'https://sefin.nfse.gov.br/SefinNacional', '2' => 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional'] as $environment => $base) {
    $environment = (string) $environment;
    foreach (['101101', '101103', '203202', '203206'] as $type) {
        $author = in_array($type, ['101101', '101103'], true) ? '11222333000181' : '52998224725';
        $cert = $author === '11222333000181' ? $provider : $recipient;
        $code = $type === '203202' ? '' : ($type === '203206' ? '3' : '2');
        $text = $type === '203202' ? '' : $reason;
        $signed = EventRequest::build(eventClientNote($environment), $author, $type, $code, $text, $cert, $occurredAt);
        $requests[$environment][$type] = $signed;
        $metadata = EventRequest::inspect($signed, $cert);
        eventClientCheck(EventRequest::verify($signed) && $metadata['id'] === 'PRE' . $key . $type
            && $metadata['environment'] === $environment && $metadata['author_tax_id'] === $author, 'pedido atende schema e assinatura em ' . $environment . '/' . $type);
        eventClientCheck(strpos($signed, '<nPedRegEvento>') === false && strlen($metadata['id']) === 59
            && $metadata['reason_code'] === $code && $metadata['reason'] === $text, 'identificador e motivo seguem o pedido nacional sem sequencial inventado/' . $type);
        $registered = eventClientRegistered($signed, $key, $type);
        $calls = []; $logs = [];
        $client = new EventClient($environment, $cert,
            static function ($method, $url, $body, $usedCertificate) use (&$calls, $registered, $environment): array {
                $calls[] = [$method, $url, $body, $usedCertificate];
                return eventClientResponse($registered, $environment, $method === 'POST' ? 201 : 200);
            }, static function (array $entry) use (&$logs): void { $logs[] = $entry; });
        $result = $client->submit($signed);
        eventClientCheck(count($calls) === 1 && $calls[0][0] === 'POST' && $calls[0][1] === $base . '/nfse/' . $key . '/eventos'
            && $calls[0][3] === $cert, 'submit faz um único POST no ambiente e certificado exatos/' . $environment . '/' . $type);
        $envelope = json_decode($calls[0][2], true, 32, JSON_THROW_ON_ERROR);
        eventClientCheck(array_keys($envelope) === ['pedidoRegistroEventoXmlGZipB64']
            && gzdecode(base64_decode($envelope['pedidoRegistroEventoXmlGZipB64'], true)) === $signed, 'envelope preserva pedido assinado/' . $type);
        eventClientCheck($result['type'] === $type && $result['xml'] === $registered && $result['cancellation'] === ($type === '101101')
            && $result['protocol'] === '', 'apenas cancelamento registrado muda situação; nenhum protocolo fabricado/' . $type);
        $found = $client->find($key, $type);
        eventClientCheck($found['id'] === $result['id'] && count($calls) === 2 && $calls[1][0] === 'GET'
            && $calls[1][1] === $base . '/nfse/' . $key . '/eventos/' . $type . '/1' && $calls[1][2] === null, 'recuperação consulta o evento exato sem novo POST/' . $type);
        $serializedLogs = json_encode($logs);
        eventClientCheck(count($logs) === 2 && $logs[0]['operation'] === 'api.event_submit' && $logs[1]['operation'] === 'api.event_find'
            && $logs[0]['operation_status'] === 'success' && strpos($serializedLogs, $key) === false
            && strpos($serializedLogs, $author) === false && strpos($serializedLogs, 'pedidoRegistroEventoXmlGZipB64') === false
            && strpos($serializedLogs, 'xMotivo') === false, 'auditoria registra operação sem conteúdo fiscal ou identificações/' . $type);
    }
}

// Input and actor restrictions are checked before any transmission.
foreach (['105102', '105104', '105105', '305101', '205204', '202201', '999999'] as $disallowed) {
    eventClientFails(fn() => EventRequest::build($note, '11222333000181', $disallowed, '2', $reason, $provider, $occurredAt), 'evento de autoridade/automático/não implementado bloqueado/' . $disallowed);
}
eventClientFails(fn() => EventRequest::build($note, '52998224725', '101101', '2', $reason, $recipient, $occurredAt), 'tomador não cancela a nota do prestador');
eventClientFails(fn() => EventRequest::build($note, '11222333000181', '203202', '', '', $provider, $occurredAt), 'prestador não manifesta confirmação em nome do tomador');
eventClientFails(fn() => EventRequest::build($note, '11222333000181', '101101', '2', $reason, $recipient, $occurredAt), 'certificado do tomador não assina pedido do prestador');
foreach ([['', $reason], ['3', $reason], ['2', 'curta'], ['2', str_repeat('a', 256)], ['2', 'Justificativa fiscal inválida 😀']] as [$code, $text]) {
    eventClientFails(fn() => EventRequest::build($note, '11222333000181', '101101', $code, $text, $provider, $occurredAt), 'motivo/justificativa inválido não recebe padrão silencioso');
}
eventClientCheck(EventRequest::verify(EventRequest::build($note, '11222333000181', '101103', '9', str_repeat('a', 255), $provider, $occurredAt)), 'limite superior de justificativa aceito sem truncamento');
eventClientCheck(EventRequest::verify(EventRequest::build($note, '52998224725', '203206', '9', '', $recipient, $occurredAt)), 'rejeição respeita justificativa opcional do XSD');
eventClientFails(fn() => EventRequest::build($note, '52998224725', '203202', '1', $reason, $recipient, $occurredAt), 'confirmação não aceita campos de rejeição');
eventClientFails(fn() => EventRequest::build($note, '11222333000181', '101101', '2', $reason, $provider, '2026-02-30T12:01:30-03:00'), 'data inexistente bloqueada');

$calls = 0;
$guard = new EventClient('2', $provider, static function () use (&$calls): array { ++$calls; throw new RuntimeException('Transporte não deveria ocorrer.'); });
$signed = $requests['2']['101101'];
foreach ([
    $requests['1']['101101'],
    str_replace('Serviço não prestado', 'Serviço foi prestado', $signed),
    str_replace('http://www.w3.org/2000/09/xmldsig#rsa-sha1', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', $signed),
    str_replace('<tpAmb>2</tpAmb>', '<tpAmb>2</tpAmb><tpAmb>2</tpAmb>', $signed),
    str_replace('<cMotivo>2</cMotivo>', '<cMotivo>3</cMotivo>', $signed),
    '<!DOCTYPE pedRegEvento [<!ENTITY secret SYSTEM "file:///never-read">]>' . preg_replace('/<\?xml[^>]*\?>/', '', $signed),
    $requests['2']['203206'],
] as $invalid) {
    eventClientFails(fn() => $guard->submit($invalid), 'pedido adulterado, ambiente ou certificado divergente bloqueado', static fn(Throwable $e): bool => $e instanceof InvalidArgumentException);
}
eventClientCheck($calls === 0, 'falhas locais jamais chegam ao POST');
eventClientFails(fn() => EventRequest::inspect($signed, $otherProviderKey), 'assinatura de outro A1 é rejeitada mesmo com o mesmo CNPJ');

// The typed GET may establish explicit absence; routes/permissions/timeouts may not.
$notFound = new EventClient('2', $provider, static fn() => eventClientError(404, 'Nenhum evento encontrado para a NFS-e.'));
eventClientCheck($notFound->find($key, '101101') === null, 'ausência fiscal explícita no ambiente correto retorna null');
foreach ([
    ['status' => 404, 'body' => '<html>Route not found</html>'],
    ['status' => 404, 'body' => '{}'],
    ['status' => 204, 'body' => ''],
    eventClientError(404, 'Recurso de eventos não encontrado.'),
    eventClientError(404, 'Evento não encontrado por falta de permissão.'),
    eventClientError(404, 'Nenhum evento encontrado. Solicitante não autorizado.'),
    eventClientError(404, 'Nenhum evento encontrado para a NFS-e.', 'E0000', '1'),
    eventClientError(500, 'Nenhum evento encontrado para a NFS-e.'),
] as $failure) {
    $readCalls = 0;
    $client = new EventClient('2', $provider, static function () use (&$readCalls, $failure): array { ++$readCalls; return $failure; });
    eventClientFails(fn() => $client->find($key, '101101'), '404/204 ambíguo, ambiente incorreto ou erro não são ausência', static fn(Throwable $e): bool => $e instanceof ApiException && $e->isUncertain());
    eventClientCheck($readCalls === 1, 'consulta inconclusiva não repete nem transmite evento');
}

$registered = eventClientRegistered($signed, $key, '101101');
$otherReason = EventRequest::build($note, '11222333000181', '101101', '1', 'Cancelamento por erro no documento originalmente emitido.', $provider, $occurredAt);
$mismatches = [
    eventClientResponse($registered, '1'),
    eventClientResponse(eventClientRegistered(str_replace($key, str_repeat('2', 50), $signed), str_repeat('2', 50), '101101')),
    eventClientResponse(eventClientRegistered($requests['2']['101103'], $key, '101103')),
    eventClientResponse($signed),
    eventClientResponse(eventClientRegistered($otherReason, $key, '101101')),
    ['status' => 201, 'body' => json_encode(['tipoAmbiente' => 2, 'eventoXmlGZipB64' => base64_encode(gzencode(str_repeat('x', 5242881)))])],
    eventClientError(500, 'Falha interna.'),
    eventClientError(429, 'Limite de consulta.'),
    eventClientError(400, 'O evento já foi registrado.', 'E0001'),
];
foreach ($mismatches as $response) {
    $postCalls = 0; $logs = [];
    $client = new EventClient('2', $provider, static function () use (&$postCalls, $response): array { ++$postCalls; return $response; },
        static function (array $entry) use (&$logs): void { $logs[] = $entry; });
    eventClientFails(fn() => $client->submit($signed), 'POST sem correlação íntegra não confirma registro', static fn(Throwable $e): bool => $e instanceof ApiException && $e->isUncertain());
    eventClientCheck($postCalls === 1 && count($logs) === 1 && $logs[0]['operation_status'] === 'uncertain', 'incerteza é registrada sem repetição automática do POST');
}
$lostCalls = [];
$lost = new EventClient('2', $provider, static function ($method) use (&$lostCalls, $registered): array {
    $lostCalls[] = $method;
    if ($method === 'POST') { throw new RuntimeException('Timeout; https://secret:PRIVATE_TOKEN@host.invalid/11222333000181'); }
    return eventClientResponse($registered, '2', 200);
});
$lostError = eventClientFails(fn() => $lost->submit($signed), 'resposta perdida permanece inconclusiva', static fn(Throwable $e): bool => $e instanceof ApiException && $e->httpStatus() === 0 && $e->isUncertain());
eventClientCheck($lost->find($key, '101101')['id'] === 'EVT' . $key . '101101001' && $lostCalls === ['POST', 'GET'], 'recuperação depois de timeout obtém evento sem duplicar POST');
eventClientCheck(strpos($lostError->getMessage(), 'PRIVATE_TOKEN') === false && strpos($lostError->getMessage(), '11222333000181') === false, 'erro de transporte não revela credenciais ou CNPJ');

// Legacy collection item encoding is accepted only in the typed lookup envelope.
$legacy = ['status' => 200, 'body' => json_encode(['tipoAmbiente' => 2, 'eventos' => [['arquivoXml' => base64_encode(base64_encode(gzencode($registered)))]]])];
eventClientCheck((new EventClient('2', $provider, static fn() => $legacy))->find($key, '101101')['cancellation'], 'consulta reconhece arquivoXml com envelope duplo utilizado pela SEFIN');
$logs = []; $private = 'CPF 52998224725; CNPJ 11222333000181; <NFSe>PRIVATE_XML</NFSe>; senha=PRIVATE_PASSWORD';
$rejected = new EventClient('2', $provider, static fn() => eventClientError(400, $private, 'E0827'), static function (array $entry) use (&$logs): void { $logs[] = $entry; });
$error = eventClientFails(fn() => $rejected->submit($signed), 'rejeição fiscal explícita preserva código útil', static fn(Throwable $e): bool => $e instanceof ApiException && !$e->isUncertain() && $e->httpStatus() === 400);
$visible = $error->getMessage() . json_encode($logs);
eventClientCheck(strpos($visible, 'E0827') !== false && strpos($visible, '52998224725') === false && strpos($visible, '11222333000181') === false
    && strpos($visible, 'PRIVATE_XML') === false && strpos($visible, 'PRIVATE_PASSWORD') === false, 'erro externo não expõe texto fiscal/pessoal em logs ou mensagem');
$observerCalls = 0;
$badObserver = new EventClient('2', $provider, static function () use (&$observerCalls, $registered): array { ++$observerCalls; return eventClientResponse($registered); }, static function (): void { throw new RuntimeException('Observador offline indisponível.'); });
eventClientCheck($badObserver->submit($signed)['cancellation'] && $observerCalls === 1, 'falha de auditoria não perde evento confirmado nem repete envio');

echo "OK — {$checks} verificações de pedidos, assinatura, schema e eventos SEFIN (sem rede).\n";
