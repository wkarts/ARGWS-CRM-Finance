<?php
declare(strict_types=1);

namespace NfseNacional;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** XMLDSig enveloped, conforme o perfil documentado pelo Sistema Nacional. */
final class XmlSigner
{
    public const NFSE_NS = 'http://www.sped.fazenda.gov.br/nfse';
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    public static function sign(string $xml, Certificate $certificate): string
    {
        $certificate->assertUsable();
        $doc = self::loadSafe($xml);
        $xpath = self::xpath($doc);
        $info = $xpath->query('/n:DPS/n:infDPS');
        if ($info->length !== 1 || $xpath->query('//ds:Signature')->length !== 0) {
            throw new \InvalidArgumentException('A DPS deve conter um único infDPS e ainda não pode estar assinada.');
        }
        /** @var DOMElement $inf */
        $inf = $info->item(0);
        $id = $inf->getAttribute('Id');
        if (!preg_match('/^DPS[0-9]{7}(?:1[0-9]{14}|2[0-9A-Z]{14})[0-9]{20}$/D', $id)) {
            throw new \InvalidArgumentException('O identificador da DPS é inválido para assinatura.');
        }
        $digest = base64_encode(hash('sha1', (string) $inf->C14N(false, false), true));
        $signature = $doc->createElementNS(self::DS, 'Signature');
        $doc->documentElement->appendChild($signature);
        $signedInfo = self::append($doc, $signature, 'SignedInfo');
        self::append($doc, $signedInfo, 'CanonicalizationMethod')->setAttribute('Algorithm', self::C14N);
        self::append($doc, $signedInfo, 'SignatureMethod')->setAttribute('Algorithm', self::DS . 'rsa-sha1');
        $reference = self::append($doc, $signedInfo, 'Reference');
        $reference->setAttribute('URI', '#' . $id);
        $transforms = self::append($doc, $reference, 'Transforms');
        self::append($doc, $transforms, 'Transform')->setAttribute('Algorithm', self::DS . 'enveloped-signature');
        self::append($doc, $transforms, 'Transform')->setAttribute('Algorithm', self::C14N);
        self::append($doc, $reference, 'DigestMethod')->setAttribute('Algorithm', self::DS . 'sha1');
        self::append($doc, $reference, 'DigestValue', $digest);
        $value = '';
        if (!openssl_sign((string) $signedInfo->C14N(false, false), $value, $certificate->privateKeyPem(), OPENSSL_ALGO_SHA1)) {
            throw new \RuntimeException('Não foi possível assinar a DPS com o certificado A1.');
        }
        self::append($doc, $signature, 'SignatureValue', base64_encode($value));
        $keyInfo = self::append($doc, $signature, 'KeyInfo');
        $x509 = self::append($doc, $keyInfo, 'X509Data');
        $pemBody = preg_replace('/-----[^-]+-----|\s/', '', $certificate->certificatePem()) ?? '';
        self::append($doc, $x509, 'X509Certificate', $pemBody);
        $result = (string) $doc->saveXML();
        if (!self::verify($result)) {
            throw new \RuntimeException('A verificação local da assinatura da DPS falhou. Nenhum documento foi transmitido.');
        }
        return $result;
    }

    /** Validates integrity, not ICP-Brasil trust/revocation, which the SEFIN verifies. */
    public static function verify(string $xml): bool
    {
        try {
            $doc = self::loadSafe($xml);
            $xp = self::xpath($doc);
            if ($xp->query('/n:DPS/n:infDPS')->length !== 1 || $xp->query('//ds:Signature')->length !== 1
                || $xp->query('/n:DPS/ds:Signature')->length !== 1 || $xp->query('//*[@Id]')->length !== 1) {
                return false;
            }
            $inf = $xp->query('/n:DPS/n:infDPS')->item(0);
            $base = '/n:DPS/ds:Signature/ds:SignedInfo';
            if ($xp->query($base)->length !== 1 || $xp->query($base . '/ds:Reference')->length !== 1) { return false; }
            if ($xp->evaluate('string(' . $base . '/ds:CanonicalizationMethod/@Algorithm)') !== self::C14N
                || $xp->evaluate('string(' . $base . '/ds:SignatureMethod/@Algorithm)') !== self::DS . 'rsa-sha1'
                || $xp->evaluate('string(' . $base . '/ds:Reference/@URI)') !== '#' . $inf->getAttribute('Id')
                || $xp->evaluate('string(' . $base . '/ds:Reference/ds:DigestMethod/@Algorithm)') !== self::DS . 'sha1') {
                return false;
            }
            $transforms = $xp->query($base . '/ds:Reference/ds:Transforms/ds:Transform');
            if ($transforms->length !== 2
                || $transforms->item(0)->getAttribute('Algorithm') !== self::DS . 'enveloped-signature'
                || $transforms->item(1)->getAttribute('Algorithm') !== self::C14N) { return false; }
            $expected = base64_encode(hash('sha1', (string) $inf->C14N(false, false), true));
            if (!hash_equals($expected, $xp->evaluate('string(' . $base . '/ds:Reference/ds:DigestValue)'))) { return false; }
            $sig = base64_decode($xp->evaluate('string(/n:DPS/ds:Signature/ds:SignatureValue)'), true);
            $cert = $xp->evaluate('string(/n:DPS/ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate)');
            if ($sig === false || $cert === '') { return false; }
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($cert, 64, "\n") . "-----END CERTIFICATE-----\n";
            return @openssl_verify((string) $xp->query($base)->item(0)->C14N(false, false), $sig, $pem, OPENSSL_ALGO_SHA1) === 1;
        } catch (\Throwable $e) { return false; }
    }

    public static function loadSafe(string $xml): DOMDocument
    {
        if ($xml === '' || strlen($xml) > 16777216 || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
            throw new \InvalidArgumentException('XML vazio, muito grande ou com declaração de entidade não permitida.');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = true;
            $doc->formatOutput = false;
            if (!$doc->loadXML($xml, LIBXML_NONET) || $doc->doctype !== null) {
                throw new \InvalidArgumentException('O XML fiscal não está bem formado.');
            }
            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('n', self::NFSE_NS);
        $xp->registerNamespace('ds', self::DS);
        return $xp;
    }

    private static function append(DOMDocument $doc, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $el = $doc->createElementNS(self::DS, $name);
        if ($value !== null) { $el->appendChild($doc->createTextNode($value)); }
        $parent->appendChild($el);
        return $el;
    }
}
