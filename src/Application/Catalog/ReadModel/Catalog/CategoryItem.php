<?php

declare(strict_types=1);

namespace App\Application\Catalog\ReadModel\Catalog;

use App\Domain\Catalog\Model\Category;
use DateTimeImmutable;

final readonly class CategoryItem
{
    /**
     * @param list<CategoryItem>|null $children
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public int $nbProduct,
        public string $slug,
        public int $level,
        public bool $hasChildren,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?CategoryItem $parent = null,
        public ?array $children = null,
    ) {
    }

    /**
     * @param list<Category>|null $children
     */
    public static function fromCategory(Category $category, ?Category $parent = null, ?array $children = null): self
    {
        return new self(
            id: $category->getId()->toString(),
            title: $category->getTitle()->toString(),
            description: $category->getDescription()?->toString(),
            nbProduct: $category->getProductCount(),
            slug: $category->getSlug()->toString(),
            level: $category->getLevel(),
            hasChildren: $category->hasChildren(),
            createdAt: $category->getCreatedAt(),
            updatedAt: $category->getUpdatedAt(),
            parent: null === $parent ? null : self::fromCategory($parent),
            children: null === $children ? null : array_map(
                static fn (Category $child): self => self::fromCategory($child),
                $children,
            ),
        );
    }
}
