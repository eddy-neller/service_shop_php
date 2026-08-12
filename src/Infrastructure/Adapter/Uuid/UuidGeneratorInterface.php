<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Uuid;

interface UuidGeneratorInterface
{
    public function generate(): string;
}
