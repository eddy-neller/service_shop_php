<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Query;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\UseCase\Query\DisplayProduct\DisplayProductQuery;
use App\Application\Catalog\UseCase\Query\DisplayProduct\DisplayProductQueryHandler;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisplayProductTest extends TestCase
{
    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440001';

    private ProductRepositoryInterface&MockObject $productRepository;

    private CategoryRepositoryInterface&MockObject $categoryRepository;

    private DisplayProductQueryHandler $handler;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->handler = new DisplayProductQueryHandler(
            $this->productRepository,
            $this->categoryRepository,
        );
    }

    public function testHandleReturnsProductViewWhenFound(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);
        $category = $this->createCategory($categoryId);
        $query = new DisplayProductQuery($productId->toString());

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $output = $this->handler->handle($query);

        $this->assertSame($product->getId()->toString(), $output->id);
        $this->assertSame($product->getTitle()->toString(), $output->title);
        $this->assertSame($category->getId()->toString(), $output->category->id);
    }

    public function testHandleThrowsWhenProductNotFound(): void
    {
        $this->categoryRepository->expects($this->never())
            ->method('findById');

        $productId = ProductId::fromString(self::PRODUCT_ID);
        $query = new DisplayProductQuery($productId->toString());

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn(null);

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product not found.');

        $this->handler->handle($query);
    }

    public function testHandleThrowsWhenCategoryNotFound(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);
        $query = new DisplayProductQuery($productId->toString());

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn(null);

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Category not found.');

        $this->handler->handle($query);
    }

    private function createProduct(ProductId $productId, CategoryId $categoryId): Product
    {
        return Product::create(
            id: $productId,
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1299),
            slug: Slug::fromString('product-title'),
            categoryId: $categoryId,
            now: new DateTimeImmutable('2024-01-01 09:00:00'),
        );
    }

    private function createCategory(CategoryId $categoryId): Category
    {
        return Category::create(
            id: $categoryId,
            title: CategoryTitle::fromString('Category title'),
            slug: Slug::fromString('category-title'),
            now: new DateTimeImmutable('2024-01-01 09:00:00'),
        );
    }
}
