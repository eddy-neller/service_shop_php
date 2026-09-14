<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Catalog;

use App\Application\Shared\Port\SlugGeneratorInterface;
use App\Domain\SharedKernel\ValueObject\Slug;
use Symfony\Component\String\Slugger\AsciiSlugger;

final readonly class SymfonySlugGenerator implements SlugGeneratorInterface
{
    private AsciiSlugger $slugger;

    public function __construct(
        ?AsciiSlugger $slugger = null,
    ) {
        $this->slugger = $slugger ?? new AsciiSlugger();
    }

    public function generate(string $value): Slug
    {
        $slug = $this->slugger->slug($value)->lower()->toString();

        return Slug::fromString($slug);
    }
}
