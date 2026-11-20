<?php
declare(strict_types=1);

namespace NfseNacional;

/**
 * Read-only ADN taxpayer distribution. A cursor belongs to a complete CPF/CNPJ
 * and environment; the certificate's CNPJ root never broadens that scope.
 *
 * Transport doubles have the same signature as ApiClient: (method, URL, null,
 * Certificate) -> ['status' => int, 'body' => string, 'headers' => array|string].
 * No request, document body, personal identifier or remote error text is logged.
 */
final class DistributionClient
{
    private const MAX_NSU = '9223372036854775807'; // OpenAPI int64, never a float.
    private const MAX_RESPONSE = 16777216;
    private const MAX_XML = 5242880;
    private const MAX_TOTAL_XML = 33554432;
    private const MAX_ITEMS = 100; // Defensive limit, not a claim about the server's batch size.
    private const EMPTY_RETRY = 3600;
    private const RATE_RETRY = 60;

    private string $environment;
    private Certificate $certificate;
    private $transport;
    private $observer;
    /** Transport-only diagnostics: numeric timing and cURL error class, never URL or credentials. */
    private array $transportDiagnostics = [];

    public function __construct(string $environment, Certificate $certificate, ?callable $transport = null, ?callable $observer = null)
    {
        if (!in_array($environment, ['1', '2'], true)) { throw new \InvalidArgumentException('Ambiente fiscal inválido.'); }
        $this->environment = $environment;
        $this->certificate = $certificate;
        $this->transport = $transport;
        $this->observer = $observer;
    }

    /**
     * Return one validated batch, without saving or retrying it.
     *
     * next_nsu is only a proposed cursor: the caller must first persist the whole
     * batch. has_more means another query is eligible after a nonempty batch; it
     * does NOT assert that further documents exist. ADN exposes no maxNSU here.
     * empty=true means explicit fiscal absence, never a transport failure/429.
     * HTTP 429 returns empty=false and an unchanged cursor with retry_after.
     * Invalid input throws InvalidArgumentException; remote failures throw
     * ApiException. All NSUs are canonical decimal strings, including on PHP 32-bit.
     *
     * @return array{items:array, next_nsu:string, has_more:bool, empty:bool, retry_after:int}
     */
    public function distribute(string $lastNsu, string $taxId): array
    {
        $this->transportDiagnostics = [];
        $lastNsu = self::normalizeNsu($lastNsu);
        $taxId = self::normalizeTaxId($taxId);
        $this->certificate->assertUsable($taxId);
        $parameter = strlen($taxId) === 14 ? 'cnpjConsulta' : 'cpfConsulta';
        $base = $this->environment === '1'
            ? 'https://adn.nfse.gov.br/contribuintes'
            : 'https://adn.producaorestrita.nfse.gov.br/contribuintes';
        $url = $base . '/DFe/' . $lastNsu . '?lote=true&' . $parameter . '=' . rawurlencode($taxId);
        $event = [
            'operation' => 'api.distribution', 'environment' => $this->environment,
            'service' => 'adn_contribuintes', 'method' => 'GET',
            'endpoint' => $base . '/DFe/{NSU}?lote=true&' . $parameter . '={inscricao}',
            'http_status' => 0, 'duration_ms' => 0, 'request_bytes' => 0,
            'response_bytes' => 0, 'request_sha256' => hash('sha256', ''),
            'response_sha256' => null, 'response_type' => 'transport_error',
            'operation_status' => 'uncertain', 'error_codes' => [], 'errors' => [],
            'generation_date_fallbacks' => 0, 'batch_items' => 0, 'stage' => 'transport',
        ];
        $started = hrtime(true);
        try {
            try {
                $response = $this->transport !== null
                    ? ($this->transport)('GET', $url, null, $this->certificate)
                    : $this->curl($url);
            } catch (\Throwable $error) {
                // Transport messages may contain a query string, proxy credentials or PEM paths.
                throw new ApiException('Não foi possível concluir a consulta de documentos no ambiente nacional. Tente novamente mais tarde.', 0, true);
            }
            if (!is_array($response)) { throw new ApiException('A consulta nacional retornou uma resposta de transporte inválida.', 0, true); }
            $status = isset($response['status']) && is_int($response['status']) ? $response['status'] : 0;
            $event['http_status'] = $status;
            $event['stage'] = 'http_response';
            $raw = $response['body'] ?? null;
            if (!is_string($raw) || strlen($raw) > self::MAX_RESPONSE) {
                $event['response_type'] = is_string($raw) ? 'oversized' : 'invalid_body';
                throw new ApiException('A resposta da consulta nacional excede o limite seguro ou está inválida.', $status, true);
            }
            $event['response_bytes'] = strlen($raw);
            $event['response_sha256'] = hash('sha256', $raw);
            $event['response_type'] = self::responseType($raw);

            // A gateway may rate-limit without the fiscal JSON envelope. This is
            // only a transport pause: no documents or absence are accepted from it.
            if ($status === 429) {
                try { $limited = json_decode($raw, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
                catch (\Throwable $ignored) { $limited = null; }
                if ($limited instanceof \stdClass && property_exists($limited, 'TipoAmbiente')) {
                    $this->assertEnvironment((array) $limited, $status);
                }
                $event['operation_status'] = 'rate_limited';
                $event['error_codes'] = ['HTTP429'];
                $event['errors'] = [['code' => 'HTTP429', 'message' => 'O ambiente nacional solicitou uma pausa antes da próxima consulta.']];
                return self::result([], $lastNsu, false, false, self::retryAfter($response['headers'] ?? [], self::RATE_RETRY, false));
            }

            $event['stage'] = 'json_decode';
            try { $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (\Throwable $error) { throw new ApiException('A consulta nacional não retornou um JSON válido (HTTP ' . $status . '). A consulta permanece inconclusiva.', $status, true); }
            if (!$decoded instanceof \stdClass) {
                throw new ApiException('A consulta nacional retornou um formato inesperado. A consulta permanece inconclusiva.', $status, true);
            }
            $data = (array) $decoded;
            $event['stage'] = 'envelope_validation';
            $this->assertEnvironment($data, $status);
            $processing = $data['StatusProcessamento'] ?? null;
            if (in_array($processing, ['REJEICAO', 'NENHUM_DOCUMENTO_LOCALIZADO', 'DOCUMENTOS_LOCALIZADOS'], true)) {
                $event['processing_status'] = $processing;
            }
            if (!in_array($processing, ['REJEICAO', 'NENHUM_DOCUMENTO_LOCALIZADO', 'DOCUMENTOS_LOCALIZADOS'], true)) {
                throw new ApiException('A consulta nacional não identifica uma situação de processamento válida.', $status, true);
            }
            $errors = self::messages($data['Erros'] ?? null, $status);
            $alerts = self::messages($data['Alertas'] ?? null, $status);
            $allMessages = array_merge($errors, $alerts);
            $items = $data['LoteDFe'] ?? [];
            if ($items === null) { $items = []; }
            if (is_array($items)) { $event['batch_items'] = min(count($items), self::MAX_ITEMS); }
            if (!is_array($items) || count($items) > self::MAX_ITEMS) {
                throw new ApiException('O lote nacional está inválido ou ultrapassa o limite seguro de documentos.', $status, true);
            }
            $onlyAbsent = $allMessages !== [];
            foreach ($allMessages as $message) {
                if ($message['code'] !== 'E2220') { $onlyAbsent = false; }
            }
            $absence = !$items && (
                ($status === 404 && $onlyAbsent && in_array($processing, ['REJEICAO', 'NENHUM_DOCUMENTO_LOCALIZADO'], true))
                || ($status === 200 && $processing === 'NENHUM_DOCUMENTO_LOCALIZADO' && (!$allMessages || $onlyAbsent))
            );
            if ($absence) {
                $event['operation_status'] = 'not_found';
                $event['error_codes'] = $onlyAbsent ? ['E2220'] : [];
                $event['errors'] = $onlyAbsent ? [['code' => 'E2220', 'message' => self::errorMessage('E2220')]] : [];
                return self::result([], $lastNsu, false, true, self::retryAfter($response['headers'] ?? [], self::EMPTY_RETRY));
            }
            if ($status !== 200 || $processing !== 'DOCUMENTOS_LOCALIZADOS' || $errors || !$items) {
                $uncertain = $status === 0 || $status >= 500 || in_array($status, [200, 204, 408, 409], true) || !$errors;
                // A structured permission error is explicit; an opaque 404 is not absence.
                throw new ApiException('Não foi possível concluir a consulta de documentos no ambiente nacional (HTTP ' . $status . ').'
                    . ($errors ? ' ' . implode(' ', array_unique(array_column($errors, 'message'))) : ' A consulta permanece inconclusiva.'),
                    $status, $uncertain, $errors);
            }
            foreach ($alerts as $alert) {
                if ($alert['code'] === 'E2220') { throw new ApiException('O lote nacional contém informações contraditórias sobre a existência de documentos.', $status, true); }
            }

            $event['stage'] = 'document_validation';
            $validated = []; $cursor = $lastNsu; $totalXml = 0;
            foreach ($items as $item) {
                if (!$item instanceof \stdClass) { throw new ApiException('O lote nacional contém um documento ilegível.', $status, true); }
                $item = (array) $item;
                $value = $item['NSU'] ?? null;
                if (!is_string($value) && !is_int($value)) { throw new ApiException('O lote nacional contém um identificador de sequência inválido.', $status, true); }
                try { $nsu = self::normalizeNsu((string) $value); }
                catch (\InvalidArgumentException $error) { throw new ApiException('O lote nacional contém um identificador de sequência inválido.', $status, true); }
                if (self::compareNsu($nsu, $cursor) <= 0) {
                    throw new ApiException('A sequência dos documentos nacionais está repetida ou fora de ordem. O lote não foi confirmado.', $status, true);
                }
                $generationDateFallback = false;
                $document = $this->decodeItem($item, $generationDateFallback);
                if ($generationDateFallback) { ++$event['generation_date_fallbacks']; }
                $totalXml += strlen($document['xml']);
                if ($totalXml > self::MAX_TOTAL_XML) { throw new ApiException('Os documentos nacionais ultrapassam o limite seguro de leitura.', $status, true); }
                $document['nsu'] = $nsu;
                $validated[] = $document;
                $cursor = $nsu;
            }
            $event['operation_status'] = 'success';
            $event['stage'] = 'validated';
            return self::result($validated, $cursor, true, false, 0);
        } catch (ApiException $error) {
            if ($event['http_status'] === 0) { $event['http_status'] = $error->httpStatus(); }
            $event['operation_status'] = $error->isUncertain() ? 'uncertain' : 'error';
            $event['errors'] = $error->errors() ?: [['code' => '', 'message' => $error->getMessage()]];
            $event['error_codes'] = array_values(array_unique(array_filter(array_column($event['errors'], 'code'))));
            throw $error;
        } finally {
            $event = array_merge($event, $this->transportDiagnostics);
            $event['duration_ms'] = (int) round((hrtime(true) - $started) / 1000000);
            if ($this->observer !== null) {
                try { ($this->observer)($event); }
                catch (\Throwable $ignored) { /* An audit failure never triggers another query or changes the result. */ }
            }
        }
    }

    /** Canonical unsigned decimal int64 string; rejects signs, whitespace and float notation. */
    public static function normalizeNsu(string $value): string
    {
        if (!preg_match('/^[0-9]{1,19}$/D', $value)) { throw new \InvalidArgumentException('Identificador de sequência inválido.'); }
        $value = ltrim($value, '0');
        if ($value === '') { return '0'; }
        if (strlen($value) === 19 && strcmp($value, self::MAX_NSU) > 0) {
            throw new \InvalidArgumentException('Identificador de sequência fora do intervalo permitido.');
        }
        return $value;
    }

    /** Numeric comparison without casting: -1, 0 or 1. Inputs obey normalizeNsu(). */
    public static function compareNsu(string $left, string $right): int
    {
        $left = self::normalizeNsu($left); $right = self::normalizeNsu($right);
        return strlen($left) === strlen($right) ? (strcmp($left, $right) <=> 0) : (strlen($left) <=> strlen($right));
    }

    private static function normalizeTaxId(string $value): string
    {
        if (strlen($value) > 64 || !preg_match('/^[0-9A-Za-z.\/\-\s]+$/D', $value)) {
            throw new \InvalidArgumentException('Informe o CPF/CNPJ válido da empresa para consultar seus documentos.');
        }
        $value = preg_replace('/[.\/\-\s]/', '', strtoupper($value)) ?? '';
        if (!Validator::taxId($value)) { throw new \InvalidArgumentException('Informe o CPF/CNPJ válido da empresa para consultar seus documentos.'); }
        return $value;
    }

    private function assertEnvironment(array $data, int $status): void
    {
        $expected = $this->environment === '1' ? 'PRODUCAO' : 'HOMOLOGACAO';
        if (($data['TipoAmbiente'] ?? null) !== $expected) {
            throw new ApiException('A resposta nacional pertence a outro ambiente ou não identifica o ambiente consultado.', $status, true);
        }
    }

    private function decodeItem(array $item, bool &$generationDateFallback): array
    {
        $kind = $item['TipoDocumento'] ?? null;
        if (!in_array($kind, ['NFSE', 'EVENTO', 'DPS', 'PEDIDO_REGISTRO_EVENTO', 'CNC'], true)) {
            throw new ApiException('O lote nacional contém um tipo de documento não reconhecido.', 200, true);
        }
        $key = $item['ChaveAcesso'] ?? '';
        if ($key === null) { $key = ''; }
        if (!is_string($key) || ($key !== '' && !preg_match('/^[0-9A-Z]{50}$/D', $key))
            || ($key === '' && in_array($kind, ['NFSE', 'EVENTO'], true))) {
            throw new ApiException('O documento nacional não contém uma chave de acesso válida.', 200, true);
        }
        $encoded = $item['ArquivoXml'] ?? null;
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_RESPONSE) {
            throw new ApiException('O documento nacional não contém o XML esperado.', 200, true);
        }
        $compressed = base64_decode($encoded, true);
        if (!is_string($compressed) || substr($compressed, 0, 2) !== "\x1f\x8b") {
            throw new ApiException('O documento nacional não está no formato GZip/Base64 esperado.', 200, true);
        }
        $xml = @gzdecode($compressed, self::MAX_XML + 1);
        if (!is_string($xml) || strlen($xml) > self::MAX_XML) {
            throw new ApiException('Não foi possível descompactar o documento nacional com segurança.', 200, true);
        }
        try { $doc = XmlSigner::loadSafe($xml); }
        catch (\Throwable $error) { throw new ApiException('O documento nacional contém um XML inválido ou inseguro.', 200, true); }
        $root = $doc->documentElement;
        if (!$root || $root->namespaceURI !== XmlSigner::NFSE_NS) {
            throw new ApiException('O documento recebido não pertence ao padrão nacional da NFS-e.', 200, true);
        }
        $expectedRoot = ['NFSE' => 'NFSe', 'EVENTO' => 'evento', 'DPS' => 'DPS', 'PEDIDO_REGISTRO_EVENTO' => 'pedRegEvento'];
        if ((isset($expectedRoot[$kind]) && $root->localName !== $expectedRoot[$kind])
            || ($kind === 'CNC' && in_array($root->localName, array_values($expectedRoot), true))) {
            throw new ApiException('O tipo do documento nacional diverge do XML recebido.', 200, true);
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('n', XmlSigner::NFSE_NS);
        $environments = $xpath->query('//*[local-name()="tpAmb"]');
        foreach ($environments as $environment) {
            if ($environment->namespaceURI !== XmlSigner::NFSE_NS || $environment->getElementsByTagName('*')->length > 0
                || trim($environment->textContent) !== $this->environment) {
                throw new ApiException('O XML nacional contém um ambiente diferente do consultado.', 200, true);
            }
        }
        $eventType = '';
        if ($kind === 'NFSE') {
            foreach (['NFSe', 'infNFSe', 'DPS', 'infDPS'] as $group) {
                if ($xpath->query('//*[local-name()="' . $group . '"]')->length !== 1) {
                    throw new ApiException('A NFS-e nacional contém grupos fiscais ausentes ou duplicados.', 200, true);
                }
            }
            if ($xpath->evaluate('string(/n:NFSe/n:infNFSe/@Id)') !== 'NFS' . $key
                || $xpath->query('/n:NFSe/n:infNFSe/n:DPS/n:infDPS/n:tpAmb')->length !== 1 || $environments->length !== 1) {
                throw new ApiException('A chave de acesso ou o ambiente não corresponde ao XML da NFS-e nacional.', 200, true);
            }
        } elseif ($kind === 'EVENTO') {
            try { $event = FiscalEvent::fromXml($xml, $key, $this->environment); }
            catch (\Throwable $error) { throw new ApiException('O XML recebido não confirma um evento registrado para a NFS-e e o ambiente consultados.' . ($error instanceof FiscalEventException ? ' ' . $error->getMessage() : ''), 200, true); }
            $eventType = $event['type'];
        } elseif (in_array($kind, ['DPS', 'PEDIDO_REGISTRO_EVENTO'], true)) {
            $path = $kind === 'DPS' ? '/n:DPS/n:infDPS' : '/n:pedRegEvento/n:infPedReg';
            if ($xpath->query($path)->length !== 1 || $xpath->query($path . '/n:tpAmb')->length !== 1 || $environments->length !== 1
                || ($kind === 'PEDIDO_REGISTRO_EVENTO' && $key !== '' && $xpath->evaluate('string(' . $path . '/n:chNFSe)') !== $key)) {
                throw new ApiException('O XML nacional não confirma a identificação e o ambiente do documento.', 200, true);
            }
        }
        // DataHoraGeracao is advisory ADN envelope metadata, not the date that
        // authorizes the document. Some production responses omit a timezone or
        // serialize it differently. Do not reject an otherwise validated batch:
        // recover a trustworthy timestamp from the XML already checked above.
        // NSU, access key, fiscal environment, XML and gzip validation remain strict.
        $generated = self::generationDate($item['DataHoraGeracao'] ?? null, $xpath, $kind, $generationDateFallback);
        // The numeric event type comes from the correlated registered XML, never
        // from the descriptive TipoEvento label (which is not fiscal proof).
        return ['nsu' => '', 'access_key' => $key, 'type' => $kind === 'NFSE' ? 'nfse' : ($kind === 'EVENTO' ? 'event' : 'other'),
            'xml' => $xml, 'event_type' => $eventType, 'generated_at' => $generated];
    }

    /** Envelope timestamp is optional metadata; only the correlated XML can supply a fallback. */
    private static function generationDate($metadata, \DOMXPath $xpath, string $kind, bool &$fallback): string
    {
        $fallback = false;
        if (is_string($metadata) && self::validTimestamp($metadata)) { return $metadata; }
        $fallback = true;
        $paths = [
            'NFSE' => '/n:NFSe/n:infNFSe/n:dhProc',
            'EVENTO' => '/n:evento/n:infEvento/n:dhProc',
            'DPS' => '/n:DPS/n:infDPS/n:dhEmi',
            'PEDIDO_REGISTRO_EVENTO' => '/n:pedRegEvento/n:infPedReg/n:dhEvento',
        ];
        if (!isset($paths[$kind])) { return ''; }
        $nodes = $xpath->query($paths[$kind]);
        if (!$nodes || $nodes->length !== 1 || $nodes->item(0)->getElementsByTagName('*')->length !== 0) { return ''; }
        $value = trim((string) $nodes->item(0)->textContent);
        return self::validTimestamp($value) ? $value : '';
    }

    private static function validTimestamp(string $value): bool
    {
        // ISO-8601 / RFC-3339 supports fractional seconds up to nanosecond precision.
        // Without an offset, a date cannot be interpreted unambiguously as an instant.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value)) { return false; }
        try { return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:s') === substr($value, 0, 19); }
        catch (\Throwable $error) { return false; }
    }

    private static function result(array $items, string $nsu, bool $more, bool $empty, int $retry): array
    {
        return ['items' => $items, 'next_nsu' => $nsu, 'has_more' => $more, 'empty' => $empty, 'retry_after' => $retry];
    }

    /** Only fixed local descriptions are retained; remote strings may contain personal data/XML. */
    private static function messages($messages, int $status): array
    {
        if ($messages === null) { return []; }
        if (!is_array($messages) || count($messages) > 100) { throw new ApiException('A consulta nacional retornou mensagens de processamento inválidas.', $status, true); }
        $result = [];
        foreach ($messages as $message) {
            if (!$message instanceof \stdClass) { throw new ApiException('A consulta nacional retornou uma mensagem de processamento inválida.', $status, true); }
            $code = $message->Codigo ?? '';
            if (!is_string($code) || !preg_match('/^E[0-9]{3,5}$/D', $code)) { $code = ''; }
            $result[] = ['code' => $code, 'message' => self::errorMessage($code)];
        }
        return $result;
    }

    private static function errorMessage(string $code): string
    {
        $messages = [
            'E2210' => 'O ambiente nacional recusou o certificado de transmissão.',
            'E2211' => 'O certificado de transmissão está revogado.',
            'E2212' => 'A cadeia do certificado não foi aceita pelo ambiente nacional.',
            'E2213' => 'O ambiente nacional identificou um certificado vencido.',
            'E2214' => 'O ambiente nacional não conseguiu consultar a revogação do certificado.',
            'E2220' => 'Nenhum novo documento foi disponibilizado a partir da sequência consultada.',
            'E2230' => 'Não há documento para a sequência exata informada.',
            'E2240' => 'Não foram localizados documentos para a chave consultada.',
            'E2241' => 'O documento não está disponível para o contribuinte consultado.',
            'E2243' => 'O certificado não tem o vínculo exigido com o contribuinte consultado.',
            'E2244' => 'O ambiente nacional recusou os parâmetros de identificação da consulta.',
            'E2245' => 'O certificado não possui a procuração necessária para esta consulta.',
            'E2246' => 'O ambiente nacional recusou o CNPJ informado.',
            'E2247' => 'O ambiente nacional recusou o CPF informado.',
            'E999' => 'O ambiente nacional encontrou uma falha interna ao processar a consulta.',
        ];
        return ($code !== '' ? $code . ': ' : '') . ($messages[$code] ?? 'O ambiente nacional retornou uma mensagem de processamento não reconhecida.');
    }

    private static function responseType(string $body): string
    {
        $body = ltrim($body);
        if ($body === '') { return 'empty'; }
        if ($body[0] === '{') { return 'json_object'; }
        if ($body[0] === '[') { return 'json_array'; }
        if (preg_match('/^<(?:!doctype\s+html|html)\b/i', $body)) { return 'html'; }
        return $body[0] === '<' ? 'xml' : 'invalid_json';
    }

    private static function retryAfter($headers, int $minimum, bool $strictMinimum = true): int
    {
        if (is_string($headers)) { $headers = preg_split('/\r?\n/', $headers) ?: []; }
        if (!is_array($headers)) { return $minimum; }
        $retry = $strictMinimum ? $minimum : 0; $provided = false;
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value) && preg_match('/^Retry-After\s*:\s*(.*?)\s*$/iD', $value, $match)) { $value = $match[1]; }
            elseif (!is_string($name) || strcasecmp($name, 'Retry-After') !== 0) { continue; }
            foreach (is_array($value) ? $value : [$value] as $candidate) {
                if (!is_string($candidate) && !is_int($candidate)) { continue; }
                $candidate = trim((string) $candidate);
                if (preg_match('/^[0-9]{1,10}$/D', $candidate)) { $seconds = (int) min((float) $candidate, (float) PHP_INT_MAX); }
                else {
                    $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $candidate, new \DateTimeZone('UTC'));
                    if (!$date || $date->format('D, d M Y H:i:s \G\M\T') !== $candidate) { continue; }
                    $seconds = max(0, $date->getTimestamp() - time());
                }
                $provided = true; $retry = max($retry, $seconds);
            }
        }
        return $strictMinimum ? $retry : ($provided ? max(1, $retry) : $minimum);
    }

    /** Only numerical metrics are retained; cURL text can contain proxy paths or secrets. */
    private function curlDiagnostics($handle): array
    {
        $fields = [
            'dns_ms' => 'CURLINFO_NAMELOOKUP_TIME',
            'connect_ms' => 'CURLINFO_CONNECT_TIME',
            'tls_ms' => 'CURLINFO_APPCONNECT_TIME',
            'first_byte_ms' => 'CURLINFO_STARTTRANSFER_TIME',
            'transfer_ms' => 'CURLINFO_TOTAL_TIME',
        ];
        $info = [];
        foreach ($fields as $key => $constant) {
            if (!defined($constant)) { continue; }
            $seconds = curl_getinfo($handle, constant($constant));
            if (is_float($seconds) || is_int($seconds)) { $info[$key] = round(max(0, $seconds) * 1000, 2); }
        }
        return $info;
    }

    private static function curlFailureCategory(int $number): string
    {
        return match ($number) {
            6 => 'dns', 7 => 'connect', 28 => 'timeout', 35 => 'tls_handshake',
            51, 60 => 'tls_verification', 58, 77, 86 => 'certificate',
            52 => 'empty_reply', 55 => 'send_failure', 56 => 'receive_failure',
            23 => 'response_limit', default => 'transport',
        };
    }

    private function curl(string $url): array
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('A extensão cURL do PHP é necessária.'); }
        $handle = curl_init();
        if ($handle === false) { throw new \RuntimeException('Não foi possível inicializar o cliente HTTPS.'); }
        $temporaryFiles = []; $body = ''; $headers = []; $headerBytes = 0;
        try {
            $options = [
                CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => 'GET', CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'Perfex-Nfse-Nacional/ADN-Contribuintes',
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE) { return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$headerBytes): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 32768) { return 0; }
                    if (preg_match('/^HTTP\/\S+\s+\d{3}\b/', $line)) { $headers = []; }
                    elseif (stripos($line, 'Retry-After:') === 0) { $headers[] = trim($line); }
                    return strlen($line);
                },
            ];
            if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
                $options[CURLOPT_SSLCERT_BLOB] = $this->certificate->certificatePem();
                $options[CURLOPT_SSLKEY_BLOB] = $this->certificate->privateKeyPem();
            } else {
                foreach (['certificate' => $this->certificate->certificatePem(), 'key' => $this->certificate->privateKeyPem()] as $kind => $contents) {
                    $file = tempnam(sys_get_temp_dir(), 'nfse_adn_tls_');
                    if ($file === false) { throw new \RuntimeException('Não foi possível preparar o certificado para a conexão.'); }
                    $temporaryFiles[] = $file;
                    if (!chmod($file, 0600) || file_put_contents($file, $contents, LOCK_EX) !== strlen($contents)) {
                        throw new \RuntimeException('Não foi possível proteger o certificado temporário.');
                    }
                    $options[$kind === 'certificate' ? CURLOPT_SSLCERT : CURLOPT_SSLKEY] = $file;
                }
            }
            $options[CURLOPT_SSLCERTTYPE] = 'PEM';
            $options[CURLOPT_SSLKEYTYPE] = 'PEM';
            curl_setopt_array($handle, $options);
            if (curl_exec($handle) === false) {
                $code = (int) curl_errno($handle);
                $this->transportDiagnostics = $this->curlDiagnostics($handle);
                $this->transportDiagnostics['curl_errno'] = $code;
                $this->transportDiagnostics['transport_category'] = self::curlFailureCategory($code);
                throw new \RuntimeException('A conexão HTTPS não pôde ser concluída.');
            }
            $this->transportDiagnostics = $this->curlDiagnostics($handle);
            return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $body, 'headers' => $headers];
        } finally {
            curl_close($handle);
            foreach ($temporaryFiles as $file) { if (is_file($file)) { @unlink($file); } }
        }
    }
}
