<?php

declare(strict_types=1);

namespace App\Application\Shared\Port;

interface ConfigInterface
{
    public function get(string $key): mixed;

    public function getString(string $key, ?string $default = null): string;

    public function has(string $key): bool;
}
