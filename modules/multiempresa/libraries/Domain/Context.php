<?php

declare(strict_types=1);

namespace Multiempresa\Domain;

/** Constructed once per HTTP request or child process; never changes database mid-request. */
final class Context
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $organizationId,
        public readonly string $mode,
        public readonly string $prefix,
        public readonly string $baseUrl,
        public readonly string $route,
        public readonly array $connection,
        public readonly string $encryptionKey,
        public readonly ?array $principal = null,
    ) {
        Id::prefix($prefix, $mode);
        if (!preg_match('/\A[a-z][a-z0-9_]{0,15}\z/', $mode)) {
            throw new Failure('storage_mode', 'Identificador de armazenamento inválido.');
        }
        if (!preg_match('/\A[a-f0-9]{32}\z/', $companyId)) {
            throw new Failure('company_id', 'Empresa inválida.');
        }
        if (strlen($encryptionKey) !== 32) {
            throw new Failure('encryption_key', 'Chave de criptografia empresarial inválida.');
        }
    }
    public function namespace(string $key): string
    {
        return 'pme:' . $this->companyId . ':' . $key;
    }
}
