<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Cache;

interface QueryCacheInterface
{
    public function get(string $key, int $ttlSeconds, array $tags, callable $callback): mixed;

    public function invalidateTags(array $tags): void;
}
