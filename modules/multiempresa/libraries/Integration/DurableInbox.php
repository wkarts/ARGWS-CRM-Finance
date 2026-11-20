<?php

declare(strict_types=1);

namespace Multiempresa\Integration;

use Multiempresa\Contracts\EventProcessor;
use Multiempresa\Domain\Context;
use Multiempresa\Application\App;

/** A generic integration destination, not an implicit financial posting. */
final class DurableInbox implements EventProcessor
{
    public function __construct(private App $app)
    {
    }
    public function process(Context $context, array $event): void
    {
        $pdo = $this->app->storage($context->mode)->connect($context->connection);
        $stmt = $pdo->prepare('INSERT INTO `'.$context->prefix.'pme_inbox` (endpoint_id,event_id,payload,received_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE event_id=VALUES(event_id)');
        $stmt->execute([$event['endpoint_id'],$event['provider_event'],$this->app->vault->seal(['body' => $event['body']]),date('Y-m-d H:i:s')]);
    }
}
