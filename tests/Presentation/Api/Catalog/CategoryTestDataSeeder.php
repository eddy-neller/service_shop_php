<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Api\Catalog;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryDescription;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;

final readonly class CategoryTestDataSeeder
{
    public const string ANCHOR_CATEGORY_TITLE = 'Shop category level 1 title 1';

    public function __construct(
        private CategoryRepositoryInterface $categories,
        private TransactionalInterface $transactional,
    ) {
    }

    public function seed(): void
    {
        $now = new DateTimeImmutable();

        $this->transactional->transactional(function () use ($now): void {
            $rootOne = $this->category('Shop category level 0 title 1', $now);
            $rootTwo = $this->category('Shop category level 0 title 2', $now);
            $this->categories->save($rootOne);
            $this->categories->save($rootTwo);

            $anchor = $this->category(
                self::ANCHOR_CATEGORY_TITLE,
                $now,
                $rootOne->getId(),
                "Description de la categorie d'ancrage.",
            );
            $this->categories->save($anchor);
            $this->categories->save($this->category('Shop category level 1 title 2', $now, $rootOne->getId()));
            $this->categories->save($this->category('Shop category level 1 title 3', $now, $rootTwo->getId()));
            $this->categories->save($this->category('Shop category level 2 title 1', $now, $anchor->getId()));
        });
    }

    private function category(
        string $title,
        DateTimeImmutable $now,
        ?CategoryId $parentId = null,
        ?string $description = null,
    ): Category {
        return Category::create(
            id: $this->categories->nextIdentity(),
            title: CategoryTitle::fromString($title),
            slug: Slug::fromString($this->slugify($title)),
            now: $now,
            parentId: $parentId,
            description: null === $description ? null : CategoryDescription::fromString($description),
        );
    }

    private function slugify(string $value): string
    {
        return strtolower(str_replace([' ', "'"], ['-', ''], $value));
    }
}
