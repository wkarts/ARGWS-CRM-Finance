<?php

declare(strict_types=1);

namespace Multiempresa\Infrastructure;

final class PrefixStorage extends MySqlStorage
{
    public function name(): string
    {
        return 'prefix';
    }
}
