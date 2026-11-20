<?php

declare(strict_types=1);

namespace Multiempresa\Contracts;

use Multiempresa\Domain\Context;

interface EventProcessor
{
    /** Must be idempotent in the destination transaction. Throw on incomplete delivery. */
    public function process(Context $context, array $event): void;
}
