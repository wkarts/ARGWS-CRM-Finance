<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
// Minimal stand-in for DOM node selection so we can execute the real date
// recovery helper on PHP installations missing php-xml. No network or fiscal
// claims are inferred from this unit test.
if (!class_exists('DOMXPath')) {
    final class DistributionFallbackNode {
        public object $childNodes;
        public function __construct(public string $textContent) { $this->childNodes = (object) ['length' => 0]; }
        public function getElementsByTagName(string $tag): object { return (object) ['length' => 0]; }
    }
    final class DOMXPath {
        public array $queried = [];
        public function query(string $xpath): object {
            $this->queried[] = $xpath;
            return new class {
                public int $length = 1;
                public function item(int $i): DistributionFallbackNode { return new DistributionFallbackNode('2026-11-10T12:01:00-03:00'); }
            };
        }
    }
}
require_once dirname(__DIR__) . '/autoload.php';
$method = new ReflectionMethod(\NfseNacional\DistributionClient::class, 'generationDate');
$checkCount = 0;
foreach ([
    [['2026-11-10T12:01:00.123456789-03:00', '2026-11-10T12:01:00.123456789-03:00', false], 'fractional nanos'],
    [['2026-11-10T12:01:00.1234567-03:00', '2026-11-10T12:01:00.1234567-03:00', false], 'fractional ticks'],
    [['2026-11-10T12:01:00-03:00', '2026-11-10T12:01:00-03:00', false], 'RFC3339'],
    [['2026-02-30T12:00:00Z', '2026-11-10T12:01:00-03:00', true], 'calendar invalid'],
    [['2026-11-10T12:01:00', '2026-11-10T12:01:00-03:00', true], 'timezone missing'],
    [[[], '2026-11-10T12:01:00-03:00', true], 'unexpected array'],
    [[null, '2026-11-10T12:01:00-03:00', true], 'missing metadata'],
] as [$case, $label]) {
    [$metadata, $expected, $flag] = $case;
    $fallback = false;
    if (class_exists('DOMDocument')) {
        $doc = new DOMDocument();
        $doc->loadXML('<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe><dhProc>2026-11-10T12:01:00-03:00</dhProc></infNFSe></NFSe>');
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
    } else {
        $xpath = new DOMXPath();
    }
    $args = [$metadata, $xpath, 'NFSE', &$fallback];
    $actual = $method->invokeArgs(null, $args);
    if ($actual !== $expected || $fallback !== $flag) { throw new RuntimeException('FALHOU: ' . $label); }
    ++$checkCount;
}
// The fiscal XML timestamp validators stay strict about real calendar dates
// and mandatory offsets, but accept nanosecond fractions valid for xs:dateTime.
foreach ([\NfseNacional\NfseDocument::class, \NfseNacional\FiscalEvent::class] as $class) {
    $validator = new ReflectionMethod($class, 'validTimestamp');
    foreach ([
        ['2026-11-10T12:01:00.123456789-03:00', true],
        ['2026-11-10T12:01:00Z', true],
        ['2026-11-10T12:01:00', false],
        ['2026-02-30T12:01:00-03:00', false],
    ] as [$value, $expected]) {
        if ($validator->invoke(null, $value) !== $expected) { throw new RuntimeException('FALHOU: ' . $class . ' / ' . $value); }
        ++$checkCount;
    }
}

// The diagnostic counter is safe; external raw fields and identifiers stay out.
$sanitized = \NfseNacional\OperationalLog::sanitize(['generation_date_fallbacks' => 4,
    'cnpjConsulta' => '11222333000181', 'DataHoraGeracao' => 'PRIVATE-TIMESTAMP']);
if ($sanitized !== ['generation_date_fallbacks' => 4]) { throw new RuntimeException('FALHOU: sanitização do contador de metadados'); }
++$checkCount;
$curlClass = new ReflectionMethod(\NfseNacional\DistributionClient::class, 'curlFailureCategory');
foreach ([6 => 'dns', 7 => 'connect', 28 => 'timeout', 35 => 'tls_handshake', 60 => 'tls_verification', 58 => 'certificate', 99 => 'transport'] as $errno => $category) {
    if ($curlClass->invoke(null, $errno) !== $category) { throw new RuntimeException('FALHOU: classificacao de transporte ' . $errno); }
    ++$checkCount;
}
$network = \NfseNacional\OperationalLog::sanitize([
    'curl_errno' => 60, 'transport_category' => 'tls_verification', 'dns_ms' => 9.5, 'connect_ms' => 34.19,
    'tls_ms' => 80.1, 'first_byte_ms' => 200, 'transfer_ms' => 220,
    'processing_status' => 'DOCUMENTOS_LOCALIZADOS', 'stage' => 'document_validation',
    'curl_raw_error' => 'PRIVATE_PROXY_PASSWORD', 'certificate_file' => '/secret/cert.pem',
]);
if (($network['curl_errno'] ?? null) !== 60 || ($network['tls_ms'] ?? null) !== 80.1
    || ($network['transport_category'] ?? '') !== 'tls_verification'
    || ($network['processing_status'] ?? '') !== 'DOCUMENTOS_LOCALIZADOS'
    || str_contains(json_encode($network), 'PRIVATE_PROXY_PASSWORD') || str_contains(json_encode($network), '/secret')) {
    throw new RuntimeException('FALHOU: observabilidade de transporte segura');
}
++$checkCount;
echo 'OK: ' . $checkCount . " testes unitários de datas e observabilidade ADN (sem validar XML).\n";
