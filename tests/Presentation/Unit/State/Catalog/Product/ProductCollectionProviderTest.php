<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Catalog\Product;

use ApiPlatform\Metadata\GetCollection;
use App\Application\Catalog\Port\ProductImageUrlResolverInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Catalog\ReadModel\Catalog\ProductList;
use App\Application\Catalog\UseCase\Query\DisplayListProduct\DisplayListProductQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Catalog\Model\Category as DomainCategory;
use App\Domain\Catalog\Model\Product as DomainProduct;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use App\Presentation\Catalog\ApiResource\ProductResource;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Catalog\Presenter\ProductResourcePresenter;
use App\Presentation\Catalog\State\Product\ProductCollectionProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProductCollectionProviderTest extends TestCase
{
    public function testItMapsProductsToResourcesAndSetsPagination(): void
    {
        $request = new Request();
        $queryBus = $this->createMock(QueryBusInterface::class);
        $category = $this->createCategory();
        $product = $this->createProduct($category->getId());
        $output = new ProductList([
            ProductItem::fromProduct($product, $category),
        ], 5, 3);

        $queryBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($output): ProductList {
                $this->assertInstanceOf(DisplayListProductQuery::class, $query);
                $this->assertSame('2', $query->page);
                $this->assertSame('15', $query->itemsPerPage);
                $this->assertSame('Product', $query->filters['title'] ?? null);
                $this->assertSame('Subtitle', $query->filters['subtitle'] ?? null);
                $this->assertSame('Nice', $query->filters['description'] ?? null);
                $this->assertSame('/api/shop/categories/550e8400-e29b-41d4-a716-446655440000', $query->filters['category'] ?? null);
                $this->assertSame(['createdAt' => 'ASC'], $query->orderBy);

                return $output;
            });

        $imageUrlResolver = $this->createMock(ProductImageUrlResolverInterface::class);
        $imageUrlResolver
            ->expects($this->once())
            ->method('resolve')
            ->with('product.jpg')
            ->willReturn('/uploads/product.jpg');

        $provider = new ProductCollectionProvider(
            queryBus: $queryBus,
            productResourcePresenter: new ProductResourcePresenter(
                $imageUrlResolver,
                new CategoryResourcePresenter(),
            ),
        );

        $result = $provider->provide(
            new GetCollection(name: 'shop-products-col'),
            context: [
                'request' => $request,
                'filters' => [
                    'page' => '2',
                    'itemsPerPage' => '15',
                    'title' => 'Product',
                    'subtitle' => 'Subtitle',
                    'description' => 'Nice',
                    'category' => '/api/shop/categories/550e8400-e29b-41d4-a716-446655440000',
                    'order' => [
                        'createdAt' => 'asc',
                    ],
                ],
            ],
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ProductResource::class, $result[0]);
        $this->assertSame('Product title', $result[0]->title);
        $this->assertSame('/uploads/product.jpg', $result[0]->imageUrl);
        $this->assertSame('Category title', $result[0]->category->title);
        $this->assertSame(5, $request->attributes->get('_total_items'));
        $this->assertSame(3, $request->attributes->get('_total_pages'));
    }

    public function testItHandlesInvalidFiltersWithoutRequest(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);
        $category = $this->createCategory();
        $product = $this->createProduct($category->getId());
        $output = new ProductList([
            ProductItem::fromProduct($product, $category),
        ], 1, 1);

        $queryBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($output): ProductList {
                $this->assertInstanceOf(DisplayListProductQuery::class, $query);
                $this->assertNull($query->page);
                $this->assertNull($query->itemsPerPage);
                $this->assertSame([], $query->filters);
                $this->assertSame([], $query->orderBy);

                return $output;
            });

        $imageUrlResolver = $this->createMock(ProductImageUrlResolverInterface::class);
        $imageUrlResolver
            ->expects($this->once())
            ->method('resolve')
            ->with('product.jpg')
            ->willReturn('/uploads/product.jpg');

        $provider = new ProductCollectionProvider(
            queryBus: $queryBus,
            productResourcePresenter: new ProductResourcePresenter(
                $imageUrlResolver,
                new CategoryResourcePresenter(),
            ),
        );

        $result = $provider->provide(
            new GetCollection(name: 'shop-products-col'),
            context: [
                'filters' => 'not-an-array',
            ],
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ProductResource::class, $result[0]);
    }

    private function createCategory(): DomainCategory
    {
        return DomainCategory::reconstitute(
            id: CategoryId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            title: CategoryTitle::fromString('Category title'),
            slug: Slug::fromString('category-title'),
            createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2025-02-01 10:00:00'),
            productCount: 2,
            level: 1,
        );
    }

    private function createProduct(CategoryId $categoryId): DomainProduct
    {
        $product = DomainProduct::create(
            id: ProductId::fromString('1d2f4c1a-2b2b-4aa2-9a20-8b3e18f1d152'),
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Nice product'),
            price: Money::fromInt(1999),
            slug: Slug::fromString('product-title'),
            categoryId: $categoryId,
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        $product->updateImage('product.jpg', new DateTimeImmutable('2025-01-02 10:00:00'));

        return $product;
    }
}
