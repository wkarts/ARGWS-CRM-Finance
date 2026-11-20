<?php
declare(strict_types=1);
namespace NfseNacional;

/** Monthly accounting export from the original national/local authorized XML snapshots. */
final class MonthlyPackage
{
    private NationalRepository $repository;
    private ?\Closure $pdfRenderer;

    public function __construct(private $model, ?NationalRepository $repository = null, ?callable $pdfRenderer = null)
    {
        $this->repository = $repository ?? new NationalRepository($model);
        $this->pdfRenderer = $pdfRenderer ? \Closure::fromCallable($pdfRenderer) : null;
    }

    public function report(array $filters): array
    {
        [$filters, $documents, $issuer] = $this->collect($filters);
        return $this->summarize($filters, $documents, $issuer);
    }

    public function build(array $filters, string $expectedFingerprint): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint)) { throw new \InvalidArgumentException('Confira a composição do pacote antes de gerar os arquivos.'); }
        [$filters, $documents, $issuer] = $this->collect($filters);
        $report = $this->summarize($filters, $documents, $issuer);
        if (!hash_equals($report['fingerprint'], $expectedFingerprint)) {
            throw new \RuntimeException('Os documentos do mês mudaram. Confira novamente a composição antes de continuar.');
        }
        if ($report['total'] === 0) { throw new \RuntimeException('Não há notas autorizadas ou canceladas para o período selecionado.'); }
        $zip = new ZipBuilder();
        $manifest = [['Origem', 'Papel', 'Situação', 'Número NFS-e', 'Chave de acesso', 'Competência', 'Emissão', 'Valor dos serviços',
            'Prestador', 'CPF/CNPJ prestador', 'Tomador', 'CPF/CNPJ tomador', 'XML', 'DANFSe', 'Evento de cancelamento']];
        foreach ($documents as &$document) {
            [$xml, $eventXml] = $this->contents($document);
            $folder = ['issued' => 'Emitidas', 'received' => 'Recebidas', 'both' => 'Emitidas_e_Recebidas'][$document['direction']];
            $base = $folder . '/NFSe-' . $document['access_key']; $xmlFile = ''; $pdfFile = ''; $eventFile = '';
            if (in_array($filters['format'], ['xml', 'both'], true)) { $xmlFile = $base . '.xml'; $zip->add($xmlFile, $xml); }
            if (in_array($filters['format'], ['pdf', 'both'], true)) {
                $pdf = $this->pdfRenderer ? ($this->pdfRenderer)($xml, $document['fiscal_status']) : Danfe::render($xml, $document['fiscal_status']);
                if (!is_string($pdf) || strncmp($pdf, '%PDF-', 5) !== 0) { throw new \RuntimeException('Não foi possível gerar um DANFSe válido. Nenhum pacote parcial foi disponibilizado.'); }
                $pdfFile = $base . '.pdf'; $zip->add($pdfFile, $pdf); unset($pdf);
            }
            if ($eventXml !== '') {
                $eventFile = $folder . '/Eventos/Cancelamento-' . $document['access_key'] . '.xml'; $zip->add($eventFile, $eventXml);
            }
            $manifest[] = [$document['source'] === 'national' ? 'Ambiente nacional' : 'Emissão no módulo',
                ['issued' => 'Emitida', 'received' => 'Recebida', 'both' => 'Emitida e recebida'][$document['direction']],
                $document['status'] === 'cancelled' ? 'Cancelada' : 'Autorizada', $document['nfse_number'], $document['access_key'],
                $document['competence'], $document['issued_at'], $document['service_amount'], $document['provider_name'], $document['provider_tax_id'],
                $document['recipient_name'], $document['recipient_tax_id'], $xmlFile, $pdfFile, $eventFile];
            unset($xml, $eventXml);
        }
        unset($document);
        $zip->add('manifesto.csv', self::csv($manifest));
        $bytes = $zip->finish();
        $name = 'NFSe_' . $filters['month'] . '_' . ($filters['environment'] === '1' ? 'Producao' : 'Homologacao')
            . '_' . ['issued' => 'Emitidas', 'received' => 'Recebidas', 'both' => 'Emitidas_Recebidas'][$filters['direction']]
            . '_' . ['xml' => 'XML', 'pdf' => 'PDF', 'both' => 'XML_PDF'][$filters['format']] . '.zip';
        return ['bytes' => $bytes, 'filename' => $name, 'count' => $report['total'], 'sha256' => hash('sha256', $bytes),
            'report' => $report, 'uncompressed_bytes' => $zip->uncompressedSize()];
    }

    public static function filters(array $filters): array
    {
        $result = ['environment' => NationalRepository::environment(Config::text($filters['environment'] ?? '', 1)),
            'month' => Config::text($filters['month'] ?? '', 7), 'direction' => Config::text($filters['direction'] ?? 'both', 8),
            'format' => Config::text($filters['format'] ?? 'both', 4)];
        if (!preg_match('/^20[0-9]{2}-(?:0[1-9]|1[0-2])$/D', $result['month'])) { throw new \InvalidArgumentException('Selecione uma competência válida no formato ano-mês.'); }
        if (!in_array($result['direction'], ['issued', 'received', 'both'], true) || !in_array($result['format'], ['xml', 'pdf', 'both'], true)) {
            throw new \InvalidArgumentException('Selecione o tipo de nota e os arquivos do pacote.');
        }
        return $result;
    }

    private function collect(array $filters): array
    {
        $filters = self::filters($filters);
        Permissions::assertReport('accounting');
        $issuer = $this->repository->contextTaxId(); $documents = [];
        $nationalRows = $this->repository->exportReferences($filters['environment'], $filters['month'], $filters['direction']);
        if (count($nationalRows) > 1000) { throw new \RuntimeException('A competência contém mais de 1.000 notas. Reduza a seleção para gerar o pacote.'); }
        foreach ($nationalRows as $reference) {
            $record = $this->repository->document((int) $reference['id']);
            $xml = $this->repository->recordXml($record);
            $entry = $this->entry($record, $xml, 'national', $issuer, $filters);
            if ($entry !== null) { $documents[$entry['access_key']] = $entry; }
        }
        unset($nationalRows);
        // The local table predates the national archive. Read small pages of its
        // existing draft snapshots as an index; matching/missing competence is
        // then confirmed exclusively against the original authorized XML.
        $cursor = 0;
        do {
            $query = $this->model->db->select('id,draft_json')->where('environment', $filters['environment'])
                ->where_in('status', ['authorized', 'cancelled'])->where('id >', $cursor)->order_by('id', 'asc')->limit(250)
                ->get($this->model->table('documents'));
            if (!$query) { throw new \RuntimeException('Não foi possível consultar as notas emitidas no módulo.'); }
            $rows = $query->result_array();
            foreach ($rows as $index) {
                $cursor = (int) $index['id'];
                $draft = json_decode((string) ($index['draft_json'] ?? ''), true);
                $competence = is_array($draft) && is_string($draft['competence'] ?? null) ? $draft['competence'] : '';
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $competence) && substr($competence, 0, 7) !== $filters['month']) { continue; }
                $recordQuery = $this->model->db->where('id', $cursor)->where('environment', $filters['environment'])
                    ->where_in('status', ['authorized', 'cancelled'])->get($this->model->table('documents'));
                $record = $recordQuery ? $recordQuery->row_array() : null;
                if (!$record || empty($record['response_enc'])) { throw new \RuntimeException('Uma nota local não possui XML autorizado disponível para o pacote.'); }
                $xml = $this->model->crypto()->decrypt($record['response_enc']);
                $entry = $this->entry($record, $xml, 'local', $issuer, $filters);
                if ($entry === null) { continue; }
                $key = $entry['access_key'];
                if (!isset($documents[$key])) { $documents[$key] = $entry; }
                elseif ($entry['status'] === 'cancelled' && $documents[$key]['status'] !== 'cancelled') {
                    // Prefer the ADN XML, but never discard a confirmed local cancellation.
                    $documents[$key]['status'] = 'cancelled'; $documents[$key]['event_source'] = $entry['event_source'];
                    $documents[$key]['event_record_id'] = $entry['event_record_id']; $documents[$key]['event_bytes'] = $entry['event_bytes'];
                    $documents[$key]['event_hash'] = $entry['event_hash']; $documents[$key]['fiscal_status'] = $entry['fiscal_status'];
                }
                if (count($documents) > 1000) { throw new \RuntimeException('A competência contém mais de 1.000 notas. Reduza a seleção para gerar o pacote.'); }
            }
        } while (count($rows) === 250);
        ksort($documents, SORT_STRING);
        $size = 0; foreach ($documents as $document) { $size += $document['xml_bytes'] + $document['event_bytes']; }
        if ($size > 104857600) { throw new \RuntimeException('Os documentos da competência ultrapassam 100 MB. Reduza a seleção.'); }
        return [$filters, array_values($documents), $issuer];
    }

    private function entry(array $record, string $xml, string $source, string $issuer, array $filters): ?array
    {
        $note = new NfseDocument($xml);
        if ($note->environment() !== $filters['environment'] || $note->accessKey() !== ($record['access_key'] ?? '')
            || $note->dpsId() !== ($record['dps_id'] ?? '')) { throw new \RuntimeException('Uma nota do pacote possui identificação fiscal inconsistente.'); }
        $direction = NationalRepository::direction($note, $issuer);
        if ($direction === '' || ($filters['direction'] !== 'both' && !in_array($direction, [$filters['direction'], 'both'], true))) { return null; }
        $competence = $note->dpsText('dCompet'); $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $competence, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $competence) { throw new \RuntimeException('Uma nota não possui competência válida no XML. Não foi aplicada data alternativa.'); }
        if (substr($competence, 0, 7) !== $filters['month']) { return null; }
        if (!in_array($record['status'] ?? '', ['authorized', 'cancelled'], true)) { throw new \RuntimeException('O pacote aceita somente notas autorizadas ou canceladas com evidência fiscal.'); }
        $eventXml = ''; $meta = FiscalStatus::metadata($record);
        if ($record['status'] === 'cancelled') {
            if (empty($record['cancellation_event_enc'])) { throw new \RuntimeException('Uma nota cancelada está sem o evento de confirmação.'); }
            $eventXml = $this->model->crypto()->decrypt($record['cancellation_event_enc']);
            $event = FiscalEvent::fromXml($eventXml, $note->accessKey(), $note->environment());
            if (!$event['cancellation'] || ($meta['cancellation_confirmed'] ?? false) !== true
                || $event['id'] !== ($meta['cancellation_event_id'] ?? '') || $event['processed_at'] !== ($meta['cancelled_at'] ?? '')) {
                throw new \RuntimeException('O evento da nota não comprova seu cancelamento.');
            }
        }
        $statusRecord = array_replace($record, ['response_enc' => $record['response_enc'] ?? ($record['xml_enc'] ?? 'official'), 'environment' => $note->environment()]);
        return ['source' => $source, 'id' => (int) $record['id'], 'direction' => $direction, 'environment' => $note->environment(),
            'access_key' => $note->accessKey(), 'nfse_number' => $note->number(), 'status' => $record['status'],
            'competence' => $competence, 'issued_at' => $note->dpsText('dhEmi'),
            'provider_name' => $note->dpsText('prest/xNome') ?: $note->text('emit/xNome'), 'provider_tax_id' => NationalRepository::noteTaxId($note, 'prest'),
            'recipient_name' => $note->dpsText('toma/xNome'), 'recipient_tax_id' => NationalRepository::noteTaxId($note, 'toma'),
            'service_amount' => Decimal::normalize($note->dpsText('valores/vServPrest/vServ')), 'xml_bytes' => strlen($xml), 'xml_hash' => hash('sha256', $xml),
            'event_bytes' => strlen($eventXml), 'event_hash' => $eventXml === '' ? '' : hash('sha256', $eventXml),
            'event_source' => $eventXml === '' ? '' : $source, 'event_record_id' => $eventXml === '' ? 0 : (int) $record['id'],
            'fiscal_status' => FiscalStatus::describe($statusRecord, $note)];
    }

    private function contents(array $document): array
    {
        $record = $this->record($document['source'], $document['id'], $document['environment']);
        $xml = $document['source'] === 'national' ? $this->repository->recordXml($record) : $this->model->crypto()->decrypt((string) ($record['response_enc'] ?? ''));
        $note = new NfseDocument($xml);
        if (!hash_equals($document['xml_hash'], hash('sha256', $xml)) || $note->accessKey() !== $document['access_key']
            || $note->environment() !== $document['environment'] || NationalRepository::direction($note, $this->repository->contextTaxId()) !== $document['direction']
            || ($document['status'] === 'authorized' && $record['status'] !== 'authorized')) {
            throw new \RuntimeException('Um documento mudou durante a geração. Confira novamente a composição do pacote.');
        }
        $eventXml = '';
        if ($document['event_source'] !== '') {
            $eventRecord = $document['event_source'] === $document['source'] && $document['event_record_id'] === $document['id']
                ? $record : $this->record($document['event_source'], $document['event_record_id'], $document['environment']);
            $eventXml = $this->model->crypto()->decrypt((string) ($eventRecord['cancellation_event_enc'] ?? ''));
            $event = FiscalEvent::fromXml($eventXml, $note->accessKey(), $note->environment());
            $meta = FiscalStatus::metadata($eventRecord);
            if ($eventRecord['status'] !== 'cancelled' || !$event['cancellation'] || !hash_equals($document['event_hash'], hash('sha256', $eventXml))
                || ($meta['cancellation_confirmed'] ?? false) !== true || $event['id'] !== ($meta['cancellation_event_id'] ?? '')
                || $event['processed_at'] !== ($meta['cancelled_at'] ?? '')) {
                throw new \RuntimeException('O evento fiscal mudou durante a geração. Confira novamente o pacote.');
            }
        }
        return [$xml, $eventXml];
    }

    private function record(string $source, int $id, string $environment): array
    {
        $this->repository->contextTaxId();
        if ($source === 'national') { return $this->repository->document($id); }
        $query = $this->model->db->where('id', $id)->where('environment', $environment)->where_in('status', ['authorized', 'cancelled'])->get($this->model->table('documents'));
        $row = $query ? $query->row_array() : null;
        if (!$row) { throw new \RuntimeException('Uma nota do pacote não está mais disponível. Confira a seleção.'); }
        return $row;
    }

    private function summarize(array $filters, array $documents, string $issuer): array
    {
        $result = ['issued' => 0, 'received' => 0, 'total' => count($documents), 'cancelled' => 0, 'local' => 0, 'national' => 0, 'missing_competence' => 0];
        $identity = [];
        foreach ($documents as $document) {
            if (in_array($document['direction'], ['issued', 'both'], true)) { ++$result['issued']; }
            if (in_array($document['direction'], ['received', 'both'], true)) { ++$result['received']; }
            if ($document['status'] === 'cancelled') { ++$result['cancelled']; }
            ++$result[$document['source']];
            $identity[] = [$document['environment'], $document['access_key'], $document['xml_hash'], $document['status'], $document['event_hash']];
        }
        $result['filters'] = $filters;
        $result['fingerprint'] = hash('sha256', json_encode(['issuer' => $issuer, 'filters' => $filters, 'documents' => $identity], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $result;
    }

    private static function csv(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $value) {
                $value = (string) $value;
                if (preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) || preg_match('/^[\t\r\n]/', $value)) { $value = "'" . $value; }
                $cells[] = '"' . str_replace('"', '""', $value) . '"';
            }
            $lines[] = implode(';', $cells);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }
}
