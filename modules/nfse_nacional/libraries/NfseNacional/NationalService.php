<?php
declare(strict_types=1);

namespace NfseNacional;

/** Read-only ADN synchronization. It never creates invoices, transmits DPS or sends mail. */
final class NationalService
{
    private $model;
    private NationalRepository $repository;
    private \Closure $apiFactory;
    private \Closure $clock;

    public function __construct($model, ?callable $apiFactory = null, ?callable $clock = null)
    {
        $this->model = $model;
        $this->repository = new NationalRepository($model);
        $this->apiFactory = $apiFactory ? \Closure::fromCallable($apiFactory)
            : static fn(string $environment, Certificate $certificate) => new DistributionClient($environment, $certificate, null, static function (array $event) {
                try { OperationalLog::current()->record('info', 'api.distribution', 'Resposta da distribuição nacional.', $event); }
                catch (\Throwable $ignored) { /* Logging never changes the fiscal outcome. */ }
            });
        $this->clock = $clock ? \Closure::fromCallable($clock) : static fn(): int => time();
    }

    public function sync(string $environment): array
    {
        NationalRepository::environment($environment);
        if (!nfse_nacional_can('national_view') || !nfse_nacional_can('national_sync')) {
            throw new \RuntimeException('Você não tem permissão para sincronizar os documentos nacionais.', 403);
        }
        $issuer = $this->repository->contextTaxId();
        return $this->model->lock('national:' . $issuer . ':' . $environment, function () use ($issuer, $environment): array {
            if (!hash_equals($issuer, $this->repository->contextTaxId())) { throw new \RuntimeException('O emitente foi alterado. Atualize a página.'); }
            $now = (int) ($this->clock)(); $state = $this->repository->state($environment);
            $nextAt = $state['next_sync_at'] ? strtotime($state['next_sync_at'] . ' UTC') : false;
            if ($nextAt !== false && $nextAt > $now) {
                return $this->result($environment, [], true, 'A próxima consulta estará disponível após o intervalo do ambiente nacional.');
            }
            $lastNsu = DistributionClient::normalizeNsu((string) $state['last_nsu']);
            $stage = 'certificate';
            try {
                $certificate = (new CertificateResolver($this->model))->resolve($issuer, $environment);
                $certificate->assertUsable($issuer);
                $api = ($this->apiFactory)($environment, $certificate);
                $stage = 'request';
                $batch = $api->distribute($lastNsu, $issuer);
                $stage = 'batch_validation';
                if (!is_array($batch) || !is_array($batch['items'] ?? null)
                    || !is_bool($batch['has_more'] ?? null) || !is_bool($batch['empty'] ?? null)) {
                    throw new \RuntimeException('A distribuição nacional retornou um lote inválido.');
                }
                $next = DistributionClient::normalizeNsu($batch['next_nsu'] ?? '');
                $retry = max(0, (int) ($batch['retry_after'] ?? 0));
                if ($batch['items'] === [] && !$batch['empty'] && $retry > 0) {
                    if ($next !== $lastNsu) { throw new \RuntimeException('O limite de consulta retornou cursor inconsistente.'); }
                    $this->repository->failure($environment, $issuer, 'O ambiente nacional solicitou uma pausa antes de nova consulta.',
                        gmdate('Y-m-d H:i:s', $now + max(1, $retry)), gmdate('Y-m-d H:i:s', $now));
                    return $this->result($environment, [], true, 'Consulta temporariamente limitada pelo ambiente nacional. O acervo foi preservado.');
                }
                if (($batch['empty'] && $batch['items'] !== []) || ($batch['items'] === [] && $batch['has_more'])
                    || DistributionClient::compareNsu($next, $lastNsu) < 0) {
                    throw new \RuntimeException('A distribuição nacional retornou sequência inconsistente.');
                }
                $stage = 'document_validation';
                $prepared = []; $previous = $lastNsu;
                foreach ($batch['items'] as $item) {
                    if (!is_array($item) || !is_string($item['xml'] ?? null) || $item['xml'] === '') { throw new \RuntimeException('O lote contém documento fiscal ausente ou ilegível.'); }
                    $nsu = DistributionClient::normalizeNsu($item['nsu'] ?? '');
                    if (DistributionClient::compareNsu($nsu, $previous) <= 0 || DistributionClient::compareNsu($nsu, $next) > 0) {
                        throw new \RuntimeException('O lote contém sequência fiscal repetida ou fora de ordem.');
                    }
                    $previous = $nsu;
                    if ($item['type'] === 'nfse') {
                        $note = new NfseDocument($item['xml']);
                        if ($note->environment() !== $environment || $note->accessKey() !== ($item['access_key'] ?? '')) {
                            throw new \RuntimeException('A NFS-e distribuída não corresponde à chave ou ao ambiente consultado.');
                        }
                        $record = $this->project($note, $issuer);
                        $prepared[] = ['kind' => 'nfse', 'nsu' => $nsu, 'record' => $record, 'xml' => $item['xml']];
                    } elseif ($item['type'] === 'event') {
                        $event = FiscalEvent::fromXml($item['xml'], (string) ($item['access_key'] ?? ''), $environment);
                        if (!empty($item['event_type']) && (string) $item['event_type'] !== $event['type']) {
                            throw new \RuntimeException('O tipo de evento distribuído difere do seu XML.');
                        }
                        $prepared[] = ['kind' => 'event', 'nsu' => $nsu, 'event' => $event, 'xml' => $item['xml']];
                    } elseif ($item['type'] === 'other') {
                        $xml = XmlSigner::loadSafe($item['xml']);
                        $root = $xml->documentElement;
                        if (!$root || in_array($root->localName, ['NFSe', 'evento'], true)) { throw new \RuntimeException('Um documento fiscal conhecido foi classificado incorretamente no lote.'); }
                        $prepared[] = ['kind' => 'other', 'nsu' => $nsu];
                    } else { throw new \RuntimeException('O lote retornou tipo de documento não reconhecido.'); }
                }
                if ($prepared !== [] && DistributionClient::compareNsu($previous, $next) !== 0) {
                    throw new \RuntimeException('O cursor retornado ultrapassa os documentos conferidos.');
                }
                if ($prepared === [] && $next !== $lastNsu) { throw new \RuntimeException('Uma consulta vazia não pode avançar a sequência fiscal.'); }
                $nextSyncAt = !$batch['has_more'] ? gmdate('Y-m-d H:i:s', $now + max(3600, $retry)) : null;
                $stage = 'persistence';
                $counts = $this->repository->persist($environment, $issuer, $lastNsu, $prepared, $next, $batch['has_more'], $nextSyncAt, gmdate('Y-m-d H:i:s', $now));
                $counts['processed'] = count($prepared);
                return $this->result($environment, $counts, false, $batch['empty'] ? 'Consulta concluída. Não há novos documentos disponíveis neste momento.' : 'Lote de documentos nacionais conferido e arquivado.');
            } catch (\Throwable $error) {
                // Diagnostic strings are application-owned. Never persist upstream XML,
                // certificate material, HTTP credentials or an arbitrary provider body.
                try { $this->repository->failure($environment, $issuer, 'A consulta não foi concluída. O cursor confirmado e o acervo anterior foram preservados.', null, gmdate('Y-m-d H:i:s', $now)); }
                catch (\Throwable $ignored) { /* Preserve the original error if storage is also unavailable. */ }
                if ($error instanceof ApiException) { throw $error; }
                throw new SyncFailure($stage, $error);
            }
        });
    }

    public function pdf(int $id): string
    {
        $record = $this->repository->document($id);
        $xml = $this->repository->recordXml($record); $note = new NfseDocument($xml);
        $statusDocument = $record + ['response_enc' => $record['xml_enc'], 'attempts' => 0];
        if ($record['status'] === 'cancelled') {
            if (empty($record['cancellation_event_enc'])) { throw new \RuntimeException('O cancelamento não possui evento fiscal confirmado.'); }
            $event = FiscalEvent::fromXml($this->model->crypto()->decrypt($record['cancellation_event_enc']), $record['access_key'], $record['environment']);
            $meta = FiscalStatus::metadata($record);
            if (!$event['cancellation'] || ($meta['cancellation_confirmed'] ?? false) !== true
                || $event['id'] !== ($meta['cancellation_event_id'] ?? '') || $event['processed_at'] !== ($meta['cancelled_at'] ?? '')) {
                throw new \RuntimeException('O evento arquivado não confirma o cancelamento deste documento.');
            }
        } elseif ($record['status'] !== 'authorized') { throw new \RuntimeException('O documento nacional não possui situação fiscal confirmada.'); }
        return Danfe::render($xml, FiscalStatus::describe($statusDocument, $note));
    }

    private function project(NfseDocument $note, string $issuer): array
    {
        $provider = NationalRepository::noteTaxId($note, 'prest');
        $recipient = NationalRepository::noteTaxId($note, 'toma');
        if ($provider === '' || substr($note->dpsId(), 11, 14) !== str_pad($provider, 14, '0', STR_PAD_LEFT)) {
            throw new \RuntimeException('A identificação do prestador não corresponde à DPS distribuída.');
        }
        $emitTaxId = $note->text('emit/CNPJ') ?: $note->text('emit/CPF');
        if ($emitTaxId !== '' && Config::taxId($emitTaxId) !== $provider) {
            throw new \RuntimeException('A identificação do emitente não corresponde ao prestador da nota.');
        }
        $amount = Decimal::normalize($note->dpsText('valores/vServPrest/vServ'));
        if (str_starts_with($amount, '-') || strlen(explode('.', $amount)[0]) > 16) { throw new \RuntimeException('O valor dos serviços da nota está inválido.'); }
        $competence = $note->dpsText('dCompet');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $competence, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $competence) { throw new \RuntimeException('A competência da nota está inválida.'); }
        $issuedAt = $note->dpsText('dhEmi');
        // Keep the calendar issue date in the XML's own offset so a note issued at
        // 23:59 on the month's last day stays in that month for the accountant.
        $issuedAt = (new \DateTimeImmutable($issuedAt))->format('Y-m-d H:i:s');
        $meta = ['processed_at' => $note->text('dhProc'), 'record_number' => $note->text('nDFSe'), 'protocol' => '',
            'source' => 'national_distribution', 'cancellation_confirmed' => false];
        return ['direction' => NationalRepository::direction($note, $issuer), 'access_key' => $note->accessKey(),
            'nfse_number' => $note->number(), 'dps_id' => $note->dpsId(),
            'provider_name' => Config::text($note->dpsText('prest/xNome') ?: $note->text('emit/xNome'), 255), 'provider_tax_id' => $provider,
            'recipient_name' => Config::text($note->dpsText('toma/xNome'), 255), 'recipient_tax_id' => $recipient,
            'service_amount' => $amount, 'issued_at' => $issuedAt, 'competence' => $competence,
            'fiscal_meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
    }

    private function result(string $environment, array $counts, bool $throttled, string $message): array
    {
        $state = $this->repository->state($environment);
        return array_replace(['imported' => 0, 'updated' => 0, 'events' => 0, 'ignored' => 0], $counts)
            + ['state' => $state, 'has_more' => (bool) $state['has_more'], 'next_sync_at' => $state['next_sync_at'], 'throttled' => $throttled, 'message' => $message];
    }

}
