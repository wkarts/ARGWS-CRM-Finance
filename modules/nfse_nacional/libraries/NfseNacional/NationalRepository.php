<?php
declare(strict_types=1);

namespace NfseNacional;

/** Company/issuer-scoped archive of documents obtained from the national distribution service. */
final class NationalRepository
{
    private $model;
    private $db;
    private string $prefix;
    private string $scope;

    public function __construct($model)
    {
        $this->model = $model;
        $this->db = $model->db;
        $this->scope = nfse_nacional_scope();
        $this->prefix = db_prefix();
        if (!preg_match('/^[A-Za-z0-9_]{0,40}$/D', $this->prefix)) { throw new \RuntimeException('O contexto da empresa está indisponível.'); }
        $this->db->save_queries = false;
        $this->db->db_debug = false;
    }

    public static function schema(): array
    {
        return [
            'national_documents' => "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `issuer_tax_id` VARCHAR(14) NOT NULL, `environment` CHAR(1) NOT NULL,
                `direction` VARCHAR(8) NOT NULL, `access_key` VARCHAR(50) NOT NULL,
                `nfse_number` VARCHAR(20) NOT NULL, `dps_id` VARCHAR(50) NOT NULL,
                `provider_name` VARCHAR(255) NOT NULL, `provider_tax_id` VARCHAR(14) NOT NULL,
                `recipient_name` VARCHAR(255) NOT NULL, `recipient_tax_id` VARCHAR(14) NOT NULL,
                `service_amount` DECIMAL(18,2) NOT NULL, `issued_at` DATETIME NOT NULL,
                `competence` DATE NOT NULL, `status` VARCHAR(16) NOT NULL DEFAULT 'authorized',
                `nsu` VARCHAR(20) NOT NULL, `xml_enc` LONGTEXT NOT NULL, `xml_hash` CHAR(64) NOT NULL,
                `fiscal_meta_json` LONGTEXT NOT NULL, `cancellation_event_enc` LONGTEXT NULL,
                `synced_at` DATETIME NOT NULL, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `issuer_environment_key` (`issuer_tax_id`,`environment`,`access_key`),
                KEY `issuer_environment_date` (`issuer_tax_id`,`environment`,`issued_at`),
                KEY `issuer_environment_direction` (`issuer_tax_id`,`environment`,`direction`,`issued_at`)",
            'national_events' => "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `issuer_tax_id` VARCHAR(14) NOT NULL, `environment` CHAR(1) NOT NULL,
                `access_key` VARCHAR(50) NOT NULL, `event_id` VARCHAR(80) NOT NULL,
                `event_type` CHAR(6) NOT NULL, `is_cancellation` TINYINT NOT NULL DEFAULT 0,
                `processed_at` DATETIME NOT NULL, `nsu` VARCHAR(20) NOT NULL,
                `xml_enc` LONGTEXT NOT NULL, `xml_hash` CHAR(64) NOT NULL, `metadata_json` LONGTEXT NOT NULL,
                `synced_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `issuer_environment_event` (`issuer_tax_id`,`environment`,`event_id`),
                KEY `issuer_environment_key` (`issuer_tax_id`,`environment`,`access_key`,`is_cancellation`)",
            'national_sync' => "`issuer_tax_id` VARCHAR(14) NOT NULL, `environment` CHAR(1) NOT NULL,
                `last_nsu` VARCHAR(20) NOT NULL DEFAULT '0', `has_more` TINYINT NOT NULL DEFAULT 0,
                `processed_count` BIGINT NOT NULL DEFAULT 0, `batch_count` BIGINT NOT NULL DEFAULT 0,
                `last_synced_at` DATETIME NULL, `next_sync_at` DATETIME NULL, `last_error` TEXT NULL,
                `updated_at` DATETIME NOT NULL, PRIMARY KEY (`issuer_tax_id`,`environment`)",
        ];
    }

    public function contextTaxId(): string
    {
        $this->assertAccess();
        $id = Config::taxId((string) ($this->model->settings()['provider']['tax_id'] ?? ''));
        if (!Validator::taxId($id)) { throw new \RuntimeException('Configure o CPF/CNPJ da empresa para consultar seus documentos nacionais.'); }
        CompanyContext::current()->assertIssuer($id);
        return $id;
    }

    public static function environment(string $environment): string
    {
        if (!in_array($environment, ['1', '2'], true)) { throw new \InvalidArgumentException('Ambiente fiscal inválido.'); }
        return $environment;
    }

    public function state(string $environment): array
    {
        $environment = self::environment($environment); $issuer = $this->contextTaxId();
        $query = $this->db->where('issuer_tax_id', $issuer)->where('environment', $environment)->get($this->table('sync'));
        if (!$query) { throw new \RuntimeException('O histórico nacional está indisponível. Atualize o banco de dados do módulo.'); }
        $row = $query->row_array() ?: [];
        return array_replace(['issuer_tax_id' => $issuer, 'environment' => $environment, 'last_nsu' => '0', 'has_more' => 0,
            'last_synced_at' => null, 'next_sync_at' => null, 'last_error' => null, 'updated_at' => null, 'processed_count' => 0, 'batch_count' => 0], $row);
    }

    public function list(string $direction, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $issuer = $this->contextTaxId(); $filters = $this->filters($direction, $filters);
        $perPage = max(1, min(200, $perPage)); $page = max(1, min(100000, $page));
        $this->applyFilters($issuer, $direction, $filters);
        $total = $this->db->count_all_results($this->table('documents'));
        if ($total === false) { throw new \RuntimeException('Não foi possível consultar o acervo nacional.'); }
        $pages = max(1, (int) ceil((int) $total / $perPage)); $page = min($page, $pages);
        $this->applyFilters($issuer, $direction, $filters);
        $query = $this->db->select(self::publicColumns())->order_by('issued_at', 'desc')->order_by('id', 'desc')
            ->limit($perPage, ($page - 1) * $perPage)->get($this->table('documents'));
        if (!$query) { throw new \RuntimeException('Não foi possível consultar o acervo nacional.'); }
        return ['rows' => $query->result_array(), 'total' => (int) $total, 'page' => $page, 'per_page' => $perPage,
            'pages' => $pages, 'filters' => $filters, 'direction' => $direction];
    }

    /** Internal trusted record. Controllers must not serialize encrypted payloads or metadata. */
    public function document(int $id): array
    {
        if ($id < 1) { throw new \InvalidArgumentException('Documento nacional inválido.'); }
        $issuer = $this->contextTaxId();
        $query = $this->db->where('id', $id)->where('issuer_tax_id', $issuer)->get($this->table('documents'));
        $row = $query ? $query->row_array() : null;
        if (!$row) { throw new \RuntimeException('Documento nacional não encontrado nesta empresa.', 404); }
        self::environment((string) $row['environment']);
        return $row;
    }

    public function xml(int $id): string
    {
        return $this->recordXml($this->document($id));
    }

    /** Revalidates the encrypted snapshot before downloads and PDF generation. */
    public function recordXml(array $record): string
    {
        $issuer = $this->contextTaxId();
        if (!is_string($record['issuer_tax_id'] ?? null) || !hash_equals($issuer, $record['issuer_tax_id'])) {
            throw new \RuntimeException('Documento nacional não encontrado nesta empresa.', 404);
        }
        $xml = $this->model->crypto()->decrypt((string) ($record['xml_enc'] ?? ''));
        if (!hash_equals((string) ($record['xml_hash'] ?? ''), hash('sha256', $xml))) { throw new \RuntimeException('O documento armazenado falhou na conferência de integridade.'); }
        $note = new NfseDocument($xml);
        if ($note->accessKey() !== ($record['access_key'] ?? '') || $note->environment() !== ($record['environment'] ?? '')
            || $note->number() !== ($record['nfse_number'] ?? '') || self::direction($note, $issuer) !== ($record['direction'] ?? '')) {
            throw new \RuntimeException('O XML armazenado não corresponde ao documento desta empresa.');
        }
        return $xml;
    }

    /** Monthly accounting packages use dCompet from the original XML, never the synchronization date. */
    public function exportRows(string $environment, string $month, string $direction): array
    {
        $issuer = $this->contextTaxId();
        $filters = $this->filters($direction, ['environment' => $environment, 'month' => $month]);
        if ($filters['month'] === '') { throw new \InvalidArgumentException('Informe o mês dos documentos para exportar.'); }
        $this->applyFilters($issuer, $direction, $filters);
        $query = $this->db->order_by('issued_at', 'asc')->order_by('id', 'asc')->limit(1001)->get($this->table('documents'));
        if (!$query) { throw new \RuntimeException('Não foi possível selecionar os documentos do mês.'); }
        return $query->result_array();
    }

    /** Lightweight references prevent loading a month of encrypted XML into PHP memory at once. */
    public function exportReferences(string $environment, string $month, string $direction): array
    {
        $issuer = $this->contextTaxId(); $filters = $this->filters($direction, ['environment' => $environment, 'month' => $month]);
        if ($filters['month'] === '') { throw new \InvalidArgumentException('Informe a competência dos documentos.'); }
        $this->applyFilters($issuer, $direction, $filters);
        $query = $this->db->select('id')->order_by('issued_at', 'asc')->order_by('id', 'asc')->limit(1001)->get($this->table('documents'));
        if (!$query) { throw new \RuntimeException('Não foi possível selecionar os documentos da competência.'); }
        return $query->result_array();
    }

    /** One atomic distribution page. Cursor and archive either commit together or remain unchanged. */
    public function persist(string $environment, string $issuer, string $expectedNsu, array $items, string $nextNsu,
        bool $hasMore, ?string $nextSyncAt, string $now): array
    {
        $this->assertSync($issuer); self::environment($environment);
        $state = $this->state($environment);
        if (DistributionClient::compareNsu((string) $state['last_nsu'], $expectedNsu) !== 0
            || DistributionClient::compareNsu($nextNsu, $expectedNsu) < 0) { throw new \RuntimeException('A consulta nacional mudou em outra janela. Atualize a página.'); }
        $counts = ['imported' => 0, 'updated' => 0, 'events' => 0, 'ignored' => 0];
        if ($this->db->trans_begin() === false) { throw new \RuntimeException('Não foi possível iniciar a gravação do acervo nacional.'); }
        try {
            foreach ($items as $item) {
                if ($item['kind'] === 'nfse') {
                    if ($item['record']['direction'] === '') { ++$counts['ignored']; continue; }
                    $existing = $this->findDocument($issuer, $environment, $item['record']['access_key']);
                    $hash = hash('sha256', $item['xml']);
                    if ($existing) {
                        if (!hash_equals($existing['xml_hash'], $hash)) { throw new \RuntimeException('A distribuição retornou XML divergente para uma chave já arquivada. O lote foi preservado para revisão.'); }
                        ++$counts['ignored'];
                    } else {
                        $record = $item['record'] + ['issuer_tax_id' => $issuer, 'environment' => $environment,
                            'status' => 'authorized', 'nsu' => $item['nsu'], 'xml_enc' => $this->model->crypto()->encrypt($item['xml']),
                            'xml_hash' => $hash, 'cancellation_event_enc' => null, 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now];
                        $this->must($this->db->insert($this->table('documents'), $record));
                        $record['id'] = (int) $this->db->insert_id(); $existing = $record; ++$counts['imported'];
                    }
                    $event = $this->pendingCancellation($issuer, $environment, $existing['access_key']);
                    if ($event && $existing['status'] !== 'cancelled') { $this->applyCancellation($existing, $event, $now); ++$counts['updated']; }
                } elseif ($item['kind'] === 'event') {
                    $event = $item['event'];
                    $query = $this->db->where('issuer_tax_id', $issuer)->where('environment', $environment)->where('event_id', $event['id'])->get($this->table('events'));
                    if (!$query) { throw new \RuntimeException('Não foi possível conferir o evento nacional.'); }
                    $stored = $query->row_array(); $hash = hash('sha256', $item['xml']);
                    if ($stored) {
                        if (!hash_equals($stored['xml_hash'], $hash)) { throw new \RuntimeException('Um evento arquivado retornou conteúdo divergente. A sincronização não foi avançada.'); }
                        ++$counts['ignored'];
                    } else {
                        $meta = $event; unset($meta['xml']);
                        $stored = ['issuer_tax_id' => $issuer, 'environment' => $environment, 'access_key' => $event['access_key'],
                            'event_id' => $event['id'], 'event_type' => $event['type'], 'is_cancellation' => $event['cancellation'] ? 1 : 0,
                            'processed_at' => self::utc($event['processed_at']), 'nsu' => $item['nsu'],
                            'xml_enc' => $this->model->crypto()->encrypt($item['xml']), 'xml_hash' => $hash,
                            'metadata_json' => self::json($meta), 'synced_at' => $now];
                        $this->must($this->db->insert($this->table('events'), $stored)); ++$counts['events'];
                    }
                    if ($event['cancellation']) {
                        $document = $this->findDocument($issuer, $environment, $event['access_key']);
                        if ($document && $document['status'] !== 'cancelled') { $this->applyCancellation($document, $stored, $now); ++$counts['updated']; }
                    }
                } else { ++$counts['ignored']; }
            }
            $this->saveState($issuer, $environment, ['last_nsu' => $nextNsu, 'has_more' => $hasMore ? 1 : 0,
                'processed_count' => (int) $state['processed_count'] + count($items), 'batch_count' => (int) $state['batch_count'] + 1,
                'last_synced_at' => $now, 'next_sync_at' => $nextSyncAt, 'last_error' => null, 'updated_at' => $now]);
            if ($this->db->trans_status() === false) { throw new \RuntimeException('A gravação do acervo nacional não foi concluída.'); }
            if ($this->db->trans_commit() === false) { throw new \RuntimeException('Não foi possível confirmar a gravação do acervo nacional.'); }
            return $counts;
        } catch (\Throwable $error) { $this->db->trans_rollback(); throw $error; }
    }

    /** A retry/error changes scheduling only, never the last confirmed NSU or fiscal archive. */
    public function failure(string $environment, string $issuer, string $message, ?string $nextSyncAt, string $now): void
    {
        $this->assertSync($issuer); self::environment($environment);
        $changes = ['last_error' => mb_substr($message, 0, 1000, 'UTF-8'), 'updated_at' => $now];
        if ($nextSyncAt !== null) { $changes['next_sync_at'] = $nextSyncAt; }
        $this->saveState($issuer, $environment, $changes);
    }

    public static function noteTaxId(NfseDocument $note, string $person): string
    {
        if (!in_array($person, ['prest', 'toma'], true)) { throw new \InvalidArgumentException('Participante fiscal inválido.'); }
        $cnpj = $note->dpsText($person . '/CNPJ'); $cpf = $note->dpsText($person . '/CPF');
        if ($cnpj !== '' && $cpf !== '') { throw new \InvalidArgumentException('O XML contém identificação duplicada do participante.'); }
        $id = Config::taxId($cnpj !== '' ? $cnpj : $cpf);
        if ($id !== '' && !Validator::taxId($id)) { throw new \InvalidArgumentException('O documento nacional contém CPF/CNPJ inválido.'); }
        return $id;
    }

    public static function direction(NfseDocument $note, string $issuer): string
    {
        $issued = self::noteTaxId($note, 'prest') === $issuer;
        $received = self::noteTaxId($note, 'toma') === $issuer;
        return $issued && $received ? 'both' : ($issued ? 'issued' : ($received ? 'received' : ''));
    }

    public static function utc(string $timestamp): string
    {
        try { return (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
        catch (\Throwable $error) { throw new \InvalidArgumentException('Data fiscal inválida.'); }
    }

    private function assertAccess(): void
    {
        if (!nfse_nacional_can('national_view')) { throw new \RuntimeException('Você não tem permissão para consultar os documentos nacionais.', 403); }
        if ($this->prefix !== db_prefix() || !hash_equals($this->scope, nfse_nacional_scope())) { throw new \RuntimeException('A empresa ativa mudou. Atualize a página antes de continuar.', 403); }
        CompanyContext::current();
    }

    private function assertSync(string $issuer): void
    {
        if (!nfse_nacional_can('national_sync') || !hash_equals($this->contextTaxId(), $issuer)) { throw new \RuntimeException('Você não tem permissão para sincronizar este emitente.', 403); }
    }

    private function table(string $suffix): string
    {
        if (!in_array($suffix, ['documents', 'events', 'sync'], true)) { throw new \InvalidArgumentException('Tabela nacional inválida.'); }
        return $this->prefix . 'nfse_nacional_national_' . $suffix;
    }

    private static function publicColumns(): string
    {
        return 'id,environment,direction,access_key,nfse_number,provider_name,provider_tax_id,recipient_name,recipient_tax_id,service_amount,issued_at,competence,status,synced_at';
    }

    private function filters(string $direction, array $input): array
    {
        if (!in_array($direction, ['issued', 'received', 'both'], true)) { throw new \InvalidArgumentException('Selecione notas emitidas ou recebidas.'); }
        $result = ['environment' => self::environment(Config::text($input['environment'] ?? $this->model->settings()['environment'], 1)),
            'month' => Config::text($input['month'] ?? '', 7), 'status' => Config::text($input['status'] ?? '', 16),
            'search' => Config::text($input['search'] ?? '', 100), 'date_from' => Config::text($input['date_from'] ?? '', 10), 'date_to' => Config::text($input['date_to'] ?? '', 10)];
        if (!in_array($result['status'], ['', 'authorized', 'cancelled'], true)) { throw new \InvalidArgumentException('Situação fiscal inválida.'); }
        if ($result['month'] !== '' && (!preg_match('/^20[0-9]{2}-(?:0[1-9]|1[0-2])$/D', $result['month']))) { throw new \InvalidArgumentException('Informe um mês válido no formato ano-mês.'); }
        foreach (['date_from', 'date_to'] as $key) {
            if ($result[$key] === '') { continue; }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $result[$key], new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $result[$key]) { throw new \InvalidArgumentException('Informe uma data válida para o período.'); }
        }
        if ($result['date_from'] !== '' && $result['date_to'] !== '' && $result['date_from'] > $result['date_to']) { throw new \InvalidArgumentException('A data inicial deve ser anterior à data final.'); }
        return $result;
    }

    private function applyFilters(string $issuer, string $direction, array $filters): void
    {
        $this->db->where('issuer_tax_id', $issuer)->where('environment', $filters['environment']);
        if ($direction !== 'both') { $this->db->where_in('direction', [$direction, 'both']); }
        if ($filters['status'] !== '') { $this->db->where('status', $filters['status']); }
        if ($filters['month'] !== '') {
            $from = $filters['month'] . '-01';
            $until = (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->modify('+1 month')->format('Y-m-d');
            $this->db->where('competence >=', $from)->where('competence <', $until);
        }
        if ($filters['date_from'] !== '') { $this->db->where('issued_at >=', $filters['date_from'] . ' 00:00:00'); }
        if ($filters['date_to'] !== '') {
            $until = (new \DateTimeImmutable($filters['date_to'], new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            $this->db->where('issued_at <', $until . ' 00:00:00');
        }
        if ($filters['search'] !== '') {
            $this->db->group_start()->like('provider_name', $filters['search'])->or_like('recipient_name', $filters['search'])
                ->or_like('provider_tax_id', $filters['search'])->or_like('recipient_tax_id', $filters['search'])
                ->or_like('access_key', $filters['search'])->or_like('nfse_number', $filters['search'])->group_end();
        }
    }

    private function findDocument(string $issuer, string $environment, string $key): ?array
    {
        $query = $this->db->where('issuer_tax_id', $issuer)->where('environment', $environment)->where('access_key', $key)->get($this->table('documents'));
        if (!$query) { throw new \RuntimeException('Não foi possível conferir a chave no acervo nacional.'); }
        return $query->row_array() ?: null;
    }

    private function pendingCancellation(string $issuer, string $environment, string $key): ?array
    {
        $query = $this->db->where('issuer_tax_id', $issuer)->where('environment', $environment)->where('access_key', $key)
            ->where('is_cancellation', 1)->order_by('processed_at', 'asc')->limit(1)->get($this->table('events'));
        if (!$query) { throw new \RuntimeException('Não foi possível conferir os eventos do documento nacional.'); }
        return $query->row_array() ?: null;
    }

    /** An event returned by an explicit contributor action does not move the distribution cursor. */
    public function applyRegisteredCancellation(int $id, string $xml): void
    {
        $record = $this->document($id);
        $event = FiscalEvent::fromXml($xml, $record['access_key'], $record['environment']);
        if (!$event['cancellation']) { throw new \InvalidArgumentException('O evento recebido não confirma cancelamento.'); }
        $this->applyCancellation($record, ['xml_enc' => $this->model->crypto()->encrypt($xml), 'xml_hash' => hash('sha256', $xml), 'event_id' => $event['id']], gmdate('Y-m-d H:i:s'));
    }

    private function applyCancellation(array $record, array $storedEvent, string $now): void
    {
        $xml = $this->model->crypto()->decrypt($storedEvent['xml_enc']);
        if (!hash_equals($storedEvent['xml_hash'], hash('sha256', $xml))) { throw new \RuntimeException('O evento fiscal arquivado falhou na conferência de integridade.'); }
        $event = FiscalEvent::fromXml($xml, $record['access_key'], $record['environment']);
        if (!$event['cancellation'] || $event['id'] !== $storedEvent['event_id']) { throw new \RuntimeException('O evento arquivado não confirma o cancelamento.'); }
        $meta = json_decode($record['fiscal_meta_json'], true, 16, JSON_THROW_ON_ERROR);
        $meta = array_replace($meta, ['cancellation_confirmed' => true, 'cancelled_at' => $event['processed_at'],
            'cancellation_event_id' => $event['id'], 'cancellation_record_number' => $event['record_number'],
            'cancellation_protocol' => $event['protocol'], 'status_checked_at' => $now]);
        $this->must($this->db->where('id', $record['id'])->where('issuer_tax_id', $record['issuer_tax_id'])->where('environment', $record['environment'])
            ->update($this->table('documents'), ['status' => 'cancelled', 'cancellation_event_enc' => $storedEvent['xml_enc'],
                'fiscal_meta_json' => self::json($meta), 'updated_at' => $now, 'synced_at' => $now]));
    }

    private function saveState(string $issuer, string $environment, array $changes): void
    {
        $query = $this->db->where('issuer_tax_id', $issuer)->where('environment', $environment)->get($this->table('sync'));
        if (!$query) { throw new \RuntimeException('Não foi possível ler o controle de sincronização nacional.'); }
        if ($query->row_array()) {
            $this->must($this->db->where('issuer_tax_id', $issuer)->where('environment', $environment)->update($this->table('sync'), $changes));
        } else {
            $this->must($this->db->insert($this->table('sync'), $changes + ['issuer_tax_id' => $issuer, 'environment' => $environment, 'last_nsu' => '0',
                'has_more' => 0, 'last_synced_at' => null, 'next_sync_at' => null, 'last_error' => null]));
        }
    }

    private function must($result): void { if ($result === false) { throw new \RuntimeException('Não foi possível gravar o acervo nacional. Nenhum avanço foi confirmado.'); } }
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
