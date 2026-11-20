<?php

declare(strict_types=1);

namespace Multiempresa\Contracts;

interface EventAuthenticator
{
    /** Returns a stable provider event id only after authentication; payload company_id is never authority. */
    public function authenticate(array $headers, string $body, string $secret, int $now): string;
}
