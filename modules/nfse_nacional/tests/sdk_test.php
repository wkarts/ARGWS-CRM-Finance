<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Offline checks only. Uses a temporary self-signed TEST certificate, never a real identity. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'NfseNacional\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require_once dirname(__DIR__) . '/libraries/NfseNacional/' . substr($class, strlen($prefix)) . '.php';
    }
});

use NfseNacional\ApiClient;
use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\DpsBuilder;
use NfseNacional\XmlSigner;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    $checks++;
    echo 'OK: ' . $message . PHP_EOL;
}
function throws(callable $fn, string $message, ?callable $verify = null): void {
    try { $fn(); } catch (Throwable $e) { check($verify === null || $verify($e), $message); return; }
    check(false, $message);
}

$config = tempnam(sys_get_temp_dir(), 'nfse_test_openssl_');
file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:11222333000181\n");
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
$csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:11222333000181'], $key, ['config' => $config, 'digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'digest_alg' => 'sha256']);
unlink($config);
$pfx = '';
check(openssl_pkcs12_export($cert, $pfx, $key, 'offline-test-password'), 'Criação de A1 de teste efêmero');
$certificate = Certificate::fromPkcs12($pfx, 'offline-test-password', '11222333000181');
check($certificate->metadata()['tax_id'] === '11222333000181', 'Identificação do titular no certificado');
throws(fn() => Certificate::fromPkcs12($pfx, 'senha-incorreta'), 'Senha incorreta é rejeitada');
throws(fn() => $certificate->assertUsable('00000000000191'), 'Certificado de outro prestador é rejeitado');

$draft = [
    'environment' => '2', 'competence' => '2026-11-01', 'issued_at' => '2026-11-10T12:00:00-03:00',
    'provider' => ['tax_id' => '11222333000181', 'municipality_code' => '3550308', 'simple_status' => '3', 'simple_apportionment' => '1', 'special_regime' => '0'],
    'recipient' => ['name' => 'TOMADOR DE TESTE & FILHOS', 'tax_id' => '52998224725', 'email' => 'offline@example.invalid', 'phone' => '11999999999', 'municipality_code' => '3550308', 'zip' => '01001000', 'street' => 'Rua de teste', 'number' => '10', 'complement' => '', 'district' => 'Centro'],
    'service' => ['municipality_code' => '3550308', 'national_code' => '010101', 'municipal_code' => '', 'nbs' => '115061000', 'description' => 'Serviço de teste & desenvolvimento <offline>'],
    'values' => ['service_amount' => '100.00', 'unconditional_discount' => '2.50', 'conditional_discount' => '1.25', 'iss_taxation' => '1', 'retention' => '1', 'iss_rate' => '2.00', 'total_tax_mode' => 'none'],
    'ibscbs' => ['enabled' => '0', 'operation_indicator' => '100301', 'destination_indicator' => '0', 'cst' => '000', 'class_code' => '000001', 'final_consumer' => '1', 'purpose' => '0'],
];
$builder = new DpsBuilder();
foreach (['1', '2'] as $environment) {
    foreach (['0', '1'] as $ibsEnabled) {
        $draft['environment'] = $environment;
        $draft['ibscbs']['enabled'] = $ibsEnabled;
        $xml = $builder->build($draft, '1', '1');
        $signed = XmlSigner::sign($xml, $certificate);
        check(XmlSigner::verify($signed), 'Assinatura íntegra ambiente ' . $environment . ' IBS/CBS ' . $ibsEnabled);
        $errors = $builder->validateSchema($signed, $environment);
        check($errors === [], 'XSD ambiente ' . $environment . ' IBS/CBS ' . $ibsEnabled . ': ' . implode('; ', $errors));
        check(!XmlSigner::verify(str_replace('100.00', '100.01', $signed)), 'Alteração do valor invalida assinatura');
        check(strpos($signed, '&amp;') !== false && strpos($signed, '&lt;offline&gt;') !== false, 'Texto livre protegido pelo DOM');
    }
}
check(DpsBuilder::identifier($draft['provider'], '1', '1') === 'DPS355030821122233300018100001000000000000001', 'Identificador DPS 45 posições');
throws(fn() => $builder->build($draft, '90000', '1'), 'Série reservada 90000 é recusada');
throws(fn() => $builder->build($draft, '1', '0'), 'Número zero não é uma DPS válida');
throws(fn() => XmlSigner::loadSafe('<!DOCTYPE x [<!ENTITY file SYSTEM "file:///etc/passwd">]><x>&file;</x>'), 'DTD e entidade externa são bloqueadas');
$draft['values']['total_tax_mode'] = 'amount';
$draft['values']['total_federal'] = '10.00';
$draft['values']['total_state'] = '0.00';
$draft['values']['total_municipal'] = '2.00';
check($builder->validateSchema(XmlSigner::sign($builder->build($draft, '1', '2'), $certificate), '2') === [], 'Totalizadores monetários no XSD');
$draft['values']['total_tax_mode'] = 'simple';
$draft['values']['simple_rate'] = '6.00';
check($builder->validateSchema(XmlSigner::sign($builder->build($draft, '1', '3'), $certificate), '2') === [], 'Alíquota total do Simples no XSD');
$draft['ibscbs']['cst'] = '410';
$draft['ibscbs']['class_code'] = '410001';
check($builder->validateSchema(XmlSigner::sign($builder->build($draft, '1', '4'), $certificate), '2') === [], 'CST 410 sem gIBSCBS no leiaute NT009');
$draft['recipient']['tax_id'] = '12ABC34501DE35';
check($builder->validateSchema(XmlSigner::sign($builder->build($draft, '1', '5'), $certificate), '2') === [], 'CNPJ alfanumérico no XSD de homologação');
$draft['environment'] = '1';
check($builder->validateSchema(XmlSigner::sign($builder->build($draft, '1', '5'), $certificate), '1') !== [], 'XSD de produção antigo sinaliza limitação de CNPJ alfanumérico');
$draft['environment'] = '2';
$draft['recipient']['tax_id'] = '52998224725';
$signed = XmlSigner::sign($builder->build($draft, '1', '6'), $certificate);
$id = DpsBuilder::identifier($draft['provider'], '1', '6');
$key50 = str_repeat('1', 50);
$authorizedXml = '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key50 . '"><nNFSe>1</nNFSe>' . preg_replace('/<\?xml[^>]*\?>\s*/', '', $signed) . '</infNFSe></NFSe>';
$success = ['tipoAmbiente' => 2, 'idDps' => $id, 'chaveAcesso' => $key50, 'nfseXmlGZipB64' => base64_encode(gzencode($authorizedXml))];
$calls = 0;
$api = new ApiClient('2', $certificate, function ($method, $url, $body) use (&$calls, $success, $signed) {
    $calls++;
    check($method === 'POST' && $url === 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional/nfse', 'Endpoint de homologação fixo');
    check(gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64'])) === $signed, 'Envelope JSON GZip/Base64 preserva XML assinado');
    return ['status' => 201, 'body' => json_encode($success)];
});
check($api->submit($signed)['chaveAcesso'] === $key50 && $calls === 1, 'Autorização simulada sem retransmissão automática');
check(ApiClient::decodeXml($success) === $authorizedXml, 'XML autorizado é descompactado integralmente');
$bad = new ApiClient('2', $certificate, fn() => ['status' => 201, 'body' => '{"mensagem":"incompleto"}']);
throws(fn() => $bad->submit($signed), 'Sucesso HTTP incompleto é resultado incerto', fn($e) => $e instanceof ApiException && $e->isUncertain());
$timeout = new ApiClient('2', $certificate, function () { throw new RuntimeException('timeout'); });
throws(fn() => $timeout->submit($signed), 'Timeout é resultado incerto', fn($e) => $e instanceof ApiException && $e->isUncertain());
$reject = new ApiClient('2', $certificate, fn() => ['status' => 400, 'body' => '{"erros":[{"codigo":"E1235","descricao":"Falha de esquema"}]}']);
throws(fn() => $reject->submit($signed), 'Rejeição fiscal estruturada é identificada', fn($e) => $e instanceof ApiException && !$e->isUncertain() && $e->httpStatus() === 400);
$absent = new ApiClient('2', $certificate, fn() => ['status' => 404, 'body' => '{"erro":{"codigo":"E0001","descricao":"DPS não encontrada"}}']);
check($absent->findByDps($id) === null, 'DPS não encontrada é retornada como ausência');
$bad404 = new ApiClient('2', $certificate, fn() => ['status' => 404, 'body' => '<html>Gateway</html>']);
throws(fn() => $bad404->findByDps($id), '404 do gateway não vira ausência de DPS', fn($e) => $e instanceof ApiException && $e->isUncertain());
$municipal = new ApiClient('2', $certificate, function ($method, $url) {
    check($url === 'https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/convenio', 'Consulta municipal usa a API ADN parametrizacao');
    return ['status' => 200, 'body' => '{"mensagem":null,"parametrosConvenio":{"aderenteAmbienteNacional":1,"aderenteEmissorNacional":1}}'];
});
$municipal->municipality('3550308');

$municipalEvents = [];
$observeMunicipal = static function (array $event) use (&$municipalEvents): void { $municipalEvents[] = $event; };
$municipal404 = new ApiClient('1', $certificate, function ($method, $url) {
    check($method === 'GET' && $url === 'https://adn.nfse.gov.br/parametrizacao/3550308/convenio', 'Consulta de produção preserva a base oficial de parâmetros');
    return ['status' => 404, 'body' => '{"mensagem":"Município não encontrado neste ambiente.","parametrosConvenio":null}'];
}, $observeMunicipal);
throws(fn() => $municipal404->municipality('3550308'), '404 municipal preserva a mensagem do ADN sem concluir ausência de convênio', fn($e) => $e instanceof ApiException && $e->httpStatus() === 404 && $e->isUncertain()
    && strpos($e->getMessage(), 'Município não encontrado neste ambiente.') !== false
    && strpos($e->getMessage(), 'SEFIN') === false && strpos($e->getMessage(), 'não confirma a ausência de convênio') !== false);
check(count($municipalEvents) === 1 && $municipalEvents[0]['operation'] === 'api.municipality'
    && $municipalEvents[0]['service'] === 'adn_parametrizacao' && $municipalEvents[0]['http_status'] === 404
    && $municipalEvents[0]['operation_status'] === 'uncertain', 'Observer registra uma única consulta municipal inconclusiva');
check($municipalEvents[0]['endpoint'] === 'https://adn.nfse.gov.br/parametrizacao/{codigoMunicipio}/convenio'
    && $municipalEvents[0]['request_bytes'] === 0 && $municipalEvents[0]['response_type'] === 'json_object', 'Log municipal usa URL oficial com parâmetro mascarado e metadados');

foreach (['' => 'empty', '<!doctype html><html>proxy-secret</html>' => 'html', '<error>proxy-secret</error>' => 'xml', '{}' => 'json_object', 'not found' => 'invalid_json'] as $body404 => $type) {
    $events = [];
    $unavailable = new ApiClient('2', $certificate, fn() => ['status' => 404, 'body' => $body404], static function (array $event) use (&$events): void { $events[] = $event; });
    throws(fn() => $unavailable->municipality('3550308'), '404 municipal ' . $type . ' não vira rejeição fiscal ou ausência de convênio', fn($e) => $e instanceof ApiException && $e->isUncertain() && strpos($e->getMessage(), 'SEFIN') === false);
    check(count($events) === 1 && $events[0]['response_type'] === $type && $events[0]['response_bytes'] === strlen($body404)
        && $events[0]['response_sha256'] === hash('sha256', $body404) && strpos(json_encode($events), 'proxy-secret') === false, 'Observer distingue ' . $type . ' sem copiar corpo bruto');
}
$badMunicipal = new ApiClient('2', $certificate, fn() => ['status' => 200, 'body' => '{"mensagem":"Consulta sem dados","parametrosConvenio":null}']);
throws(fn() => $badMunicipal->municipality('3550308'), 'HTTP200 sem parâmetros municipais não confirma consulta bem-sucedida', fn($e) => $e instanceof ApiException && $e->isUncertain());

foreach (['{"erro":{"codigo":"E404","descricao":"Endpoint DPS não encontrado"}}', '{"erro":{"codigo":"E0001","descricao":"Não encontrada"}}', '{"message":"Not Found"}'] as $opaque404) {
    $notAbsent = new ApiClient('2', $certificate, fn() => ['status' => 404, 'body' => $opaque404]);
    throws(fn() => $notAbsent->findByDps($id), '404 sem confirmação de ausência da DPS permanece incerto', fn($e) => $e instanceof ApiException && $e->isUncertain());
}
$absenceEvents = [];
$confirmedAbsent = new ApiClient('2', $certificate, fn() => ['status' => 404, 'body' => '{"erro":{"codigo":"E0001","descricao":"Não existe NFS-e para o identificador da DPS informado."}}'], static function (array $event) use (&$absenceEvents): void { $absenceEvents[] = $event; });
check($confirmedAbsent->findByDps($id) === null && count($absenceEvents) === 1 && $absenceEvents[0]['operation_status'] === 'not_found', 'Ausência expressa de DPS é distinta de falha de rota');

$observed = [];
$observedBody = null;
$observedApi = new ApiClient('2', $certificate, function ($method, $url, $body) use ($success, &$observedBody) {
    $observedBody = $body;
    return ['status' => 201, 'body' => json_encode($success)];
}, static function (array $event) use (&$observed): void { $observed[] = $event; });
check($observedApi->submit($signed)['chaveAcesso'] === $key50 && count($observed) === 1 && $observed[0]['operation_status'] === 'success', 'Observer confirma sucesso somente após validar a autorização');
check($observed[0]['request_bytes'] === strlen($observedBody) && $observed[0]['request_sha256'] === hash('sha256', $observedBody)
    && $observed[0]['response_bytes'] === strlen(json_encode($success)) && $observed[0]['response_sha256'] === hash('sha256', json_encode($success))
    && $observed[0]['duration_ms'] >= 0, 'Tamanhos, resumos SHA256 e duração correlacionam a comunicação sem conteúdo fiscal');
$observation = json_encode($observed);
check(strpos($observation, $id) === false && strpos($observation, $key50) === false && strpos($observation, 'dpsXmlGZipB64') === false
    && strpos($observation, 'nfseXmlGZipB64') === false && strpos($observation, 'CERTIFICATE') === false, 'Evento HTTP não expõe XML, Base64, DPS, chave ou certificado');

$incompleteEvents = [];
$incomplete = new ApiClient('2', $certificate, fn() => ['status' => 201, 'body' => '{"mensagem":"incompleto"}'], static function (array $event) use (&$incompleteEvents): void { $incompleteEvents[] = $event; });
throws(fn() => $incomplete->submit($signed), 'Resposta201 incompleta é incerta também no observer', fn($e) => $e instanceof ApiException && $e->isUncertain());
check(count($incompleteEvents) === 1 && $incompleteEvents[0]['http_status'] === 201 && $incompleteEvents[0]['operation_status'] === 'uncertain', 'Log preserva HTTP real e resultado inconclusivo de uma resposta incompleta');

$transportEvents = [];
$transportFailure = new ApiClient('2', $certificate, function () { throw new RuntimeException('proxy-password-private-key-details'); }, static function (array $event) use (&$transportEvents): void { $transportEvents[] = $event; });
throws(fn() => $transportFailure->municipality('3550308'), 'Falha de transporte municipal não menciona SEFIN', fn($e) => $e instanceof ApiException && $e->isUncertain() && strpos($e->getMessage(), 'ADN') !== false);
check(count($transportEvents) === 1 && $transportEvents[0]['response_type'] === 'transport_error' && $transportEvents[0]['http_status'] === 0
    && strpos(json_encode($transportEvents), 'proxy-password-private-key-details') === false, 'Falha de transporte é observada sem detalhes privados da exceção original');

$loggingCalls = 0;
$brokenObserver = new ApiClient('2', $certificate, function () use ($success, &$loggingCalls) { $loggingCalls++; return ['status' => 201, 'body' => json_encode($success)]; }, static function (): void { throw new RuntimeException('Banco de logs indisponível'); });
check($brokenObserver->submit($signed)['chaveAcesso'] === $key50 && $loggingCalls === 1, 'Falha do logger não altera a emissão nem repete o POST');

$lookupEvents = [];
$lookup = new ApiClient('2', $certificate, fn() => ['status' => 200, 'body' => json_encode($success)], static function (array $event) use (&$lookupEvents): void { $lookupEvents[] = $event; });
check($lookup->findByDps($id)['chaveAcesso'] === $key50 && $lookup->fetch($key50)['chaveAcesso'] === $key50, 'Consultas preservam validação e retorno com observer');
check(count($lookupEvents) === 2 && $lookupEvents[0]['operation'] === 'api.find_dps' && $lookupEvents[1]['operation'] === 'api.fetch'
    && strpos($lookupEvents[0]['endpoint'], $id) === false && strpos($lookupEvents[1]['endpoint'], $key50) === false, 'Operações DPS e XML são identificadas sem expor identificadores no endpoint');

foreach (['<DPS><toma><xNome>PRIVATE_RECIPIENT_NAME</xNome></toma></DPS>', '&lt;DPS&gt;&lt;xNome&gt;PRIVATE_RECIPIENT_NAME&lt;/xNome&gt;&lt;/DPS&gt;'] as $echoedXml) {
    $echoEvents = [];
    $echoResponse = new ApiClient('2', $certificate, fn() => ['status' => 404, 'body' => json_encode(['mensagem' => $echoedXml])], static function (array $event) use (&$echoEvents): void { $echoEvents[] = $event; });
    throws(fn() => $echoResponse->municipality('3550308'), 'XML ecoado no erro municipal é omitido antes de remover tags', fn($e) => $e instanceof ApiException && $e->isUncertain()
        && strpos($e->getMessage(), 'PRIVATE_RECIPIENT_NAME') === false && strpos($e->getMessage(), 'XML omitidos') !== false);
    check(count($echoEvents) === 1 && strpos(json_encode($echoEvents), 'PRIVATE_RECIPIENT_NAME') === false, 'Observer não transforma XML de erro em texto pessoal sem identificação');
}
echo PHP_EOL . $checks . " verificações offline aprovadas. Nenhuma requisição real foi realizada.\n";
