<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Catalog;

use App\Domain\Catalog\Model\Product as DomainProduct;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;

final readonly class ProductMapper
{
    public function toDomain(ProductDocument $document): DomainProduct
    {
        return DomainProduct::reconstitute(
            id: ProductId::fromString($document->id),
            title: ProductTitle::fromString($document->title),
            subtitle: ProductSubtitle::fromString($document->subtitle),
            description: ProductDescription::fromString($document->description),
            price: Money::fromInt($document->priceAmount, $document->priceCurrency),
            slug: Slug::fromString($document->slug),
            categoryId: CategoryId::fromString($document->categoryId),
            imageName: $document->imageName,
            createdAt: $document->createdAt,
            updatedAt: $document->updatedAt,
        );
    }

    public function toDocument(DomainProduct $product, ?ProductDocument $document = null): ProductDocument
    {
        if (null === $document) {
            $document = new ProductDocument();
            $document->id = $product->getId()->toString();
        }

        $document->title = $product->getTitle()->toString();
        $document->subtitle = $product->getSubtitle()->toString();
        $document->description = $product->getDescription()->toString();
        $document->priceAmount = $product->getPrice()->amount();
        $document->priceCurrency = $product->getPrice()->currency();
        $document->slug = $product->getSlug()->toString();
        $document->categoryId = $product->getCategoryId()->toString();
        $document->imageName = $product->getImageName();
        $document->createdAt = $product->getCreatedAt();
        $document->updatedAt = $product->getUpdatedAt();

        return $document;
    }
}
