<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use NfseNacional\ApiClient;
use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\DpsBuilder;
use NfseNacional\XmlSigner;

/**
 * Routing contract, entirely offline. Expected URLs are independent of the client.
 * Synthetic identities/envelopes follow sdk_test.php and fiscal_events_test.php.
 * Every client receives a transport double; no real A1 or network is used.
 */
$checks = 0;
function environmentCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$GLOBALS['checks'];
}
function environmentFails(callable $operation, callable $verify, string $message): void
{
    try { $operation(); }
    catch (Throwable $error) { environmentCheck($verify($error), $message); return; }
    throw new RuntimeException('FALHOU: ' . $message);
}

function environmentEvent(string $environment, string $key): string
{
    // Registered-event structure only, as in fiscal_events_test.php; not an ICP-Brasil signature fixture.
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

$opensslConfig = tempnam(sys_get_temp_dir(), 'nfse_environments_test_');
if ($opensslConfig === false) { throw new RuntimeException('Não foi possível preparar o certificado sintético.'); }
try {
    file_put_contents($opensslConfig, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:11222333000181\n");
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $opensslConfig]);
    $csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:11222333000181'], $private, ['config' => $opensslConfig, 'digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $private, 1, ['config' => $opensslConfig, 'digest_alg' => 'sha256']);
    $pfx = '';
    if (!openssl_pkcs12_export($x509, $pfx, $private, 'offline-environments')) {
        throw new RuntimeException('Não foi possível gerar o certificado sintético.');
    }
    $certificate = Certificate::fromPkcs12($pfx, 'offline-environments', '11222333000181');
} finally { if (is_file($opensslConfig)) { unlink($opensslConfig); } }

$draft = [
    'environment' => '2', 'competence' => '2026-11-01', 'issued_at' => '2026-11-10T12:00:00-03:00',
    'provider' => ['tax_id' => '11222333000181', 'municipality_code' => '3550308', 'simple_status' => '3', 'simple_apportionment' => '1', 'special_regime' => '0'],
    'recipient' => ['name' => 'TOMADOR SINTÉTICO', 'tax_id' => '52998224725', 'email' => 'offline@example.invalid',
        'municipality_code' => '3550308', 'zip' => '01001000', 'street' => 'Rua de teste', 'number' => '10', 'district' => 'Centro'],
    'service' => ['municipality_code' => '3550308', 'national_code' => '010101', 'municipal_code' => '', 'nbs' => '115061000', 'description' => 'TESTE OFFLINE'],
    'values' => ['service_amount' => '100.00', 'unconditional_discount' => '0.00', 'conditional_discount' => '0.00',
        'iss_taxation' => '1', 'retention' => '1', 'iss_rate' => '2.00', 'total_tax_mode' => 'none'],
    'ibscbs' => ['enabled' => '0'],
];
$builder = new DpsBuilder();
$signedByEnvironment = [];
foreach (['1', '2'] as $environment) {
    $draft['environment'] = $environment;
    $signedByEnvironment[$environment] = XmlSigner::sign($builder->build($draft, '1', '6'), $certificate);
}
$dpsId = DpsBuilder::identifier($draft['provider'], '1', '6');
$key = str_repeat('1', 50);
$matrix = [
    '1' => ['sefin' => 'https://sefin.nfse.gov.br/SefinNacional', 'parameters' => 'https://adn.nfse.gov.br/parametrizacao'],
    '2' => ['sefin' => 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional', 'parameters' => 'https://adn.producaorestrita.nfse.gov.br/parametrizacao'],
];

foreach ($matrix as $environmentKey => $bases) {
    $environment = (string) $environmentKey;
    $otherEnvironment = $environment === '1' ? '2' : '1';
    $signed = $signedByEnvironment[$environment];
    $authorizedXml = '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">'
        . '<infNFSe Id="NFS' . $key . '"><nNFSe>1</nNFSe>'
        . preg_replace('/<\?xml[^>]*\?>\s*/', '', $signed) . '</infNFSe></NFSe>';
    $authorized = ['tipoAmbiente' => (int) $environment, 'idDps' => $dpsId, 'chaveAcesso' => $key,
        'nfseXmlGZipB64' => base64_encode(gzencode($authorizedXml))];
    $dps = ['tipoAmbiente' => (int) $environment, 'idDps' => $dpsId, 'chaveAcesso' => $key];
    $event = ['tipoAmbiente' => (int) $environment, 'eventoXmlGZipB64' => base64_encode(gzencode(environmentEvent($environment, $key)))];
    $municipality = ['parametrosConvenio' => ['aderenteAmbienteNacional' => 1, 'aderenteEmissorNacional' => 1]];
    $expected = [
        ['POST', $bases['sefin'] . '/nfse', $authorized, 'api.submit', 'sefin_nacional'],
        ['GET', $bases['sefin'] . '/dps/' . $dpsId, $dps, 'api.find_dps', 'sefin_nacional'],
        ['GET', $bases['sefin'] . '/nfse/' . $key, $authorized, 'api.fetch', 'sefin_nacional'],
        ['GET', $bases['sefin'] . '/nfse/' . $key . '/eventos', $event, 'api.events', 'sefin_nacional'],
        ['GET', $bases['parameters'] . '/3550308/convenio', $municipality, 'api.municipality', 'adn_parametrizacao'],
    ];
    $calls = []; $observed = [];
    $client = new ApiClient($environment, $certificate,
        static function ($method, $url, $body, $usedCertificate) use (&$calls, $expected): array {
            $index = count($calls);
            $calls[] = [$method, $url, $body, $usedCertificate];
            if (!isset($expected[$index])) { throw new RuntimeException('Chamada adicional não prevista pela matriz offline.'); }
            return ['status' => $index === 0 ? 201 : 200, 'body' => json_encode($expected[$index][2])];
        }, static function (array $log) use (&$observed): void { $observed[] = $log; });

    environmentCheck($client->submit($signed)['chaveAcesso'] === $key, 'emissão simulada confirmada no ambiente ' . $environment);
    environmentCheck($client->findByDps($dpsId)['chaveAcesso'] === $key, 'consulta DPS no ambiente ' . $environment);
    environmentCheck(ApiClient::decodeXml($client->fetch($key)) === $authorizedXml, 'consulta NFS-e preserva XML no ambiente ' . $environment);
    $events = $client->getEvents($key);
    environmentCheck(count($events) === 1 && $events[0]['environment'] === $environment && $events[0]['cancellation'], 'consulta eventos no ambiente ' . $environment);
    environmentCheck($client->municipality('3550308') === $municipality, 'consulta convênio no ambiente ' . $environment);
    environmentCheck(count($calls) === 5 && count($observed) === 5, 'uma requisição e um log por operação no ambiente ' . $environment);
    foreach ($expected as $index => $request) {
        environmentCheck($calls[$index][0] === $request[0] && $calls[$index][1] === $request[1],
            'método e URL exatos de ' . $request[3] . ' no ambiente ' . $environment);
        environmentCheck($calls[$index][3] === $certificate && ($index === 0 || $calls[$index][2] === null),
            'certificado selecionado preservado e consulta sem corpo: ' . $request[3] . '/' . $environment);
        environmentCheck($observed[$index]['environment'] === $environment && $observed[$index]['operation'] === $request[3]
            && $observed[$index]['service'] === $request[4] && $observed[$index]['operation_status'] === 'success',
            'log identifica o ambiente e serviço de ' . $request[3] . '/' . $environment);
    }
    $envelope = json_decode($calls[0][2], true, 32, JSON_THROW_ON_ERROR);
    environmentCheck(array_keys($envelope) === ['dpsXmlGZipB64']
        && gzdecode(base64_decode($envelope['dpsXmlGZipB64'], true)) === $signed,
        'POST mantém a DPS assinada do ambiente ' . $environment);

    // This is the critical pre-send guard: a production XML cannot travel to the test host or vice versa.
    $blockedCalls = 0; $blockedLogs = 0;
    $guard = new ApiClient($environment, $certificate,
        static function () use (&$blockedCalls): array { ++$blockedCalls; throw new RuntimeException('Transporte não deveria ser chamado.'); },
        static function () use (&$blockedLogs): void { ++$blockedLogs; });
    environmentFails(fn() => $guard->submit($signedByEnvironment[$otherEnvironment]),
        static fn(Throwable $e): bool => $e instanceof InvalidArgumentException && strpos($e->getMessage(), 'ambiente') !== false,
        'DPS do outro ambiente bloqueada antes do POST: ' . $otherEnvironment . ' → ' . $environment);
    environmentCheck($blockedCalls === 0 && $blockedLogs === 0, 'divergência não alcança transporte no ambiente ' . $environment);

    // An HTTP success from the wrong environment must not be accepted as a fiscal success.
    foreach (['submit', 'findByDps', 'fetch', 'getEvents'] as $operation) {
        $wrongResponse = $operation === 'getEvents' ? $event : ($operation === 'findByDps' ? $dps : $authorized);
        $wrongResponse['tipoAmbiente'] = (int) $otherEnvironment;
        $wrongCalls = []; $wrongLogs = [];
        $wrongClient = new ApiClient($environment, $certificate,
            static function ($method, $url) use (&$wrongCalls, $wrongResponse): array {
                $wrongCalls[] = [$method, $url];
                return ['status' => 200, 'body' => json_encode($wrongResponse)];
            }, static function (array $entry) use (&$wrongLogs): void { $wrongLogs[] = $entry; });
        $argument = $operation === 'submit' ? $signed : ($operation === 'findByDps' ? $dpsId : $key);
        environmentFails(fn() => $wrongClient->$operation($argument),
            static fn(Throwable $e): bool => $e instanceof ApiException && $e->isUncertain(),
            'retorno de outro ambiente não confirma ' . $operation . '/' . $environment);
        environmentCheck(count($wrongCalls) === 1 && count($wrongLogs) === 1
            && $wrongLogs[0]['operation_status'] === 'uncertain' && $wrongLogs[0]['environment'] === $environment,
            'divergência de retorno não dispara repetição ou troca de host: ' . $operation . '/' . $environment);
    }
}

echo "OK — {$checks} verificações de apontamento e isolamento dos ambientes (sem rede).\n";
