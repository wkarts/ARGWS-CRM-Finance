<?php
declare(strict_types=1);

namespace NfseNacional;

/** Builds signed contributor event requests from an already authorized NFS-e. */
final class EventRequest
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const MAX_XML = 1048576;

    /** No municipal/officio, tacit, substitution or fiscal-decision events can be authored here. */
    public static function definitions(): array
    {
        $cancellation = ['1' => 'Erro na emissão', '2' => 'Serviço não prestado', '9' => 'Outros'];
        return [
            '101101' => ['label' => 'Cancelar NFS-e', 'description' => 'Cancelamento de NFS-e', 'role' => 'prest', 'reasons' => $cancellation, 'reason_required' => true],
            '101103' => ['label' => 'Solicitar análise fiscal para cancelamento', 'description' => 'Solicitação de Análise Fiscal para Cancelamento de NFS-e', 'role' => 'prest', 'reasons' => $cancellation, 'reason_required' => true],
            '203202' => ['label' => 'Confirmar NFS-e recebida', 'description' => 'Manifestação de NFS-e - Confirmação do Tomador', 'role' => 'toma', 'reasons' => [], 'reason_required' => false],
            '203206' => ['label' => 'Rejeitar NFS-e recebida', 'description' => 'Manifestação de NFS-e - Rejeição do Tomador', 'role' => 'toma',
                'reasons' => ['1' => 'NFS-e em duplicidade', '2' => 'NFS-e já emitida pelo tomador', '3' => 'Não ocorrência do fato gerador',
                    '4' => 'Erro na responsabilidade tributária', '5' => 'Erro no valor, deduções, serviço ou data do fato gerador', '9' => 'Outros'], 'reason_required' => false],
        ];
    }

    /** The caller must also check stored status, permission, production gate and municipal rules. */
    public static function build(NfseDocument $note, string $authorTaxId, string $type, string $reasonCode, string $reason,
        Certificate $certificate, ?string $occurredAt = null): string
    {
        $definition = self::definition($type);
        $authorTaxId = Config::taxId($authorTaxId);
        if (!Validator::taxId($authorTaxId)) { throw new \InvalidArgumentException('Informe o CPF/CNPJ válido do autor do evento.'); }
        $certificate->assertUsable($authorTaxId);
        $cnpj = $note->dpsText($definition['role'] . '/CNPJ');
        $cpf = $note->dpsText($definition['role'] . '/CPF');
        if (($cnpj !== '' && $cpf !== '') || !hash_equals($authorTaxId, $cnpj !== '' ? $cnpj : $cpf)) {
            throw new \InvalidArgumentException('A empresa selecionada não possui o papel exigido para este evento na NFS-e.');
        }
        $emitter = $note->dpsText('tpEmit');
        if (($definition['role'] === 'prest' && $emitter !== '1') || ($definition['role'] === 'toma' && $emitter !== '1')) {
            throw new \InvalidArgumentException('Este evento está disponível para NFS-e emitida pelo prestador.');
        }
        // Never silently choose, truncate or replace a fiscal justification.
        $reason = trim($reason);
        self::validateReason($definition, $reasonCode, $reason);
        $occurredAt = $occurredAt ?? gmdate('Y-m-d\TH:i:sP');
        if (!self::validTimestamp($occurredAt)) { throw new \InvalidArgumentException('Informe data e hora válidas para o evento fiscal.'); }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true; $doc->formatOutput = false;
        $root = $doc->createElementNS(XmlSigner::NFSE_NS, 'pedRegEvento');
        $root->setAttribute('versao', '1.01'); $doc->appendChild($root);
        $info = self::append($doc, $root, 'infPedReg');
        $info->setAttribute('Id', 'PRE' . $note->accessKey() . $type);
        self::append($doc, $info, 'tpAmb', $note->environment());
        self::append($doc, $info, 'verAplic', 'Perfex-NFSe');
        self::append($doc, $info, 'dhEvento', $occurredAt);
        self::append($doc, $info, strlen($authorTaxId) === 14 ? 'CNPJAutor' : 'CPFAutor', $authorTaxId);
        self::append($doc, $info, 'chNFSe', $note->accessKey());
        $specific = self::append($doc, $info, 'e' . $type);
        self::append($doc, $specific, 'xDesc', $definition['description']);
        if ($definition['reasons']) { self::append($doc, $specific, 'cMotivo', $reasonCode); }
        if ($reason !== '') { self::append($doc, $specific, 'xMotivo', $reason); }
        self::sign($doc, $certificate);
        $xml = (string) $doc->saveXML();
        self::inspect($xml, $certificate);
        return $xml;
    }

    /**
     * Verify the supported schema, unique signed node, profile, digest, signature,
     * author and (when provided) that the signing A1 is the selected connection A1.
     * This is not an ICP-Brasil chain/revocation validation, performed by SEFIN.
     *
     * @return array{id:string,type:string,access_key:string,environment:string,author_tax_id:string,occurred_at:string,reason_code:string,reason:string,description:string}
     */
    public static function inspect(string $xml, ?Certificate $certificate = null): array
    {
        if (strlen($xml) > self::MAX_XML) { throw new \InvalidArgumentException('O pedido de evento excede o limite seguro.'); }
        $doc = XmlSigner::loadSafe($xml); $xp = self::xpath($doc);
        $root = $doc->documentElement;
        if (!$root || $root->localName !== 'pedRegEvento' || $root->namespaceURI !== XmlSigner::NFSE_NS || $root->getAttribute('versao') !== '1.01'
            || $xp->query('/n:pedRegEvento/n:infPedReg')->length !== 1 || $xp->query('//*[local-name()="infPedReg"]')->length !== 1
            || $xp->query('//*[@Id]')->length !== 1 || $xp->query('//ds:Signature')->length !== 1
            || $xp->query('/n:pedRegEvento/ds:Signature')->length !== 1) {
            throw new \InvalidArgumentException('O pedido deve conter um único evento fiscal assinado no padrão nacional.');
        }
        $info = $xp->query('/n:pedRegEvento/n:infPedReg')->item(0);
        $get = static function (string $path, bool $optional = false) use ($xp): string {
            $nodes = $xp->query('/n:pedRegEvento/n:infPedReg/n:' . str_replace('/', '/n:', $path));
            if ($optional && $nodes->length === 0) { return ''; }
            if ($nodes->length !== 1 || $nodes->item(0)->getElementsByTagName('*')->length > 0) {
                throw new \InvalidArgumentException('O pedido de evento contém um campo fiscal ausente ou duplicado.');
            }
            return (string) $nodes->item(0)->textContent;
        };
        $id = $info->getAttribute('Id'); $key = $get('chNFSe'); $environment = $get('tpAmb');
        if (!preg_match('/^PRE([0-9A-Z]{50})([0-9]{6})$/D', $id, $parts) || !hash_equals($parts[1], $key)
            || !in_array($environment, ['1', '2'], true)) { throw new \InvalidArgumentException('A identificação do pedido de evento está inconsistente.'); }
        $type = $parts[2]; $definition = self::definition($type);
        $cnpj = $get('CNPJAutor', true); $cpf = $get('CPFAutor', true);
        $author = $cnpj !== '' ? $cnpj : $cpf;
        if (($cnpj !== '' && $cpf !== '') || !Validator::taxId($author)) { throw new \InvalidArgumentException('O autor do evento fiscal está inválido.'); }
        if ($certificate !== null) { $certificate->assertUsable($author); }
        if ($get('e' . $type . '/xDesc') !== $definition['description']) { throw new \InvalidArgumentException('A descrição do evento não corresponde ao tipo solicitado.'); }
        $reasonCode = $get('e' . $type . '/cMotivo', true); $reason = $get('e' . $type . '/xMotivo', true);
        self::validateReason($definition, $reasonCode, $reason);
        $occurredAt = $get('dhEvento');
        if (!self::validTimestamp($occurredAt)) { throw new \InvalidArgumentException('A data e hora do pedido de evento está inválida.'); }
        self::validateSchema($doc, $environment);
        if (!self::validSignature($doc, $certificate)) { throw new \InvalidArgumentException('A assinatura do pedido de evento é inválida ou não corresponde ao certificado selecionado.'); }
        return ['id' => $id, 'type' => $type, 'access_key' => $key, 'environment' => $environment,
            'author_tax_id' => $author, 'occurred_at' => $occurredAt, 'reason_code' => $reasonCode,
            'reason' => $reason, 'description' => $definition['description']];
    }

    public static function verify(string $xml): bool
    {
        try { self::inspect($xml); return true; }
        catch (\Throwable $error) { return false; }
    }

    private static function definition(string $type): array
    {
        $definition = self::definitions()[$type] ?? null;
        if ($definition === null) { throw new \InvalidArgumentException('Este tipo de evento não pode ser emitido pelo contribuinte neste módulo.'); }
        return $definition;
    }

    private static function validateReason(array $definition, string $code, string $reason): void
    {
        if (!$definition['reasons']) {
            if ($code !== '' || $reason !== '') { throw new \InvalidArgumentException('A confirmação da NFS-e não recebe motivo ou justificativa.'); }
            return;
        }
        if (!array_key_exists($code, $definition['reasons'])) { throw new \InvalidArgumentException('Selecione um motivo permitido para este evento.'); }
        if ($reason === '' && !$definition['reason_required']) { return; }
        if (!mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason, 'UTF-8') < 15 || mb_strlen($reason, 'UTF-8') > 255) {
            throw new \InvalidArgumentException('A justificativa do evento deve conter entre 15 e 255 caracteres.');
        }
        if ($reason !== trim($reason) || preg_match('/[^\x{0020}-\x{00FF}]/u', $reason)) {
            throw new \InvalidArgumentException('A justificativa contém caracteres não aceitos pelo leiaute fiscal.');
        }
    }

    private static function validTimestamp(string $timestamp): bool
    {
        if (!preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-](?:0\d|1[012]):00$/D', $timestamp)) { return false; }
        try { return (new \DateTimeImmutable($timestamp))->format('Y-m-d\TH:i:sP') === $timestamp; }
        catch (\Throwable $error) { return false; }
    }

    private static function sign(\DOMDocument $doc, Certificate $certificate): void
    {
        $info = $doc->documentElement->firstChild;
        $digest = base64_encode(hash('sha1', (string) $info->C14N(false, false), true));
        $signature = self::append($doc, $doc->documentElement, 'Signature', null, self::DS);
        $signedInfo = self::append($doc, $signature, 'SignedInfo', null, self::DS);
        self::append($doc, $signedInfo, 'CanonicalizationMethod', null, self::DS)->setAttribute('Algorithm', self::C14N);
        self::append($doc, $signedInfo, 'SignatureMethod', null, self::DS)->setAttribute('Algorithm', self::DS . 'rsa-sha1');
        $reference = self::append($doc, $signedInfo, 'Reference', null, self::DS); $reference->setAttribute('URI', '#' . $info->getAttribute('Id'));
        $transforms = self::append($doc, $reference, 'Transforms', null, self::DS);
        self::append($doc, $transforms, 'Transform', null, self::DS)->setAttribute('Algorithm', self::DS . 'enveloped-signature');
        self::append($doc, $transforms, 'Transform', null, self::DS)->setAttribute('Algorithm', self::C14N);
        self::append($doc, $reference, 'DigestMethod', null, self::DS)->setAttribute('Algorithm', self::DS . 'sha1');
        self::append($doc, $reference, 'DigestValue', $digest, self::DS);
        $value = '';
        if (!openssl_sign((string) $signedInfo->C14N(false, false), $value, $certificate->privateKeyPem(), OPENSSL_ALGO_SHA1)) {
            throw new \RuntimeException('Não foi possível assinar o pedido de evento fiscal.');
        }
        self::append($doc, $signature, 'SignatureValue', base64_encode($value), self::DS);
        $keyInfo = self::append($doc, $signature, 'KeyInfo', null, self::DS);
        $x509 = self::append($doc, $keyInfo, 'X509Data', null, self::DS);
        self::append($doc, $x509, 'X509Certificate', preg_replace('/-----[^-]+-----|\s/', '', $certificate->certificatePem()) ?? '', self::DS);
    }

    private static function validSignature(\DOMDocument $doc, ?Certificate $certificate): bool
    {
        $xp = self::xpath($doc); $base = '/n:pedRegEvento/ds:Signature/ds:SignedInfo';
        $info = $xp->query('/n:pedRegEvento/n:infPedReg')->item(0);
        if ($xp->query($base)->length !== 1 || $xp->query($base . '/ds:Reference')->length !== 1
            || $xp->evaluate('string(' . $base . '/ds:CanonicalizationMethod/@Algorithm)') !== self::C14N
            || $xp->evaluate('string(' . $base . '/ds:SignatureMethod/@Algorithm)') !== self::DS . 'rsa-sha1'
            || $xp->evaluate('string(' . $base . '/ds:Reference/@URI)') !== '#' . $info->getAttribute('Id')
            || $xp->evaluate('string(' . $base . '/ds:Reference/ds:DigestMethod/@Algorithm)') !== self::DS . 'sha1') { return false; }
        $transforms = $xp->query($base . '/ds:Reference/ds:Transforms/ds:Transform');
        if ($transforms->length !== 2 || $transforms->item(0)->getAttribute('Algorithm') !== self::DS . 'enveloped-signature'
            || $transforms->item(1)->getAttribute('Algorithm') !== self::C14N) { return false; }
        $expected = base64_encode(hash('sha1', (string) $info->C14N(false, false), true));
        if (!hash_equals($expected, $xp->evaluate('string(' . $base . '/ds:Reference/ds:DigestValue)'))) { return false; }
        $signature = '/n:pedRegEvento/ds:Signature';
        $signatureValue = base64_decode($xp->evaluate('string(' . $signature . '/ds:SignatureValue)'), true);
        $x509 = $xp->evaluate('string(' . $signature . '/ds:KeyInfo/ds:X509Data/ds:X509Certificate)');
        if ($signatureValue === false || $x509 === '' || $xp->query($signature . '/ds:KeyInfo/ds:X509Data/ds:X509Certificate')->length !== 1) { return false; }
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($x509, 64, "\n") . "-----END CERTIFICATE-----\n";
        if ($certificate !== null && !hash_equals($certificate->metadata()['fingerprint_sha256'], (string) @openssl_x509_fingerprint($pem, 'sha256'))) { return false; }
        return @openssl_verify((string) $xp->query($base)->item(0)->C14N(false, false), $signatureValue, $pem, OPENSSL_ALGO_SHA1) === 1;
    }

    private static function validateSchema(\DOMDocument $doc, string $environment): void
    {
        $path = dirname(__DIR__, 2) . '/schemas/' . ($environment === '1' ? '20260209' : '20261020') . '/pedRegEvento_v1.01.xsd';
        if (!is_file($path)) { throw new \RuntimeException('O esquema dos eventos fiscais deste ambiente não está instalado no módulo.'); }
        $directory = realpath(dirname($path));
        $previous = libxml_use_internal_errors(true);
        $loader = function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;
        libxml_set_external_entity_loader(static function ($public, $system, $context) use ($directory) {
            if (!is_string($system) || preg_match('#^[a-z]+://#i', $system) || strtolower(pathinfo($system, PATHINFO_EXTENSION)) !== 'xsd') { return null; }
            $file = realpath($system) ?: realpath($directory . '/' . basename($system));
            return $file && dirname($file) === $directory ? fopen($file, 'rb') : null;
        });
        libxml_clear_errors();
        try {
            if (!$doc->schemaValidate($path)) { throw new \InvalidArgumentException('O pedido de evento não atende ao esquema oficial deste ambiente. Revise os campos informados.'); }
        } finally {
            libxml_clear_errors(); libxml_set_external_entity_loader($loader); libxml_use_internal_errors($previous);
        }
    }

    private static function xpath(\DOMDocument $doc): \DOMXPath
    {
        $xp = new \DOMXPath($doc); $xp->registerNamespace('n', XmlSigner::NFSE_NS); $xp->registerNamespace('ds', self::DS); return $xp;
    }

    private static function append(\DOMDocument $doc, \DOMElement $parent, string $name, ?string $value = null, string $namespace = XmlSigner::NFSE_NS): \DOMElement
    {
        $element = $doc->createElementNS($namespace, $name);
        if ($value !== null) { $element->appendChild($doc->createTextNode($value)); }
        $parent->appendChild($element); return $element;
    }
}
