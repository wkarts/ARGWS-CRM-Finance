<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

use Multiempresa\Domain\Failure;

final class Vault
{
    private string $key;
    public function __construct(string $encoded)
    {
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new Failure('master_key', 'A chave mestra deve conter 32 bytes em Base64.', 503);
        }
        $this->key = $key;
    }
    public function seal(array $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(json_encode($value, JSON_THROW_ON_ERROR), $nonce, $this->key));
    }
    public function open(string $value): array
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 40) {
            throw new Failure('vault_integrity', 'Segredo inválido.', 503);
        }
        $text = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), $this->key);
        if ($text === false) {
            throw new Failure('vault_integrity', 'Não foi possível validar o segredo.', 503);
        }
        return json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    }
    public function mac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }
}
