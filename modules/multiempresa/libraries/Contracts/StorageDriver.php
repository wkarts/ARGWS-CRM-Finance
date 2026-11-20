<?php

declare(strict_types=1);

namespace Multiempresa\Contracts;

interface StorageDriver
{
    public function name(): string;
    public function connect(array $credentials): \PDO;
    public function validate(array $credentials, string $prefix): void;
    public function tables(\PDO $pdo, string $prefix): array;
}
