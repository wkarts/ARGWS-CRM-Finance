<?php
declare(strict_types=1);

namespace NfseNacional;

/** Contributor event workflows with a durable request and explicit recovery after lost replies. */
final class LifecycleService
{
    private $model;
    private FiscalRequestRepository $requests;
    private \Closure $apiFactory;
    public function __construct($model, ?callable $apiFactory = null)
    {
        $this->model = $model; $this->requests = new FiscalRequestRepository($model);
        $this->apiFactory = $apiFactory ? \Closure::fromCallable($apiFactory)
            : static fn(string $environment, Certificate $certificate) => new EventClient($environment, $certificate);
    }

    public static function definitions(): array
    {
        $cancel = ['1' => 'Erro na emissão', '2' => 'Serviço não prestado', '9' => 'Outros'];
        return [
            '101101' => ['label' => 'Cancelar NFS-e', 'role' => 'prest', 'permission' => 'cancel', 'reasons' => $cancel, 'reason_required' => true],
            '101103' => ['label' => 'Solicitar análise fiscal para cancelamento', 'role' => 'prest', 'permission' => 'cancel', 'reasons' => $cancel, 'reason_required' => true],
            '203202' => ['label' => 'Confirmar NFS-e recebida', 'role' => 'toma', 'permission' => 'manifest', 'reasons' => [], 'reason_required' => false],
            '203206' => ['label' => 'Rejeitar NFS-e recebida', 'role' => 'toma', 'permission' => 'manifest',
                'reasons' => ['1' => 'NFS-e em duplicidade', '2' => 'NFS-e já emitida pelo tomador', '3' => 'Não ocorrência do fato gerador',
                    '4' => 'Erro na responsabilidade tributária', '5' => 'Erro no valor, deduções, serviço ou data do fato gerador', '9' => 'Outros'], 'reason_required' => false],
        ];
    }

    public function page(string $origin, int $id): array
    {
        [$record, $note, $issuer] = $this->context($origin, $id);
        $actions = []; $history = [];
        foreach (self::definitions() as $type => $definition) {
            if (NationalRepository::noteTaxId($note, $definition['role']) !== $issuer) { continue; }
            $row = $this->requests->find($issuer, $note->environment(), $note->accessKey(), (string) $type);
            if ($row) { $history[] = $this->publicRequest($row) + ['label' => $definition['label']]; }
            $actions[(string) $type] = $definition + ['can_submit' => nfse_nacional_can($definition['permission']) && $record['status'] === 'authorized',
                'request_status' => $row['status'] ?? '', 'can_retry' => $row && in_array($row['status'], ['processing', 'uncertain'], true)
                    && !empty($row['request_enc']) && nfse_nacional_can($definition['permission'])];
        }
        return ['origin' => $origin, 'id' => $id, 'environment' => $note->environment(), 'nfse_number' => $note->number(),
            'access_key' => $note->accessKey(), 'status' => $record['status'], 'actions' => $actions, 'history' => $history];
    }

    public function submit(string $origin, int $id, string $type, string $reasonCode, string $reason, bool $retry = false): array
    {
        [$record, $note, $issuer] = $this->context($origin, $id);
        $this->authorizeAction($note, $issuer, $type, true);
        return $this->model->lock('fiscal:' . $note->environment() . ':' . $note->accessKey(), function () use ($origin, $id, $type, $reasonCode, $reason, $retry): array {
            [$record, $note, $issuer] = $this->context($origin, $id);
            $this->authorizeAction($note, $issuer, $type, true);
            $existing = $this->requests->find($issuer, $note->environment(), $note->accessKey(), $type);
            if ($existing && $existing['status'] === 'registered') { return $this->registered($origin, $record, $note, $issuer, $type, $this->proof($existing, $note, $issuer, $type)); }
            $certificate = (new CertificateResolver($this->model))->resolve($issuer, $note->environment());
            $api = ($this->apiFactory)($note->environment(), $certificate);
            // Before any POST, recover a registered event from the exact same fiscal identity.
            $found = $api->find($note->accessKey(), $type, 1);
            if ($found) { return $this->registered($origin, $record, $note, $issuer, $type, $found); }
            if ($record['status'] !== 'authorized') { throw new \RuntimeException('A nota não está autorizada para solicitar esse evento. Consulte sua situação.'); }
            if ($origin === 'local' && in_array($type, ['101101', '101103'], true)) {
                $replacement = $this->model->replacementFor($id);
                if ($replacement && (!empty($replacement['dps_id']) || (int) $replacement['attempts'] > 0)) {
                    throw new \RuntimeException('Existe uma substituição transmitida ou aguardando confirmação. Consulte o documento de substituição antes de cancelar.');
                }
            }
            if ($note->environment() === '1' && ($this->model->settings()['production_enabled'] ?? '0') !== '1') {
                throw new \RuntimeException('Habilite as operações em produção nas configurações da NFS-e.');
            }
            if ($existing && in_array($existing['status'], ['processing', 'uncertain'], true)) {
                if (!$retry) { throw new \RuntimeException('O pedido anterior ainda está sem confirmação. Consulte o resultado ou solicite explicitamente o reenvio do mesmo pedido.'); }
                $signed = $this->model->crypto()->decrypt((string) $existing['request_enc']);
                if (!hash_equals((string) $existing['request_hash'], hash('sha256', $signed))) { throw new \RuntimeException('O pedido armazenado falhou na conferência.'); }
            } else {
                if ($retry) { throw new \RuntimeException('Não há um pedido pendente para reenviar.'); }
                $signed = EventRequest::build($note, $issuer, $type, $reasonCode, $reason, $certificate);
            }
            // A valid stored signature/hash is not enough: it might be a request
            // accidentally attached to another note. Recheck its fiscal identity
            // against the current action before persisting or transmitting it.
            $request = EventRequest::inspect($signed, $certificate);
            if ($request['access_key'] !== $note->accessKey() || $request['environment'] !== $note->environment()
                || $request['type'] !== $type || $request['author_tax_id'] !== $issuer) {
                throw new \RuntimeException('O pedido armazenado não corresponde à nota, ao evento e à empresa selecionados. Nenhum reenvio foi realizado.');
            }
            $this->requests->save($issuer, $note->environment(), $note->accessKey(), $type,
                ['status' => 'processing', 'request_enc' => $this->model->crypto()->encrypt($signed), 'request_hash' => hash('sha256', $signed),
                    'attempts' => (int) ($existing['attempts'] ?? 0) + 1, 'last_error' => null]);
            try {
                $event = $api->submit($signed);
                return $this->registered($origin, $record, $note, $issuer, $type, $event);
            } catch (\Throwable $error) {
                $uncertain = !($error instanceof ApiException) || $error->isUncertain();
                try {
                    // The registered XML is saved before projecting cancellation onto
                    // the local document. A failed projection must not erase that
                    // confirmed result: the next action can replay it without a POST.
                    $saved = $this->requests->find($issuer, $note->environment(), $note->accessKey(), $type);
                    $confirmed = false;
                    if ($saved && $saved['status'] === 'registered') {
                        try {
                            $this->proof($saved, $note, $issuer, $type);
                            $confirmed = true;
                        } catch (\Throwable $invalidProof) {
                            // A status label alone is not evidence of registration.
                        }
                    }
                    if (!$confirmed) {
                        $this->requests->save($issuer, $note->environment(), $note->accessKey(), $type,
                            ['status' => $uncertain ? 'uncertain' : 'rejected', 'last_error' => $uncertain
                                ? 'O resultado não foi confirmado. Consulte o pedido já enviado antes de repetir.'
                                : 'O ambiente nacional rejeitou o pedido. Consulte os detalhes no log antes de corrigir.']);
                    }
                } catch (\Throwable $persistenceError) {
                    // Keep the original failure; a durable processing request is
                    // still recoverable when storage becomes available again.
                }
                throw $error;
            }
        });
    }

    public function consult(string $origin, int $id, string $type): array
    {
        [$record, $note, $issuer] = $this->context($origin, $id);
        $this->authorizeAction($note, $issuer, $type, false);
        return $this->model->lock('fiscal:' . $note->environment() . ':' . $note->accessKey(), function () use ($origin, $id, $type): array {
            [$record, $note, $issuer] = $this->context($origin, $id);
            $api = ($this->apiFactory)($note->environment(), (new CertificateResolver($this->model))->resolve($issuer, $note->environment()));
            $event = $api->find($note->accessKey(), $type, 1);
            if ($event) { return $this->registered($origin, $record, $note, $issuer, $type, $event); }
            return ['status' => 'not_found', 'message' => 'O evento ainda não foi localizado. A situação anterior e o pedido original foram preservados.'];
        });
    }

    public function eventXml(string $origin, int $id, string $type): string
    {
        [, $note, $issuer] = $this->context($origin, $id); $this->authorizeAction($note, $issuer, $type, false);
        $row = $this->requests->find($issuer, $note->environment(), $note->accessKey(), $type);
        if (!$row || $row['status'] !== 'registered') { throw new \RuntimeException('O XML do evento estará disponível após a confirmação do registro.'); }
        return $this->proof($row, $note, $issuer, $type)['xml'];
    }

    private function context(string $origin, int $id): array
    {
        if (!nfse_nacional_can('view') || !in_array($origin, ['local', 'national'], true)) { throw new \RuntimeException('Documento indisponível para este usuário.'); }
        $issuer = Config::taxId((string) ($this->model->settings()['provider']['tax_id'] ?? ''));
        if (!Validator::taxId($issuer)) { throw new \RuntimeException('Configure o CPF/CNPJ da empresa antes de consultar eventos fiscais.'); }
        CompanyContext::current()->assertIssuer($issuer);
        if ($origin === 'local') {
            $record = $this->model->document($id); $service = new Service($this->model);
            $service->fiscalStatus($record); $xml = $service->xml($id);
        } else {
            $repository = new NationalRepository($this->model); $record = $repository->document($id); $xml = $repository->recordXml($record);
        }
        $note = new NfseDocument($xml);
        if ($note->environment() !== $record['environment'] || $note->accessKey() !== $record['access_key']
            || NationalRepository::direction($note, $issuer) === '') { throw new \RuntimeException('A nota não corresponde à empresa e ao ambiente selecionados.'); }
        return [$record, $note, $issuer];
    }

    private function authorizeAction(NfseDocument $note, string $issuer, string $type, bool $write): void
    {
        $definition = self::definitions()[$type] ?? null;
        if (!$definition || NationalRepository::noteTaxId($note, $definition['role']) !== $issuer
            || ($write && !nfse_nacional_can($definition['permission']))) {
            throw new \RuntimeException('Este evento não está disponível para o seu usuário ou para o papel da empresa na NFS-e.');
        }
    }

    private function proof(array $row, NfseDocument $note, string $issuer, string $type): array
    {
        return $this->correlate($this->model->crypto()->decrypt((string) $row['response_enc']), $note, $issuer, $type);
    }
    private function correlate(string $xml, NfseDocument $note, string $issuer, string $type): array
    {
        $event = FiscalEvent::fromXml($xml, $note->accessKey(), $note->environment());
        $dom = XmlSigner::loadSafe($xml); $xp = new \DOMXPath($dom); $xp->registerNamespace('n', XmlSigner::NFSE_NS);
        $authors = $xp->query('/n:evento/n:infEvento/n:pedRegEvento/n:infPedReg/n:CNPJAutor | /n:evento/n:infEvento/n:pedRegEvento/n:infPedReg/n:CPFAutor');
        if ($event['type'] !== $type || $event['sequence'] !== 1 || $authors->length !== 1 || trim($authors->item(0)->textContent) !== $issuer) {
            throw new \RuntimeException('O evento retornado não confirma o autor, o tipo e o sequencial solicitados.');
        }
        return $event;
    }
    private function registered(string $origin, array $record, NfseDocument $note, string $issuer, string $type, array $event): array
    {
        $event = $this->correlate((string) ($event['xml'] ?? ''), $note, $issuer, $type);
        $saved = $this->requests->save($issuer, $note->environment(), $note->accessKey(), $type,
            ['status' => 'registered', 'response_enc' => $this->model->crypto()->encrypt($event['xml']),
                'event_id' => $event['id'], 'processed_at' => $event['processed_at'], 'record_number' => $event['record_number'], 'last_error' => null]);
        if ($event['cancellation']) {
            if ($origin === 'national') { (new NationalRepository($this->model))->applyRegisteredCancellation((int) $record['id'], $event['xml']); }
            else {
                $meta = FiscalStatus::metadata($record);
                $meta = array_replace($meta, ['cancellation_confirmed' => true, 'cancelled_at' => $event['processed_at'],
                    'cancellation_event_id' => $event['id'], 'cancellation_record_number' => $event['record_number'],
                    'status_checked_at' => \Nfse_nacional_model::now(), 'status_check_error' => '']);
                $this->model->updateDocument((int) $record['id'], ['status' => 'cancelled', 'last_error' => null,
                    'cancellation_event_enc' => $this->model->crypto()->encrypt($event['xml']), 'fiscal_meta_json' => \Nfse_nacional_model::json($meta)]);
            }
        }
        return ['status' => 'registered', 'event' => $this->publicRequest($saved), 'message' => $event['cancellation']
            ? 'Cancelamento confirmado pelo evento fiscal registrado. XML original e evento preservados.'
            : ($type === '101103' ? 'Pedido de análise fiscal registrado. O cancelamento depende do resultado da análise do município.' : 'Manifestação registrada no ambiente nacional.')];
    }
    private function publicRequest(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'event_type', 'status', 'event_id', 'processed_at', 'record_number', 'attempts', 'last_error', 'created_at', 'updated_at']));
    }
}
