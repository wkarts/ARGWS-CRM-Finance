<?php
declare(strict_types=1);
namespace NfseNacional;

/** Public IBPT service table. NBS records are matched exactly; NCM and LC 116 are never fallbacks. */
final class IbptTable
{
    private const BASE = 'https://ibpt.wwsoftwares.com.br/tabela/ibpt/';
    private const MAX_BYTES = 8388608;
    private const MAX_ROWS = 60000;
    private const MAX_SERVICES = 4000;
    private const RETRY_SECONDS = 3600;
    private const MANUAL_RETRY_SECONDS = 60;
    private const COLUMNS = ['codigo', 'ex', 'tipo', 'descricao', 'nacionalfederal', 'importadosfederal',
        'estadual', 'municipal', 'vigenciainicio', 'vigenciafim', 'chave', 'versao', 'fonte'];
    private \Closure $http;
    private \Closure $clock;
    private ?\Closure $logger;

    /** The injected transport returns raw CSV bytes; it receives no client or certificate data. */
    public function __construct(private IbptStore $store, ?callable $http = null, ?callable $clock = null, ?callable $logger = null)
    {
        $this->http = $http ? \Closure::fromCallable($http) : static fn(string $url, int $timeout): string => self::request($url, $timeout);
        $this->clock = $clock ? \Closure::fromCallable($clock) : static fn(): int => time();
        $this->logger = $logger ? \Closure::fromCallable($logger) : null;
    }

    public static function current(): self
    {
        $context = CompanyContext::current()->descriptor('');
        $ci = get_instance();
        $directory = (string) ($ci->config->item('cache_path') ?: APPPATH . 'cache/');
        return new self(new IbptStore(rtrim($directory, '/\\') . '/nfse_nacional_ibpt', $context['scope']), null, null,
            static function (string $level, string $message, array $context): void {
                try { OperationalLog::current()->record($level, 'ibpt.lookup', $message, $context); }
                catch (\Throwable $ignored) { /* Optional diagnostics cannot prevent a fiscal calculation. */ }
            });
    }

    /** $date is the server's emission date, not the invoice competence or a browser field. */
    public function lookup(string $uf, string $nbs, ?string $date = null, bool $refresh = false): array
    {
        $uf = self::uf($uf); $nbs = self::nbs($nbs);
        $date = self::date($date ?? self::today(($this->clock)()));
        $fallback = null;
        if (!$refresh) {
            // Read without acquiring the updater lock. Stale-but-structurally-valid IBPT remains usable.
            $local = $this->store->inspect($uf, static function (array $state, int $now) use ($uf, $nbs, $date, &$fallback): ?array {
                $snapshot = self::cached($state, $uf, $nbs, $date);
                $metadata = self::metadata($state, $uf, $date);
                $fallback = $snapshot;
                if ($snapshot !== null && ($metadata['status'] === 'current'
                    || (!empty($state['auto_refresh_paused']) && $metadata['status'] === 'expired')
                    || ($metadata['status'] === 'expired' && self::timestamp($state['retry_at'] ?? null) > $now))) {
                    return $snapshot;
                }
                if ($snapshot === null && !isset($state['rows'][$nbs])
                    && ($metadata['status'] === 'current' || (!empty($state['auto_refresh_paused']) && $metadata['status'] === 'expired'))) {
                    throw self::missing($uf, $nbs);
                }
                return null;
            });
            if ($local !== null) { return $local; }
        }
        try {
            return $this->store->transaction($uf, function (array &$state, int $now) use ($uf, $nbs, $date, $refresh): array {
                $cache = self::cached($state, $uf, $nbs, $date);
                $metadata = self::metadata($state, $uf, $date);
                if (!$refresh && $cache !== null && ($metadata['status'] === 'current'
                    || (!empty($state['auto_refresh_paused']) && $metadata['status'] === 'expired'))) { return $cache; }
                if (!$refresh && ($metadata['status'] === 'current'
                    || (!empty($state['auto_refresh_paused']) && $metadata['status'] === 'expired')) && !isset($state['rows'][$nbs])) {
                    throw self::missing($uf, $nbs);
                }
                $waitUntil = $refresh ? self::manualAt($state, $now) : self::timestamp($state['retry_at'] ?? null);
                if ($waitUntil > $now) {
                    if ($cache !== null) { return $cache; }
                    throw self::unavailable($state, $uf, $nbs, $date);
                }
                // Expiration triggers a throttled renewal attempt, but a failed/old response never blocks emission.
                $this->download($state, $uf, $now, $nbs);
                $snapshot = self::cached($state, $uf, $nbs, $date);
                if ($snapshot !== null) { return $snapshot; }
                throw self::unavailable($state, $uf, $nbs, $date);
            });
        } catch (\RuntimeException $error) {
            // A concurrent manual update must not block an emission that already has a usable local snapshot.
            if (!$refresh && $error->getCode() === 429 && $fallback !== null) { return $fallback; }
            throw $error;
        }
    }

    /** Inspect the selected issuer UF using only the protected local copy. */
    public function status(string $uf): array
    {
        $uf = self::uf($uf);
        return $this->store->inspect($uf, static fn(array $state, int $now): array => self::summary($state, $uf, $now));
    }

    /** Service suggestions from the installed, company-scoped table, never from the network.
     *  Missing, invalid or future tables have no catalog. Expired tables remain searchable.
     *  These are labels for the editor only; the fiscal NBS is still validated separately.
     *  @return list<array{code:string,description:string}>
     */
    public function services(string $uf): array
    {
        $uf = self::uf($uf);
        return $this->store->inspect($uf, static function (array $state, int $now) use ($uf): array {
            $meta = self::metadata($state, $uf, self::today($now));
            if (!in_array($meta['status'], ['current', 'expired'], true) || !self::cacheHeader($state, $uf)) {
                return [];
            }
            $services = [];
            foreach ($state['rows'] as $code => $row) {
                // metadata() has already validated every row, including its origin and version.
                $services[] = ['code' => (string) $code, 'description' => (string) $row['description']];
            }
            usort($services, static fn(array $a, array $b): int => strcmp($a['code'], $b['code']));
            return $services;
        });
    }

    /** Explicit refresh needs an issuer UF only and never changes fiscal drafts or settings. */
    public function refresh(string $uf, bool $allowOlder = false): array
    {
        $uf = self::uf($uf);
        return $this->store->transaction($uf, function (array &$state, int $now) use ($uf, $allowOlder): array {
            if (self::manualAt($state, $now) > $now) {
                $result = self::summary($state, $uf, $now);
                $result['refresh_state'] = 'throttled';
                $result['refresh_message'] = 'Aguarde um minuto entre as tentativas de atualização manual da tabela IBPT.';
                return $result;
            }
            $this->download($state, $uf, $now, '', $allowOlder);
            return self::summary($state, $uf, $now);
        });
    }

    /** Uploads recognize explicit headers/UF; the standard table still uses its original filename. */
    public function import(string $uf, string $raw, string $filename, string $key = '', bool $allowOlder = false): array
    {
        $uf = self::uf($uf);
        $normalized = IbptCsvImport::normalize($raw, $uf, $key);
        self::importFilename($filename, $uf, $normalized['has_uf']);
        $hash = hash('sha256', $raw);
        return $this->store->transaction($uf, static function (array &$state, int $now) use ($uf, $normalized, $hash, $allowOlder): array {
            // Validate everything before assigning state: a rejected file must preserve the local copy.
            $rows = self::parse($normalized['csv'], $uf, $now);
            foreach ($rows as &$row) { $row['source_hash'] = $hash; } unset($row);
            $incoming = ['version' => 1, 'uf' => $uf, 'fetched_at' => $now, 'rows' => $rows,
                'acquisition' => 'upload', 'key_origin' => $normalized['used_manual_key'] ? 'manual' : 'file',
                'last_attempt_at' => $now, 'retry_at' => 0, 'last_outcome' => 'imported'];
            $date = self::today($now);
            $next = self::metadata($incoming, $uf, $date);
            if ($next['status'] === 'future') {
                throw new \InvalidArgumentException('A tabela enviada tem vigência futura. Envie uma tabela válida na data atual.');
            }
            if (!in_array($next['status'], ['current', 'expired'], true)) {
                throw new \InvalidArgumentException('A tabela enviada contém versões, chaves ou períodos divergentes. Envie o arquivo IBPT completo e original.');
            }
            $previous = self::metadata($state, $uf, $date);
            $older = self::isOlder($previous, $next);
            if ($older && !$allowOlder) {
                throw new \InvalidArgumentException('A tabela enviada é anterior à versão local. Habilite a permissão de versões anteriores nas configurações, salve e tente novamente.');
            }
            $incoming['auto_refresh_paused'] = $older;
            $incoming['last_outcome'] = $older ? 'imported_older' : 'imported';
            $state = $incoming;
            return self::summary($state, $uf, $now);
        });
    }

    /** Date/version regression is an explicit choice, never an automatic cache policy. */
    private static function isOlder(array $previous, array $incoming): bool
    {
        if (!in_array($previous['status'], ['current', 'expired'], true)
            || !in_array($incoming['status'], ['current', 'expired'], true)) { return false; }
        return $incoming['valid_from'] < $previous['valid_from']
            || $incoming['valid_to'] < $previous['valid_to']
            || strnatcasecmp((string) $incoming['version'], (string) $previous['version']) < 0;
    }

    private static function importFilename(string $filename, string $uf, bool $hasUf = false): void
    {
        $namedUf = preg_match('/\A(?:(?:Tabela[ _-]*)?IBPT(?:ax)?[ _-]*)?([A-Za-z]{2})(?:[ _.-]*[0-9][A-Za-z0-9._-]*)?(?: ?\([0-9]{1,3}\))?\.csv\z/Di', $filename, $match);
        if (strlen($filename) > 255 || preg_match('/[\x00-\x1f\x7f\\\\\/]/', $filename)
            || !preg_match('/\A.+\.csv\z/Di', $filename) || (!$hasUf && !$namedUf)) {
            throw new \InvalidArgumentException('Selecione o CSV original da tabela IBPT com a UF no nome, por exemplo TabelaIBPTax' . $uf . '26.2.B.csv.');
        }
        if ($namedUf && strtoupper($match[1]) !== $uf) {
            throw new \InvalidArgumentException('A UF indicada no nome do arquivo não corresponde à UF ' . $uf . ' do emitente.');
        }
    }

    private function download(array &$state, string $uf, int $now, string $nbs, bool $allowOlder = false): void
    {
        $started = microtime(true); $status = 0; $bytes = 0; $hash = '';
        $date = self::today($now);
        $previous = self::metadata($state, $uf, $date);
        $state['last_attempt_at'] = $now;
        try {
            $raw = ($this->http)(self::BASE . $uf, 12000);
            if (!is_string($raw)) { throw new \RuntimeException('Resposta da tabela inválida.'); }
            $status = 200; $bytes = strlen($raw); $hash = hash('sha256', $raw);
            $rows = self::parse($raw, $uf, $now);
            $incoming = ['version' => 1, 'uf' => $uf, 'fetched_at' => $now, 'rows' => $rows, 'acquisition' => 'download'];
            $next = self::metadata($incoming, $uf, $date);
            // A valid older version is accepted only after an explicit authorized refresh.
            // Structural failures, mismatched metadata and future tables never replace the local copy.
            $older = self::isOlder($previous, $next);
            $validIncoming = in_array($next['status'], ['current', 'expired'], true);
            $regressive = $older || ($previous['status'] === 'current' && $next['status'] !== 'current');
            $hasPrevious = in_array($previous['status'], ['current', 'expired'], true);
            if ($hasPrevious && (!$validIncoming || (!$allowOlder && $regressive))) {
                $state['retry_at'] = $now + self::RETRY_SECONDS;
                $state['last_outcome'] = $regressive ? 'regressive' : 'failed';
                $this->record('warning', self::outcomeMessage($state['last_outcome'], $uf), $uf, $nbs, $started, $status, $bytes, $hash);
                return;
            }
            $selectedOlder = $allowOlder && $older;
            $same = $previous['source_hash'] !== null && hash_equals($previous['source_hash'], $hash);
            if (!$same) { $state = $incoming + ['last_attempt_at' => $now, 'key_origin' => 'api']; }
            $state['auto_refresh_paused'] = $selectedOlder;
            $state['retry_at'] = $selectedOlder || $next['status'] === 'current' ? 0 : $now + self::RETRY_SECONDS;
            $state['last_outcome'] = $selectedOlder ? 'downgraded'
                : ($next['status'] === 'current' ? ($same ? 'unchanged' : 'updated') : $next['status']);
            $snapshot = $nbs !== '' ? ($rows[$nbs] ?? []) : reset($rows);
            $this->record($next['status'] === 'current' ? 'info' : 'warning', self::outcomeMessage($state['last_outcome'], $uf),
                $uf, $nbs, $started, $status, $bytes, $hash, is_array($snapshot) ? $snapshot : []);
        } catch (\Throwable $error) {
            if ($status === 0 && $error->getCode() >= 100 && $error->getCode() <= 599) { $status = (int) $error->getCode(); }
            $state['retry_at'] = $now + self::RETRY_SECONDS;
            $state['last_outcome'] = 'failed';
            $this->record('warning', self::outcomeMessage('failed', $uf), $uf, $nbs, $started, $status, $bytes, $hash);
        }
    }

    private static function summary(array $state, string $uf, int $now): array
    {
        $metadata = self::metadata($state, $uf, self::today($now));
        $status = $metadata['status'];
        $labels = ['missing' => 'Tabela não obtida', 'current' => 'Tabela vigente', 'expired' => 'Tabela vencida',
            'future' => 'Vigência futura', 'invalid' => 'Tabela inválida', 'mixed' => 'Metadados divergentes'];
        $messages = ['missing' => 'A tabela será obtida automaticamente ao preparar uma emissão com IBPT, pelo botão Atualizar agora ou pelo envio do arquivo.',
            'current' => 'A tabela local é reutilizada durante sua vigência, sem baixar novamente a cada emissão.',
            'expired' => 'A tabela local está vencida. O cálculo e a emissão continuam disponíveis com os percentuais armazenados, sob responsabilidade do emitente. Atualize quando possível.',
            'future' => 'A tabela local ainda não iniciou sua vigência e não pode ser utilizada nesta emissão.',
            'invalid' => 'A cópia local não pôde ser validada. Atualize a tabela antes de emitir com IBPT.',
            'mixed' => 'A tabela contém versões, chaves ou períodos diferentes entre os serviços. Cada NBS é conferido na emissão.' ];
        $attempt = self::attemptAt($state, $now);
        $retry = self::timestamp($state['retry_at'] ?? null);
        $manual = self::manualAt($state, $now);
        $paused = !empty($state['auto_refresh_paused']) && in_array($status, ['current', 'expired'], true);
        $nextAuto = $paused ? null : ($status === 'current'
            ? (new \DateTimeImmutable($metadata['valid_to'] . ' 00:00:00', new \DateTimeZone('America/Bahia')))->modify('+1 day')->getTimestamp()
            : max($now, $retry));
        $origin = $state['key_origin'] ?? ((($state['acquisition'] ?? '') === 'upload') ? 'file' : 'api');
        if (!in_array($origin, ['manual', 'file', 'api'], true)) { $origin = 'api'; }
        $keyOriginLabel = ['manual' => 'Cadastrada manualmente no envio',
            'file' => 'Presente no arquivo CSV', 'api' => 'Fornecida pela API'][$origin];
        $outcome = is_string($state['last_outcome'] ?? null) ? $state['last_outcome'] : '';
        $acquisition = $metadata['service_count'] > 0 && $status !== 'invalid'
            ? (($state['acquisition'] ?? 'download') === 'upload' ? 'upload' : 'download') : null;
        $result = ['uf' => $uf, 'status' => $status, 'status_label' => $labels[$status],
            'version' => $metadata['version'], 'key' => $metadata['key'], 'source' => $metadata['source'],
            'acquisition' => $acquisition, 'acquisition_label' => $acquisition === null ? '—' : ($acquisition === 'upload' ? 'Arquivo enviado' : 'Consulta à fonte'),
            'valid_from' => $metadata['valid_from'], 'valid_to' => $metadata['valid_to'],
            'fetched_at' => $metadata['fetched_at'], 'last_attempt_at' => self::iso($attempt),
            'next_retry_at' => $retry > $now ? self::iso($retry) : null,
            'next_auto_check_at' => $nextAuto === null ? null : self::iso($nextAuto), 'next_manual_at' => $manual > $now ? self::iso($manual) : null,
            'auto_refresh_paused' => $paused, 'key_origin' => $metadata['service_count'] > 0 ? $origin : null,
            'key_origin_label' => $metadata['service_count'] > 0 ? $keyOriginLabel : null,
            'service_count' => $metadata['service_count'], 'can_refresh' => $manual <= $now,
            'message' => $messages[$status] . ($paused ? ' A consulta automática foi suspensa para preservar a versão anterior escolhida; use Atualizar tabela agora para renovar.' : ''),
            'auto_policy' => $paused
                ? 'Tabela anterior selecionada manualmente: atualização automática suspensa até nova atualização explícita.'
                : 'Renovação automática quando a tabela local estiver ausente ou vencida; após falha, nova tentativa em uma hora.',
            'refresh_state' => in_array($outcome, ['imported', 'imported_older', 'downgraded'], true) ? 'updated'
                : (in_array($outcome, ['updated', 'unchanged'], true) ? $outcome : ($outcome === '' ? 'idle' : 'failed')),
            'refresh_message' => self::outcomeMessage($outcome, $uf)];
        return $result;
    }

    /** Summary metadata is exposed only when all service rows agree on that identity and period. */
    private static function metadata(array $state, string $uf, string $date): array
    {
        $result = ['status' => 'missing', 'version' => null, 'key' => null, 'source' => null, 'valid_from' => null,
            'valid_to' => null, 'fetched_at' => null, 'source_hash' => null, 'service_count' => 0];
        if (!isset($state['rows'])) { return $result; }
        $result['status'] = 'invalid';
        if (!self::cacheHeader($state, $uf) || !$state['rows']) { return $result; }
        $result['service_count'] = count($state['rows']);
        $identity = null; $mixed = false;
        foreach ($state['rows'] as $nbs => $row) {
            if (!is_array($row)) { return $result; }
            try { $row = self::validateSnapshot($row, $uf, (string) $nbs, self::date($row['valid_from'] ?? '')); }
            catch (\Throwable $error) { return $result; }
            $current = array_intersect_key($row, array_flip(['versao', 'chave', 'fonte', 'valid_from', 'valid_to', 'fetched_at', 'source_hash']));
            if ($identity === null) { $identity = $current; }
            elseif ($identity !== $current) { $mixed = true; }
        }
        if ($mixed) { $result['status'] = 'mixed'; return $result; }
        $result = array_replace($result, ['version' => $identity['versao'], 'key' => $identity['chave'], 'source' => $identity['fonte'],
            'valid_from' => $identity['valid_from'], 'valid_to' => $identity['valid_to'], 'fetched_at' => $identity['fetched_at'], 'source_hash' => $identity['source_hash']]);
        $result['status'] = $date < $result['valid_from'] ? 'future' : ($date > $result['valid_to'] ? 'expired' : 'current');
        return $result;
    }

    private static function outcomeMessage(string $outcome, string $uf): string
    {
        return match ($outcome) {
            'imported' => 'Tabela IBPT de ' . $uf . ' importada e disponível localmente.',
            'imported_older' => 'Versão anterior da tabela IBPT de ' . $uf . ' importada por escolha do emitente. Atualização automática suspensa.',
            'downgraded' => 'A fonte retornou uma versão IBPT anterior, instalada por escolha do emitente. Atualização automática suspensa.',
            'updated' => 'Tabela IBPT de ' . $uf . ' atualizada e disponível localmente.',
            'unchanged' => 'A fonte retornou a mesma tabela IBPT de ' . $uf . '. A cópia local foi mantida.',
            'expired' => 'A fonte retornou uma tabela IBPT de ' . $uf . ' vencida. A tabela armazenada permanece disponível para cálculo e emissão.',
            'future' => 'A fonte retornou uma tabela IBPT de ' . $uf . ' com vigência futura.',
            'mixed' => 'A fonte retornou metadados diferentes entre os serviços. Confira o NBS utilizado na emissão.',
            'regressive' => 'A fonte retornou uma tabela anterior ou inconsistente. A cópia local mais recente foi preservada.',
            'failed', 'invalid' => 'Não foi possível atualizar a tabela IBPT de ' . $uf . '. A cópia local anterior foi preservada, inclusive se vencida.',
            default => '',
        };
    }

    private static function timestamp(mixed $value): int { return is_int($value) && $value > 0 ? $value : 0; }
    private static function attemptAt(array $state, int $now): int
    {
        $value = self::timestamp($state['last_attempt_at'] ?? $state['fetched_at'] ?? null);
        return $value <= $now ? $value : 0;
    }
    private static function manualAt(array $state, int $now): int
    {
        $attempt = self::attemptAt($state, $now);
        return $attempt > 0 ? $attempt + self::MANUAL_RETRY_SECONDS : 0;
    }
    private static function iso(int $timestamp): ?string { return $timestamp > 0 ? gmdate('Y-m-d\\TH:i:s\\Z', $timestamp) : null; }
    private static function today(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('America/Bahia'))->format('Y-m-d');
    }

    /** Validate and allowlist a saved quote without changing it or contacting the source. */
    public static function validateSnapshot(array $snapshot, string $uf, string $nbs, string $date): array
    {
        $uf = self::uf($uf); $nbs = self::nbs($nbs); $date = self::date($date);
        if (($snapshot['uf'] ?? null) !== $uf || ($snapshot['nbs'] ?? null) !== $nbs) {
            throw new \InvalidArgumentException('A estimativa IBPT não corresponde à UF e ao NBS desta nota.');
        }
        $result = ['uf' => $uf, 'nbs' => $nbs];
        foreach (['description' => 2000, 'chave' => 32, 'versao' => 40, 'fonte' => 150] as $key => $limit) {
            $result[$key] = self::text($snapshot[$key] ?? null, $limit);
        }
        if (!preg_match('/\A[A-Za-z0-9]{1,32}\z/D', $result['chave'])
            || !preg_match('/\A[A-Za-z0-9._-]{1,40}\z/D', $result['versao'])) {
            throw new \InvalidArgumentException('A tabela IBPT não informou uma chave e versão válidas.');
        }
        foreach (['nacionalfederal', 'estadual', 'municipal'] as $key) {
            if (!is_string($snapshot[$key] ?? null)) { throw new \InvalidArgumentException('Alíquota inválida na tabela IBPT.'); }
            $result[$key] = self::rate($snapshot[$key]);
        }
        $result['valid_from'] = self::date($snapshot['valid_from'] ?? '');
        $result['valid_to'] = self::date($snapshot['valid_to'] ?? '');
        if ($result['valid_to'] < $result['valid_from']) { throw new \InvalidArgumentException('Vigência inválida na tabela IBPT.'); }
        // Expiration is informative only. Future-effective tables remain unavailable until their start date.
        if ($date < $result['valid_from']) { throw self::validity($uf, $result['valid_from'], $result['valid_to'], $date); }
        $result['fetched_at'] = self::text($snapshot['fetched_at'] ?? null, 20);
        $fetched = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $result['fetched_at'], new \DateTimeZone('UTC'));
        if (!$fetched || $fetched->format('Y-m-d\TH:i:s\Z') !== $result['fetched_at']) {
            throw new \InvalidArgumentException('Data de obtenção da tabela IBPT inválida.');
        }
        $result['source_hash'] = self::text($snapshot['source_hash'] ?? null, 64);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $result['source_hash'])) { throw new \InvalidArgumentException('Identificação da tabela IBPT inválida.'); }
        return $result;
    }

    /** Parse the supplied CSV, retaining service rows only. Expired rows stay inspectable in the cache. */
    public static function parse(string $raw, string $uf, int $fetchedAt): array
    {
        $uf = self::uf($uf);
        if ($fetchedAt < 1 || strlen($raw) < 40 || strlen($raw) > self::MAX_BYTES || str_contains($raw, "\0")
            || str_starts_with($raw, "\xff\xfe") || str_starts_with($raw, "\xfe\xff")) {
            throw new \InvalidArgumentException('Arquivo da tabela IBPT inválido ou acima do limite permitido.');
        }
        $hash = hash('sha256', $raw);
        if (str_starts_with($raw, "\xef\xbb\xbf")) { $raw = substr($raw, 3); }
        if (!preg_match('//u', $raw)) { $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); }
        if (!preg_match('//u', $raw) || strlen($raw) > self::MAX_BYTES * 3) { throw new \InvalidArgumentException('Codificação inválida na tabela IBPT.'); }
        $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
        if (!is_resource($stream)) { throw new \RuntimeException('Não foi possível ler a tabela IBPT.'); }
        try {
            if (fwrite($stream, $raw) !== strlen($raw)) { throw new \RuntimeException('Não foi possível ler a tabela IBPT.'); }
            rewind($stream);
            $header = fgetcsv($stream, 16384, ';', '"', '');
            if (!is_array($header)) { throw new \InvalidArgumentException('Cabeçalho da tabela IBPT inválido.'); }
            $header = array_map(static fn($value): string => strtolower(trim((string) $value)), $header);
            if (count($header) !== count(self::COLUMNS) || count(array_unique($header)) !== count($header)
                || array_diff(self::COLUMNS, $header) || array_diff($header, self::COLUMNS)) {
                throw new \InvalidArgumentException('O formato da tabela IBPT não contém os campos necessários.');
            }
            $result = []; $count = 0; $stamp = gmdate('Y-m-d\TH:i:s\Z', $fetchedAt);
            while (($line = fgetcsv($stream, 16384, ';', '"', '')) !== false) {
                if ($line === [null] || $line === ['']) { continue; }
                if (++$count > self::MAX_ROWS || count($line) !== count($header)) { throw new \InvalidArgumentException('Quantidade de registros ou colunas inválida na tabela IBPT.'); }
                $row = array_combine($header, $line);
                $type = trim((string) $row['tipo']);
                if (!in_array($type, ['0', '1', '2'], true)) { throw new \InvalidArgumentException('Tipo de registro inválido na tabela IBPT.'); }
                if ($type !== '1') { continue; }
                $nbs = self::nbs(trim((string) $row['codigo']));
                if (trim((string) $row['ex']) !== '' || isset($result[$nbs])) { throw new \InvalidArgumentException('A tabela IBPT contém um NBS duplicado ou ambíguo.'); }
                $snapshot = ['uf' => $uf, 'nbs' => $nbs, 'description' => trim((string) $row['descricao']),
                    'nacionalfederal' => trim((string) $row['nacionalfederal']), 'estadual' => trim((string) $row['estadual']),
                    'municipal' => trim((string) $row['municipal']), 'chave' => trim((string) $row['chave']),
                    'versao' => trim((string) $row['versao']), 'fonte' => trim((string) $row['fonte']),
                    'valid_from' => self::csvDate((string) $row['vigenciainicio']), 'valid_to' => self::csvDate((string) $row['vigenciafim']),
                    'fetched_at' => $stamp, 'source_hash' => $hash];
                // Use the table's first valid day for structural validation. Lookup checks the real emission day.
                $result[$nbs] = self::validateSnapshot($snapshot, $uf, $nbs, $snapshot['valid_from']);
                if (count($result) > self::MAX_SERVICES) { throw new \InvalidArgumentException('A tabela IBPT excede o limite de serviços.'); }
            }
            if (!$result) { throw new \InvalidArgumentException('A tabela IBPT não contém serviços classificados por NBS.'); }
            return $result;
        } finally { fclose($stream); }
    }

    private static function cacheHeader(array $state, string $uf): bool
    {
        return ($state['version'] ?? null) === 1 && ($state['uf'] ?? null) === $uf
            && is_array($state['rows'] ?? null) && count($state['rows']) <= self::MAX_SERVICES;
    }

    private static function cached(array $state, string $uf, string $nbs, string $date): ?array
    {
        if (!self::cacheHeader($state, $uf) || !is_array($state['rows'][$nbs] ?? null)) { return null; }
        try { return self::validateSnapshot($state['rows'][$nbs], $uf, $nbs, $date); }
        catch (\Throwable $error) { return null; }
    }

    private static function unavailable(array $state, string $uf, string $nbs, string $date): \RuntimeException
    {
        if (self::cacheHeader($state, $uf)) {
            if (!isset($state['rows'][$nbs])) { return self::missing($uf, $nbs); }
            $row = $state['rows'][$nbs];
            if (is_array($row)) {
                try {
                    $from = self::date($row['valid_from'] ?? ''); $to = self::date($row['valid_to'] ?? '');
                    if ($date < $from || $date > $to) { return self::validity($uf, $from, $to, $date); }
                } catch (\Throwable $error) { }
            }
        }
        return new \RuntimeException('Não foi possível obter uma tabela IBPT válida para ' . $uf . '. Tente novamente em alguns minutos antes de emitir com tributos aproximados.', 503);
    }

    private static function missing(string $uf, string $nbs): \RuntimeException
    {
        return new \RuntimeException('O NBS ' . $nbs . ' não foi encontrado na tabela IBPT de ' . $uf . '. Confira a classificação do serviço.', 422);
    }

    private static function validity(string $uf, string $from, string $to, string $date): \RuntimeException
    {
        $label = static fn(string $value): string => substr($value, 8, 2) . '/' . substr($value, 5, 2) . '/' . substr($value, 0, 4);
        return new \RuntimeException($date > $to
            ? 'A tabela IBPT de ' . $uf . ' tem vigência encerrada em ' . $label($to) . '. Atualize a fonte antes de emitir com tributos aproximados.'
            : 'A tabela IBPT de ' . $uf . ' inicia a vigência em ' . $label($from) . ' e ainda não pode ser usada nesta emissão.', 422);
    }

    public static function uf(string $value): string
    {
        $value = strtoupper(trim($value));
        if (!in_array($value, explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'), true)) {
            throw new \InvalidArgumentException('Informe a UF do estabelecimento emitente para consultar os tributos aproximados.');
        }
        return $value;
    }

    public static function nbs(string $value): string
    {
        if (!preg_match('/\A[0-9]{9}\z/D', $value)) { throw new \InvalidArgumentException('Informe o NBS de 9 dígitos do serviço para calcular os tributos aproximados pelo IBPT.'); }
        return $value;
    }

    private static function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) { throw new \InvalidArgumentException('Data de referência da tabela IBPT inválida.'); }
        return $value;
    }

    private static function csvDate(string $value): string
    {
        $value = trim($value); $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        if (!$date || $date->format('d/m/Y') !== $value) { throw new \InvalidArgumentException('Vigência inválida na tabela IBPT.'); }
        return $date->format('Y-m-d');
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || $value === '' || !preg_match('//u', $value)
            || mb_strlen($value, 'UTF-8') > $limit || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
            throw new \InvalidArgumentException('Identificação ou descrição inválida na tabela IBPT.');
        }
        return $value;
    }

    private static function rate(string $value): string
    {
        if (!preg_match('/\A[0-9]{1,3}(?:[.,][0-9]{1,4})?\z/D', $value)) { throw new \InvalidArgumentException('Alíquota inválida na tabela IBPT.'); }
        $value = Decimal::normalize($value, 4);
        if ((int) str_replace('.', '', $value) > 1000000) { throw new \InvalidArgumentException('Alíquota da tabela IBPT fora do intervalo de 0 a 100%.'); }
        return $value;
    }

    private static function request(string $url, int $timeout): string
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('Consulta de tributos indisponível.'); }
        $curl = curl_init($url); $body = '';
        curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT_MS => 3000, CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: text/csv, text/plain;q=0.9'], CURLOPT_USERAGENT => 'Perfex-NFSe-Nacional/IBPT',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        try {
            $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status !== 200) { throw new \RuntimeException('Consulta de tributos indisponível.', $status); }
            return $body;
        } finally { curl_close($curl); }
    }

    private function record(string $level, string $message, string $uf, string $nbs, float $started,
        int $status, int $bytes, string $hash, array $snapshot = []): void
    {
        if ($this->logger === null) { return; }
        $context = ['method' => 'GET', 'endpoint' => '/tabela/ibpt/' . $uf, 'service' => 'ibpt', 'uf' => $uf, 'nbs' => $nbs,
            'http_status' => $status, 'duration_ms' => (microtime(true) - $started) * 1000, 'response_bytes' => $bytes];
        if ($hash !== '') { $context['response_sha256'] = $hash; }
        foreach (['chave' => 'ibpt_key', 'versao' => 'ibpt_version', 'valid_from' => 'ibpt_valid_from', 'valid_to' => 'ibpt_valid_to'] as $from => $to) {
            if (isset($snapshot[$from])) { $context[$to] = $snapshot[$from]; }
        }
        try { ($this->logger)($level, $message, $context); } catch (\Throwable $ignored) { }
    }
}
