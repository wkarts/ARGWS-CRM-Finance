<?php

declare(strict_types=1);

namespace Multiempresa\Contracts;

interface MigrationAdapter
{
    public function preflight(array $company, string $releaseRoot): void;
    public function migrate(array $company, string $releaseRoot): void;
    public function verify(array $company, string $releaseRoot): void;
}
