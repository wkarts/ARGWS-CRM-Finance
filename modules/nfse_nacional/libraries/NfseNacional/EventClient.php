<?php
declare(strict_types=1);

namespace NfseNacional;

/**
 * Fixed SEFIN event endpoints, selected A1, no redirects and no automatic retry.
 * The lifecycle service persists the signed request before submit(), and calls
 * find() before any repeat after uncertainty. A request alone is never an event.
 */
final class EventClient
{
    private const MAX_RESPONSE = 4194304;
    private const MAX_XML = 5242880;
    private string $environment;
    private Certificate $certificate;
    private $transport;
    private $observer;

    public function __construct(string $environment, Certificate $certificate, ?callable $transport = null, ?callable $observer = null)
    {
        if (!in_array($environment, ['1', '2'], true)) { throw new \InvalidArgumentException('Ambiente fiscal inválido.'); }
        $this->environment = $environment; $this->certificate = $certificate;
        $this->transport = $transport; $this->observer = $observer;
    }

    /** Exactly one POST. Callers must persist and reconcile the request before retrying it. */
    public function submit(string $signedRequest): array
    {
        $request = EventRequest::inspect($signedRequest, $this->certificate);
        if ($request['environment'] !== $this->environment) { throw new \InvalidArgumentException('O ambiente do pedido de evento difere do ambiente da conexão.'); }
        $compressed = gzencode($signedRequest, 9);
        if ($compressed === false) { throw new \RuntimeException('Não foi possível compactar o pedido de evento.'); }
        return $this->request('POST', '/nfse/' . $request['access_key'] . '/eventos',
            ['pedidoRegistroEventoXmlGZipB64' => base64_encode($compressed)],
            $request['access_key'], $request['type'], 1, $request);
    }

    /** null means explicit fiscal absence for this key, event type, sequence and environment. */
    public function find(string $accessKey, string $type, int $sequence = 1): ?array
    {
        if (!preg_match('/^[0-9A-Z]{50}$/D', $accessKey) || !preg_match('/^[0-9]{6}$/D', $type) || $sequence < 1 || $sequence > 999) {
            throw new \InvalidArgumentException('Informe uma chave, tipo e sequência de evento válidos.');
        }
        return $this->request('GET', '/nfse/' . $accessKey . '/eventos/' . $type . '/' . $sequence, null, $accessKey, $type, $sequence);
    }

    private function request(string $method, string $path, ?array $payload, string $key, string $type, int $sequence, ?array $expectedRequest = null): ?array
    {
        $this->certificate->assertUsable();
        $base = $this->environment === '1' ? 'https://sefin.nfse.gov.br/SefinNacional' : 'https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional';
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $log = ['operation' => $method === 'POST' ? 'api.event_submit' : 'api.event_find',
            'service' => 'sefin_nacional', 'environment' => $this->environment, 'method' => $method,
            'endpoint' => $base . '/nfse/{chaveAcesso}/eventos' . ($method === 'GET' ? '/{tipoEvento}/{numSeqEvento}' : ''),
            'http_status' => 0, 'duration_ms' => 0, 'request_bytes' => strlen($body ?? ''), 'response_bytes' => 0,
            'request_sha256' => hash('sha256', $body ?? ''), 'response_sha256' => null,
            'response_type' => 'transport_error', 'operation_status' => 'uncertain', 'error_codes' => [], 'errors' => []];
        $start = hrtime(true);
        try {
            try {
                $response = $this->transport !== null
                    ? ($this->transport)($method, $base . $path, $body, $this->certificate)
                    : $this->curl($method, $base . $path, $body);
            } catch (\Throwable $error) {
                throw new ApiException('Não foi possível confirmar a comunicação do evento com a SEFIN. Consulte o evento antes de reenviar.', 0, true);
            }
            if (!is_array($response)) { throw new ApiException('Resposta de transporte inválida na operação do evento.', 0, true); }
            $status = isset($response['status']) && is_int($response['status']) ? $response['status'] : 0;
            $log['http_status'] = $status; $raw = $response['body'] ?? null;
            if (!is_string($raw) || strlen($raw) > self::MAX_RESPONSE) { throw new ApiException('A resposta do evento está inválida ou excede o limite seguro.', $status, true); }
            $log['response_bytes'] = strlen($raw); $log['response_sha256'] = hash('sha256', $raw);
            $trimmed = ltrim($raw);
            $log['response_type'] = $trimmed === '' ? 'empty' : ($trimmed[0] === '{' ? 'json_object' : ($trimmed[0] === '<' ? 'html_or_xml' : 'invalid_json'));
            try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (\Throwable $error) { throw new ApiException('A SEFIN não retornou uma resposta JSON válida para o evento (HTTP ' . $status . '). Consulte antes de reenviar.', $status, true); }
            if (!is_array($data) || $trimmed === '' || $trimmed[0] !== '{') { throw new ApiException('A resposta da SEFIN não confirma a situação do evento.', $status, true); }
            $environment = $data['tipoAmbiente'] ?? null;
            if ((!is_int($environment) && !is_string($environment)) || (string) $environment !== $this->environment) {
                throw new ApiException('A resposta do evento não identifica o ambiente consultado ou pertence a outro ambiente.', $status, true);
            }
            $rawErrors = $data['erros'] ?? (isset($data['erro']) ? [$data['erro']] : []);
            if (!is_array($rawErrors)) { throw new ApiException('A SEFIN retornou mensagens de evento inválidas.', $status, true); }
            if ($method === 'GET' && $status === 404 && self::explicitAbsence($rawErrors)) {
                $log['operation_status'] = 'not_found';
                return null;
            }
            if (($method === 'GET' ? $status !== 200 : !in_array($status, [200, 201], true)) || $rawErrors) {
                $messages = self::messages($rawErrors);
                $detail = implode(' ', array_column($messages, 'message'));
                $uncertain = $status === 0 || $status >= 500 || in_array($status, [200, 201, 202, 204, 404, 408, 409, 429], true) || !$messages;
                if ($method === 'POST') {
                    foreach ($rawErrors as $error) {
                        $description = is_array($error) && is_string($error['descricao'] ?? null) ? $error['descricao'] : '';
                        if (preg_match('/duplic|j[aá]\s+(?:foi|existe|registrad|processad)/iu', $description)) { $uncertain = true; }
                    }
                }
                throw new ApiException('A SEFIN não confirmou o evento (HTTP ' . $status . ').' . ($detail !== '' ? ' ' . $detail : '')
                    . ($uncertain ? ' Consulte a situação antes de reenviar.' : ''), $status, $uncertain, $messages);
            }
            $event = $this->decodeEvent($data, $key, $type, $sequence);
            if ($expectedRequest !== null) { self::assertRequest($event['xml'], $expectedRequest); }
            $log['operation_status'] = 'success';
            return $event;
        } catch (ApiException $error) {
            if ($log['http_status'] === 0) { $log['http_status'] = $error->httpStatus(); }
            $log['operation_status'] = $error->isUncertain() ? 'uncertain' : 'error';
            $log['errors'] = $error->errors() ?: [['code' => '', 'message' => $error->getMessage()]];
            $log['error_codes'] = array_values(array_unique(array_filter(array_column($log['errors'], 'code'))));
            throw $error;
        } finally {
            $log['duration_ms'] = (int) round((hrtime(true) - $start) / 1000000);
            if ($this->observer !== null) {
                try { ($this->observer)($log); }
                catch (\Throwable $ignored) { /* Logging cannot change a confirmed event or repeat its POST. */ }
            }
        }
    }

    private function decodeEvent(array $data, string $key, string $type, int $sequence): array
    {
        $encoded = $data['eventoXmlGZipB64'] ?? null; $doubleBase64 = false;
        if (array_key_exists('eventos', $data)) {
            if ($encoded !== null || !is_array($data['eventos']) || count($data['eventos']) !== 1 || !isset($data['eventos'][0]) || !is_array($data['eventos'][0])) {
                throw new ApiException('A SEFIN não retornou exatamente o evento solicitado.', 200, true);
            }
            $encoded = $data['eventos'][0]['arquivoXml'] ?? null; $doubleBase64 = true;
        }
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > self::MAX_RESPONSE) { throw new ApiException('A resposta não contém o XML do evento registrado.', 200, true); }
        $compressed = base64_decode($encoded, true);
        if ($doubleBase64 && is_string($compressed) && substr($compressed, 0, 2) !== "\x1f\x8b") { $compressed = base64_decode($compressed, true); }
        if (!is_string($compressed) || substr($compressed, 0, 2) !== "\x1f\x8b") { throw new ApiException('O evento retornado não está no formato GZip/Base64 esperado.', 200, true); }
        $xml = @gzdecode($compressed, self::MAX_XML + 1);
        if (!is_string($xml) || strlen($xml) > self::MAX_XML) { throw new ApiException('Não foi possível descompactar o evento com segurança.', 200, true); }
        try { $event = FiscalEvent::fromXml($xml, $key, $this->environment); }
        catch (\Throwable $error) { throw new ApiException('O documento retornado não confirma um evento registrado para esta NFS-e e este ambiente.', 200, true); }
        if ($event['type'] !== $type || $event['sequence'] !== $sequence) { throw new ApiException('O evento retornado não corresponde ao tipo e à sequência solicitados.', 200, true); }
        return $event;
    }

    /** Correlate the registered XML with the exact fiscal contents persisted before POST. */
    private static function assertRequest(string $eventXml, array $request): void
    {
        $doc = XmlSigner::loadSafe($eventXml); $xp = new \DOMXPath($doc); $xp->registerNamespace('n', XmlSigner::NFSE_NS);
        $base = '/n:evento/n:infEvento/n:pedRegEvento/n:infPedReg';
        if ($xp->evaluate('string(' . $base . '/@Id)') !== $request['id']) { throw new ApiException('O evento registrado não corresponde ao pedido enviado.', 201, true); }
        $checks = ['tpAmb' => $request['environment'], 'chNFSe' => $request['access_key'], 'dhEvento' => $request['occurred_at'],
            'e' . $request['type'] . '/xDesc' => $request['description'], 'e' . $request['type'] . '/cMotivo' => $request['reason_code'],
            'e' . $request['type'] . '/xMotivo' => $request['reason']];
        $authorField = strlen($request['author_tax_id']) === 14 ? 'CNPJAutor' : 'CPFAutor';
        $checks[$authorField] = $request['author_tax_id'];
        $checks[$authorField === 'CNPJAutor' ? 'CPFAutor' : 'CNPJAutor'] = '';
        foreach ($checks as $path => $expected) {
            $nodes = $xp->query($base . '/n:' . str_replace('/', '/n:', $path));
            if ($nodes->length > 1 || ($expected !== '' && $nodes->length !== 1)
                || ($nodes->length === 1 && ($nodes->item(0)->getElementsByTagName('*')->length > 0 || $nodes->item(0)->textContent !== $expected))) {
                throw new ApiException('O conteúdo do evento registrado difere do pedido enviado. Consulte a situação antes de qualquer nova ação.', 201, true);
            }
        }
    }

    private static function explicitAbsence(array $errors): bool
    {
        if (!$errors || count($errors) > 20) { return false; }
        foreach ($errors as $error) {
            if (!is_array($error) || !is_string($error['descricao'] ?? null)) { return false; }
            $message = $error['descricao'];
            if (preg_match('/\b(?:rota|route|endpoint|URL|gateway|proxy|recurso|resource|servi[cç]o|certificado|permiss[aã]o|CPF|CNPJ)\b|autoriza|inv[aá]lid|acesso\s+(?:negado|proibido)/iu', $message)
                || !preg_match('/\beventos?\b/iu', $message)
                || !preg_match('/n[aã]o\s+(?:(?:foi|foram)\s+)?(?:encontrad|localizad|exist)|inexistente|nenhum\s+evento\s+(?:encontrado|localizado|registrado)/iu', $message)) { return false; }
        }
        return true;
    }

    private static function messages(array $errors): array
    {
        $result = [];
        foreach (array_slice($errors, 0, 10) as $error) {
            if (!is_array($error)) { continue; }
            $code = $error['codigo'] ?? '';
            if (!is_string($code) || !preg_match('/^E[0-9]{3,5}$/D', $code)) { $code = ''; }
            $text = $code === 'E0827' ? 'O município não permite o cancelamento automático com tributos recolhidos.'
                : ($code === 'E0840' ? 'Já existe solicitação de análise fiscal em andamento.' : 'O pedido de evento foi recusado pelo ambiente nacional. Revise as regras e o motivo informado.');
            $result[] = ['code' => $code, 'message' => ($code !== '' ? $code . ': ' : '') . $text];
        }
        return $result;
    }

    private function curl(string $method, string $url, ?string $body): array
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('A extensão cURL do PHP é necessária.'); }
        $handle = curl_init();
        if ($handle === false) { throw new \RuntimeException('Não foi possível inicializar a conexão HTTPS.'); }
        $files = []; $response = '';
        try {
            $options = [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
                CURLOPT_USERAGENT => 'Perfex-Nfse-Nacional/Eventos',
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE) { return 0; }
                    $response .= $chunk; return strlen($chunk);
                }];
            if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
                $options[CURLOPT_SSLCERT_BLOB] = $this->certificate->certificatePem();
                $options[CURLOPT_SSLKEY_BLOB] = $this->certificate->privateKeyPem();
            } else {
                foreach (['certificate' => $this->certificate->certificatePem(), 'key' => $this->certificate->privateKeyPem()] as $kind => $contents) {
                    $file = tempnam(sys_get_temp_dir(), 'nfse_event_tls_');
                    if ($file === false) { throw new \RuntimeException('Não foi possível preparar o certificado.'); }
                    $files[] = $file;
                    if (!chmod($file, 0600) || file_put_contents($file, $contents, LOCK_EX) !== strlen($contents)) { throw new \RuntimeException('Não foi possível proteger o certificado temporário.'); }
                    $options[$kind === 'certificate' ? CURLOPT_SSLCERT : CURLOPT_SSLKEY] = $file;
                }
            }
            $options[CURLOPT_SSLCERTTYPE] = 'PEM'; $options[CURLOPT_SSLKEYTYPE] = 'PEM';
            if ($body !== null) { $options[CURLOPT_POSTFIELDS] = $body; }
            curl_setopt_array($handle, $options);
            if (curl_exec($handle) === false) { throw new \RuntimeException('A conexão HTTPS não foi concluída.'); }
            return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $response];
        } finally {
            curl_close($handle);
            foreach ($files as $file) { if (is_file($file)) { @unlink($file); } }
        }
    }
}
