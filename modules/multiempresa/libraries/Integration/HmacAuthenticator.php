<?php

declare(strict_types=1);

namespace Multiempresa\Integration;

use Multiempresa\Contracts\EventAuthenticator;
use Multiempresa\Domain\Failure;

final class HmacAuthenticator implements EventAuthenticator
{
    public function authenticate(array $headers, string $body, string $secret, int $now): string
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = (string)($headers['x-pme-timestamp'] ?? '');
        if (!ctype_digit($timestamp) || abs($now - (int)$timestamp) > 300 || !hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), (string)($headers['x-pme-signature'] ?? ''))) {
            throw new Failure('webhook_auth', 'Assinatura inválida ou expirada.', 401);
        }
        $event=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        $id=$event['id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_.:-]{1,190}\z/',$id)) { throw new Failure('event_id','Informe um ID estável para o evento.'); }
        return $id;
    }
}
