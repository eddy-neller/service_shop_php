<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Command;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductImageStorageInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\UseCase\Command\DeleteProductByAdmin\DeleteProductByAdminCommand;
use App\Application\Catalog\UseCase\Command\DeleteProductByAdmin\DeleteProductByAdminCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
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

final class DeleteProductByAdminTest extends TestCase
{
    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440001';

    private ProductRepositoryInterface&MockObject $productRepository;

    private CategoryRepositoryInterface&MockObject $categoryRepository;

    private ProductImageStorageInterface&MockObject $imageStorage;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<\App\Domain\SharedKernel\Event\DomainEventInterface> */
    private array $publishedEvents = [];

    private DeleteProductByAdminCommandHandler $handler;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->imageStorage = $this->createMock(ProductImageStorageInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);
        $this->publishedEvents = [];
        $eventBus = $this->createStub(DomainEventBusInterface::class);
        $eventBus->method('publishAll')->willReturnCallback(function (array $events): void {
            $this->publishedEvents = [...$this->publishedEvents, ...$events];
        });
        $this->handler = new DeleteProductByAdminCommandHandler(
            $this->productRepository,
            $this->categoryRepository,
            $this->imageStorage,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleDeletesProductAndUpdatesCategory(): void
    {
        $now = new DateTimeImmutable('2024-03-01 10:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);
        $category = $this->createCategory($categoryId);
        $category->increaseProductCount(new DateTimeImmutable('2024-02-01 10:00:00'));

        $command = new DeleteProductByAdminCommand($productId->toString());

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);

        $this->categoryRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Category $savedCategory) use ($category, $now): bool {
                return $savedCategory === $category
                    && 0 === $savedCategory->getProductCount()
                    && $savedCategory->getUpdatedAt() === $now;
            }));

        $this->productRepository->expects($this->once())
            ->method('delete')
            ->with($product);

        $this->imageStorage->expects($this->never())->method('remove');

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());

        $this->handler->handle($command);

        $this->assertSame($now, $product->getUpdatedAt());
    }

    public function testHandleRemovesTheProductImageAfterTheTransactionCommits(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId, 'old-image.jpg');
        $category = $this->createCategory($categoryId);
        $category->increaseProductCount(new DateTimeImmutable());

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);
        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn($category);
        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn(new DateTimeImmutable());
        $this->categoryRepository->expects($this->once())->method('save')->with($category);
        $this->productRepository->expects($this->once())->method('delete')->with($product);
        $this->expectTransactionalPassthrough();

        $this->imageStorage->expects($this->once())
            ->method('remove')
            ->with('old-image.jpg');

        $this->handler->handle(new DeleteProductByAdminCommand($productId->toString()));
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

        $productId = ProductId::fromString(self::PRODUCT_ID);
        $command = new DeleteProductByAdminCommand($productId->toString());

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn(null);

        $this->imageStorage->expects($this->never())->method('remove');

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsWhenCategoryNotFound(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $categoryId = CategoryId::fromString(self::CATEGORY_ID);
        $product = $this->createProduct($productId, $categoryId);

        $command = new DeleteProductByAdminCommand($productId->toString());

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->clock->expects($this->never())
            ->method('now');

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($categoryId)
            ->willReturn(null);

        $this->imageStorage->expects($this->never())->method('remove');

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Category not found.');

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                $callback();
            });

        $this->handler->handle($command);
    }

    private function createProduct(ProductId $productId, CategoryId $categoryId, ?string $imageName = null): Product
    {
        $product = Product::create(
            id: $productId,
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1299),
            slug: Slug::fromString('product-title'),
            categoryId: $categoryId,
            now: new DateTimeImmutable('2024-01-01 09:00:00'),
        );

        if (null !== $imageName) {
            $product->updateImage($imageName, new DateTimeImmutable());
        }

        $product->clearDomainEvents();

        return $product;
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

    private function expectTransactionalPassthrough(): void
    {
        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
    }
}
