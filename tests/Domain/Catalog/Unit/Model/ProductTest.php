<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Unit\Model;

use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductImage;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440001';

    public function testCreateSetsDefaults(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');

        $product = Product::create(
            id: ProductId::fromString(self::PRODUCT_ID),
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1299),
            slug: Slug::fromString('product-title'),
            categoryId: CategoryId::fromString(self::CATEGORY_ID),
            now: $now,
        );

        $this->assertTrue($product->getId()->equals(ProductId::fromString(self::PRODUCT_ID)));
        $this->assertSame('Product title', $product->getTitle()->toString());
        $this->assertSame('Product subtitle', $product->getSubtitle()->toString());
        $this->assertSame('Product description', $product->getDescription()->toString());
        $this->assertTrue($product->getPrice()->equals(Money::fromInt(1299)));
        $this->assertSame('product-title', $product->getSlug()->toString());
        $this->assertTrue($product->getCategoryId()->equals(CategoryId::fromString(self::CATEGORY_ID)));
        $this->assertNull($product->getImageName());
        $this->assertSame($now, $product->getCreatedAt());
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testRenameUpdatesTitleSubtitleAndUpdatedAt(): void
    {
        $product = $this->createProduct();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $product->rename(
            ProductTitle::fromString('New title'),
            ProductSubtitle::fromString('New subtitle'),
            $now,
        );

        $this->assertSame('New title', $product->getTitle()->toString());
        $this->assertSame('New subtitle', $product->getSubtitle()->toString());
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testRepriceUpdatesPriceAndUpdatedAt(): void
    {
        $product = $this->createProduct();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');
        $price = Money::fromInt(2599);

        $product->reprice($price, $now);

        $this->assertTrue($product->getPrice()->equals($price));
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testRewriteUpdatesDescriptionAndUpdatedAt(): void
    {
        $product = $this->createProduct();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');
        $description = ProductDescription::fromString('New description');

        $product->rewrite($description, $now);

        $this->assertSame($description, $product->getDescription());
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testMoveToCategoryUpdatesCategoryAndUpdatedAt(): void
    {
        $product = $this->createProduct();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');
        $categoryId = CategoryId::fromString('550e8400-e29b-41d4-a716-446655440002');

        $product->moveToCategory($categoryId, $now);

        $this->assertTrue($product->getCategoryId()->equals($categoryId));
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testReSlugUpdatesSlugAndUpdatedAt(): void
    {
        $product = $this->createProduct();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $product->reSlug(Slug::fromString('new-slug'), $now);

        $this->assertSame('new-slug', $product->getSlug()->toString());
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testUpdateImageStoresNameAndTimestamp(): void
    {
        $product = $this->createProduct();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $product->updateImage(ProductImage::create('image.jpg'), $now);

        $this->assertSame('image.jpg', $product->getImageName());
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testDeleteTouchesUpdatedAt(): void
    {
        $product = $this->createProduct();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $product->delete($now);

        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testReconstituteRestoresState(): void
    {
        $createdAt = new DateTimeImmutable('2024-12-01 10:00:00');
        $updatedAt = new DateTimeImmutable('2024-12-10 10:00:00');
        $image = ProductImage::create('stored.jpg');

        $product = Product::reconstitute(
            id: ProductId::fromString(self::PRODUCT_ID),
            title: ProductTitle::fromString('Stored title'),
            subtitle: ProductSubtitle::fromString('Stored subtitle'),
            description: ProductDescription::fromString('Stored description'),
            price: Money::fromInt(4999),
            slug: Slug::fromString('stored-title'),
            categoryId: CategoryId::fromString(self::CATEGORY_ID),
            image: $image,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $this->assertTrue($product->getId()->equals(ProductId::fromString(self::PRODUCT_ID)));
        $this->assertSame('Stored title', $product->getTitle()->toString());
        $this->assertSame('Stored subtitle', $product->getSubtitle()->toString());
        $this->assertSame('Stored description', $product->getDescription()->toString());
        $this->assertTrue($product->getPrice()->equals(Money::fromInt(4999)));
        $this->assertSame('stored-title', $product->getSlug()->toString());
        $this->assertTrue($product->getCategoryId()->equals(CategoryId::fromString(self::CATEGORY_ID)));
        $this->assertSame('stored.jpg', $product->getImageName());
        $this->assertSame($createdAt, $product->getCreatedAt());
        $this->assertSame($updatedAt, $product->getUpdatedAt());
    }

    private function createProduct(): Product
    {
        return Product::create(
            id: ProductId::fromString(self::PRODUCT_ID),
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1299),
            slug: Slug::fromString('product-title'),
            categoryId: CategoryId::fromString(self::CATEGORY_ID),
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
        );
    }
}
