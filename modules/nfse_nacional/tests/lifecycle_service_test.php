<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';
class App_Model {}
require dirname(__DIR__) . '/models/Nfse_nacional_model.php';

use NfseNacional\ApiException;
use NfseNacional\Certificate;
use NfseNacional\Config;
use NfseNacional\Crypto;
use NfseNacional\EventClient;
use NfseNacional\EventRequest;
use NfseNacional\FiscalRequestRepository;
use NfseNacional\LifecycleService;
use NfseNacional\NationalRepository;
use NfseNacional\NfseDocument;

/** Real fiscal workflow/signature/crypto/HTTP client; only persistence, session and HTTPS are fixtures. */
$checks = 0; $lifePrefix = 'life_a_'; $lifeTaxId = '11222333000181';
$lifePermissions = ['view' => true, 'national_view' => true, 'national_sync' => false, 'cancel' => true, 'manifest' => true];
function db_prefix(): string { return $GLOBALS['lifePrefix']; }
function nfse_nacional_scope(): string { return hash('sha256', 'lifecycle-offline:' . db_prefix()); }
function nfse_nacional_can(string $permission): bool { return $GLOBALS['lifePermissions'][$permission] ?? false; }
function is_staff_logged_in(): bool { return true; }
function get_staff_user_id(): int { return 7; }
function get_option(string $name) { return $name === 'company_vat' ? $GLOBALS['lifeTaxId'] : ''; }
function lifeCheck(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException('FALHOU: ' . $message); } ++$GLOBALS['checks']; }
function lifeFails(callable $action, string $message, ?callable $verify = null): Throwable {
    try { $action(); } catch (Throwable $error) { lifeCheck($verify === null || $verify($error), $message); return $error; }
    throw new RuntimeException('FALHOU: ' . $message);
}

final class LifeResult {
    public function __construct(private array $rows) {}
    public function row_array(): ?array { return $this->rows[0] ?? null; }
    public function result_array(): array { return $this->rows; }
}
final class LifeDatabase {
    public array $tables = []; public array $writes = [];
    public bool $save_queries = true; public bool $db_debug = true;
    public $failWrite = null;
    private array $conditions = [];
    public function where(string $name, $value): self { $this->conditions[$name] = (string) $value; return $this; }
    private function matches(array $row): bool { foreach ($this->conditions as $key => $value) { if ((string) ($row[$key] ?? '') !== $value) { return false; } } return true; }
    public function get(string $table): LifeResult {
        $rows = array_values(array_filter($this->tables[$table] ?? [], fn($row) => $this->matches($row)));
        $this->conditions = []; return new LifeResult($rows);
    }
    public function insert(string $table, array $row): bool {
        if ($this->failWrite !== null && ($this->failWrite)($table, $row)) { $this->conditions = []; return false; }
        $row['id'] = max(array_column($this->tables[$table] ?? [], 'id') ?: [0]) + 1;
        if (str_ends_with($table, '_fiscal_requests')) {
            $row += ['request_enc' => null, 'request_hash' => null, 'response_enc' => null, 'event_id' => null,
                'processed_at' => null, 'record_number' => null, 'attempts' => 0, 'last_error' => null];
        }
        $this->tables[$table][] = $row; $this->writes[] = [$table, $row]; $this->conditions = []; return true;
    }
    public function update(string $table, array $changes): bool {
        if ($this->failWrite !== null && ($this->failWrite)($table, $changes)) { $this->conditions = []; return false; }
        foreach ($this->tables[$table] ?? [] as $index => $row) {
            if ($this->matches($row)) { $this->tables[$table][$index] = array_replace($row, $changes); }
        }
        $this->writes[] = [$table, $changes]; $this->conditions = []; return true;
    }
}
final class LifeModel {
    public array $configuration; public array $certificateRow; public array $documents = []; public array $replacements = [];
    public array $locks = []; public bool $failProjection = false; public $beforeLock = null;
    private Crypto $cipher; private bool $locked = false;
    public function __construct(public LifeDatabase $db, string $pfx) {
        $this->cipher = new Crypto(str_repeat('lifecycle-offline-key-', 3), nfse_nacional_scope());
        $this->configuration = Config::defaults(); $this->configuration['provider']['tax_id'] = $GLOBALS['lifeTaxId'];
        $this->configuration['use_local_certificate'] = '1';
        $this->certificateRow = ['certificate_enc' => $this->cipher->encrypt(json_encode(['pfx' => base64_encode($pfx), 'password' => 'offline-lifecycle']))];
    }
    public function crypto(): Crypto { return $this->cipher; }
    public function settings(): array { return $this->configuration; }
    public function settingsRow(): array { return $this->certificateRow; }
    public function document(int $id): array { if (!isset($this->documents[$id])) { throw new RuntimeException('Documento local não encontrado.'); } return $this->documents[$id]; }
    public function updateDocument(int $id, array $changes): void {
        if ($this->failProjection) { $this->failProjection = false; throw new RuntimeException('Falha offline ao atualizar a situação local.'); }
        $this->documents[$id] = array_replace($this->document($id), $changes);
    }
    public function replacementFor(int $id): ?array { return $this->replacements[$id] ?? null; }
    public function lock(string $name, callable $operation) {
        if ($this->locked) { throw new RuntimeException('Operação concorrente.'); }
        $this->locked = true; $this->locks[] = $name;
        try { if ($this->beforeLock !== null) { ($this->beforeLock)(); } return $operation(); }
        finally { $this->locked = false; }
    }
}
function lifePfx(): string {
    $config = tempnam(sys_get_temp_dir(), 'nfse_lifecycle_');
    if ($config === false) { throw new RuntimeException('Falha na fixture de certificado.'); }
    try {
        file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:11222333000181\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:11222333000181'], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'digest_alg' => 'sha256']);
        $pfx = ''; if (!openssl_pkcs12_export($cert, $pfx, $key, 'offline-lifecycle')) { throw new RuntimeException('Falha na fixture A1.'); }
        return $pfx;
    } finally { if (is_file($config)) { unlink($config); } }
}
function lifeNote(int $number, bool $received = false, string $environment = '2'): string {
    $provider = $received ? '11444777000161' : '11222333000181';
    $recipient = $received ? '11222333000181' : '52998224725';
    $key = '355030822' . $provider . str_pad((string) $number, 13, '0', STR_PAD_LEFT) . '2611' . '123456789' . '1';
    $dps = 'DPS35503082' . $provider . '00001' . str_pad((string) $number, 15, '0', STR_PAD_LEFT);
    $recipientTag = $received ? 'CNPJ' : 'CPF';
    return '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key . '">'
        . '<nNFSe>' . $number . '</nNFSe><cStat>100</cStat><dhProc>2026-11-10T12:00:00-03:00</dhProc><nDFSe>42</nDFSe>'
        . '<emit><CNPJ>' . $provider . '</CNPJ><xNome>PRESTADOR OFFLINE</xNome></emit>'
        . '<DPS versao="1.01"><infDPS Id="' . $dps . '"><tpAmb>' . $environment . '</tpAmb><dhEmi>2026-11-10T11:59:00-03:00</dhEmi>'
        . '<serie>1</serie><nDPS>' . $number . '</nDPS><dCompet>2026-11-10</dCompet><tpEmit>1</tpEmit>'
        . '<prest><CNPJ>' . $provider . '</CNPJ></prest><toma><' . $recipientTag . '>' . $recipient . '</' . $recipientTag . '><xNome>TOMADOR OFFLINE</xNome></toma>'
        . '<serv><cServ><cTribNac>010101</cTribNac><xDescServ>Teste offline</xDescServ></cServ></serv>'
        . '<valores><vServPrest><vServ>100.00</vServ></vServPrest></valores></infDPS></DPS></infNFSe></NFSe>';
}
function lifeSeed(LifeModel $model, string $origin, int $id, string $xml): array {
    $note = new NfseDocument($xml);
    $record = ['id' => $id, 'environment' => $note->environment(), 'status' => 'authorized', 'access_key' => $note->accessKey(),
        'dps_id' => $note->dpsId(), 'nfse_number' => $note->number(), 'attempts' => 1, 'fiscal_meta_json' => '{}', 'cancellation_event_enc' => null,
        'last_error' => null, 'created_at' => '2026-11-10 12:00:00', 'updated_at' => '2026-11-10 12:00:00'];
    if ($origin === 'local') {
        $record['response_enc'] = $model->crypto()->encrypt($xml); $record['request_enc'] = 'LOCAL_DPS_ORIGINAL_UNCHANGED';
        $model->documents[$id] = $record;
    } else {
        $record += ['issuer_tax_id' => $GLOBALS['lifeTaxId'], 'direction' => NationalRepository::direction($note, $GLOBALS['lifeTaxId']),
            'xml_enc' => $model->crypto()->encrypt($xml), 'xml_hash' => hash('sha256', $xml), 'nsu' => '42'];
        $model->db->tables[db_prefix() . 'nfse_nacional_national_documents'][] = $record;
    }
    return $record;
}
function lifeRegistered(string $request): string {
    $info = EventRequest::inspect($request);
    return '<evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infEvento Id="EVT' . $info['access_key'] . $info['type'] . '001">'
        . '<verAplic>FIXTURE OFFLINE</verAplic><ambGer>2</ambGer><nSeqEvento>001</nSeqEvento><dhProc>2026-11-11T12:02:00-03:00</dhProc><nDFSe>99</nDFSe>'
        . preg_replace('/<\?xml[^>]*\?>\s*/', '', $request) . '</infEvento>'
        . '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignatureValue>RklYVFVSRSBPRkZMSU5F</SignatureValue></Signature></evento>';
}
final class LifeGateway {
    public array $calls = []; public array $accepted = []; public array $postedXml = []; public array $persistedBeforePost = [];
    public string $postMode = 'accept'; public ?array $getResponse = null; public $afterAccept = null;
    public function __construct(private LifeModel $model) {}
    public function factory(): callable {
        return fn(string $environment, Certificate $certificate) => new EventClient($environment, $certificate,
            fn($method, $url, $body, $cert) => $this->request($environment, $method, $url, $body, $cert));
    }
    private function request(string $environment, string $method, string $url, ?string $body, Certificate $certificate): array {
        $this->calls[] = [$method, $url];
        if ($method === 'GET') {
            if ($this->getResponse !== null) { return $this->getResponse; }
            if (!preg_match('~/nfse/([0-9A-Z]{50})/eventos/([0-9]{6})/1$~D', $url, $match)) { throw new RuntimeException('Rota de consulta inesperada.'); }
            $identity = $environment . ':' . $match[1] . ':' . $match[2];
            if (isset($this->accepted[$identity])) { return $this->response($this->accepted[$identity], $environment, 200); }
            return ['status' => 404, 'body' => json_encode(['tipoAmbiente' => (int) $environment,
                'erros' => [['codigo' => 'E0000', 'descricao' => 'Nenhum evento encontrado para a NFS-e.']]])];
        }
        if ($method !== 'POST') { throw new RuntimeException('Método inesperado.'); }
        $payload = json_decode((string) $body, true, 32, JSON_THROW_ON_ERROR);
        $signed = gzdecode(base64_decode($payload['pedidoRegistroEventoXmlGZipB64'], true));
        $info = EventRequest::inspect($signed, $certificate);
        $row = (new FiscalRequestRepository($this->model))->find($info['author_tax_id'], $environment, $info['access_key'], $info['type']);
        $persisted = $row && $row['status'] === 'processing' && $row['request_hash'] === hash('sha256', $signed)
            && $this->model->crypto()->decrypt($row['request_enc']) === $signed;
        $this->persistedBeforePost[] = (bool) $persisted;
        if (!$persisted) { throw new RuntimeException('POST anterior à persistência durável do pedido.'); }
        $this->postedXml[] = $signed;
        if ($this->postMode === 'timeout_without_accept') { throw new RuntimeException('Timeout offline sem confirmação.'); }
        $event = lifeRegistered($signed); $this->accepted[$environment . ':' . $info['access_key'] . ':' . $info['type']] = $event;
        if ($this->afterAccept !== null) { ($this->afterAccept)(); }
        if ($this->postMode === 'timeout_after_accept') { throw new RuntimeException('Resposta aceita perdida na rede.'); }
        return $this->response($event, $environment, 201);
    }
    public function response(string $xml, string $environment = '2', int $status = 200): array {
        return ['status' => $status, 'body' => json_encode(['tipoAmbiente' => (int) $environment,
            'eventoXmlGZipB64' => base64_encode(gzencode($xml)), 'dataHoraProcessamento' => '2026-11-11T12:02:00-03:00'])];
    }
}
function lifeFixture(string $pfx, string $origin = 'local', bool $received = false, string $environment = '2'): array {
    $model = new LifeModel(new LifeDatabase(), $pfx); $xml = lifeNote(1, $received, $environment);
    lifeSeed($model, $origin, 1, $xml); $gateway = new LifeGateway($model);
    return [$model, $gateway, new LifecycleService($model, $gateway->factory()), $xml];
}
function lifeRequest(LifeModel $model, string $type, string $origin = 'local'): ?array {
    $record = $origin === 'local' ? $model->document(1) : (new NationalRepository($model))->document(1);
    return (new FiscalRequestRepository($model))->find($GLOBALS['lifeTaxId'], $record['environment'], $record['access_key'], $type);
}
function lifePostCount(LifeGateway $gateway): int { return count(array_filter($gateway->calls, static fn($call) => $call[0] === 'POST')); }

$pfx = lifePfx(); $certificate = Certificate::fromPkcs12($pfx, 'offline-lifecycle', $lifeTaxId);
$reason = 'Serviço não prestado conforme conferência da empresa.';

// Local and distributed notes share the same durable event workflow.
foreach (['local', 'national'] as $origin) {
    [$model, $gateway, $service, $xml] = lifeFixture($pfx, $origin);
    $before = $origin === 'local' ? $model->document(1) : (new NationalRepository($model))->document(1);
    $page = $service->page($origin, 1);
    lifeCheck(array_keys($page['actions']) === [101101, 101103] && $page['actions'][101101]['can_submit'], 'ações do prestador usam chaves PHP numéricas sem erro de tipo/' . $origin);
    $result = $service->submit($origin, 1, '101101', '2', $reason);
    $after = $origin === 'local' ? $model->document(1) : (new NationalRepository($model))->document(1);
    lifeCheck($result['status'] === 'registered' && $after['status'] === 'cancelled', 'cancelamento confirmado atualiza a nota/' . $origin);
    lifeCheck(array_column($gateway->calls, 0) === ['GET', 'POST'] && $gateway->persistedBeforePost === [true], 'consulta ocorre antes do único POST e pedido já está persistido/' . $origin);
    $source = $origin === 'local' ? 'response_enc' : 'xml_enc';
    lifeCheck($before[$source] === $after[$source] && $model->crypto()->decrypt($after[$source]) === $xml, 'XML autorizado original não é substituído pelo evento/' . $origin);
    lifeCheck(lifeRequest($model, '101101', $origin)['status'] === 'registered' && !isset($result['event']['request_enc'], $result['event']['response_enc']), 'histórico durável sem payload protegido na resposta/' . $origin);
    $event = $service->eventXml($origin, 1, '101101');
    lifeCheck(strpos($event, '<evento') === 0 && strpos($event, '<e101101>') !== false, 'download retorna o evento fiscal registrado/' . $origin);
    $calls = count($gateway->calls); $model->certificateRow = [];
    lifeCheck($service->submit($origin, 1, '101101', '1', 'Motivo novo que não pode ser transmitido.')['status'] === 'registered'
        && count($gateway->calls) === $calls, 'clique repetido usa prova registrada sem certificado ou POST adicional/' . $origin);
    lifeCheck(!$service->page($origin, 1)['actions'][101101]['can_submit'], 'nota cancelada não apresenta nova transmissão habilitada/' . $origin);
}

foreach ([['local', false, '101103', '2', $reason], ['national', true, '203202', '', ''], ['national', true, '203206', '3', $reason]] as [$origin, $received, $type, $code, $text]) {
    [$model, $gateway, $service, $xml] = lifeFixture($pfx, $origin, $received);
    $result = $service->submit($origin, 1, $type, $code, $text);
    $record = $origin === 'local' ? $model->document(1) : (new NationalRepository($model))->document(1);
    lifeCheck($result['status'] === 'registered' && $record['status'] === 'authorized' && empty($record['cancellation_event_enc']), 'pedido/manifestação não cancela a NFS-e/' . $type);
    lifeCheck(lifeRequest($model, $type, $origin)['event_type'] === $type && lifePostCount($gateway) === 1, 'manifestação ou pedido possui seu evento próprio/' . $type);
}

// Permission, full actor identity, context switching and environment checks precede transmission.
foreach ([['local', false, 'view', '101101'], ['local', false, 'cancel', '101101'], ['national', true, 'manifest', '203206'], ['national', false, 'national_view', '101101']] as [$origin, $received, $denied, $type]) {
    [$model, $gateway, $service] = lifeFixture($pfx, $origin, $received); $lifePermissions[$denied] = false;
    lifeFails(fn() => $service->submit($origin, 1, $type, '2', $reason), 'permissão específica é exigida/' . $denied);
    lifeCheck($gateway->calls === [], 'permissão recusada não acessa SEFIN/' . $denied); $lifePermissions[$denied] = true;
}
foreach ([['local', false, '203202'], ['national', true, '101101']] as [$origin, $received, $type]) {
    [$model, $gateway, $service] = lifeFixture($pfx, $origin, $received);
    lifeFails(fn() => $service->submit($origin, 1, $type, '2', $reason), 'empresa não atua no papel de outra parte/' . $type);
    lifeCheck($gateway->calls === [], 'papel divergente bloqueado antes da consulta');
}
[$model, $gateway, $service] = lifeFixture($pfx);
$model->configuration['provider']['tax_id'] = '11444777000161';
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'alterar emitente nos parâmetros não muda empresa autenticada');
lifeCheck($gateway->calls === [], 'emitente divergente não chega à API');
[$model, $gateway, $service] = lifeFixture($pfx);
$lifePrefix = 'life_b_';
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'serviço antigo não escreve após troca de empresa');
lifeCheck($gateway->calls === [], 'troca de prefixo é bloqueada antes da rede'); $lifePrefix = 'life_a_';
[$model, $gateway, $service] = lifeFixture($pfx);
$model->documents[1]['environment'] = '1';
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'metadado do ambiente não sobrepõe o ambiente fiscal do XML');
lifeCheck($gateway->calls === [], 'divergência de ambiente não transmite');
[$model, $gateway, $service] = lifeFixture($pfx);
$model->beforeLock = static function (): void { $GLOBALS['lifePermissions']['cancel'] = false; };
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'permissão é conferida novamente dentro da trava');
lifeCheck($gateway->calls === [], 'revogação durante a aquisição da trava impede rede'); $lifePermissions['cancel'] = true;
[$model, $gateway, $service] = lifeFixture($pfx, 'local', false, '1');
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'porta de produção impede POST fiscal');
lifeCheck(array_column($gateway->calls, 0) === ['GET'], 'produção desabilitada permite recuperação somente por consulta');

// A lost response is recovered with GET and never creates another event POST.
[$model, $gateway, $service, $xml] = lifeFixture($pfx);
$gateway->postMode = 'timeout_after_accept';
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'timeout mantém pedido sem confirmação', static fn(Throwable $e): bool => $e instanceof ApiException && $e->isUncertain());
$pending = lifeRequest($model, '101101');
lifeCheck($pending['status'] === 'uncertain' && $model->document(1)['status'] === 'authorized' && $pending['attempts'] === 1, 'timeout não presume cancelamento nem apaga o pedido');
$recovered = $service->consult('local', 1, '101101');
lifeCheck($recovered['status'] === 'registered' && $model->document(1)['status'] === 'cancelled'
    && array_column($gateway->calls, 0) === ['GET', 'POST', 'GET'], 'GET recupera aceitação perdida sem duplicar POST');
lifeCheck($model->crypto()->decrypt($model->document(1)['response_enc']) === $xml, 'recuperação preserva XML autorizado original');

// Explicit retry uses the original signed snapshot, ignoring newly submitted reasons.
[$model, $gateway, $service] = lifeFixture($pfx);
$gateway->postMode = 'timeout_without_accept';
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'pedido de retry inicialmente fica incerto');
$original = $gateway->postedXml[0];
lifeFails(fn() => $service->submit('local', 1, '101101', '1', 'Justificativa alterada não autorizada para o pedido original.'), 'repetição sem ação explícita não reenvia');
lifeCheck(lifePostCount($gateway) === 1, 'clique comum sobre pendência somente consulta');
$gateway->postMode = 'accept';
$service->submit('local', 1, '101101', '1', 'Justificativa nova não deve substituir o pedido durável.', true);
lifeCheck($gateway->postedXml === [$original, $original] && lifeRequest($model, '101101')['attempts'] === 2, 'reenvio explícito mantém XML assinado byte a byte e registra tentativa');

// A correctly signed/hash-valid request attached to the wrong row must never be sent.
[$model, $gateway, $service] = lifeFixture($pfx);
$gateway->postMode = 'timeout_without_accept';
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'fixture de pedido pendente para correlação');
$wrong = EventRequest::build(new NfseDocument(lifeNote(2)), $lifeTaxId, '101101', '2', $reason, $certificate);
$requestTable = db_prefix() . 'nfse_nacional_fiscal_requests';
$model->db->tables[$requestTable][0]['request_enc'] = $model->crypto()->encrypt($wrong);
$model->db->tables[$requestTable][0]['request_hash'] = hash('sha256', $wrong);
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason, true), 'retry rejeita XML válido de outra nota mesmo com hash íntegro');
lifeCheck(lifePostCount($gateway) === 1, 'pedido vinculado incorretamente não chega a um novo POST');

[$model, $gateway, $service] = lifeFixture($pfx);
$gateway->getResponse = ['status' => 404, 'body' => '<html>Route not found</html>'];
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), '404 de rota mantém consulta inconclusiva', static fn(Throwable $e): bool => $e instanceof ApiException && $e->isUncertain());
lifeCheck(lifePostCount($gateway) === 0 && lifeRequest($model, '101101') === null, '404 ambíguo não cria pedido processando nem POST');

// Storage failures before send and after acceptance remain recoverable.
[$model, $gateway, $service] = lifeFixture($pfx);
$model->db->failWrite = static fn($table, $data): bool => str_ends_with($table, '_fiscal_requests') && ($data['status'] ?? '') === 'processing';
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'falha ao persistir pedido impede transmissão');
lifeCheck(lifePostCount($gateway) === 0 && $model->document(1)['status'] === 'authorized', 'não há efeito fiscal quando persistência prévia falha');
[$model, $gateway, $service] = lifeFixture($pfx);
$failRegistration = true;
$model->db->failWrite = static function ($table, $data) use (&$failRegistration): bool {
    if ($failRegistration && str_ends_with($table, '_fiscal_requests') && ($data['status'] ?? '') === 'registered') { $failRegistration = false; return true; }
    return false;
};
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'falha ao salvar resposta aceita preserva pendência');
lifeCheck(lifeRequest($model, '101101')['status'] === 'uncertain' && lifePostCount($gateway) === 1, 'pedido original persiste apesar da falha de armazenamento da resposta');
$service->consult('local', 1, '101101');
lifeCheck($model->document(1)['status'] === 'cancelled' && lifePostCount($gateway) === 1, 'retomada consulta resposta aceita sem novo POST após falha de banco');

[$model, $gateway, $service] = lifeFixture($pfx);
$gateway->afterAccept = static function () use ($model): void {
    $model->db->failWrite = static fn($table, $data): bool => true;
};
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'indisponibilidade persistente após aceite não apaga pedido durável');
$pending = lifeRequest($model, '101101');
lifeCheck($pending['status'] === 'processing' && $model->crypto()->decrypt($pending['request_enc']) === $gateway->postedXml[0]
    && lifePostCount($gateway) === 1, 'mesmo sem salvar diagnóstico o XML anterior ao POST continua recuperável');
$model->db->failWrite = null; $gateway->afterAccept = null;
$service->consult('local', 1, '101101');
lifeCheck($model->document(1)['status'] === 'cancelled' && lifePostCount($gateway) === 1, 'recuperação após indisponibilidade persistente usa somente GET');

[$model, $gateway, $service] = lifeFixture($pfx);
$model->failProjection = true;
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'falha de projeção após armazenar evento confirmado fica recuperável');
lifeCheck(lifeRequest($model, '101101')['status'] === 'registered' && $model->document(1)['status'] === 'authorized', 'prova registrada não perde certeza quando somente projeção local falha');
$calls = count($gateway->calls); $model->certificateRow = [];
$service->submit('local', 1, '101101', '2', $reason);
lifeCheck($model->document(1)['status'] === 'cancelled' && count($gateway->calls) === $calls, 'prova durável reaplica projeção sem depender de nova rede ou A1');

[$model, $gateway, $service, $xml] = lifeFixture($pfx, 'national');
$failProjection = true;
$model->db->failWrite = static function ($table, $data) use (&$failProjection): bool {
    if ($failProjection && str_ends_with($table, '_national_documents') && ($data['status'] ?? '') === 'cancelled') {
        $failProjection = false; return true;
    }
    return false;
};
lifeFails(fn() => $service->submit('national', 1, '101101', '2', $reason), 'falha na projeção nacional mantém evento registrado');
lifeCheck(lifeRequest($model, '101101', 'national')['status'] === 'registered'
    && (new NationalRepository($model))->document(1)['status'] === 'authorized', 'prova nacional registrada também preserva certeza durante falha de projeção');
$calls = count($gateway->calls); $model->certificateRow = [];
$service->submit('national', 1, '101101', '2', $reason);
$stored = (new NationalRepository($model))->document(1);
lifeCheck($stored['status'] === 'cancelled' && count($gateway->calls) === $calls && $stored['nsu'] === '42'
    && $model->crypto()->decrypt($stored['xml_enc']) === $xml, 'reaplicação nacional preserva NSU e XML original sem nova consulta');

// Author and cached evidence are checked again at the persistence boundary.
[$model, $gateway, $service] = lifeFixture($pfx);
$request = EventRequest::build(new NfseDocument(lifeNote(1)), $lifeTaxId, '101101', '2', $reason, $certificate);
$wrongAuthor = str_replace('<CNPJAutor>11222333000181</CNPJAutor>', '<CNPJAutor>11444777000161</CNPJAutor>', lifeRegistered($request));
$gateway->getResponse = $gateway->response($wrongAuthor);
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'evento do mesmo tipo/chave com autor divergente é recusado');
lifeCheck(lifePostCount($gateway) === 0 && lifeRequest($model, '101101') === null && $model->document(1)['status'] === 'authorized', 'autor incorreto não persiste prova nem altera status');
[$model, $gateway, $service] = lifeFixture($pfx);
$service->submit('local', 1, '101101', '2', $reason); $calls = count($gateway->calls);
$otherRequest = EventRequest::build(new NfseDocument(lifeNote(2)), $lifeTaxId, '101101', '2', $reason, $certificate);
$model->db->tables[$requestTable][0]['response_enc'] = $model->crypto()->encrypt(lifeRegistered($otherRequest));
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'cache registrado exige evento da chave correta');
lifeCheck(count($gateway->calls) === $calls, 'cache inválido não tenta recriar evento');

// A later absent event or another nonterminal event never reactivates a cancelled note.
[$model, $gateway, $service, $xml] = lifeFixture($pfx);
$service->submit('local', 1, '101101', '2', $reason);
$status = $service->consult('local', 1, '101103');
lifeCheck($status['status'] === 'not_found' && $model->document(1)['status'] === 'cancelled', 'consulta vazia de outro tipo não desfaz cancelamento');
lifeFails(fn() => $service->submit('local', 1, '101103', '2', $reason), 'nota cancelada não recebe novo pedido fiscal');
lifeCheck(lifePostCount($gateway) === 1 && $model->crypto()->decrypt($model->document(1)['response_enc']) === $xml
    && $model->document(1)['request_enc'] === 'LOCAL_DPS_ORIGINAL_UNCHANGED', 'cancelamento e consultas preservam XML e DPS originais');
[$model, $gateway, $service] = lifeFixture($pfx);
$model->replacements[1] = ['dps_id' => 'PENDING_SUBSTITUTION', 'attempts' => 1];
lifeFails(fn() => $service->submit('local', 1, '101101', '2', $reason), 'substituição pendente impede cancelamento concorrente');
lifeCheck(lifePostCount($gateway) === 0, 'conflito com substituição não transmite evento');

echo "OK — {$checks} verificações do ciclo fiscal durável, recuperação e isolamento (sem rede).\n";
