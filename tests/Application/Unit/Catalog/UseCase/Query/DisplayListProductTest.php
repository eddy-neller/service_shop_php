<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Query;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Catalog\UseCase\Query\DisplayListProduct\DisplayListProductQuery;
use App\Application\Catalog\UseCase\Query\DisplayListProduct\DisplayListProductQueryHandler;
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

final class DisplayListProductTest extends TestCase
{
    private ProductRepositoryInterface&MockObject $repository;

    private DisplayListProductQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ProductRepositoryInterface::class);
        $this->handler = new DisplayListProductQueryHandler($this->repository);
    }

    public function testHandleReturnsProductsAndPagination(): void
    {
        $query = new DisplayListProductQuery(
            page: '2',
            itemsPerPage: '5',
            filters: [
                'title' => 'Product',
                'subtitle' => 'Subtitle',
            ],
            orderBy: ['title' => 'ASC'],
        );

        $categoryId = CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $product = $this->createProduct(ProductId::fromString('1d2f4c1a-2b2b-4aa2-9a20-8b3e18f1d152'), $categoryId);
        $category = $this->createCategory($categoryId);
        $item = ProductItem::fromProduct(
            product: $product,
            category: $category,
        );

        $this->repository->expects($this->once())
            ->method('list')
            ->with(['title' => 'Product', 'subtitle' => 'Subtitle'], ['title' => 'ASC'], 2, 5)
            ->willReturn(['items' => [['product' => $product, 'category' => $category]], 'totalItems' => 10, 'totalPages' => 2]);

        $output = $this->handler->handle($query);

        $this->assertEquals([$item], $output->items);
        $this->assertSame(10, $output->totalItems);
        $this->assertSame(2, $output->totalPages);
    }

    public function testHandleAppliesDefaultsWhenValuesAreInvalid(): void
    {
        $query = new DisplayListProductQuery(
            page: '0',
            itemsPerPage: '0',
            filters: [],
            orderBy: [],
        );

        $categoryId = CategoryId::fromString('550e8400-e29b-41d4-a716-446655440001');
        $product = $this->createProduct(ProductId::fromString('2d2f4c1a-2b2b-4aa2-9a20-8b3e18f1d153'), $categoryId);
        $category = $this->createCategory($categoryId);
        $item = ProductItem::fromProduct(
            product: $product,
            category: $category,
        );

        $this->repository->expects($this->once())
            ->method('list')
            ->with([], ['createdAt' => 'DESC'], 1, 30)
            ->willReturn(['items' => [['product' => $product, 'category' => $category]], 'totalItems' => 1, 'totalPages' => 1]);

        $output = $this->handler->handle($query);

        $this->assertEquals([$item], $output->items);
    }

    public function testQueryCacheKeyIsStableWhenFiltersAndOrderByAreReordered(): void
    {
        $this->repository->expects($this->never())->method('list');

        $queryA = new DisplayListProductQuery(
            page: '2',
            itemsPerPage: '5',
            filters: ['title' => 'Product', 'subtitle' => 'Sub'],
            orderBy: ['title' => 'ASC', 'createdAt' => 'DESC'],
        );
        $queryB = new DisplayListProductQuery(
            page: '2',
            itemsPerPage: '5',
            filters: ['subtitle' => 'Sub', 'title' => 'Product'],
            orderBy: ['createdAt' => 'DESC', 'title' => 'ASC'],
        );

        $this->assertSame($queryA->cacheKey(), $queryB->cacheKey());
    }

    public function testQueryCacheMetadata(): void
    {
        $this->repository->expects($this->never())->method('list');

        $query = new DisplayListProductQuery(
            page: '1',
            itemsPerPage: '10',
        );

        $this->assertSame(3600, $query->cacheTtl());
        $this->assertSame(['products-collection'], $query->cacheTags());
    }

    private function createCategory(CategoryId $categoryId): Category
    {
        return Category::reconstitute(
            id: $categoryId,
            title: CategoryTitle::fromString('Category title'),
            slug: Slug::fromString('category-title'),
            createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2025-01-02 10:00:00'),
            productCount: 0,
            level: 1,
        );
    }

    private function createProduct(ProductId $productId, CategoryId $categoryId): Product
    {
        return Product::create(
            id: $productId,
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1999),
            slug: Slug::fromString('product-title'),
            categoryId: $categoryId,
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
        );
    }
}
