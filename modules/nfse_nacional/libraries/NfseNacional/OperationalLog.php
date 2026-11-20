<?php
declare(strict_types=1);
namespace NfseNacional;

/** Bounded operational diagnostics; fiscal XMLs remain in their existing document storage. */
final class OperationalLog
{
    private const HEADER = "<?php exit; ?>\n";
    private const MAX_LINE = 16384;
    private const LEVELS = ['debug', 'info', 'warning', 'error'];
    private string $directory;
    private \Closure $clock;
    private int $retentionDays;
    private int $maxFiles;
    private int $maxFileBytes;
    private int $maxExportRecords;
    private static bool $failureReported = false;

    /** Directory and scope are server-owned; neither is a request parameter. */
    public function __construct(string $directory, private string $scope, private int $staffId = 0,
        ?callable $clock = null, array $limits = [])
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $scope) || $staffId < 0 || $directory === ''
            || str_contains($directory, "\0") || str_contains($directory, '://')
            || str_starts_with($directory, '//') || str_starts_with($directory, '\\\\')
            || !preg_match('~\A(?:/|[A-Za-z]:[\\\\/])~', $directory)) {
            throw new \InvalidArgumentException('Contexto de diagnóstico inválido.');
        }
        $this->directory = rtrim($directory, '/\\') . '/nfse_nacional/' . $scope;
        $this->clock = $clock ? \Closure::fromCallable($clock) : static fn(): int => time();
        $this->retentionDays = max(1, min(30, (int) ($limits['retention_days'] ?? 30)));
        $this->maxFiles = max(2, min(10, (int) ($limits['max_files'] ?? 10)));
        $this->maxFileBytes = max(32768, min(2097152, (int) ($limits['max_file_bytes'] ?? 2097152)));
        $this->maxExportRecords = max(1, min(2000, (int) ($limits['max_export_records'] ?? 2000)));
    }

    public static function current(): self
    {
        $context = CompanyContext::current()->descriptor('');
        $ci = get_instance();
        $directory = isset($ci->config) ? $ci->config->item('log_path') : '';
        if (!is_string($directory) || $directory === '') { $directory = APPPATH . 'logs/'; }
        return new self($directory, $context['scope'], $context['staff_id']);
    }

    /** Returns the event ID, or null if optional storage fails. No sensitive fallback log is emitted. */
    public function record(string $level, string $operation, string $message, array $context = []): ?string
    {
        $lock = null;
        try {
            $now = ($this->clock)();
            $clean = self::sanitize($context);
            $id = bin2hex(random_bytes(16));
            $row = ['id' => $id, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'level' => in_array(strtolower($level), self::LEVELS, true) ? strtolower($level) : 'error',
                'operation' => self::operation($operation), 'message' => self::text($message, 1200),
                'correlation_id' => $clean['correlation_id'] ?? $id, 'staff_id' => $this->staffId,
                'environment' => $clean['environment'] ?? '', 'document_id' => $clean['document_id'] ?? 0,
                'invoice_id' => $clean['invoice_id'] ?? 0, 'context' => $clean];
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($encoded) > self::MAX_LINE) {
                $row['context'] = array_intersect_key($clean, array_flip(['http_status', 'duration_ms', 'method', 'endpoint', 'service', 'municipality_code']));
                $row['context']['details_omitted'] = true;
                $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
            }
            $this->prepare();
            $lock = $this->lock(LOCK_EX);
            $this->prune($now);
            $files = $this->files();
            $day = gmdate('Ymd', $now);
            $today = array_values(array_filter($files, static fn(string $file): bool => str_contains(basename($file), 'events-' . $day . '-')));
            $file = $today ? end($today) : $this->directory . '/events-' . $day . '-000001.jsonl.php';
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) + strlen($encoded) > $this->maxFileBytes) {
                preg_match('/-([0-9]{6})\.jsonl\.php\z/', $file, $parts);
                $sequence = (int) ($parts[1] ?? 0) + 1;
                if ($sequence > 999999) { throw new \RuntimeException('Limite de segmentos atingido.'); }
                $file = $this->directory . '/events-' . $day . '-' . sprintf('%06d', $sequence) . '.jsonl.php';
            }
            if (is_link($file)) { throw new \RuntimeException('Arquivo de diagnóstico inválido.'); }
            $stream = @fopen($file, 'c+b');
            if (!is_resource($stream)) { throw new \RuntimeException('Armazenamento de diagnóstico indisponível.'); }
            try {
                @chmod($file, 0600);
                $stat = fstat($stream);
                if (!$stat || (($stat['mode'] & 0170000) !== 0100000)) { throw new \RuntimeException('Arquivo de diagnóstico inválido.'); }
                if ($stat['size'] === 0) {
                    if (fwrite($stream, self::HEADER) !== strlen(self::HEADER)) { throw new \RuntimeException('Falha de gravação.'); }
                } else {
                    if (fread($stream, strlen(self::HEADER)) !== self::HEADER) { throw new \RuntimeException('Cabeçalho de diagnóstico inválido.'); }
                }
                if (fseek($stream, 0, SEEK_END) !== 0 || fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream)) {
                    throw new \RuntimeException('Falha de gravação.');
                }
            } finally { fclose($stream); }
            clearstatcache(true, $file);
            $this->prune($now);
            return $id;
        } catch (\Throwable $error) {
            if (!self::$failureReported) {
                self::$failureReported = true;
                try { error_log('[NFS-e] Armazenamento do diagnóstico indisponível. A operação fiscal não foi interrompida por essa falha.'); }
                catch (\Throwable $ignored) { }
            }
            return null;
        } finally { $this->unlock($lock); }
    }

    public function report(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $filters = self::filters($filters);
        $page = max(1, min(10000, $page)); $perPage = max(1, min(100, $perPage));
        $rows = []; $total = 0; $offset = ($page - 1) * $perPage;
        $scan = $this->scan(static function (array $row) use ($filters, $offset, $perPage, &$rows, &$total): void {
            if (!self::matches($row, $filters)) { return; }
            if ($total >= $offset && count($rows) < $perPage) { $rows[] = $row; }
            ++$total;
        });
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)), 'has_more' => $total > $offset + count($rows),
            'truncated' => $scan['truncated'], 'storage' => $scan['storage'], 'storage_available' => $scan['storage'] !== 'unavailable',
            'retention_days' => $this->retentionDays];
    }

    public function find(string $id): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) { return null; }
        $found = null;
        $this->scan(static function (array $row) use ($id, &$found): void {
            if (hash_equals($id, $row['id'])) { $found = $row; }
        });
        return $found;
    }

    /** Sanitized JSON Lines only; never exposes local filenames or raw HTTP bodies. */
    public function export(array $filters = []): array
    {
        $filters = self::filters($filters); $content = ''; $count = 0; $limited = false;
        $scan = $this->scan(function (array $row) use ($filters, &$content, &$count, &$limited): void {
            if (!self::matches($row, $filters)) { return; }
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
            if ($count >= $this->maxExportRecords || strlen($content) + strlen($line) > 4194304) { $limited = true; return; }
            $content .= $line; ++$count;
        });
        return ['content' => $content, 'count' => $count, 'truncated' => $limited || $scan['truncated'],
            'storage' => $scan['storage']];
    }

    /** Structured support report: full correlated timeline, but never raw request/response bodies. */
    public function detailedExport(array $filters = [], ?string $eventId = null): ?array
    {
        if ($eventId !== null) {
            if (!preg_match('/\A[a-f0-9]{32}\z/D', $eventId)) { return null; }
            $selected = $this->find($eventId);
            if ($selected === null) { return null; }
            // An event selected in the current company's store authorizes only
            // its own correlation; arbitrary tenant IDs and paths are ignored.
            $filters = ['correlation_id' => $selected['correlation_id']];
        }
        $export = $this->export($filters);
        if ($export['storage'] === 'unavailable') {
            return ['content' => '', 'count' => 0, 'truncated' => true, 'storage' => 'unavailable'];
        }
        $rows = [];
        foreach (explode("\n", (string) $export['content']) as $line) {
            if ($line === '') { continue; }
            $row = json_decode($line, true, 32);
            if (is_array($row)) { $rows[] = $row; }
        }
        // Storage scans from newest to oldest; the report presents the actual
        // operation timeline in increasing timestamp order.
        $rows = array_reverse($rows);
        $levels = []; $http = []; $operations = []; $stages = []; $environments = [];
        $first = null; $last = null; $slowestMs = 0.0; $durationSum = 0.0;
        $durationCount = 0; $generationFallbacks = 0; $curlCodes = [];
        foreach ($rows as $row) {
            $levels[$row['level']] = ($levels[$row['level']] ?? 0) + 1;
            $operations[$row['operation']] = ($operations[$row['operation']] ?? 0) + 1;
            if (in_array($row['environment'], ['1', '2'], true)) {
                $environments[$row['environment']] = ($environments[$row['environment']] ?? 0) + 1;
            }
            $created = (string) ($row['created_at'] ?? '');
            if ($created !== '') { $first = $first === null || strcmp($created, $first) < 0 ? $created : $first;
                $last = $last === null || strcmp($created, $last) > 0 ? $created : $last; }
            $ctx = $row['context'] ?? [];
            if (isset($ctx['http_status'])) { $key = (string) $ctx['http_status']; $http[$key] = ($http[$key] ?? 0) + 1; }
            if (isset($ctx['stage'])) { $key = $ctx['stage']; $stages[$key] = ($stages[$key] ?? 0) + 1; }
            if (isset($ctx['curl_errno']) && $ctx['curl_errno'] > 0) {
                $key = (string) $ctx['curl_errno']; $curlCodes[$key] = ($curlCodes[$key] ?? 0) + 1;
            }
            $generationFallbacks += (int) ($ctx['generation_date_fallbacks'] ?? 0);
            if (isset($ctx['duration_ms'])) {
                $durationSum += (float) $ctx['duration_ms']; ++$durationCount;
                $slowestMs = max($slowestMs, (float) $ctx['duration_ms']);
            }
        }
        $insights = [];
        if (isset($http['0'])) { $insights[] = 'HTTP 0: nao houve resposta HTTP conclusiva; examine a etapa, o codigo cURL e as duracoes DNS/TLS.'; }
        if (array_filter(array_keys($http), static fn($key): bool => (int) $key >= 200 && (int) $key < 300)) {
            $insights[] = 'HTTP 2xx recebido: uma falha posterior pode ocorrer ao validar o envelope, documento XML ou armazenamento.';
        }
        if ($generationFallbacks > 0) {
            $insights[] = 'A data auxiliar do envelope ADN exigiu recuperacao; os campos obrigatorios do XML, chave, ambiente e NSU continuam validados.';
        }
        if ($curlCodes !== []) { $insights[] = 'Erros cURL registrados numericamente; mensagens de transporte, caminhos e segredos nao sao exportados.'; }
        if ($rows === []) { $insights[] = 'Nenhum registro disponivel nos filtros informados.'; }
        if ($export['truncated']) { $insights[] = 'Relatorio parcial devido ao limite de registros, tamanho ou retencao.'; }
        $report = [
            'format' => 'nfse-nacional-diagnostico-v1',
            'module_version' => defined('NFSE_NACIONAL_VERSION') ? NFSE_NACIONAL_VERSION : 'unknown',
            'exported_at_utc' => gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()),
            'scope' => 'somente empresa autenticada',
            'selection' => $eventId === null ? 'filtros da listagem' : 'operacao do registro selecionado',
            'retention_days' => $this->retentionDays,
            'limits' => ['max_records' => $this->maxExportRecords, 'truncated' => $export['truncated'], 'storage' => $export['storage']],
            'summary' => [
                'records' => count($rows), 'first_at_utc' => $first, 'last_at_utc' => $last,
                'levels' => $levels, 'environments' => $environments, 'operations' => $operations,
                'http_statuses' => $http, 'stages' => $stages, 'curl_errors' => $curlCodes,
                'generation_date_fallbacks' => $generationFallbacks,
                'duration_ms' => ['observations' => $durationCount,
                    'average' => $durationCount ? round($durationSum / $durationCount, 2) : null,
                    'maximum' => $durationCount ? round($slowestMs, 2) : null],
            ],
            'insights' => $insights,
            'privacy' => 'Apenas diagnosticos previamente sanitizados; sem PFX, senha, token, chave privada, CPF/CNPJ, URL com query, cabecalhos secretos, XML ou corpo HTTP.',
            'events' => $rows,
        ];
        $content = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        return ['content' => $content, 'count' => count($rows), 'truncated' => $export['truncated'], 'storage' => $export['storage']];
    }

    public function diagnostics(): array
    {
        $parent = $this->directory;
        while (!file_exists($parent) && dirname($parent) !== $parent) { $parent = dirname($parent); }
        $available = !is_link($this->directory) && !is_link(dirname($this->directory)) && is_dir($parent) && is_readable($parent);
        $writable = $available && is_writable($parent);
        return ['available' => $available, 'writable' => $writable, 'retention_days' => $this->retentionDays,
            'max_files' => $this->maxFiles, 'max_file_bytes' => $this->maxFileBytes,
            'max_total_bytes' => $this->maxFiles * $this->maxFileBytes,
            'message' => !$available ? 'Não foi possível acessar o armazenamento dos registros.'
                : (!$writable ? 'O armazenamento dos registros está sem permissão de escrita.'
                    : 'Registros disponíveis para esta empresa, com retenção e tamanho limitados.')];
    }

    /** Allowlist-first: unknown fields and raw payloads are never persisted. */
    public static function sanitize(array $context): array
    {
        return self::cleanContext($context, 0);
    }

    private static function cleanContext(array $context, int $depth): array
    {
        if ($depth > 2) { return []; }
        $out = [];
        foreach (array_slice($context, 0, 100, true) as $key => $value) {
            if (!is_string($key)) { continue; }
            if (in_array($key, ['document_id', 'national_document_id', 'delivery_id', 'invoice_id', 'client_id', 'staff_id', 'attempt', 'request_bytes', 'response_bytes', 'field_count', 'document_count', 'recipient_count', 'generation_date_fallbacks', 'batch_items', 'curl_errno'], true)) {
                if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 0 && (int) $value <= 2147483647) { $out[$key] = (int) $value; }
            } elseif ($key === 'http_status') {
                if (is_numeric($value) && ((int) $value === 0 || ((int) $value >= 100 && (int) $value <= 599))) { $out[$key] = (int) $value; }
            } elseif (in_array($key, ['dns_ms', 'connect_ms', 'tls_ms', 'first_byte_ms', 'transfer_ms'], true)) {
                if (is_numeric($value) && is_finite((float) $value)) { $out[$key] = round(max(0, min(3600000, (float) $value)), 2); }
            } elseif ($key === 'duration_ms') {
                if (is_numeric($value) && is_finite((float) $value)) { $out[$key] = round(max(0, min(3600000, (float) $value)), 2); }
            } elseif ($key === 'environment') {
                if ((is_int($value) || is_string($value)) && in_array((string) $value, ['1', '2'], true)) { $out[$key] = (string) $value; }
            } elseif ($key === 'municipality_code') {
                if (is_scalar($value) && preg_match('/\A[1-9][0-9]{6}\z/D', (string) $value)) { $out[$key] = (string) $value; }
            } elseif ($key === 'uf') {
                if (is_string($value) && in_array($value, explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'), true)) { $out[$key] = $value; }
            } elseif ($key === 'nbs') {
                if (is_string($value) && preg_match('/^[0-9]{9}$/D', $value)) { $out[$key] = $value; }
            } elseif ($key === 'ibpt_key') {
                if (is_string($value) && preg_match('/^[A-Za-z0-9]{1,32}$/D', $value)) { $out[$key] = $value; }
            } elseif ($key === 'ibpt_version') {
                if (is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,40}$/D', $value)) { $out[$key] = $value; }
            } elseif (in_array($key, ['ibpt_valid_from', 'ibpt_valid_to'], true)) {
                if (is_string($value)) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if ($date && $date->format('Y-m-d') === $value) { $out[$key] = $value; }
                }
            } elseif ($key === 'event_type') {
                if (is_string($value) && preg_match('/^[0-9]{6}$/D', $value)) { $out[$key] = $value; }
            } elseif (in_array($key, ['request_sha256', 'response_sha256'], true)) {
                if (is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value)) { $out[$key] = $value; }
            } elseif ($key === 'correlation_id') {
                if (is_string($value) && preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/Di', $value)) { $out[$key] = strtolower($value); }
            } elseif ($key === 'method') {
                if (is_string($value) && in_array(strtoupper($value), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) { $out[$key] = strtoupper($value); }
            } elseif (in_array($key, ['endpoint', 'route'], true)) {
                if (is_string($value)) { $out[$key] = self::endpoint($value); }
            } elseif ($key === 'processing_status') {
                if (is_string($value) && in_array($value, ['REJEICAO', 'NENHUM_DOCUMENTO_LOCALIZADO', 'DOCUMENTOS_LOCALIZADOS'], true)) { $out[$key] = $value; }
            } elseif ($key === 'transport_category') {
                if (is_string($value) && in_array($value, ['dns', 'connect', 'timeout', 'tls_handshake', 'tls_verification', 'certificate', 'empty_reply', 'send_failure', 'receive_failure', 'response_limit', 'transport'], true)) { $out[$key] = $value; }
            } elseif (in_array($key, ['service', 'operation_status', 'response_type', 'exception_class', 'reason', 'stage'], true)) {
                if (is_string($value) && strlen($value) <= 100 && preg_match('~\A[A-Za-z0-9_.:/\\\\ -]+\z~D', $value)) { $out[$key] = self::text($value, 100); }
            } elseif (in_array($key, ['request', 'response'], true) && is_array($value)) {
                $out[$key] = self::cleanContext($value, $depth + 1);
            } elseif ($key === 'errors' && is_array($value)) {
                $out[$key] = [];
                foreach (array_slice($value, 0, 10) as $error) {
                    if (!is_array($error)) { continue; }
                    $item = [];
                    if (isset($error['code']) && is_scalar($error['code'])) { $item['code'] = self::text((string) $error['code'], 64); }
                    if (isset($error['message']) && is_string($error['message'])) { $item['message'] = self::text($error['message'], 500); }
                    if ($item) { $out[$key][] = $item; }
                }
            } elseif (in_array($key, ['error_codes', 'field_names'], true) && is_array($value)) {
                $out[$key] = [];
                foreach (array_slice($value, 0, 30) as $item) {
                    if (is_string($item) && preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,63}\z/D', $item)) { $out[$key][] = self::text($item, 64); }
                }
            } elseif (in_array($key, ['uncertain', 'cached', 'certificate_changed', 'local_priority', 'sent', 'details_omitted'], true) && is_bool($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function operation(string $operation): string
    {
        return preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $operation) ? $operation : 'operation';
    }

    private static function text(string $value, int $limit): string
    {
        $value = mb_convert_encoding(substr($value, 0, 32768), 'UTF-8', 'UTF-8');
        if (preg_match('/<\??(?:xml|DPS|NFSe|infDPS|infNFSe|Signature|X509Certificate)\b/i', $value)) { return '[XML omitido]'; }
        $value = preg_replace('/-----BEGIN [^-]+-----.*?(?:-----END [^-]+-----|\z)/s', '[certificado ou chave omitidos]', $value) ?? '';
        $value = preg_replace('/\b(?:Bearer|Basic)\s+[^\s,;]+/i', '[credencial omitida]', $value) ?? '';
        $value = preg_replace('/((?:password|passwd|senha|token|secret|authorization|credential|api[_-]?key|nome|razao[_ ]?social|name|email|telefone|phone|address|endereco)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\r\n,;}]+)/iu', '$1[omitido]', $value) ?? '';
        $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[e-mail omitido]', $value) ?? '';
        $value = preg_replace('/\bDPS[0-9A-Z]{20,64}\b/i', '[DPS omitida]', $value) ?? '';
        $value = preg_replace('/(?<![A-Za-z0-9])(?:[A-Z0-9]{50}|[0-9]{3}\.[0-9]{3}\.[0-9]{3}-[0-9]{2}|[A-Z0-9]{2}\.[A-Z0-9]{3}\.[A-Z0-9]{3}\/[A-Z0-9]{4}-[0-9]{2}|[A-Z0-9]{12}[0-9]{2}|[0-9]{11})(?![A-Za-z0-9])/i', '[identificador omitido]', $value) ?? '';
        $value = preg_replace('/\b[A-Za-z0-9+_\/=.-]{64,}\b/', '[conteúdo omitido]', $value) ?? '';
        $value = preg_replace('/\+?[0-9]{0,3}\s*\(?[0-9]{2}\)?\s*[0-9]{4,5}[- ][0-9]{4}\b/', '[telefone omitido]', $value) ?? '';
        $value = strip_tags($value);
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '');
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private static function endpoint(string $value): string
    {
        if (strlen($value) > 2048 || str_contains($value, "\0")) { return '[endereço omitido]'; }
        $parts = parse_url($value);
        if ($parts === false) { return '[endereço omitido]'; }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        $path = preg_replace('~/(?:DPS[0-9A-Z]+|[0-9A-Z]{50}|[0-9A-Z]{12}[0-9]{2}|[0-9]{11})(?=/|$)~i', '/[identificador]', $path) ?? '';
        $host = (string) ($parts['host'] ?? '');
        if ($host !== '' && (!preg_match('/\A[a-z0-9.-]+\z/Di', $host) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true))) { return '[endereço omitido]'; }
        return self::text(($host !== '' ? ($parts['scheme'] . '://' . $host) : '') . $path, 400);
    }

    private function prepare(): void
    {
        foreach ([dirname($this->directory), $this->directory] as $path) {
            if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path))) {
                throw new \RuntimeException('Diretório de diagnóstico indisponível.');
            }
        }
        foreach (['.htaccess' => "Require all denied\nDeny from all\n", 'index.html' => ''] as $name => $contents) {
            $file = $this->directory . '/' . $name;
            if (is_link($file)) { throw new \RuntimeException('Proteção de diagnóstico inválida.'); }
            if (!file_exists($file) && @file_put_contents($file, $contents, LOCK_EX) === false) { throw new \RuntimeException('Proteção de diagnóstico indisponível.'); }
            @chmod($file, 0600);
        }
    }

    private function lock(int $mode)
    {
        if (is_link($this->directory) || is_link(dirname($this->directory))) { throw new \RuntimeException('Diretório de diagnóstico inválido.'); }
        $file = $this->directory . '/lock.php';
        if (is_link($file)) { throw new \RuntimeException('Arquivo de controle inválido.'); }
        $stream = @fopen($file, $mode === LOCK_EX ? 'c+b' : 'rb');
        if (!is_resource($stream)) { throw new \RuntimeException('Armazenamento de diagnóstico indisponível.'); }
        if ($mode === LOCK_EX) { @chmod($file, 0600); }
        $deadline = microtime(true) + 0.2;
        while (!flock($stream, $mode | LOCK_NB)) {
            if (microtime(true) >= $deadline) { fclose($stream); throw new \RuntimeException('Registros de diagnóstico ocupados.'); }
            usleep(2000);
        }
        $stat = fstat($stream);
        if (!$stat || (($stat['mode'] & 0170000) !== 0100000)) { $this->unlock($stream); throw new \RuntimeException('Arquivo de controle inválido.'); }
        if ($mode === LOCK_EX && $stat['size'] === 0
            && (fwrite($stream, self::HEADER) !== strlen(self::HEADER) || !fflush($stream))) {
            $this->unlock($stream); throw new \RuntimeException('Arquivo de controle indisponível.');
        }
        return $stream;
    }

    private function unlock($lock): void
    {
        if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); }
    }

    private function files(): array
    {
        $files = array_filter(glob($this->directory . '/events-*.jsonl.php') ?: [],
            static fn(string $file): bool => preg_match('/\Aevents-[0-9]{8}-[0-9]{6}\.jsonl\.php\z/D', basename($file)) === 1 && is_file($file) && !is_link($file));
        sort($files, SORT_STRING);
        return array_values($files);
    }

    private function prune(int $now): void
    {
        $cutoff = gmdate('Ymd', $now - ($this->retentionDays - 1) * 86400);
        $files = $this->files();
        foreach ($files as $index => $file) {
            if (substr(basename($file), 7, 8) < $cutoff || count($files) > $this->maxFiles) {
                if (!@unlink($file)) { throw new \RuntimeException('Não foi possível manter a retenção dos registros.'); }
                unset($files[$index]);
            }
        }
    }

    /** Reads at most ten bounded segments, newest first, without retaining the full history in memory. */
    private function scan(callable $consume): array
    {
        $lock = null; $truncated = false;
        try {
            if (!file_exists($this->directory)) { return ['storage' => $this->diagnostics()['available'] ? 'empty' : 'unavailable', 'truncated' => false]; }
            if (!is_dir($this->directory)) { throw new \RuntimeException('Armazenamento inválido.'); }
            $lock = $this->lock(LOCK_SH);
            $files = $this->files();
            if (count($files) > $this->maxFiles) { $truncated = true; $files = array_slice($files, -$this->maxFiles); }
            $cutoff = gmdate('Ymd', ($this->clock)() - ($this->retentionDays - 1) * 86400);
            foreach (array_reverse($files) as $file) {
                if (substr(basename($file), 7, 8) < $cutoff) { continue; }
                clearstatcache(true, $file);
                if (filesize($file) > $this->maxFileBytes || !is_readable($file)) { $truncated = true; continue; }
                $bytes = @file_get_contents($file, false, null, 0, $this->maxFileBytes + 1);
                if (!is_string($bytes) || !str_starts_with($bytes, self::HEADER) || strlen($bytes) > $this->maxFileBytes) { $truncated = true; continue; }
                foreach (array_reverse(explode("\n", substr($bytes, strlen(self::HEADER)))) as $line) {
                    if ($line === '') { continue; }
                    if (strlen($line) > self::MAX_LINE) { $truncated = true; continue; }
                    $row = json_decode($line, true, 16);
                    if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $row['id'])
                        || !is_string($row['created_at'] ?? null) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $row['created_at'])) {
                        $truncated = true; continue;
                    }
                    // Apply the same allowlist again to old or manually altered records.
                    $context = self::sanitize(is_array($row['context'] ?? null) ? $row['context'] : []);
                    $ids = self::sanitize(array_intersect_key($row, array_flip(['staff_id', 'document_id', 'invoice_id'])));
                    $consume(['id' => $row['id'], 'created_at' => $row['created_at'],
                        'level' => in_array($row['level'] ?? '', self::LEVELS, true) ? $row['level'] : 'error',
                        'operation' => self::operation(is_string($row['operation'] ?? null) ? $row['operation'] : ''),
                        'message' => self::text(is_string($row['message'] ?? null) ? $row['message'] : '', 1200),
                        'correlation_id' => self::sanitize(['correlation_id' => $row['correlation_id'] ?? ''])['correlation_id'] ?? '',
                        'staff_id' => $ids['staff_id'] ?? 0,
                        'environment' => in_array($row['environment'] ?? '', ['1', '2'], true) ? $row['environment'] : '',
                        'document_id' => $ids['document_id'] ?? 0, 'invoice_id' => $ids['invoice_id'] ?? 0,
                        'context' => $context]);
                }
            }
            return ['storage' => $files ? 'ok' : 'empty', 'truncated' => $truncated];
        } catch (\Throwable $error) {
            return ['storage' => 'unavailable', 'truncated' => true];
        } finally { $this->unlock($lock); }
    }

    private static function filters(array $input): array
    {
        $filters = [];
        foreach (['date_from', 'date_to', 'level', 'operation', 'http_status', 'environment', 'search', 'correlation_id'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_scalar($value) || is_bool($value)) { continue; }
            $value = trim((string) $value);
            if ($value === '') { continue; }
            if (in_array($key, ['date_from', 'date_to'], true)) {
                if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value)) { continue; }
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(date_default_timezone_get()));
                if ($date && $date->format('Y-m-d') === $value) {
                    if ($key === 'date_to') { $date = $date->modify('+1 day'); }
                    $filters[$key] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                }
            } elseif ($key === 'level' && in_array($value, self::LEVELS, true)) { $filters[$key] = $value; }
            elseif ($key === 'environment' && in_array($value, ['1', '2'], true)) { $filters[$key] = $value; }
            elseif ($key === 'http_status' && preg_match('/\A(?:0|[1-5][0-9]{2})\z/D', $value)) { $filters[$key] = (int) $value; }
            elseif ($key === 'operation' && self::operation($value) === $value) { $filters[$key] = $value; }
            elseif ($key === 'correlation_id' && preg_match('/\A[a-f0-9-]{8,36}\z/Di', $value)) { $filters[$key] = strtolower($value); }
            elseif ($key === 'search') { $filters[$key] = mb_strtolower(mb_substr($value, 0, 120, 'UTF-8'), 'UTF-8'); }
        }
        return $filters;
    }

    private static function matches(array $row, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if ($key === 'date_from' && $row['created_at'] < $value) { return false; }
            if ($key === 'date_to' && $row['created_at'] >= $value) { return false; }
            if (in_array($key, ['level', 'operation', 'environment'], true) && $row[$key] !== $value) { return false; }
            if ($key === 'http_status' && ($row['context']['http_status'] ?? -1) !== $value) { return false; }
            if ($key === 'correlation_id' && !str_contains($row['correlation_id'], $value)) { return false; }
            if ($key === 'search' && !str_contains(mb_strtolower($row['operation'] . ' ' . $row['message'] . ' ' . $row['correlation_id'] . ' ' . json_encode($row['context'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'UTF-8'), $value)) { return false; }
        }
        return true;
    }
}
