<?php

declare(strict_types=1);

namespace App\Application\Shared\Port;

use App\Domain\SharedKernel\ValueObject\Slug;

interface SlugGeneratorInterface
{
    public function generate(string $value): Slug;
}
