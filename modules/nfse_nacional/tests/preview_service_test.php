<?php
declare(strict_types=1);

namespace NfseNacional {
    /** Capture the renderer boundary. Real layout/font checks are in preview_pdf_test. */
    final class Danfe {
        public static array $calls = [];
        public static function preview(array $draft, array $document, array $status): string {
            self::$calls[] = ['kind' => 'preview', 'draft' => $draft, 'document' => $document, 'status' => $status];
            return '%PDF-FIXTURE-PREVIEW';
        }
        public static function render(string $xml, array $status = []): string {
            self::$calls[] = ['kind' => 'official', 'xml' => $xml, 'status' => $status];
            return '%PDF-FIXTURE-OFFICIAL';
        }
    }
    final class Mailer {
        public static int $checks = 0;
        public static int $sends = 0;
        public static function assertReady(): void { ++self::$checks; }
        public static function send(string $email, string $xml, string $pdf): void { ++self::$sends; }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('BASEPATH', __DIR__);
    require dirname(__DIR__) . '/autoload.php';
    class App_Model { public $db = null; }
    require dirname(__DIR__) . '/models/Nfse_nacional_model.php';

    use NfseNacional\ApiClient;
    use NfseNacional\ApiException;
    use NfseNacional\Config;
    use NfseNacional\Crypto;
    use NfseNacional\Danfe;
    use NfseNacional\DpsBuilder;
    use NfseNacional\FiscalStatus;
    use NfseNacional\Mailer;
    use NfseNacional\Service;
    use NfseNacional\XmlSigner;

    function nfse_nacional_can(string $capability): bool { return true; }
    function nfse_nacional_scope(): string { return hash('sha256', 'preview-test-company'); }
    function db_prefix(): string { return 'preview_test_'; }
    function is_staff_logged_in(): bool { return true; }
    function get_staff_user_id(): int { return 7; }
    function get_option(string $name) { return $name === 'company_vat' ? '11222333000181' : ''; }
    $checks = 0;
    function previewAssert(bool $ok, string $label): void {
        if (!$ok) { throw new \RuntimeException('FALHOU: ' . $label); }
        ++$GLOBALS['checks'];
    }
    function previewFails(callable $action, string $label, ?callable $verify = null): \Throwable {
        try { $action(); } catch (\Throwable $error) {
            previewAssert($verify === null || $verify($error), $label);
            return $error;
        }
        throw new \RuntimeException('FALHOU: ' . $label);
    }

    /** CI/database replaced only; service, crypto, resolver, API and XML correlation remain real. */
    final class PreviewMemoryModel extends \Nfse_nacional_model {
        public array $doc;
        public array $config;
        public array $rows = [];
        public object $testInvoice;
        public array $events = [];
        public array $updates = [];
        public int $reservations = 0;
        public int $settingsReads = 0;
        public int $certificateReads = 0;
        private Crypto $cipher;
        private bool $locked = false;
        public function __construct(array $draft) {
            $this->cipher = new Crypto(str_repeat('preview-only-secret-', 3), 'preview-test-company');
            $this->config = Config::defaults();
            $this->config['environment'] = '2';
            $this->config['provider'] = $draft['provider'] ?? $this->config['provider'];
            $this->config['use_local_certificate'] = '1';
            $this->testInvoice = (object) ['id' => 41, 'clientid' => 7, 'currency' => 1, 'subtotal' => '100.00',
                'discount_total' => '0.00', 'total' => '100.00', 'status' => 1,
                'items' => [['description' => 'Serviço de teste', 'qty' => '1', 'rate' => '100.00']]];
            $json = self::json($draft);
            $this->doc = ['id' => 1, 'invoice_id' => 41, 'client_id' => 7, 'environment' => '2', 'status' => 'draft',
                'draft_json' => $json, 'draft_hash' => hash('sha256', $json), 'invoice_fingerprint' => self::fingerprint($this->testInvoice),
                'dps_id' => null, 'dps_series' => null, 'dps_number' => null, 'request_enc' => null, 'response_enc' => null,
                'access_key' => null, 'nfse_number' => null, 'service_amount' => '100.00', 'send_after_authorized' => 1,
                'recipient_email' => 'offline@example.invalid', 'email_status' => 'pending', 'attempts' => 0,
                'last_error' => null, 'authorized_at' => null, 'fiscal_meta_json' => null, 'cancellation_event_enc' => null];
        }
        public function crypto(): Crypto { return $this->cipher; }
        public function settingsRow(): array { ++$this->certificateReads; return $this->rows; }
        public function settings(): array { ++$this->settingsReads; return $this->config; }
        public function document(int $id): array {
            if ($id !== 1) { throw new \RuntimeException('Documento fora do contexto da empresa.'); }
            return $this->doc;
        }
        public function invoice(int $id): object {
            if ($id !== 41) { throw new \RuntimeException('Fatura inexistente.'); }
            return $this->testInvoice;
        }
        public function assertInvoice(object $invoice): void {
            if ($invoice->status !== 1) { throw new \RuntimeException('Fatura indisponível.'); }
        }
        public function updateDocument(int $id, array $changes): void {
            $this->updates[] = $changes; $this->doc = array_replace($this->doc, $changes);
        }
        public function event(int $id, string $type, string $message): void { $this->events[] = [$type, $message]; }
        private array $heldLocks = [];
    public function lock(string $name, callable $operation) {
        if ($this->locked && !$this->heldLocks) { throw new RuntimeException('Operação já em andamento.'); }
        $this->locked = true; $this->heldLocks[] = $name;
        try { return $operation(); } finally { array_pop($this->heldLocks); $this->locked = (bool) $this->heldLocks; }
    }
    public function reserve(array $document, array $draft, array $settings): array {
            ++$this->reservations;
            throw new \RuntimeException('Uma prévia ou consulta não pode reservar numeração.');
        }
        public function installCertificate(string $pfx): void {
            $this->rows = ['certificate_enc' => $this->cipher->encrypt(self::json(['pfx' => base64_encode($pfx), 'password' => 'preview-offline']))];
        }
    }

    $draft = ['environment' => '2', 'competence' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
        'issued_at' => '2026-11-10T12:00:00-03:00', 'invoice_values_acknowledged' => '0',
        'provider' => ['tax_id' => '11222333000181', 'name' => 'EMITENTE SINTÉTICO', 'municipality_code' => '3550308',
            'municipal_registration' => '', 'simple_status' => '3', 'special_regime' => '0', 'simple_apportionment' => '1', 'phone' => '', 'email' => ''],
        'recipient' => ['name' => 'TOMADOR SINTÉTICO', 'tax_id' => '52998224725', 'email' => 'offline@example.invalid', 'phone' => '',
            'municipality_code' => '3550308', 'zip' => '01001000', 'street' => 'Rua de teste', 'number' => '1', 'complement' => '', 'district' => 'Centro'],
        'service' => ['municipality_code' => '3550308', 'national_code' => '010101', 'municipal_code' => '', 'nbs' => '', 'description' => 'Serviço sintético offline'],
        'values' => ['service_amount' => '100.00', 'unconditional_discount' => '0.00', 'conditional_discount' => '0.00', 'iss_taxation' => '1',
            'retention' => '1', 'iss_rate' => '', 'total_tax_mode' => 'none', 'total_federal' => '0.00', 'total_state' => '0.00', 'total_municipal' => '0.00', 'simple_rate' => ''],
        'ibscbs' => Config::defaults()['ibscbs']];
    $key50 = str_repeat('1', 50);
    $dps = (new DpsBuilder())->build($draft, '1', '1');
    $dpsId = DpsBuilder::identifier($draft['provider'], '1', '1');
    $officialXml = '<?xml version="1.0" encoding="UTF-8"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key50 . '">'
        . '<nNFSe>123</nNFSe><cStat>100</cStat><dhProc>2026-11-10T12:01:00-03:00</dhProc><nDFSe>42</nDFSe>'
        . preg_replace('/<\?xml[^>]*\?>\s*/', '', $dps) . '</infNFSe></NFSe>';
    $cancelXml = '<?xml version="1.0" encoding="UTF-8"?><evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">'
        . '<infEvento Id="EVT' . $key50 . '101101001"><verAplic>FIXTURE</verAplic><ambGer>2</ambGer><nSeqEvento>001</nSeqEvento>'
        . '<dhProc>2026-11-10T13:00:00-03:00</dhProc><nDFSe>43</nDFSe><pedRegEvento versao="1.01"><infPedReg Id="PRE' . $key50 . '101101">'
        . '<tpAmb>2</tpAmb><verAplic>FIXTURE</verAplic><dhEvento>2026-11-10T12:59:00-03:00</dhEvento><CNPJAutor>11222333000181</CNPJAutor>'
        . '<chNFSe>' . $key50 . '</chNFSe><e101101><xDesc>Cancelamento de NFS-e</xDesc><cMotivo>1</cMotivo><xMotivo>FIXTURE OFFLINE</xMotivo></e101101>'
        . '</infPedReg></pedRegEvento></infEvento><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignatureValue>RklYVFVSRSBPRkZMSU5F</SignatureValue></Signature></evento>';
    function previewAuthorize(PreviewMemoryModel $model, string $xml, string $key, string $dps): void {
        $model->doc = array_replace($model->doc, ['status' => 'authorized', 'access_key' => $key, 'dps_id' => $dps,
            'dps_series' => '1', 'dps_number' => '1', 'nfse_number' => '123', 'attempts' => 1,
            'response_enc' => $model->crypto()->encrypt($xml), 'authorized_at' => '2026-11-10 12:01:00',
            'fiscal_meta_json' => \Nfse_nacional_model::json(['processed_at' => '2026-11-10T12:01:00-03:00',
                'status_checked_at' => '2026-01-01 10:00:00', 'private_fixture' => 'DO_NOT_EXPOSE_PRIVATE_METADATA'])]);
    }

    $network = 0;
    $noNetwork = static function () use (&$network) { ++$network; throw new \RuntimeException('A prévia não pode iniciar comunicação fiscal.'); };
    $model = new PreviewMemoryModel($draft);
    $service = new Service($model, $noNetwork);
    foreach (['draft', 'ready', 'rejected', 'processing', 'uncertain'] as $state) {
        $model->doc['status'] = $state;
        $model->doc['attempts'] = in_array($state, ['processing', 'uncertain', 'rejected'], true) ? 1 : 0;
        $before = $model->doc;
        previewAssert($service->pdf(1) === '%PDF-FIXTURE-PREVIEW', 'prévia disponível no estado ' . $state);
        $call = end(Danfe::$calls);
        previewAssert($call['kind'] === 'preview' && $call['draft'] === $draft && $call['document'] === $before
            && $call['status']['status'] === $state, 'renderização recebe dados salvos e situação correspondente: ' . $state);
        previewAssert($model->doc === $before && !$model->updates && !$model->events && !$model->reservations
            && !$model->settingsReads && !$model->certificateReads && !$network, 'prévia não valida, não reserva, não salva e não chama API/certificado: ' . $state);
    }
    $incomplete = new PreviewMemoryModel(['environment' => '2', 'recipient' => [], 'service' => ['description' => ''], 'values' => []]);
    $incompleteService = new Service($incomplete, $noNetwork);
    $before = $incomplete->doc;
    previewAssert($incompleteService->pdf(1) === '%PDF-FIXTURE-PREVIEW', 'rascunho incompleto não exige validação fiscal ou A1 para prévia');
    previewAssert($incomplete->doc === $before && !$incomplete->certificateReads && !$incomplete->updates && !$network, 'prévia incompleta preserva estado e hash');
    previewFails(fn() => $service->pdf(2), 'prévia respeita a restrição do modelo ao documento da empresa');

    $model = new PreviewMemoryModel($draft); previewAuthorize($model, $officialXml, $key50, $dpsId);
    $service = new Service($model, $noNetwork);
    $before = $model->doc;
    previewAssert($service->pdf(1) === '%PDF-FIXTURE-OFFICIAL', 'PDF autorizado usa renderização do XML oficial');
    $call = end(Danfe::$calls);
    previewAssert($call['xml'] === $officialXml && $call['status']['is_authorized'] && $call['status']['protocol'] === ''
        && $call['status']['record_number'] === '42', 'autorização mantém XML original e registro sem inventar protocolo');
    previewAssert($model->doc === $before && !$model->updates && !$model->certificateReads && !$network, 'prévia autorizada é leitura local sem efeito fiscal');
    $model->testInvoice->status = 5;
    previewAssert(!$service->fiscalStatus($model->doc)['is_cancelled'], 'cancelamento da fatura não cancela NFS-e');
    $public = $service->publicDocument($model->doc);
    foreach (['request_enc', 'response_enc', 'draft_json', 'fiscal_meta_json', 'cancellation_event_enc'] as $field) {
        previewAssert(!array_key_exists($field, $public), 'resposta pública omite ' . $field);
    }
    previewAssert(strpos(json_encode($public), 'DO_NOT_EXPOSE_PRIVATE_METADATA') === false, 'metadados privados não escapam pela resposta pública');
    $bad = $model->doc; $bad['access_key'] = str_repeat('2', 50);
    previewFails(fn() => $service->fiscalStatus($bad), 'XML de outra chave bloqueia status e PDF');
    $model->doc = $bad;
    previewFails(fn() => $service->pdf(1), 'PDF não aceita chave distinta do XML persistido');
    $model->doc = $before;
    $model->doc['status'] = 'cancelled';
    previewFails(fn() => $service->pdf(1), 'status cancelado sem prova fiscal não gera carimbo');
    $model->doc = $before;

    // Ephemeral self-signed test identity, only used by the fake transport below.
    $config = tempnam(sys_get_temp_dir(), 'nfse_preview_service_');
    try {
        file_put_contents($config, "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=TESTE OFFLINE:11222333000181\n");
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => 'TESTE OFFLINE:11222333000181'], $private, ['config' => $config, 'digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $private, 1, ['config' => $config, 'digest_alg' => 'sha256']);
        $pfx = ''; openssl_pkcs12_export($certificate, $pfx, $private, 'preview-offline');
    } finally { if (is_file($config)) { unlink($config); } }
    $model->installCertificate($pfx);
    $model->testInvoice->status = 1;
    $methods = [];
    $currentResponse = ['status' => 200, 'body' => json_encode(['tipoAmbiente' => 2, 'eventoXmlGZipB64' => base64_encode(gzencode($cancelXml))])];
    $service = new Service($model, static function ($environment, $certificate) use (&$methods, &$currentResponse) {
        return new ApiClient($environment, $certificate, static function ($method, $url, $body) use (&$methods, &$currentResponse): array {
            $methods[] = [$method, $url, $body]; return $currentResponse;
        });
    });
    $cancelled = $service->reconcile(1);
    previewAssert($cancelled['status'] === 'cancelled' && $cancelled['fiscal_status']['is_cancelled'], 'consulta de evento efetivo confirma cancelamento');
    previewAssert(count($methods) === 1 && $methods[0][0] === 'GET' && $methods[0][2] === null
        && str_ends_with($methods[0][1], '/nfse/' . $key50 . '/eventos') && $model->reservations === 0,
        'atualização de situação usa apenas consulta sem reserva ou transmissão');
    previewAssert($model->crypto()->decrypt($model->doc['cancellation_event_enc']) === $cancelXml
        && strpos($model->doc['cancellation_event_enc'], '<evento') === false, 'recibo de cancelamento é persistido cifrado');
    previewAssert($model->doc['response_enc'] === $before['response_enc'] && $model->doc['draft_hash'] === $before['draft_hash']
        && $model->doc['dps_id'] === $before['dps_id'], 'cancelamento preserva XML autorizado, hash e identificação de origem');
    previewAssert($cancelled['fiscal_status']['cancellation_event_id'] === 'EVT' . $key50 . '101101001'
        && $cancelled['fiscal_status']['cancellation_record_number'] === '43'
        && $cancelled['fiscal_status']['cancelled_at'] === '2026-11-10T13:00:00-03:00'
        && $cancelled['fiscal_status']['cancellation_protocol'] === '', 'cancelamento exibe evento, registro e data reais sem falso protocolo');
    previewAssert(Mailer::$checks === 0 && Mailer::$sends === 0 && $model->doc['email_status'] === 'pending', 'consulta fiscal não envia e-mail mesmo com envio automático habilitado');
    foreach (['transmit', 'retrySame', 'send', 'retryEmail'] as $action) {
        $snapshot = $model->doc; $callCount = count($methods);
        previewFails(fn() => $service->{$action}(1), 'nota cancelada bloqueia ' . $action);
        previewAssert($model->doc === $snapshot && count($methods) === $callCount && Mailer::$sends === 0, 'ação bloqueada não altera nem transmite documento cancelado: ' . $action);
    }
    previewAssert($service->xml(1) === $officialXml, 'XML original da nota cancelada continua disponível para histórico');
    $snapshot = $model->doc; $updates = count($model->updates); $certReads = $model->certificateReads;
    previewAssert($service->pdf(1) === '%PDF-FIXTURE-OFFICIAL', 'DANFSe cancelada permanece disponível');
    $call = end(Danfe::$calls);
    previewAssert($call['kind'] === 'official' && $call['xml'] === $officialXml && $call['status']['is_cancelled']
        && $model->doc === $snapshot && count($model->updates) === $updates && $model->certificateReads === $certReads,
        'PDF cancelado deriva do XML autorizado com carimbo baseado no evento, sem efeitos colaterais');
    $public = $service->publicDocument($model->doc);
    previewAssert(!isset($public['cancellation_event_enc'], $public['fiscal_meta_json'])
        && strpos(json_encode($public), '<evento') === false, 'resposta cancelada não vaza recibo ou metadados internos');

    $validCancelled = $model->doc;
    foreach ([
        'cifrado corrompido' => 'not-an-encrypted-event',
        'evento de outra nota' => $model->crypto()->encrypt(str_replace($key50, str_repeat('2', 50), $cancelXml)),
        'somente solicitação' => $model->crypto()->encrypt(str_replace('101101', '101103', $cancelXml)),
    ] as $label => $evidence) {
        $model->doc = $validCancelled; $model->doc['cancellation_event_enc'] = $evidence;
        previewFails(fn() => $service->pdf(1), 'prova inválida bloqueia carimbo: ' . $label);
    }
    foreach (['cancellation_event_id' => 'EVT' . str_repeat('2', 50) . '101101001', 'cancelled_at' => '2026-11-10T14:00:00-03:00'] as $field => $value) {
        $model->doc = $validCancelled;
        $meta = FiscalStatus::metadata($model->doc); $meta[$field] = $value;
        $model->doc['fiscal_meta_json'] = \Nfse_nacional_model::json($meta);
        previewFails(fn() => $service->pdf(1), 'metadado de cancelamento divergente bloqueia carimbo: ' . $field);
    }
    $model->doc = $validCancelled;

    foreach (['cancelled', 'authorized'] as $state) {
        $model->doc = $state === 'cancelled' ? $validCancelled : $before;
        $previous = $model->doc;
        $previousCheck = FiscalStatus::metadata($previous)['status_checked_at'];
        $currentResponse = ['status' => 404, 'body' => '<html>Not Found</html>'];
        previewFails(fn() => $service->reconcile(1), '404 de rota não altera situação ' . $state,
            fn($e) => $e instanceof ApiException && $e->isUncertain());
        $afterMeta = FiscalStatus::metadata($model->doc);
        previewAssert($model->doc['status'] === $state && $model->doc['response_enc'] === $previous['response_enc']
            && $model->doc['cancellation_event_enc'] === $previous['cancellation_event_enc']
            && $afterMeta['status_checked_at'] === $previousCheck && !empty($afterMeta['status_check_attempted_at'])
            && !empty($afterMeta['status_check_error']), 'consulta falha mantém prova, situação e data da última confirmação: ' . $state);
        previewAssert(strpos($service->fiscalStatus($model->doc)['explanation'], 'situação anterior foi preservada') !== false,
            'status informa atualização inconclusiva: ' . $state);
    }

    $model->doc = $validCancelled;
    $currentResponse = ['status' => 404, 'body' => '{"tipoAmbiente":2,"erro":{"descricao":"Nenhum evento encontrado para a NFS-e"}}'];
    previewAssert($service->reconcile(1)['status'] === 'cancelled'
        && $model->doc['cancellation_event_enc'] === $validCancelled['cancellation_event_enc'], 'consulta posterior vazia não reativa nota já cancelada');
    $model->doc = $before;
    $currentResponse = ['status' => 200, 'body' => json_encode(['tipoAmbiente' => 2,
        'eventoXmlGZipB64' => base64_encode(gzencode(str_replace('101101', '101103', $cancelXml)))])];
    previewAssert($service->reconcile(1)['status'] === 'authorized' && empty($model->doc['cancellation_event_enc']), 'pedido de análise fiscal preserva autorização até cancelamento efetivo');
    previewAssert(Mailer::$checks === 0 && Mailer::$sends === 0, 'consultas sucessivas de situação não enviam mensagens');

    $rejected = new PreviewMemoryModel($draft); $rejected->installCertificate($pfx);
    $rejected->doc['status'] = 'rejected'; $rejected->doc['attempts'] = 1;
    $rejected->doc['dps_id'] = $dpsId; $rejected->doc['dps_series'] = '1'; $rejected->doc['dps_number'] = '1';
    $rejected->doc['request_enc'] = $rejected->crypto()->encrypt('DPS ANTERIOR REJEITADA');
    $retryService = new Service($rejected, $noNetwork);
    previewAssert($retryService->fiscalStatus($rejected->doc)['transmission_label'] === 'Envio rejeitado; sem autorização', 'rejeição é distinguida de rascunho nunca enviado');
    $saved = $retryService->saveDraft(1, ['service' => ['description' => 'Serviço corrigido após rejeição']], $rejected->doc['draft_hash'], true);
    previewAssert($saved['status'] === 'draft' && $saved['fiscal_status']['attempts'] === 1
        && $saved['fiscal_status']['transmission_label'] === 'Versão atual não transmitida', 'correção preserva histórico e informa versão ainda não enviada');
    $validated = $retryService->validate(1);
    previewAssert($validated['status'] === 'ready' && $validated['fiscal_status']['transmission_label'] === 'Versão atual não transmitida'
        && $rejected->doc['dps_id'] === $dpsId && $rejected->reservations === 0 && $network === 0,
        'validação após rejeição não transforma correção em autorização nem cria nova identidade');
    $pending = $rejected->doc; $pending['status'] = 'uncertain';
    previewAssert(FiscalStatus::describe($pending)['transmission_label'] === 'Envio iniciado; confirmação pendente', 'falha de confirmação não afirma que nota deixou de ser transmitida');
    echo 'OK: ' . $checks . " verificações do serviço de prévia, evidência fiscal e ausência de efeitos colaterais, todas offline (renderer/SMTP simulados).\n";
}
