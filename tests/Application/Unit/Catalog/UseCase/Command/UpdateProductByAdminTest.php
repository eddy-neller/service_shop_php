<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Command;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\UseCase\Command\UpdateProductByAdmin\UpdateProductByAdminCommand;
use App\Application\Catalog\UseCase\Command\UpdateProductByAdmin\UpdateProductByAdminCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\SlugGeneratorInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\Exception\ProductTitleAlreadyUsedException;
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

final class UpdateProductByAdminTest extends TestCase
{
    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440001';

    private const string NEW_CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440002';

    private ProductRepositoryInterface&MockObject $productRepository;

    private CategoryRepositoryInterface&MockObject $categoryRepository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    private SlugGeneratorInterface&MockObject $slugGenerator;

    private UpdateProductByAdminCommandHandler $handler;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);
        $this->slugGenerator = $this->createMock(SlugGeneratorInterface::class);
        $this->handler = new UpdateProductByAdminCommandHandler(
            $this->productRepository,
            $this->categoryRepository,
            $this->clock,
            $this->transactional,
            $this->slugGenerator,
        );
    }

    public function testHandleUpdatesAllFieldsAndMovesCategory(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $oldCategoryId = CategoryId::fromString(self::CATEGORY_ID);
        $newCategoryId = CategoryId::fromString(self::NEW_CATEGORY_ID);
        $product = $this->createProduct($productId, $oldCategoryId);
        $oldCategory = $this->createCategory($oldCategoryId, 'Old category', 'old-category');
        $oldCategory->increaseProductCount(new DateTimeImmutable('2024-01-01 10:00:00'));

        $newCategory = $this->createCategory($newCategoryId, 'New category', 'new-category');
        $slug = Slug::fromString('new-title');

        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: 'New title',
            subtitle: 'New subtitle',
            description: 'New description',
            price: 24.99,
            categoryId: $newCategoryId->toString(),
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->productRepository->expects($this->once())
            ->method('findByTitle')
            ->with(ProductTitle::fromString('New title'))
            ->willReturn(null);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('New title')
            ->willReturn($slug);

        $this->categoryRepository->expects($this->exactly(3))
            ->method('findById')
            ->willReturnCallback(function (CategoryId $id) use ($oldCategoryId, $newCategoryId, $oldCategory, $newCategory): ?Category {
                if ($id->equals($oldCategoryId)) {
                    return $oldCategory;
                }

                if ($id->equals($newCategoryId)) {
                    return $newCategory;
                }

                return null;
            });

        $this->categoryRepository->expects($this->exactly(2))
            ->method('save')
            ->with($this->callback(function (Category $category) use ($oldCategory, $newCategory, $now): bool {
                if ($category === $oldCategory) {
                    return 0 === $category->getProductCount() && $category->getUpdatedAt() === $now;
                }

                if ($category === $newCategory) {
                    return 1 === $category->getProductCount() && $category->getUpdatedAt() === $now;
                }

                return false;
            }));

        $this->productRepository->expects($this->once())
            ->method('save')
            ->with($product);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $output = $this->handler->handle($command);

        $this->assertSame($newCategory->getId()->toString(), $output->category->id);
        $this->assertSame('New title', $product->getTitle()->toString());
        $this->assertSame('New subtitle', $product->getSubtitle()->toString());
        $this->assertSame('New description', $product->getDescription()->toString());
        $this->assertTrue($product->getPrice()->equals(Money::fromInt(2499)));
        $this->assertTrue($product->getCategoryId()->equals($newCategoryId));
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testHandleUpdatesOnlyProvidedFields(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);
        $category = $this->createCategory($categoryId, 'Category', 'category');

        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: null,
            subtitle: null,
            description: 'New description',
            price: null,
            categoryId: null,
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->slugGenerator->expects($this->never())
            ->method('generate');

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->productRepository->expects($this->once())
            ->method('save')
            ->with($product);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $output = $this->handler->handle($command);

        $this->assertSame($category->getId()->toString(), $output->category->id);
        $this->assertSame('New description', $product->getDescription()->toString());
        $this->assertSame('Product title', $product->getTitle()->toString());
        $this->assertSame('Product subtitle', $product->getSubtitle()->toString());
    }

    public function testHandleUpdatesSubtitleAndKeepsTitle(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);
        $category = $this->createCategory($categoryId, 'Category', 'category');

        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: null,
            subtitle: 'Updated subtitle',
            description: null,
            price: null,
            categoryId: null,
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->slugGenerator->expects($this->never())
            ->method('generate');

        $this->productRepository->expects($this->once())
            ->method('save')
            ->with($product);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $output = $this->handler->handle($command);

        $this->assertSame($category->getId()->toString(), $output->category->id);
        $this->assertSame('Product title', $product->getTitle()->toString());
        $this->assertSame('Updated subtitle', $product->getSubtitle()->toString());
        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testHandleThrowsWhenCurrentCategoryIsMissingDuringMove(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $oldCategoryId = CategoryId::fromString(self::CATEGORY_ID);
        $newCategoryId = CategoryId::fromString(self::NEW_CATEGORY_ID);
        $product = $this->createProduct($productId, $oldCategoryId);
        $newCategory = $this->createCategory($newCategoryId, 'New category', 'new-category');

        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: null,
            subtitle: null,
            description: null,
            price: null,
            categoryId: $newCategoryId->toString(),
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->slugGenerator->expects($this->never())
            ->method('generate');

        $this->categoryRepository->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function (CategoryId $id) use ($oldCategoryId, $newCategoryId, $newCategory): ?Category {
                if ($id->equals($oldCategoryId)) {
                    return null;
                }

                if ($id->equals($newCategoryId)) {
                    return $newCategory;
                }

                return null;
            });

        $this->categoryRepository->expects($this->never())
            ->method('save');

        $this->productRepository->expects($this->never())
            ->method('save');

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Current category not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenProductNotFound(): void
    {
        $this->categoryRepository->expects($this->never())
            ->method('findById');
        $this->clock->expects($this->never())
            ->method('now');
        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $this->slugGenerator->expects($this->never())
            ->method('generate');

        $productId = ProductId::fromString(self::PRODUCT_ID);
        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: null,
            subtitle: null,
            description: null,
            price: null,
            categoryId: null,
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn(null);

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenNewCategoryNotFound(): void
    {
        $this->slugGenerator->expects($this->never())
            ->method('generate');

        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $oldCategoryId = CategoryId::fromString(self::CATEGORY_ID);
        $newCategoryId = CategoryId::fromString(self::NEW_CATEGORY_ID);
        $product = $this->createProduct($productId, $oldCategoryId);
        $oldCategory = $this->createCategory($oldCategoryId, 'Old category', 'old-category');

        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: null,
            subtitle: null,
            description: null,
            price: null,
            categoryId: $newCategoryId->toString(),
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->categoryRepository->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function (CategoryId $id) use ($oldCategoryId, $newCategoryId, $oldCategory): ?Category {
                if ($id->equals($oldCategoryId)) {
                    return $oldCategory;
                }

                if ($id->equals($newCategoryId)) {
                    return null;
                }

                return null;
            });

        $this->productRepository->expects($this->never())
            ->method('save');

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('New category not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenCategoryMissing(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);

        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: 'New title',
            subtitle: null,
            description: null,
            price: null,
            categoryId: null,
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->productRepository->expects($this->once())
            ->method('findByTitle')
            ->with(ProductTitle::fromString('New title'))
            ->willReturn(null);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('New title')
            ->willReturn(Slug::fromString('new-title'));

        $this->productRepository->expects($this->once())
            ->method('save')
            ->with($product);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn(null);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Category not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenTitleBelongsToAnotherProduct(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);
        $otherProduct = $this->createProduct(
            ProductId::fromString('550e8400-e29b-41d4-a716-446655440009'),
            $categoryId,
        );

        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: 'New title',
            subtitle: null,
            description: null,
            price: null,
            categoryId: null,
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->productRepository->expects($this->once())
            ->method('findByTitle')
            ->with(ProductTitle::fromString('New title'))
            ->willReturn($otherProduct);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('New title')
            ->willReturn(Slug::fromString('new-title'));

        $this->productRepository->expects($this->never())
            ->method('save');

        $this->categoryRepository->expects($this->never())
            ->method('findById');

        $this->categoryRepository->expects($this->never())
            ->method('save');

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $this->expectException(ProductTitleAlreadyUsedException::class);

        $this->handler->handle($command);
    }

    public function testHandleSucceedsWhenTitleBelongsToSameProduct(): void
    {
        $now = new DateTimeImmutable('2024-02-01 12:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);
        $category = $this->createCategory($categoryId, 'Category', 'category');

        $command = new UpdateProductByAdminCommand(
            productId: $productId->toString(),
            title: 'New title',
            subtitle: null,
            description: null,
            price: null,
            categoryId: null,
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->productRepository->expects($this->once())
            ->method('findByTitle')
            ->with(ProductTitle::fromString('New title'))
            ->willReturn($product);

        $this->slugGenerator->expects($this->once())
            ->method('generate')
            ->with('New title')
            ->willReturn(Slug::fromString('new-title'));

        $this->productRepository->expects($this->once())
            ->method('save')
            ->with($product);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $output = $this->handler->handle($command);

        $this->assertSame($category->getId()->toString(), $output->category->id);
        $this->assertSame('New title', $product->getTitle()->toString());
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

    private function createCategory(CategoryId $categoryId, string $title, string $slug): Category
    {
        return Category::create(
            id: $categoryId,
            title: CategoryTitle::fromString($title),
            slug: Slug::fromString($slug),
            now: new DateTimeImmutable('2024-01-01 09:00:00'),
        );
    }
}
