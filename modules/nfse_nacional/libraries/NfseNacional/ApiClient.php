<?php
declare(strict_types=1);

namespace NfseNacional;

/** Fixed official endpoints, mTLS, no redirects, no automatic POST retries. */
final class ApiClient
{
    private string $environment;
    private Certificate $certificate;
    private $transport;
    private $observer;
    private const MAX_RESPONSE = 4194304;
    private const MAX_XML = 16777216;

    public function __construct(string $environment, Certificate $certificate, ?callable $transport = null, ?callable $observer = null)
    {
        if (!in_array($environment, ['1', '2'], true)) { throw new \InvalidArgumentException('Ambiente fiscal inválido.'); }
        $this->environment = $environment;
        $this->certificate = $certificate;
        $this->transport = $transport;
        $this->observer = $observer;
    }

    public function submit(string $signedXml): array
    {
        if (!XmlSigner::verify($signedXml)) { throw new \InvalidArgumentException('Assinatura XML inválida. A DPS não foi transmitida.'); }
        $doc = XmlSigner::loadSafe($signedXml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('n', XmlSigner::NFSE_NS);
        $id = $xp->evaluate('string(/n:DPS/n:infDPS/@Id)');
        if ($xp->evaluate('string(/n:DPS/n:infDPS/n:tpAmb)') !== $this->environment) {
            throw new \InvalidArgumentException('O ambiente da DPS difere do ambiente da conexão.');
        }
        $provider = $xp->evaluate('string(/n:DPS/n:infDPS/n:prest/n:CNPJ)')
            ?: $xp->evaluate('string(/n:DPS/n:infDPS/n:prest/n:CPF)');
        $this->certificate->assertUsable($provider);
        $zip = gzencode($signedXml, 9);
        if ($zip === false) { throw new \RuntimeException('Falha ao compactar a DPS.'); }
        return $this->request('POST', '/nfse', ['dpsXmlGZipB64' => base64_encode($zip)], false, function (array $result) use ($id): void {
            if (($result['idDps'] ?? '') !== $id) {
                throw new ApiException('A resposta não confirma o identificador da DPS enviada. Consulte a situação antes de qualquer nova transmissão.', 200, true);
            }
            $this->assertNfseResult($result);
            $authorizedXml = self::decodeXml($result);
            $authorized = XmlSigner::loadSafe($authorizedXml);
            $ax = new \DOMXPath($authorized);
            $ax->registerNamespace('n', XmlSigner::NFSE_NS);
            if ($ax->evaluate('string(/n:NFSe/n:infNFSe/n:DPS/n:infDPS/@Id)') !== $id) {
                throw new ApiException('O XML retornado não corresponde à DPS enviada. O resultado precisa ser conciliado.', 200, true);
            }
        });
    }

    public function findByDps(string $dpsId): ?array
    {
        if (!preg_match('/^DPS[0-9]{7}(?:1[0-9]{14}|2[0-9A-Z]{14})[0-9]{20}$/D', $dpsId)) {
            throw new \InvalidArgumentException('Identificador de DPS inválido.');
        }
        try {
            return $this->request('GET', '/dps/' . rawurlencode($dpsId), null, false, function (array $result) use ($dpsId): void {
                if (($result['idDps'] ?? '') !== $dpsId || !self::validAccessKey((string) ($result['chaveAcesso'] ?? ''))) {
                    throw new ApiException('A consulta da DPS retornou identificação inconsistente. Tente consultar novamente.', 200, true);
                }
                $this->assertEnvironment($result);
            });
        }
        catch (ApiException $e) { if ($e->httpStatus() === 404 && !$e->isUncertain()) { return null; } throw $e; }
    }

    public function fetch(string $accessKey): array
    {
        if (!self::validAccessKey($accessKey)) { throw new \InvalidArgumentException('A chave de acesso deve conter 50 caracteres alfanuméricos.'); }
        return $this->request('GET', '/nfse/' . rawurlencode($accessKey), null, false, function (array $result) use ($accessKey): void {
            $this->assertNfseResult($result);
            if (($result['chaveAcesso'] ?? '') !== $accessKey) {
                throw new ApiException('A chave retornada não corresponde à nota solicitada.', 200, true);
            }
        });
    }

    /** Read-only consultation of registered events; an opaque 404 is inconclusive.
     * Supports the published XML envelope and the SEFIN eventos[].arquivoXml envelope.
     * If the collection route is unavailable, only the four terminal cancellation
     * types are queried. Empty then means their explicit fiscal absence, never a
     * failed route or unreadable event; it is not a complete event-history export.
     */
    public function getEvents(string $accessKey): array
    {
        if (!self::validAccessKey($accessKey)) { throw new \InvalidArgumentException('A chave de acesso deve conter 50 caracteres alfanuméricos.'); }
        try { return $this->queryEvents($accessKey); }
        catch (ApiException $error) {
            // The manual documents the collection route, while some SEFIN versions
            // expose only the typed GET described by Swagger. Never fall back after
            // a timeout, permission failure, malformed HTTP 200 or server failure.
            if (!in_array($error->httpStatus(), [404, 405], true)) { throw $error; }
        }
        $inconclusive = null;
        foreach (['101101', '105102', '105104', '305101'] as $type) {
            try {
                $events = $this->queryEvents($accessKey, $type);
                // Cancellation is terminal. One correlated registered event is sufficient.
                if ($events) { return $events; }
            } catch (ApiException $error) {
                // A missing/unsupported typed route is not proof of event absence.
                // Continue distinct read-only types to allow a positive fiscal proof.
                if (!in_array($error->httpStatus(), [404, 405], true)) { throw $error; }
                $inconclusive = $inconclusive ?? $error;
            }
        }
        if ($inconclusive !== null) {
            throw new ApiException('Não foi possível confirmar todos os eventos de cancelamento. A situação fiscal anterior foi preservada.',
                $inconclusive->httpStatus(), true, $inconclusive->errors(), $inconclusive);
        }
        // All four terminal cancellation types were explicitly absent in this environment.
        return [];
    }

    private function queryEvents(string $accessKey, ?string $expectedType = null): array
    {
        $events = [];
        $path = '/nfse/' . rawurlencode($accessKey) . '/eventos' . ($expectedType === null ? '' : '/' . $expectedType . '/1');
        try {
            $this->request('GET', $path, null, false,
                function (array $result) use ($accessKey, $expectedType, &$events): void {
                    $this->assertEnvironment($result);
                    if (array_key_exists('eventos', $result)) {
                        $items = $result['eventos'];
                        if (!is_array($items) || !$items || count($items) > 100 || array_keys($items) !== range(0, count($items) - 1)) {
                            throw new ApiException('A consulta não retornou uma lista válida de eventos fiscais. A situação permanece inconclusiva.', 200, true);
                        }
                    } elseif (isset($result['eventoXmlGZipB64'])) { $items = [$result]; }
                    else { throw new ApiException('A consulta não retornou o XML dos eventos fiscais. A situação permanece inconclusiva.', 200, true); }
                    $totalBytes = 0;
                    $ids = [];
                    foreach ($items as $item) {
                        if (!is_array($item)) { throw new ApiException('A consulta retornou um evento fiscal ilegível.', 200, true); }
                        if (isset($item['tipoAmbiente']) && ((!is_string($item['tipoAmbiente']) && !is_int($item['tipoAmbiente'])) || (string) $item['tipoAmbiente'] !== $this->environment)) {
                            throw new ApiException('Um evento retornado pertence a outro ambiente.', 200, true);
                        }
                        $encoded = $item['arquivoXml'] ?? $item['eventoXmlGZipB64'] ?? null;
                        $xml = self::decodeEventXml($encoded, isset($item['arquivoXml']));
                        $totalBytes += strlen($xml);
                        if ($totalBytes > self::MAX_XML) { throw new ApiException('Os eventos ultrapassam o limite seguro de leitura.', 200, true); }
                        try { $event = FiscalEvent::fromXml($xml, $accessKey, $this->environment); }
                        catch (\Throwable $error) { throw new ApiException('O evento recebido não confirma uma situação fiscal válida para esta NFS-e. A consulta permanece inconclusiva.', 200, true, [], $error); }
                        if ($expectedType !== null && ($event['type'] !== $expectedType || $event['sequence'] !== 1 || !$event['cancellation'])) {
                            throw new ApiException('O evento retornado não corresponde ao tipo e sequencial consultados.', 200, true);
                        }
                        foreach (['chaveAcesso' => 'access_key', 'tipoEvento' => 'type', 'numeroPedidoRegistroEvento' => 'sequence'] as $source => $target) {
                            if (isset($item[$source]) && (!is_scalar($item[$source]) || (string) $item[$source] !== (string) $event[$target])) {
                                throw new ApiException('A identificação do evento diverge do XML recebido.', 200, true);
                            }
                        }
                        if (isset($ids[$event['id']])) { throw new ApiException('A consulta retornou um evento fiscal duplicado.', 200, true); }
                        $ids[$event['id']] = true;
                        $events[] = $event;
                    }
                });
        } catch (ApiException $error) {
            if ($error->httpStatus() === 404 && !$error->isUncertain()) { return []; }
            throw $error;
        }
        return $events;
    }

    private static function decodeEventXml($encoded, bool $allowDoubleBase64): string
    {
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_RESPONSE) {
            throw new ApiException('O evento retornado não contém um XML válido.', 200, true);
        }
        $compressed = base64_decode($encoded, true);
        if ($allowDoubleBase64 && is_string($compressed) && substr($compressed, 0, 2) !== "\x1f\x8b") {
            $compressed = base64_decode($compressed, true);
        }
        if (!is_string($compressed) || substr($compressed, 0, 2) !== "\x1f\x8b") {
            throw new ApiException('O evento retornado não está no formato GZip/Base64 esperado.', 200, true);
        }
        $xml = @gzdecode($compressed, 5242881);
        if (!is_string($xml) || strlen($xml) > 5242880) {
            throw new ApiException('Não foi possível descompactar o evento fiscal com segurança.', 200, true);
        }
        return $xml;
    }

    public function municipality(string $municipalityCode): array
    {
        if (!preg_match('/^[0-9]{7}$/D', $municipalityCode)) { throw new \InvalidArgumentException('Informe o código IBGE do município com sete dígitos.'); }
        return $this->request('GET', '/' . $municipalityCode . '/convenio', null, true, static function (array $result): void {
            if (!isset($result['parametrosConvenio']) || !is_array($result['parametrosConvenio']) || !$result['parametrosConvenio']) {
                throw new ApiException('O serviço de parâmetros municipais do ADN não retornou os dados do convênio. A consulta permanece inconclusiva.', 200, true, self::messages($result, true));
            }
        });
    }

    public static function decodeXml(array $response): string
    {
        $encoded = $response['nfseXmlGZipB64'] ?? null;
        if (!is_string($encoded) || strlen($encoded) > self::MAX_RESPONSE) {
            throw new ApiException('A resposta não contém um XML de NFS-e válido.', 200, true);
        }
        $compressed = base64_decode($encoded, true);
        if ($compressed === false || substr($compressed, 0, 2) !== "\x1f\x8b") {
            throw new ApiException('O XML retornado não está no formato GZip/Base64 esperado.', 200, true);
        }
        $xml = @gzdecode($compressed, self::MAX_XML + 1);
        if (!is_string($xml) || strlen($xml) > self::MAX_XML) {
            throw new ApiException('Não foi possível descompactar o XML da NFS-e com segurança.', 200, true);
        }
        try { $doc = XmlSigner::loadSafe($xml); }
        catch (\Throwable $e) { throw new ApiException('A SEFIN retornou um XML inválido. Consulte a situação da DPS.', 200, true, [], $e); }
        if ($doc->documentElement->localName !== 'NFSe' || $doc->documentElement->namespaceURI !== XmlSigner::NFSE_NS) {
            throw new ApiException('A resposta não é um documento NFS-e do padrão nacional.', 200, true);
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('n', XmlSigner::NFSE_NS);
        $key = (string) ($response['chaveAcesso'] ?? '');
        if (!self::validAccessKey($key) || $xp->query('/n:NFSe/n:infNFSe')->length !== 1
            || $xp->evaluate('string(/n:NFSe/n:infNFSe/@Id)') !== 'NFS' . $key) {
            throw new ApiException('A chave de acesso diverge do XML recebido.', 200, true);
        }
        return $xml;
    }

    private function assertNfseResult(array $result): void
    {
        $this->assertEnvironment($result);
        if (!self::validAccessKey((string) ($result['chaveAcesso'] ?? '')) || !isset($result['nfseXmlGZipB64'])) {
            throw new ApiException('A resposta não confirma a autorização da nota. Consulte a DPS antes de reenviar.', 200, true);
        }
        self::decodeXml($result);
    }

    private function assertEnvironment(array $result): void
    {
        $environment = $result['tipoAmbiente'] ?? '';
        if ((!is_string($environment) && !is_int($environment)) || (string) $environment !== $this->environment) {
            throw new ApiException('A resposta da SEFIN pertence a outro ambiente ou não identifica o ambiente.', 200, true);
        }
    }

    private function request(string $method, string $path, ?array $payload = null, bool $parameters = false, ?callable $validate = null): array
    {
        $this->certificate->assertUsable();
        $base = $parameters
            ? ($this->environment === '1' ? 'https://adn.nfse.gov.br/parametrizacao' : 'https://adn.producaorestrita.nfse.gov.br/parametrizacao')
            : ($this->environment === '1' ? 'https://sefin.nfse.gov.br/SefinNacional' : 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional');
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $source = $parameters ? 'o serviço de parâmetros municipais do ADN' : 'a SEFIN';
        $origin = $parameters ? 'do serviço de parâmetros municipais do ADN' : 'da SEFIN';
        $eventsRequest = !$parameters && $method === 'GET' && preg_match('~/eventos(?:/[0-9]{6}/1)?$~D', $path) === 1;
        $hint = $parameters ? ' A consulta do convênio permanece inconclusiva.'
            : ($eventsRequest ? ' A consulta dos eventos permanece inconclusiva.' : ' Consulte a DPS antes de qualquer nova transmissão.');
        $operation = $parameters ? 'api.municipality' : ($eventsRequest ? 'api.events' : ($method === 'POST' ? 'api.submit' : (strpos($path, '/dps/') === 0 ? 'api.find_dps' : 'api.fetch')));
        $maskedPath = $parameters ? '/{codigoMunicipio}/convenio' : ($eventsRequest ? '/nfse/{chaveAcesso}/eventos' : ($method === 'POST' ? '/nfse' : (strpos($path, '/dps/') === 0 ? '/dps/{idDps}' : '/nfse/{chaveAcesso}')));
        if ($eventsRequest && preg_match('~/eventos/[0-9]{6}/1$~D', $path)) { $maskedPath .= '/{tipoEvento}/{numSeqEvento}'; }
        $event = [
            'operation' => $operation, 'environment' => $this->environment,
            'service' => $parameters ? 'adn_parametrizacao' : 'sefin_nacional',
            'method' => $method, 'endpoint' => $base . $maskedPath,
            'http_status' => 0, 'duration_ms' => 0,
            'request_bytes' => strlen($body ?? ''), 'response_bytes' => 0,
            'request_sha256' => hash('sha256', $body ?? ''), 'response_sha256' => null,
            'response_type' => 'transport_error', 'operation_status' => 'uncertain',
            'error_codes' => [], 'errors' => [],
        ];
        $started = hrtime(true);
        try {
            try {
                $response = $this->transport !== null
                    ? ($this->transport)($method, $base . $path, $body, $this->certificate)
                    : $this->curl($method, $base . $path, $body, $source, $hint);
            } catch (ApiException $e) { throw $e; }
            catch (\Throwable $e) {
                throw new ApiException('Não foi possível concluir a comunicação com ' . $source . '.' . $hint, 0, true, [], $e);
            }
            $status = (int) ($response['status'] ?? 0);
            $event['http_status'] = $status;
            $raw = $response['body'] ?? '';
            if (is_string($raw)) {
                $event['response_bytes'] = strlen($raw);
                $event['response_sha256'] = hash('sha256', $raw);
            }
            if (!is_string($raw) || strlen($raw) > self::MAX_RESPONSE) {
                $event['response_type'] = is_string($raw) ? 'oversized' : 'invalid_body';
                throw new ApiException('Resposta inválida ou acima do limite esperado para ' . $source . ' (HTTP ' . $status . ').' . $hint, $status, true);
            }
            $trimmed = ltrim($raw);
            try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) {
                $event['response_type'] = $trimmed === '' ? 'empty'
                    : (preg_match('/^<(?:!doctype\s+html|html)\b/i', $trimmed) ? 'html'
                        : (strpos($trimmed, '<') === 0 ? 'xml' : 'invalid_json'));
                throw new ApiException('Não foi recebida uma resposta JSON válida ' . $origin . ' (HTTP ' . $status . ').' . $hint, $status, true, [], $e);
            }
            $event['response_type'] = strpos($trimmed, '{') === 0 ? 'json_object' : (strpos($trimmed, '[') === 0 ? 'json_array' : 'json_scalar');
            if (!is_array($data) || $event['response_type'] !== 'json_object') {
                throw new ApiException('Formato inesperado na resposta ' . $origin . ' (HTTP ' . $status . ').' . $hint, $status, true);
            }
            if ($status >= 200 && $status < 300) {
                if (!empty($data['erros']) || !empty($data['erro'])) {
                    throw new ApiException('A resposta ' . $origin . ' combina sucesso HTTP com erro.' . $hint, $status, true, self::messages($data, $parameters));
                }
                if ($validate !== null) { $validate($data); }
                $event['operation_status'] = 'success';
                return $data;
            }
            $errors = self::messages($data, $parameters);
            $detail = $errors ? implode(' | ', array_column($errors, 'message')) : '';
            // Absence must also belong to this environment, even on HTTP 404.
            if ($eventsRequest && $status === 404) {
                try { $this->assertEnvironment($data); }
                catch (ApiException $error) {
                    throw new ApiException('A resposta de ausência de eventos não identifica o ambiente consultado. A consulta permanece inconclusiva.', 404, true, [], $error);
                }
            }
            $notFound = $status === 404 && !$parameters && $method === 'GET' && self::isDocumentNotFound($path, $errors);
            $message = $parameters
                ? 'Não foi possível concluir a consulta do convênio no ADN (HTTP ' . $status . ').'
                : 'A SEFIN retornou HTTP ' . $status . '.';
            if ($detail !== '') { $message .= ' ' . $detail; }
            $uncertain = $status === 0 || $status >= 500 || in_array($status, [408, 409, 429], true)
                || ($status === 404 && !$notFound)
                || ($eventsRequest && $status === 405)
                || ($method === 'POST' && (!$errors || preg_match('/duplic|j[aá]\s+(?:foi|existe|emitida|gerada|convertida)/iu', $detail)));
            if ($parameters && $status === 404) {
                $message .= ' O HTTP 404, isoladamente, não confirma a ausência de convênio no município.';
            } elseif ($uncertain) { $message .= $hint; }
            throw new ApiException($message, $status, (bool) $uncertain, $errors);
        } catch (ApiException $e) {
            if ($event['http_status'] === 0) { $event['http_status'] = $e->httpStatus(); }
            $event['operation_status'] = $e->isUncertain() ? 'uncertain' : ($e->httpStatus() === 404 ? 'not_found' : 'error');
            $event['errors'] = $e->errors() ?: [['code' => '', 'message' => $e->getMessage()]];
            $event['error_codes'] = array_values(array_unique(array_filter(array_column($event['errors'], 'code'))));
            throw $e;
        } finally {
            $event['duration_ms'] = (int) round((hrtime(true) - $started) / 1000000);
            $this->observe($event);
        }
    }

    private function curl(string $method, string $url, ?string $body, string $source, string $hint): array
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('A extensão cURL do PHP é necessária.'); }
        $handle = curl_init();
        if ($handle === false) { throw new \RuntimeException('Não foi possível inicializar o cliente HTTPS.'); }
        $temporaryFiles = [];
        $responseBody = '';
        try {
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 40,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
                CURLOPT_USERAGENT => 'Perfex-Nfse-Nacional/0.1.0',
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody): int {
                    if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE) { return 0; }
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
                $options[CURLOPT_SSLCERT_BLOB] = $this->certificate->certificatePem();
                $options[CURLOPT_SSLKEY_BLOB] = $this->certificate->privateKeyPem();
            } else {
                foreach (['certificate' => $this->certificate->certificatePem(), 'key' => $this->certificate->privateKeyPem()] as $kind => $contents) {
                    $file = tempnam(sys_get_temp_dir(), 'nfse_tls_');
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
            if ($body !== null) { $options[CURLOPT_POSTFIELDS] = $body; }
            curl_setopt_array($handle, $options);
            if (curl_exec($handle) === false) {
                // Do not expose cURL messages: paths, certificate details and proxy credentials can occur there.
                throw new ApiException('Falha de comunicação HTTPS com ' . $source . ' (código cURL ' . curl_errno($handle) . ').' . $hint, 0, true);
            }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $responseBody];
        } finally {
            curl_close($handle);
            foreach ($temporaryFiles as $file) { if (is_file($file)) { @unlink($file); } }
        }
    }

    private static function validAccessKey(string $value): bool
    {
        return preg_match('/^[0-9A-Z]{50}$/D', $value) === 1;
    }

    /** A 404 from a proxy/route must never authorize a repeat of a fiscal transmission. */
    private static function isDocumentNotFound(string $path, array $errors): bool
    {
        if (!$errors) { return false; }
        $events = preg_match('~/eventos(?:/[0-9]{6}/1)?$~D', $path) === 1;
        $subject = $events ? '/\beventos?\b/iu' : (strpos($path, '/dps/') === 0 ? '/\b(?:DPS|identificador)\b/iu' : '/\b(?:NFS-e|NFSe|chave de acesso)\b/iu');
        $found = false;
        foreach ($errors as $error) {
            $text = (string) ($error['message'] ?? '');
            if (preg_match('/\b(?:rota|route|endpoint|URL|gateway|proxy|recurso|resource|servi[cç]o|certificado|permiss[aã]o)\b|inv[aá]lid/iu', $text)) { return false; }
            if (preg_match($subject, $text) && (preg_match('/n[aã]o\s+(?:(?:foi|foram)\s+)?(?:encontrad|localizad|exist)|inexistente/iu', $text)
                || ($events && preg_match('/nenhum\s+evento\s+(?:encontrado|localizado|registrado)/iu', $text)))) {
                $found = true;
            } else { return false; }
        }
        return $found;
    }

    /** The observer receives metadata only; audit failures cannot change a fiscal result. */
    private function observe(array $event): void
    {
        if ($this->observer === null) { return; }
        try {
            foreach ($event['errors'] as &$error) {
                $message = (string) ($error['message'] ?? '');
                $message = preg_replace('/-----BEGIN[\s\S]*?(?:-----END[^\r\n]*-----|$)/', '[conteúdo protegido]', $message) ?? '';
                $message = preg_replace('/<[^>]+>[\s\S]*|H4sI[A-Za-z0-9+\/=]+|[A-Za-z0-9+\/=_-]{80,}/', '[conteúdo protegido]', $message) ?? '';
                $error['message'] = mb_substr($message, 0, 1500);
                $error['code'] = substr((string) ($error['code'] ?? ''), 0, 30);
            }
            unset($error);
            ($this->observer)($event);
        } catch (\Throwable $ignored) {
            // Logging is observational: never retry, reject or repeat an accepted operation.
        }
    }

    private static function messages(array $data, bool $parameters = false): array
    {
        $raw = $data['erros'] ?? (isset($data['erro']) ? [$data['erro']] : []);
        if (!is_array($raw)) { $raw = []; }
        $messages = [];
        foreach (array_slice($raw, 0, 10) as $error) {
            if (!is_array($error)) { continue; }
            $code = self::messageText($error['codigo'] ?? '', 30);
            $message = self::messageText($error['descricao'] ?? $error['mensagem'] ?? '');
            $complement = self::messageText($error['complemento'] ?? '');
            if ($complement !== '') { $message .= ' ' . $complement; }
            if ($message !== '') { $messages[] = ['code' => substr($code, 0, 30), 'message' => ($code !== '' ? $code . ': ' : '') . mb_substr($message, 0, 1500)]; }
        }
        // ADN parameter responses use `mensagem`, not the SEFIN `erros` envelope.
        if ($parameters && count($messages) < 10) {
            $message = self::messageText($data['mensagem'] ?? '');
            if ($message !== '') { $messages[] = ['code' => '', 'message' => $message]; }
        }
        return $messages;
    }

    private static function messageText($value, int $limit = 1500): string
    {
        if (!is_string($value) && !is_numeric($value)) { return ''; }
        // Do not strip the XML marker first: that would turn an echoed payload into unlabelled personal text.
        $decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/<\??(?:xml|DPS|NFSe|infDPS|infNFSe|Signature|X509Certificate|xNome|CPF|CNPJ|endNac|xLgr|email|fone)\b/i', $decoded)) {
            return '[Detalhes XML omitidos]';
        }
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', strip_tags((string) $value)) ?? ''), 0, $limit);
    }
}
