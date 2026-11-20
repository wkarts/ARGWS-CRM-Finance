<?php
declare(strict_types=1);

namespace NfseNacional;

/** Keeps PKCS#12 material in memory; never writes a certificate or password to logs. */
final class Certificate
{
    private string $certificate;
    private string $privateKey;
    private array $meta;

    private function __construct(string $certificate, string $privateKey, array $meta)
    {
        $this->certificate = $certificate;
        $this->privateKey = $privateKey;
        $this->meta = $meta;
    }

    public static function fromPkcs12(#[\SensitiveParameter] string $bytes, #[\SensitiveParameter] string $password, string $expectedTaxId = ''): self
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('A extensão OpenSSL do PHP é necessária.');
        }
        if ($bytes === '' || strlen($bytes) > 4194304) {
            throw new \InvalidArgumentException('O certificado A1 deve ter entre 1 byte e 4 MB.');
        }
        $parts = [];
        if (!@openssl_pkcs12_read($bytes, $parts, $password) || empty($parts['cert']) || empty($parts['pkey'])) {
            self::clearErrors();
            $parts = Pkcs12Reader::read($bytes, $password);
        }
        $parsed = @openssl_x509_parse($parts['cert']);
        if (!is_array($parsed) || !@openssl_x509_check_private_key($parts['cert'], $parts['pkey'])) {
            throw new \InvalidArgumentException('O A1 não contém certificado e chave privada correspondentes.');
        }
        $key = @openssl_pkey_get_private($parts['pkey']);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (!$details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 2048) {
            throw new \InvalidArgumentException('Utilize um certificado A1 com chave RSA de pelo menos 2048 bits.');
        }
        if (stripos((string) ($parsed['extensions']['basicConstraints'] ?? ''), 'CA:TRUE') !== false) {
            throw new \InvalidArgumentException('O arquivo é um certificado de autoridade certificadora, não um certificado A1 de contribuinte.');
        }
        $taxIds = self::extractTaxIds($parts['cert'], $parsed);
        $meta = [
            'subject' => (string) ($parsed['subject']['CN'] ?? ''),
            'issuer' => (string) ($parsed['issuer']['CN'] ?? ''),
            'serial' => (string) ($parsed['serialNumberHex'] ?? ''),
            'tax_id' => $taxIds[0] ?? '',
            'tax_ids' => $taxIds,
            'valid_from' => gmdate('c', (int) ($parsed['validFrom_time_t'] ?? 0)),
            'valid_to' => gmdate('c', (int) ($parsed['validTo_time_t'] ?? 0)),
            'valid_from_timestamp' => (int) ($parsed['validFrom_time_t'] ?? 0),
            'valid_to_timestamp' => (int) ($parsed['validTo_time_t'] ?? 0),
            'fingerprint_sha256' => (string) openssl_x509_fingerprint($parts['cert'], 'sha256'),
            'bits' => (int) $details['bits'],
        ];
        $self = new self($parts['cert'], $parts['pkey'], $meta);
        $self->assertUsable($expectedTaxId !== '' ? $expectedTaxId : null);
        return $self;
    }

    public function metadata(): array { return $this->meta; }
    public function certificatePem(): string { return $this->certificate; }
    public function privateKeyPem(): string { return $this->privateKey; }

    public function assertUsable(?string $expectedTaxId = null): void
    {
        $now = time();
        if ($now < $this->meta['valid_from_timestamp']) {
            throw new \InvalidArgumentException('O certificado A1 ainda não está válido. Confira também a data e hora do servidor.');
        }
        if ($now >= $this->meta['valid_to_timestamp']) {
            throw new \InvalidArgumentException('O certificado A1 está vencido. Substitua o arquivo nas configurações.');
        }
        if ($expectedTaxId !== null && $expectedTaxId !== '') {
            $expectedTaxId = self::normalizeTaxId($expectedTaxId);
            if (!in_array($expectedTaxId, $this->meta['tax_ids'], true)) {
                throw new \InvalidArgumentException('O CPF/CNPJ do certificado A1 não corresponde ao prestador configurado.');
            }
        }
    }

    private static function normalizeTaxId(string $value): string
    {
        return preg_replace('/[^0-9A-Z]/', '', strtoupper($value)) ?? '';
    }

    private static function extractTaxIds(string $pem, array $parsed): array
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem) ?? '', true);
        $ids = [];
        if (is_string($der)) {
            // ICP-Brasil otherName OIDs: CNPJ 2.16.76.1.3.3; birth date + CPF 2.16.76.1.3.1.
            foreach (["\x06\x05\x60\x4c\x01\x03\x03" => 'cnpj', "\x06\x05\x60\x4c\x01\x03\x01" => 'cpf'] as $oid => $kind) {
                $offset = 0;
                while (($pos = strpos($der, $oid, $offset)) !== false) {
                    $offset = $pos + strlen($oid);
                    try {
                        $node = self::readDer($der, $offset);
                        $text = self::derText($node['value'], $node['tag']);
                        $value = self::normalizeTaxId($text);
                        if ($kind === 'cnpj' && preg_match('/^[0-9A-Z]{12}[0-9]{2}$/D', $value)) {
                            $ids[] = $value;
                        } elseif ($kind === 'cpf' && preg_match('/^[0-9]{19}/', $value)) {
                            $ids[] = substr($value, 8, 11);
                        }
                    } catch (\Throwable $ignored) {
                        // The subject CN below is the interoperable fallback for older A1 issuers.
                    }
                }
            }
        }
        $cn = (string) ($parsed['subject']['CN'] ?? '');
        if (preg_match('/:([0-9A-Za-z]{14}|[0-9]{11})\s*$/D', $cn, $matches)) {
            $ids[] = strtoupper($matches[1]);
        }
        return array_values(array_unique($ids));
    }

    private static function readDer(string $der, int $offset): array
    {
        $length = strlen($der);
        if ($offset + 2 > $length) { throw new \UnexpectedValueException('DER incompleto.'); }
        $tag = ord($der[$offset++]);
        $size = ord($der[$offset++]);
        if ($size & 0x80) {
            $count = $size & 0x7f;
            if ($count < 1 || $count > 4 || $offset + $count > $length) {
                throw new \UnexpectedValueException('Comprimento DER inválido.');
            }
            $size = 0;
            for ($i = 0; $i < $count; $i++) { $size = ($size << 8) | ord($der[$offset++]); }
        }
        if ($size > 4194304 || $offset + $size > $length) { throw new \UnexpectedValueException('DER excede o certificado.'); }
        return ['tag' => $tag, 'value' => substr($der, $offset, $size), 'end' => $offset + $size];
    }

    private static function derText(string $value, int $tag, int $depth = 0): string
    {
        if ($depth > 6) { return ''; }
        if (in_array($tag, [12, 18, 19, 20, 22], true)) { return $value; }
        if (($tag & 0x20) !== 0 || $tag === 4) {
            $text = '';
            for ($offset = 0; $offset < strlen($value);) {
                $node = self::readDer($value, $offset);
                $text .= self::derText($node['value'], $node['tag'], $depth + 1);
                $offset = $node['end'];
            }
            return $text;
        }
        return '';
    }

    private static function clearErrors(): void
    {
        while (openssl_error_string() !== false) {}
    }
}
