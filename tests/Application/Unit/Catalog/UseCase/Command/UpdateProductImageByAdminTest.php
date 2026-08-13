<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Command;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductImageStorageInterface;
use App\Application\Catalog\Port\ProductImageValidatorInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\UseCase\Command\UpdateProductImageByAdmin\UpdateProductImageByAdminCommand;
use App\Application\Catalog\UseCase\Command\UpdateProductImageByAdmin\UpdateProductImageByAdminCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\FileInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Event\Product\ProductImageUpdatedEvent;
use App\Domain\Catalog\Exception\CategoryNotFoundException;
use App\Domain\Catalog\Exception\InvalidProductImageException;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpdateProductImageByAdminTest extends TestCase
{
    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440001';

    private const string STORED_NAME = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6.jpg';

    private ProductRepositoryInterface&MockObject $productRepository;

    private CategoryRepositoryInterface&MockObject $categoryRepository;

    private ProductImageStorageInterface&MockObject $imageStorage;

    private ProductImageValidatorInterface&MockObject $imageValidator;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private UpdateProductImageByAdminCommandHandler $handler;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->imageValidator = $this->createMock(ProductImageValidatorInterface::class);
        $this->imageStorage = $this->createMock(ProductImageStorageInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2024-05-01 08:00:00'));

        $this->publishedEvents = [];
        $eventBus = $this->createStub(DomainEventBusInterface::class);
        $eventBus->method('publishAll')->willReturnCallback(function (array $events): void {
            $this->publishedEvents = [...$this->publishedEvents, ...$events];
        });

        $this->handler = new UpdateProductImageByAdminCommandHandler(
            $this->productRepository,
            $this->categoryRepository,
            $this->imageValidator,
            $this->imageStorage,
            $clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleStoresTheFileAndUpdatesTheAggregate(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $product = $this->createProduct($productId);
        $category = $this->createCategory($product->getCategoryId());
        $file = $this->validFile();

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($product);

        $this->imageValidator->expects($this->once())
            ->method('validate')
            ->with($file);

        $this->imageStorage->expects($this->once())
            ->method('store')
            ->with($file)
            ->willReturn(self::STORED_NAME);

        $this->productRepository->expects($this->once())
            ->method('save')
            ->with($product);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($product->getCategoryId())
            ->willReturn($category);

        $this->expectTransactionalPassthrough();

        $output = $this->handler->handle($this->command($productId, $file));

        $this->assertSame($product->getId()->toString(), $output->id);
        $this->assertSame($category->getId()->toString(), $output->category->id);
        $this->assertSame(self::STORED_NAME, $product->getImageName());
    }

    public function testHandleRemovesThePreviousImageAfterTheTransactionCommits(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $product = $this->createProduct($productId, 'previous-image.jpg');
        $file = $this->validFile();

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->willReturn($product);

        $this->imageValidator->expects($this->once())
            ->method('validate')
            ->with($file);

        $this->imageStorage->expects($this->once())
            ->method('store')
            ->willReturn(self::STORED_NAME);

        $this->productRepository->expects($this->once())->method('save');

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->willReturn($this->createCategory($product->getCategoryId()));

        $this->imageStorage->expects($this->once())
            ->method('remove')
            ->with('previous-image.jpg');

        $this->expectTransactionalPassthrough();

        $this->handler->handle($this->command($productId, $file));

        $this->assertCount(1, $this->publishedEvents);
        $event = $this->publishedEvents[0];
        $this->assertInstanceOf(ProductImageUpdatedEvent::class, $event);
        $this->assertSame($productId->toString(), $event->aggregateId());
        $this->assertSame('shop.catalog.product.image_updated', $event->eventName());
    }

    public function testHandleThrowsExceptionWhenProductNotFound(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn(null);

        $file = $this->validFile();
        $this->imageValidator->expects($this->once())->method('validate')->with($file);
        $this->imageStorage->expects($this->once())->method('store')->with($file)->willReturn(self::STORED_NAME);
        $this->imageStorage->expects($this->once())->method('remove')->with(self::STORED_NAME);
        $this->categoryRepository->expects($this->never())->method('findById');

        $this->expectTransactionalPassthrough();

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product not found.');

        $this->handler->handle($this->command($productId, $file));
    }

    public function testHandleThrowsExceptionWhenCategoryNotFound(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $product = $this->createProduct($productId);

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->willReturn($product);

        $file = $this->validFile();
        $this->imageValidator->expects($this->once())->method('validate')->with($file);

        $this->imageStorage->expects($this->once())
            ->method('store')
            ->willReturn(self::STORED_NAME);
        $this->imageStorage->expects($this->once())->method('remove')->with(self::STORED_NAME);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($product->getCategoryId())
            ->willReturn(null);

        $this->expectTransactionalPassthrough();

        $this->expectException(CategoryNotFoundException::class);
        $this->expectExceptionMessage('Category not found.');

        $this->handler->handle($this->command($productId, $file));
    }

    public function testHandleRejectsAnInvalidImageBeforeStoringItOrOpeningTheTransaction(): void
    {
        $this->productRepository->expects($this->never())->method('findById');
        $this->imageStorage->expects($this->never())->method('store');
        $this->imageStorage->expects($this->never())->method('remove');
        $this->categoryRepository->expects($this->never())->method('findById');
        $this->transactional->expects($this->never())->method('transactional');

        $file = $this->validFile();
        $this->imageValidator->expects($this->once())
            ->method('validate')
            ->with($file)
            ->willThrowException(InvalidProductImageException::invalidMimeType('image/gif'));

        $this->expectException(InvalidProductImageException::class);
        $this->expectExceptionMessage('Invalid product image file type: image/gif.');

        $this->handler->handle($this->command(ProductId::fromString(self::PRODUCT_ID), $file));
    }

    public function testHandleRemovesTheStoredImageWhenTheTransactionFails(): void
    {
        $file = $this->validFile();

        $this->imageValidator->expects($this->once())->method('validate')->with($file);
        $this->imageStorage->expects($this->once())->method('store')->with($file)->willReturn(self::STORED_NAME);
        $this->imageStorage->expects($this->once())->method('remove')->with(self::STORED_NAME);
        $this->productRepository->expects($this->never())->method('findById');
        $this->categoryRepository->expects($this->never())->method('findById');
        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willThrowException(new RuntimeException('MongoDB failure.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MongoDB failure.');

        $this->handler->handle($this->command(ProductId::fromString(self::PRODUCT_ID), $file));
    }

    private function command(ProductId $productId, FileInterface $file): UpdateProductImageByAdminCommand
    {
        return new UpdateProductImageByAdminCommand(
            productId: $productId->toString(),
            imageFile: $file,
        );
    }

    private function validFile(): FileInterface
    {
        $file = $this->createStub(FileInterface::class);
        $file->method('isValid')->willReturn(true);

        return $file;
    }

    private function expectTransactionalPassthrough(): void
    {
        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
    }

    private function createProduct(ProductId $productId, ?string $imageName = null): Product
    {
        $product = Product::create(
            id: $productId,
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1299),
            slug: Slug::fromString('product-title'),
            categoryId: CategoryId::fromString(self::CATEGORY_ID),
            now: new DateTimeImmutable(),
        );

        if (null !== $imageName) {
            $product->updateImage($imageName, new DateTimeImmutable());
        }

        // Le cas d'usage part d'un agregat reconstitue : les evenements de la mise en place
        // ne doivent pas se retrouver dans ce que le handler publie.
        $product->clearDomainEvents();

        return $product;
    }

    private function createCategory(CategoryId $categoryId): Category
    {
        return Category::create(
            id: $categoryId,
            title: CategoryTitle::fromString('Category title'),
            slug: Slug::fromString('category-title'),
            now: new DateTimeImmutable(),
        );
    }
}
