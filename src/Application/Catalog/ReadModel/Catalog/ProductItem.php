<?php

declare(strict_types=1);

namespace App\Application\Catalog\ReadModel\Catalog;

use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\Model\Product;
use DateTimeImmutable;

final readonly class ProductItem
{
    public function __construct(
        public string $id,
        public string $title,
        public string $subtitle,
        public string $description,
        public float $price,
        public string $slug,
        public ?string $imageName,
        public CategoryItem $category,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromProduct(Product $product, Category $category): self
    {
        return new self(
            id: $product->getId()->toString(),
            title: $product->getTitle()->toString(),
            subtitle: $product->getSubtitle()->toString(),
            description: $product->getDescription()->toString(),
            price: $product->getPrice()->toEuros(),
            slug: $product->getSlug()->toString(),
            imageName: $product->getImageName(),
            category: CategoryItem::fromCategory($category),
            createdAt: $product->getCreatedAt(),
            updatedAt: $product->getUpdatedAt(),
        );
    }
}
