<?php

declare(strict_types=1);

namespace Multiempresa\Domain;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 400, public readonly array $diagnostic = [])
    {
        parent::__construct($message);
    }
}
