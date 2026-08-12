<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Uuid;

use Ramsey\Uuid\Uuid;

final class RamseyUuidGenerator implements UuidGeneratorInterface
{
    public function generate(): string
    {
        return Uuid::uuid4()->toString();
    }
}
