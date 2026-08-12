<?php

declare(strict_types=1);

namespace App\Application\Shared\CQRS\Query;

interface CacheableQueryInterface extends QueryInterface
{
    public function cacheKey(): string;

    public function cacheTtl(): int;

    public function cacheTags(): array;
}
