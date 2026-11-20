<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'NfseNacional\\')) {
        require_once dirname(__DIR__) . '/libraries/NfseNacional/' . substr($class, 13) . '.php';
    }
});
class App_Model { public $db = null; }
require_once dirname(__DIR__) . '/models/Nfse_nacional_model.php';

use NfseNacional\ApiClient;
use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\Config;
use NfseNacional\Crypto;
use NfseNacional\DpsBuilder;
use NfseNacional\DpsCorrelation;
use NfseNacional\Service;
use NfseNacional\XmlSigner;

$permissions = ['send' => false];
function nfse_nacional_can(string $capability): bool { global $permissions; return $permissions[$capability] ?? false; }
function nfse_nacional_scope(): string { return hash('sha256', 'service-test-company'); }
function db_prefix(): string { return 'service_test_'; }
function is_staff_logged_in(): bool { return true; }
function get_staff_user_id(): int { return 7; }
function get_option(string $name) { return $name === 'company_vat' ? '11222333000181' : ''; }
$checks = 0;
function assertion(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); }
    ++$checks; echo 'OK: ' . $message . PHP_EOL;
}
function expectFailure(callable $action, string $message, ?callable $verify = null): Throwable {
    try { $action(); } catch (Throwable $e) { assertion($verify === null || $verify($e), $message); return $e; }
    throw new RuntimeException('FALHOU: ' . $message);
}

/** Replaces only persistence/CI dependencies; production Service, API, signer, parser and validators remain real. */
final class ServiceMemoryModel extends Nfse_nacional_model
{
    public array $doc;
    public array $config;
    public array $rates = [];
    public array $issRates = [];
    public function competenceRate(string $month, string $type = 'simple'): ?array { return ($type === 'iss' ? $this->issRates : $this->rates)[$month] ?? null; }
    public object $testInvoice;
    public array $events = [];
    public int $reservations = 0;
    private Crypto $cipher;
    private array $configurationRow;
    private bool $locked = false;

    public function __construct(array $draft, string $pfx, string $password)
    {
        $this->cipher = new Crypto(str_repeat('test-secret-', 4), 'test-company-scope');
        $this->config = Config::defaults();
        $this->config['provider'] = $draft['provider'];
        $this->config['environment'] = $draft['environment'];
        $this->testInvoice = (object) ['id' => 41, 'clientid' => 7, 'currency' => 1, 'subtotal' => '100.00', 'discount_total' => '0.00', 'total' => '100.00', 'status' => 1, 'items' => [['description' => 'Serviço de teste', 'qty' => '1', 'rate' => '100.00']]];
        $this->configurationRow = ['certificate_enc' => $this->cipher->encrypt(self::json(['pfx' => base64_encode($pfx), 'password' => $password]))];
        $json = self::json($draft);
        $this->doc = ['id' => 1, 'invoice_id' => 41, 'client_id' => 7, 'environment' => $draft['environment'], 'status' => 'draft',
            'draft_json' => $json, 'draft_hash' => hash('sha256', $json), 'invoice_fingerprint' => self::fingerprint($this->testInvoice),
            'dps_id' => null, 'dps_series' => null, 'dps_number' => null, 'request_enc' => null, 'response_enc' => null,
            'access_key' => null, 'nfse_number' => null, 'service_amount' => '100.00', 'send_after_authorized' => 0,
            'recipient_email' => 'offline@example.invalid', 'email_status' => 'pending', 'attempts' => 0, 'last_error' => null];
    }
    public function crypto(): Crypto { return $this->cipher; }
    public function settingsRow(): array { return $this->configurationRow; }
    public function settings(): array { return $this->config; }
    public function document(int $id): array { if ($id !== 1) { throw new RuntimeException('Documento inexistente.'); } return $this->doc; }
    public function invoice(int $id): object { if ($id !== 41) { throw new RuntimeException('Fatura inexistente.'); } return $this->testInvoice; }
    public function assertInvoice(object $invoice): void { if ($invoice->status !== 1) { throw new RuntimeException('Fatura indisponível.'); } }
    public function updateDocument(int $id, array $changes): void { $this->doc = array_replace($this->doc, $changes); }
    public function event(int $id, string $type, string $message): void { $this->events[] = [$type, $message]; }
    private array $heldLocks = [];
    public function lock(string $name, callable $operation) {
        if ($this->locked && !$this->heldLocks) { throw new RuntimeException('Operação já em andamento.'); }
        $this->locked = true; $this->heldLocks[] = $name;
        try { return $operation(); } finally { array_pop($this->heldLocks); $this->locked = (bool) $this->heldLocks; }
    }
    public function reserve(array $document, array $draft, array $settings): array {
        if (!empty($document['dps_id'])) { return $document; }
        ++$this->reservations;
        $draft['issued_at'] = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:sP');
        $json = self::json($draft);
        $this->updateDocument(1, ['dps_id' => DpsBuilder::identifier($draft['provider'], '1', '1'), 'dps_series' => '1', 'dps_number' => '1', 'draft_json' => $json, 'draft_hash' => hash('sha256', $json)]);
        return $this->doc;
    }
}

$opensslConfig = tempnam(sys_get_temp_dir(), 'nfse_service_test_');
file_put_contents($opensslConfig, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:11222333000181\n");
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $opensslConfig]);
$csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:11222333000181'], $key, ['config' => $opensslConfig, 'digest_alg' => 'sha256']);
$certificate = openssl_csr_sign($csr, null, $key, 1, ['config' => $opensslConfig, 'digest_alg' => 'sha256']);
unlink($opensslConfig);
$pfx = ''; openssl_pkcs12_export($certificate, $pfx, $key, 'only-in-memory');
$draft = ['environment' => '2', 'competence' => (new DateTimeImmutable('yesterday'))->format('Y-m-d'), 'invoice_values_acknowledged' => '0',
    'provider' => ['tax_id' => '11222333000181', 'name' => 'EMITENTE DE TESTE', 'municipality_code' => '3550308', 'municipal_registration' => '', 'simple_status' => '3', 'special_regime' => '0', 'simple_apportionment' => '1', 'phone' => '', 'email' => ''],
    'recipient' => ['name' => 'TOMADOR DE TESTE', 'tax_id' => '52998224725', 'email' => 'offline@example.invalid', 'phone' => '', 'municipality_code' => '3550308', 'zip' => '01001000', 'street' => 'Rua teste', 'number' => '1', 'complement' => '', 'district' => 'Centro'],
    'service' => ['municipality_code' => '3550308', 'national_code' => '010101', 'municipal_code' => '', 'nbs' => '', 'description' => 'Serviço de teste offline'],
    'values' => ['service_amount' => '100.00', 'unconditional_discount' => '0.00', 'conditional_discount' => '0.00', 'iss_taxation' => '1', 'retention' => '1', 'iss_rate' => '', 'total_tax_mode' => 'none', 'total_federal' => '0.00', 'total_state' => '0.00', 'total_municipal' => '0.00', 'simple_rate' => ''],
    'ibscbs' => Config::defaults()['ibscbs']];

function authorizedResponse(string $signedDps, ?string $overrideDpsId = null): array {
    $dom = XmlSigner::loadSafe($signedDps); $xp = new DOMXPath($dom); $xp->registerNamespace('n', XmlSigner::NFSE_NS);
    $id = $xp->evaluate('string(/n:DPS/n:infDPS/@Id)');
    $key = str_repeat('1', 50);
    if ($overrideDpsId !== null) { $dom->getElementsByTagNameNS(XmlSigner::NFSE_NS, 'infDPS')->item(0)->setAttribute('Id', $overrideDpsId); $signedDps = $dom->saveXML(); }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key . '">'
        . '<xLocEmi>São Paulo</xLocEmi><xLocPrestacao>São Paulo</xLocPrestacao><nNFSe>123</nNFSe><cLocIncid>3550308</cLocIncid><xLocIncid>São Paulo</xLocIncid><ambGer>2</ambGer><tpEmis>1</tpEmis><procEmi>1</procEmi><cStat>100</cStat><dhProc>2026-11-10T12:00:00-03:00</dhProc><nDFSe>1</nDFSe>'
        . '<emit><CNPJ>11222333000181</CNPJ><xNome>EMITENTE DE TESTE</xNome><enderNac><xLgr>Rua teste</xLgr><nro>1</nro><xBairro>Centro</xBairro><cMun>3550308</cMun><UF>SP</UF><CEP>01001000</CEP></enderNac></emit>'
        . '<valores><vBC>100.00</vBC><pAliqAplic>2.00</pAliqAplic><vISSQN>2.00</vISSQN><vLiq>100.00</vLiq></valores>'
        . preg_replace('/<\?xml[^>]*\?>\s*/', '', $signedDps) . '</infNFSe></NFSe>';
    return ['tipoAmbiente' => 2, 'idDps' => $id, 'chaveAcesso' => $key, 'nfseXmlGZipB64' => base64_encode(gzencode($xml))];
}

function changedResponse(array $response, callable $change): array {
    $xml = ApiClient::decodeXml($response);
    $dom = XmlSigner::loadSafe($xml);
    $xp = new DOMXPath($dom); $xp->registerNamespace('n', XmlSigner::NFSE_NS);
    $change($dom, $xp);
    $response['nfseXmlGZipB64'] = base64_encode(gzencode($dom->saveXML()));
    return $response;
}

$model = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$postCount = 0;
$service = new Service($model, static function ($environment, $cert) use (&$postCount) { return new ApiClient($environment, $cert, static function ($method, $url, $body) use (&$postCount) {
    if ($method !== 'POST') { throw new RuntimeException('Consulta inesperada no teste de primeira emissão.'); }
    ++$postCount;
    $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
    return ['status' => 201, 'body' => json_encode(authorizedResponse($signed))];
}); });
$validated = $service->validate(1);
assertion($validated['status'] === 'ready' && $model->reservations === 0 && $postCount === 0, 'Validação local não reserva DPS nem transmite');
$authorized = $service->transmit(1);
assertion($authorized['status'] === 'authorized' && $postCount === 1 && $model->reservations === 1, 'Primeiro clique autoriza com uma única DPS');
$again = $service->transmit(1);
assertion($again['status'] === 'authorized' && $postCount === 1 && $model->reservations === 1, 'Segundo clique não repete POST nem numeração');
assertion(!isset($authorized['response_enc']) && !isset($authorized['request_enc']) && !isset($authorized['draft_json']), 'Resposta pública omite os dados fiscais cifrados e o rascunho interno');
assertion(str_contains($service->xml(1), '<NFSe') && !str_contains($model->doc['response_enc'], '<NFSe'), 'XML autorizado protegido na persistência');
expectFailure(fn() => $service->saveDraft(1, [], $model->doc['draft_hash'], false), 'Documento autorizado não pode ser alterado');
expectFailure(fn() => $service->send(1), 'Envio sem permissão é bloqueado');

$uncertainModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$posts = 0; $queries = 0; $recover = false; $storedResponse = null;
$uncertainService = new Service($uncertainModel, static function ($environment, $cert) use (&$posts, &$queries, &$recover, &$storedResponse) { return new ApiClient($environment, $cert, static function ($method, $url, $body) use (&$posts, &$queries, &$recover, &$storedResponse) {
    if ($method === 'POST') {
        ++$posts;
        $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
        $storedResponse = authorizedResponse($signed);
        throw new RuntimeException('Timeout depois de o servidor receber a DPS.');
    }
    ++$queries;
    if (!$recover) { return ['status' => 404, 'body' => '{"tipoAmbiente":2,"erro":{"codigo":"E0001","descricao":"DPS não encontrada"}}']; }
    return ['status' => 200, 'body' => json_encode($storedResponse)];
}); });
expectFailure(fn() => $uncertainService->transmit(1), 'Timeout depois do POST é reportado como incerto', fn($e) => $e instanceof ApiException && $e->isUncertain());
$stableId = $uncertainModel->doc['dps_id'];
assertion($uncertainModel->doc['status'] === 'uncertain' && $posts === 1 && $uncertainModel->reservations === 1, 'DPS e estado incerto persistidos após timeout');
$still = $uncertainService->transmit(1);
assertion($still['status'] === 'uncertain' && $posts === 1 && $queries === 1 && $uncertainModel->doc['dps_id'] === $stableId, 'Repetição em estado incerto consulta sem emitir');
$recover = true;
$recovered = $uncertainService->reconcile(1);
assertion($recovered['status'] === 'authorized' && $posts === 1 && $uncertainModel->doc['dps_id'] === $stableId, 'Conciliação recupera a nota com o mesmo identificador');

$retryModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$retryMethods = []; $retryBodies = [];
$retryService = new Service($retryModel, static function ($environment, $cert) use (&$retryMethods, &$retryBodies) { return new ApiClient($environment, $cert, static function ($method, $url, $body) use (&$retryMethods, &$retryBodies) {
    $retryMethods[] = $method;
    if ($method === 'GET') { return ['status' => 404, 'body' => '{"tipoAmbiente":2,"erro":{"codigo":"E0001","descricao":"DPS não encontrada"}}']; }
    $retryBodies[] = $body;
    if (count($retryBodies) === 1) { throw new RuntimeException('Resposta não recebida.'); }
    $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
    return ['status' => 201, 'body' => json_encode(authorizedResponse($signed))];
}); });
expectFailure(fn() => $retryService->transmit(1), 'Cenário de reenvio conserva o resultado inicialmente incerto');
$originalId = $retryModel->doc['dps_id'];
$retryResult = $retryService->retrySame(1);
assertion($retryResult['status'] === 'authorized' && $retryMethods === ['POST', 'GET', 'POST'], 'Reenvio explícito consulta a DPS antes de repetir o POST');
assertion($retryBodies[0] === $retryBodies[1] && $retryModel->doc['dps_id'] === $originalId && $retryModel->reservations === 1, 'Reenvio conserva exatamente o XML assinado, a DPS e a única reserva');
assertion($retryModel->doc['attempts'] === 2, 'Duas tentativas são registradas sem alterar a identidade fiscal');

foreach ([
    'rota JSON' => '{"erro":{"codigo":"E404","descricao":"Endpoint DPS não encontrado"}}',
    'gateway HTML' => '<html>Not Found</html>',
    'JSON genérico' => '{"message":"Not Found"}',
    'corpo vazio' => '',
] as $failureLabel => $route404Body) {
    $route404Model = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
    $route404Methods = [];
    $route404Service = new Service($route404Model, static function ($environment, $cert) use (&$route404Methods, $route404Body) {
        return new ApiClient($environment, $cert, static function ($method) use (&$route404Methods, $route404Body) {
            $route404Methods[] = $method;
            if ($method === 'POST') { throw new RuntimeException('Resposta perdida após envio.'); }
            return ['status' => 404, 'body' => $route404Body];
        });
    });
    expectFailure(fn() => $route404Service->transmit(1), 'Preparação do cenário de 404 ' . $failureLabel . ' preserva a tentativa incerta');
    $route404Id = $route404Model->doc['dps_id'];
    expectFailure(fn() => $route404Service->retrySame(1), '404 ' . $failureLabel . ' bloqueia o reenvio solicitado', fn($e) => $e instanceof ApiException && $e->isUncertain());
    assertion($route404Methods === ['POST', 'GET'] && $route404Model->doc['dps_id'] === $route404Id
        && $route404Model->doc['status'] === 'uncertain' && $route404Model->reservations === 1 && $route404Model->doc['attempts'] === 1,
        '404 ' . $failureLabel . ' não faz segundo POST, não incrementa tentativa e conserva a DPS');
}

$knownModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$knownMethods = []; $knownResponse = null;
$knownService = new Service($knownModel, static function ($environment, $cert) use (&$knownMethods, &$knownResponse) { return new ApiClient($environment, $cert, static function ($method, $url, $body) use (&$knownMethods, &$knownResponse) {
    $knownMethods[] = $method;
    if ($method === 'GET') { return ['status' => 200, 'body' => json_encode($knownResponse)]; }
    $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
    $knownResponse = authorizedResponse($signed);
    throw new RuntimeException('Resposta perdida após autorização.');
}); });
expectFailure(fn() => $knownService->transmit(1), 'Cenário de nota existente começa com resposta perdida');
$knownResult = $knownService->retrySame(1);
assertion($knownResult['status'] === 'authorized' && $knownMethods === ['POST', 'GET'], 'Reenvio explícito recupera nota já existente sem outro POST');

$staleModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$staleModel->testInvoice->subtotal = '200.00';
$staleModel->testInvoice->total = '200.00';
$staleCalls = 0;
$staleService = new Service($staleModel, function () use (&$staleCalls) { ++$staleCalls; throw new RuntimeException('Não deveria criar API.'); });
expectFailure(fn() => $staleService->transmit(1), 'Fatura alterada depois do rascunho bloqueia transmissão');
assertion($staleCalls === 0 && $staleModel->reservations === 0, 'Fatura divergente não consome numeração nem chama API');
expectFailure(fn() => $staleService->saveDraft(1, [], $staleModel->doc['draft_hash'], false), 'Salvar não aceita silenciosamente uma fatura alterada');
$staleService->saveDraft(1, ['acknowledge_invoice_values' => '1', 'values' => ['service_amount' => '200.00']], $staleModel->doc['draft_hash'], false);
assertion($staleService->validate(1)['status'] === 'ready' && $staleCalls === 0, 'Revisão explícita dos novos valores libera a validação local');

$reviewModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$reviewModel->testInvoice->total = '110.00';
$reviewModel->doc['invoice_fingerprint'] = Nfse_nacional_model::fingerprint($reviewModel->testInvoice);
$reviewService = new Service($reviewModel, static function () { throw new RuntimeException('A revisão não deve chamar a API.'); });
expectFailure(fn() => $reviewService->validate(1), 'Fatura com acréscimo além dos serviços exige revisão da base fiscal');
$reviewService->saveDraft(1, ['acknowledge_invoice_values' => '1'], $reviewModel->doc['draft_hash'], false);
assertion($reviewService->validate(1)['status'] === 'ready' && $reviewModel->reservations === 0, 'Base fiscal explicitamente confirmada pode ser validada sem emitir');

$malformedModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$malformedService = new Service($malformedModel, static fn($environment, $cert) => new ApiClient($environment, $cert, static function ($method, $url, $body) {
    $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
    $response = authorizedResponse($signed);
    $response['nfseXmlGZipB64'] = base64_encode(gzencode('<NFSe>incompleto'));
    return ['status' => 201, 'body' => json_encode($response)];
}));
expectFailure(fn() => $malformedService->transmit(1), 'XML inválido com HTTP 201 não comprova autorização');
assertion($malformedModel->doc['status'] === 'uncertain' && empty($malformedModel->doc['response_enc']), 'XML inválido fica incerto e não é disponibilizado');

$wrongModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$wrongService = new Service($wrongModel, static fn($environment, $cert) => new ApiClient($environment, $cert, static function ($method, $url, $body) {
    $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
    $response = authorizedResponse($signed, 'DPS355030821122233300018100001000000000999999');
    return ['status' => 201, 'body' => json_encode($response)];
}));
expectFailure(fn() => $wrongService->transmit(1), 'Resposta com DPS de outro documento é recusada');
assertion($wrongModel->doc['status'] === 'uncertain' && empty($wrongModel->doc['access_key']), 'Documento divergente nunca vira nota autorizada');

$collisionModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$collisionService = new Service($collisionModel, static fn($environment, $cert) => new ApiClient($environment, $cert, static function ($method, $url, $body) {
    $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
    $response = changedResponse(authorizedResponse($signed), static function ($dom, $xp) {
        $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:toma/n:CPF')->item(0)->nodeValue = '11144477735';
    });
    return ['status' => 201, 'body' => json_encode($response)];
}));
expectFailure(fn() => $collisionService->transmit(1), 'Mesmo ID de DPS com outro tomador é uma colisão, não uma autorização', fn($e) => $e instanceof ApiException && $e->isUncertain() && $e->getMessage() === DpsCorrelation::MISMATCH);
assertion($collisionModel->doc['status'] === 'uncertain' && empty($collisionModel->doc['response_enc']) && empty($collisionModel->doc['access_key']), 'Colisão não vincula XML ou chave de outra emissão à fatura');

$rejectedCollisionModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$otherInvoiceResponse = null;
$rejectedCollisionService = new Service($rejectedCollisionModel, static function ($environment, $cert) use (&$otherInvoiceResponse) { return new ApiClient($environment, $cert, static function ($method, $url, $body) use (&$otherInvoiceResponse) {
    if ($method === 'GET') { return ['status' => 200, 'body' => json_encode($otherInvoiceResponse)]; }
    $signed = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
    $otherInvoiceResponse = changedResponse(authorizedResponse($signed), static function ($dom, $xp) {
        $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:toma/n:CPF')->item(0)->nodeValue = '11144477735';
    });
    return ['status' => 400, 'body' => '{"erros":[{"codigo":"E1235","descricao":"Falha de esquema"}]}'];
}); });
expectFailure(fn() => $rejectedCollisionService->transmit(1), 'Colisão descoberta após rejeição HTTP conserva o erro de correlação', fn($e) => $e instanceof ApiException && $e->isUncertain() && $e->getMessage() === DpsCorrelation::MISMATCH);
assertion($rejectedCollisionModel->doc['status'] === 'uncertain' && empty($rejectedCollisionModel->doc['access_key']), 'Tratamento da rejeição não substitui colisão fiscal por rascunho editável');

foreach (['timeout' => 'timeout', 'server_error' => 'HTTP 500', 'route_not_found' => 'HTTP 404 de rota'] as $failureKind => $failureLabel) {
    $lookupModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
    $lookupService = new Service($lookupModel, static fn($environment, $cert) => new ApiClient($environment, $cert, static function ($method) use ($failureKind) {
        if ($method === 'POST') { return ['status' => 400, 'body' => '{"erros":[{"codigo":"E1235","descricao":"Falha de esquema"}]}']; }
        if ($failureKind === 'timeout') { throw new RuntimeException('Timeout ao consultar a DPS.'); }
        if ($failureKind === 'route_not_found') { return ['status' => 404, 'body' => '{"erro":{"codigo":"E404","descricao":"Rota de consulta da DPS não encontrada"}}']; }
        return ['status' => 500, 'body' => '{"erros":[{"codigo":"E9999","descricao":"Consulta temporariamente indisponível"}]}'];
    }));
    expectFailure(fn() => $lookupService->transmit(1), 'Rejeição seguida de consulta com ' . $failureLabel . ' permanece incerta', fn($e) => $e instanceof ApiException && $e->isUncertain());
    assertion($lookupModel->doc['status'] === 'uncertain' && $lookupModel->reservations === 1 && !empty($lookupModel->doc['request_enc']) && empty($lookupModel->doc['access_key']), 'Consulta com ' . $failureLabel . ' preserva DPS original sem liberar edição');
}

// A corrected rejection may no longer have request_enc: use the frozen draft,
// including its issued_at, original series and number, as the expected content.
$snapshotModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$snapshotModel->reserve($snapshotModel->doc, $draft, $snapshotModel->settings());
$snapshotModel->updateDocument(1, ['status' => 'uncertain', 'attempts' => 1]);
$snapshotXml = (new DpsBuilder())->build(Nfse_nacional_model::decode($snapshotModel->doc['draft_json']), '1', '1');
$wrongAmountResponse = changedResponse(authorizedResponse($snapshotXml), static function ($dom, $xp) {
    $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:valores/n:vServPrest/n:vServ')->item(0)->nodeValue = '999.00';
});
$snapshotService = new Service($snapshotModel, static fn($environment, $cert) => new ApiClient($environment, $cert, static function ($method) use ($wrongAmountResponse) {
    if ($method !== 'GET') { throw new RuntimeException('Conciliação não deve emitir.'); }
    return ['status' => 200, 'body' => json_encode($wrongAmountResponse)];
}));
expectFailure(fn() => $snapshotService->reconcile(1), 'Consulta com mesma DPS e outro valor é recusada mesmo sem request_enc', fn($e) => $e instanceof ApiException && $e->isUncertain());
assertion($snapshotModel->doc['status'] === 'uncertain' && empty($snapshotModel->doc['response_enc']) && empty($snapshotModel->doc['access_key']), 'Colisão encontrada na consulta preserva a incerteza e não associa a nota');

$matchingSnapshotResponse = authorizedResponse($snapshotXml);
$matchingSnapshotService = new Service($snapshotModel, static fn($environment, $cert) => new ApiClient($environment, $cert, static function ($method) use ($matchingSnapshotResponse) {
    if ($method !== 'GET') { throw new RuntimeException('Conciliação não deve emitir.'); }
    return ['status' => 200, 'body' => json_encode($matchingSnapshotResponse)];
}));
assertion($matchingSnapshotService->reconcile(1)['status'] === 'authorized', 'Snapshot fiscal completo pode confirmar a mesma emissão sem request_enc');

$expectedSigned = $model->crypto()->decrypt($model->doc['request_enc']);
$originalResponse = authorizedResponse($expectedSigned);
$enriched = changedResponse($originalResponse, static function ($dom, $xp) {
    $provider = $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:prest')->item(0);
    $name = $dom->createElementNS(XmlSigner::NFSE_NS, 'f:xNome');
    $name->appendChild($dom->createTextNode('NOME COMPLETADO PELO CADASTRO'));
    $provider->appendChild($name);
});
DpsCorrelation::assertMatches($expectedSigned, ApiClient::decodeXml($enriched));
assertion(true, 'Enriquecimento cadastral e prefixos XML não alteram os dados submetidos');
$duplicate = changedResponse($originalResponse, static function ($dom, $xp) {
    $cpf = $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:toma/n:CPF')->item(0);
    $cpf->parentNode->appendChild($cpf->cloneNode(true));
});
expectFailure(fn() => DpsCorrelation::assertMatches($expectedSigned, ApiClient::decodeXml($duplicate)), 'Caminho fiscal repetido é rejeitado mesmo quando os valores são iguais');
$foreignNamespace = changedResponse($originalResponse, static function ($dom, $xp) {
    $description = $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:serv/n:cServ/n:xDescServ')->item(0);
    $foreign = $dom->createElementNS('urn:unexpected', 'fake:xDescServ');
    $foreign->appendChild($dom->createTextNode($description->textContent));
    $description->parentNode->replaceChild($foreign, $description);
});
expectFailure(fn() => DpsCorrelation::assertMatches($expectedSigned, ApiClient::decodeXml($foreignNamespace)), 'Mesmo nome e valor em namespace diferente não comprovam correlação');

// A lost POST response is recovered through the public DPS -> key -> official XML
// chain. The wire response deliberately omits the XML on the DPS consultation.
$recoveryModel = new ServiceMemoryModel($draft, $pfx, 'only-in-memory');
$recoveryCalls = []; $recoveryEnvelope = null;
$recoveryService = new Service($recoveryModel, static function ($environment, $cert) use (&$recoveryCalls, &$recoveryEnvelope) {
    return new ApiClient($environment, $cert, static function ($method, $url, $body) use (&$recoveryCalls, &$recoveryEnvelope) {
        $recoveryCalls[] = [$method, $url, $body];
        if ($method === 'POST') {
            $submittedXml = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
            $recoveryEnvelope = authorizedResponse($submittedXml);
            throw new RuntimeException('Resposta perdida após o recebimento da DPS.');
        }
        if (str_contains($url, '/dps/')) {
            return ['status' => 200, 'body' => json_encode(array_diff_key($recoveryEnvelope, ['nfseXmlGZipB64' => true]))];
        }
        if (str_ends_with($url, '/eventos')) {
            return ['status' => 404, 'body' => '{"tipoAmbiente":2,"erro":{"codigo":"E0002","descricao":"Evento não encontrado"}}'];
        }
        return ['status' => 200, 'body' => json_encode($recoveryEnvelope)];
    });
});
expectFailure(fn() => $recoveryService->transmit(1), 'Perda da resposta após aceite mantém uma tentativa para recuperação');
$pendingRecoveryDoc = $recoveryModel->doc;
$identityFields = array_flip(['invoice_id', 'client_id', 'environment', 'dps_id', 'dps_series', 'dps_number', 'draft_json', 'draft_hash', 'request_enc', 'attempts']);
$recoveryIdentity = array_intersect_key($pendingRecoveryDoc, $identityFields);
$recoveryResult = $recoveryService->reconcile(1);
$recoveryKey = $recoveryEnvelope['chaveAcesso'];
assertion($recoveryResult['status'] === 'authorized' && $recoveryResult['access_key'] === $recoveryKey
    && array_column($recoveryCalls, 0) === ['POST', 'GET', 'GET']
    && str_ends_with($recoveryCalls[1][1], '/dps/' . $pendingRecoveryDoc['dps_id'])
    && str_ends_with($recoveryCalls[2][1], '/nfse/' . $recoveryKey), 'Recuperação consulta a DPS e depois baixa a NFS-e pela chave sem retransmitir');
assertion($recoveryService->xml(1) === ApiClient::decodeXml($recoveryEnvelope), 'XML oficial recuperado é armazenado e disponibilizado sem reescrita');
assertion(array_intersect_key($recoveryModel->doc, $identityFields) === $recoveryIdentity && $recoveryModel->reservations === 1,
    'Recuperação conserva versão, XML assinado, ambiente, série, número, DPS e fatura originais');
$recoveryMeta = Nfse_nacional_model::decode($recoveryModel->doc['fiscal_meta_json']);
assertion(($recoveryMeta['confirmation_source'] ?? '') === 'dps' && !empty($recoveryMeta['status_checked_at'])
    && !empty($recoveryMeta['status_check_attempted_at']) && ($recoveryMeta['status_check_error'] ?? '') === '', 'Recuperação pela DPS registra origem e datas de conferência');
assertion(count(array_filter($recoveryModel->events, static fn($event) => $event[0] === 'authorization_recovered' && str_contains($event[1], 'Autorização recuperada'))) === 1,
    'Histórico identifica a recuperação da autorização sem simular nova emissão');
$officialCiphertext = $recoveryModel->doc['response_enc'];
$recoveryAgain = $recoveryService->reconcile(1);
assertion($recoveryAgain['status'] === 'authorized' && array_column($recoveryCalls, 0) === ['POST', 'GET', 'GET', 'GET']
    && str_ends_with($recoveryCalls[3][1], '/nfse/' . $recoveryKey . '/eventos')
    && $recoveryModel->doc['response_enc'] === $officialCiphertext && $recoveryModel->reservations === 1,
    'Consulta repetida atualiza eventos sem repetir POST, anexar outra nota ou reservar outra DPS');
assertion(count(array_filter($recoveryModel->events, static fn($event) => $event[0] === 'authorization_recovered')) === 1,
    'Consulta idempotente não duplica o evento de autorização recuperada');

$keyRecoveryModel = clone $recoveryModel;
$keyRecoveryModel->doc = $pendingRecoveryDoc;
$keyRecoveryModel->events = [];
$keyRecoveryCalls = [];
$keyRecoveryService = new Service($keyRecoveryModel, static function ($environment, $cert) use (&$keyRecoveryCalls, $recoveryEnvelope) {
    return new ApiClient($environment, $cert, static function ($method, $url) use (&$keyRecoveryCalls, $recoveryEnvelope) {
        $keyRecoveryCalls[] = [$method, $url];
        if (str_ends_with($url, '/eventos')) { return ['status' => 404, 'body' => '{"tipoAmbiente":2,"erro":{"codigo":"E0002","descricao":"Evento não encontrado"}}']; }
        return ['status' => 200, 'body' => json_encode($recoveryEnvelope)];
    });
});
$keyRecoveryResult = $keyRecoveryService->recoverByKey(1, $recoveryKey);
assertion($keyRecoveryResult['status'] === 'authorized' && count($keyRecoveryCalls) === 1 && $keyRecoveryCalls[0][0] === 'GET'
    && str_ends_with($keyRecoveryCalls[0][1], '/nfse/' . $recoveryKey), 'Chave informada recupera o XML com somente um GET no ambiente original');
assertion(array_intersect_key($keyRecoveryModel->doc, $identityFields) === $recoveryIdentity
    && $keyRecoveryService->xml(1) === ApiClient::decodeXml($recoveryEnvelope), 'Recuperação por chave conserva o pedido e recebe exatamente o XML oficial');
$keyMeta = Nfse_nacional_model::decode($keyRecoveryModel->doc['fiscal_meta_json']);
assertion(($keyMeta['confirmation_source'] ?? '') === 'key' && !empty($keyMeta['status_checked_at'])
    && count(array_filter($keyRecoveryModel->events, static fn($event) => $event[0] === 'authorization_recovered')) === 1,
    'Recuperação por chave registra sua origem e confirmação no histórico');
$keyRecoveryService->recoverByKey(1, $recoveryKey);
assertion(count($keyRecoveryCalls) === 2 && $keyRecoveryCalls[1][0] === 'GET'
    && str_ends_with($keyRecoveryCalls[1][1], '/eventos') && $keyRecoveryModel->reservations === 1,
    'Repetir a mesma chave de uma nota autorizada somente confere os eventos fiscais');
$keyCallsBefore = count($keyRecoveryCalls);
expectFailure(fn() => $keyRecoveryService->recoverByKey(1, str_repeat('2', 50)), 'Nota autorizada não aceita ser trocada por outra chave');
assertion(count($keyRecoveryCalls) === $keyCallsBefore && $keyRecoveryModel->doc['access_key'] === $recoveryKey,
    'Troca da chave vinculada é bloqueada antes de acessar o ambiente nacional');

foreach (['chave diferente' => static function (array $response): array {
    $response['chaveAcesso'] = str_repeat('2', 50); return $response;
}, 'chave divergente no XML' => static fn(array $response): array => changedResponse($response, static function ($dom, $xp) {
    $xp->query('/n:NFSe/n:infNFSe')->item(0)->setAttribute('Id', 'NFS' . str_repeat('2', 50));
}), 'ambiente divergente no retorno' => static function (array $response): array {
    $response['tipoAmbiente'] = 1; return $response;
}, 'ambiente divergente na DPS' => static fn(array $response): array => changedResponse($response, static function ($dom, $xp) {
    $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:tpAmb')->item(0)->nodeValue = '1';
}), 'outra DPS' => static fn(array $response): array => changedResponse($response, static function ($dom, $xp) {
    $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS')->item(0)->setAttribute('Id', 'DPS355030821122233300018100001000000000999999');
}), 'outro tomador na mesma DPS' => static fn(array $response): array => changedResponse($response, static function ($dom, $xp) {
    $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:toma/n:CPF')->item(0)->nodeValue = '11144477735';
}), 'outro valor na mesma DPS' => static fn(array $response): array => changedResponse($response, static function ($dom, $xp) {
    $xp->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:valores/n:vServPrest/n:vServ')->item(0)->nodeValue = '999.00';
})] as $label => $mutate) {
    $badRecoveryModel = clone $recoveryModel;
    $badRecoveryModel->doc = $pendingRecoveryDoc;
    $badRecoveryModel->doc['status'] = 'processing';
    $badRecoveryModel->events = [];
    $badRecoveryCalls = [];
    $badRecoveryResponse = $mutate($recoveryEnvelope);
    $badRecoveryService = new Service($badRecoveryModel, static function ($environment, $cert) use (&$badRecoveryCalls, $badRecoveryResponse) {
        return new ApiClient($environment, $cert, static function ($method) use (&$badRecoveryCalls, $badRecoveryResponse) {
            $badRecoveryCalls[] = $method; return ['status' => 200, 'body' => json_encode($badRecoveryResponse)];
        });
    });
    expectFailure(fn() => $badRecoveryService->recoverByKey(1, $recoveryKey), 'Recuperação por chave recusa ' . $label);
    assertion($badRecoveryCalls === ['GET'] && $badRecoveryModel->doc['status'] === 'processing'
        && empty($badRecoveryModel->doc['response_enc']) && empty($badRecoveryModel->doc['access_key'])
        && array_intersect_key($badRecoveryModel->doc, $identityFields) === $recoveryIdentity,
        $label . ' preserva o estado pendente e todo o pedido original sem anexar a nota');
}

foreach (['sem DPS' => ['dps_id' => null], 'sem tentativa' => ['attempts' => 0], 'sem XML original' => ['request_enc' => null],
    'rascunho' => ['status' => 'draft'], 'cancelada' => ['status' => 'cancelled']] as $label => $changes) {
    $unsubmittedModel = clone $recoveryModel;
    $unsubmittedModel->doc = array_replace($pendingRecoveryDoc, $changes);
    $unsubmittedCalls = 0;
    $unsubmittedService = new Service($unsubmittedModel, static function () use (&$unsubmittedCalls) { ++$unsubmittedCalls; throw new RuntimeException('Consulta não esperada.'); });
    expectFailure(fn() => $unsubmittedService->recoverByKey(1, $recoveryKey), 'Recuperação de documento ' . $label . ' é bloqueada');
    assertion($unsubmittedCalls === 0 && $unsubmittedModel->doc === array_replace($pendingRecoveryDoc, $changes),
        $label . ' não consulta nem altera a identidade ou situação fiscal');
}
expectFailure(fn() => $keyRecoveryService->recoverByKey(1, '123'), 'Chave incompleta é rejeitada antes de consultar');

foreach (['uncertain', 'processing'] as $pendingStatus) {
    foreach (['dps', 'key'] as $lookupKind) {
        $failedQueryModel = clone $recoveryModel;
        $failedQueryModel->doc = array_replace($pendingRecoveryDoc, ['status' => $pendingStatus]);
        $failedQueryCalls = [];
        $failedQueryService = new Service($failedQueryModel, static function ($environment, $cert) use (&$failedQueryCalls) {
            return new ApiClient($environment, $cert, static function ($method, $url) use (&$failedQueryCalls) {
                $failedQueryCalls[] = [$method, $url];
                return ['status' => 404, 'body' => '<html>Gateway indisponível; conteúdo privado que não deve virar histórico.</html>'];
            });
        });
        expectFailure(fn() => $lookupKind === 'dps' ? $failedQueryService->reconcile(1) : $failedQueryService->recoverByKey(1, $recoveryKey),
            '404 genérico na consulta ' . $lookupKind . ' mantém ' . $pendingStatus . ' inconclusivo', fn($error) => $error instanceof ApiException && $error->isUncertain());
        $failedQueryMeta = Nfse_nacional_model::decode($failedQueryModel->doc['fiscal_meta_json']);
        assertion(count($failedQueryCalls) === 1 && $failedQueryCalls[0][0] === 'GET' && $failedQueryModel->doc['status'] === $pendingStatus
            && array_intersect_key($failedQueryModel->doc, $identityFields) === $recoveryIdentity && $failedQueryModel->reservations === 1,
            'Falha ' . $lookupKind . '/' . $pendingStatus . ' não faz fallback, POST, outra reserva ou alteração do pedido');
        assertion(!empty($failedQueryMeta['status_check_attempted_at']) && !empty($failedQueryMeta['status_check_error'])
            && empty($failedQueryMeta['status_checked_at']) && !str_contains($failedQueryModel->doc['last_error'], 'conteúdo privado')
            && !str_contains(Nfse_nacional_model::json($failedQueryModel->events), '<html>'),
            'Falha ' . $lookupKind . '/' . $pendingStatus . ' registra tentativa e erro seguro sem afirmar confirmação');
    }
}
$variableDraft = $draft; $variableDraft['values']['total_tax_mode'] = 'simple';
$variableModel = new ServiceMemoryModel($variableDraft, $pfx, 'only-in-memory');
$variableModel->config['variable_simple_rate'] = '1';
$variablePosts = 0;
$variableService = new Service($variableModel, static function($environment, $cert) use (&$variablePosts) {
    return new ApiClient($environment, $cert, static function($method, $url, $body) use (&$variablePosts) {
        ++$variablePosts;
        $xml = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
        assertion(str_contains($xml, '<pTotTribSN>6.25</pTotTribSN>'), 'Alíquota cheia da competência integra o XML assinado');
        return ['status' => 201, 'body' => json_encode(authorizedResponse($xml))];
    });
});
expectFailure(fn() => $variableService->validate(1), 'Sem alíquota mensal bloqueia validação', fn($e) => $e instanceof \NfseNacional\ValidationException);
expectFailure(fn() => $variableService->transmit(1), 'Sem alíquota mensal bloqueia transmissão', fn($e) => $e instanceof \NfseNacional\ValidationException);
assertion($variablePosts === 0 && $variableModel->reservations === 0, 'Competência ausente não reserva DPS nem chama API');
$month = substr($draft['competence'], 0, 7);
$variableModel->rates[$month] = ['rate' => '6.25', 'updated_at' => '2026-11-12 20:00:00'];
$variableService->validate(1);
$validatedSnapshot = Nfse_nacional_model::decode($variableModel->doc['draft_json']);
assertion($validatedSnapshot['values']['simple_rate'] === '6.25' && $validatedSnapshot['simple_rate_snapshot']['competence'] === $month,
    'Validação salva alíquota e competência no rascunho');
$issuedVariable = $variableService->transmit(1);
assertion($issuedVariable['status'] === 'authorized' && $variablePosts === 1, 'Alíquota cadastrada permite autorização sem alterar A1');

// ISSQN can be variable alone, alongside a separate Simples rate, or explicitly shared.
foreach (['iss_only', 'separate', 'shared'] as $scenario) {
    $issDraft = $draft; $issDraft['values']['retention'] = '2';
    $issDraft['values']['total_tax_mode'] = 'simple'; $issDraft['values']['simple_rate'] = '8.00';
    $issModel = new ServiceMemoryModel($issDraft, $pfx, 'only-in-memory');
    $issModel->config['variable_iss_rate'] = '1';
    $issModel->config['variable_simple_rate'] = $scenario === 'iss_only' ? '0' : '1';
    $issModel->config['variable_iss_rate_source'] = $scenario === 'shared' ? 'simple' : 'iss';
    $issPosts = 0;
    $issService = new Service($issModel, static function($environment, $cert) use (&$issPosts, $scenario) {
        return new ApiClient($environment, $cert, static function($method, $url, $body) use (&$issPosts, $scenario) {
            ++$issPosts; $xml = gzdecode(base64_decode(json_decode($body, true)['dpsXmlGZipB64']));
            assertion(str_contains($xml, '<pAliq>3.25</pAliq>'), 'ISSQN mensal integra DPS assinada: ' . $scenario);
            assertion(str_contains($xml, '<pTotTribSN>' . ($scenario === 'iss_only' ? '8.00' : ($scenario === 'shared' ? '3.25' : '11.41')) . '</pTotTribSN>'), 'Simples independente/compartilhado no XML: ' . $scenario);
            return ['status' => 201, 'body' => json_encode(authorizedResponse($xml))];
        });
    });
    expectFailure(fn() => $issService->transmit(1), 'ISSQN mensal ausente impede transmissão: ' . $scenario, fn($e) => $e instanceof \NfseNacional\ValidationException);
    assertion($issPosts === 0 && $issModel->reservations === 0, 'Sem taxa não há reserva/API: ' . $scenario);
    $issModel->rates[$month] = ['rate' => $scenario === 'shared' ? '3.25' : '11.41'];
    $issModel->issRates[$month] = ['rate' => '3.25'];
    $issService->validate(1); $savedIss = Nfse_nacional_model::decode($issModel->doc['draft_json']);
    assertion($savedIss['iss_rate_snapshot']['rate'] === '3.25', 'Validação persiste snapshot ISSQN: ' . $scenario);
    assertion($issService->transmit(1)['status'] === 'authorized' && $issPosts === 1, 'Uma autorização com o percentual fiscal: ' . $scenario);
    $before = $issModel->doc['draft_json']; $issModel->issRates[$month] = ['rate' => '4.00']; $issModel->rates[$month] = ['rate' => '4.00'];
    $issService->transmit(1); assertion($issModel->doc['draft_json'] === $before && $issPosts === 1, 'Tabela alterada não reescreve documento autorizado: ' . $scenario);
}

echo PHP_EOL . $checks . " verificações do fluxo aprovadas. Persistência em memória; sem banco, SMTP ou SEFIN reais.\n";
