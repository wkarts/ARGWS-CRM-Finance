<?php
declare(strict_types=1);
namespace NfseNacional;

final class Crypto
{
    private string $key;
    private string $scope;

    public function __construct(string $secret, string $scope)
    {
        if (strlen($secret) < 16 || $scope === '') {
            throw new \RuntimeException('A chave de criptografia da instalação deve estar configurada antes de usar a NFS-e.');
        }
        $this->key = hash_hkdf('sha256', $secret, 32, 'nfse_nacional:v1', $scope);
        $this->scope = $scope;
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $this->scope, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Não foi possível proteger o documento fiscal.');
        }
        return 'v1:' . base64_encode($nonce . $tag . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $binary = strncmp($encoded, 'v1:', 3) === 0 ? base64_decode(substr($encoded, 3), true) : false;
        if ($binary === false || strlen($binary) < 28) {
            throw new \RuntimeException('Conteúdo fiscal protegido inválido.');
        }
        $plain = openssl_decrypt(substr($binary, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($binary, 0, 12), substr($binary, 12, 16), $this->scope);
        if ($plain === false) {
            throw new \RuntimeException('Não foi possível abrir o conteúdo protegido desta empresa. Confira a chave de criptografia e o backup.');
        }
        return $plain;
    }
}
