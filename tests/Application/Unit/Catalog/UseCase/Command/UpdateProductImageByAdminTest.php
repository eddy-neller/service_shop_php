<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Catalog\UseCase\Command;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Catalog\UseCase\Command\UpdateProductImageByAdmin\UpdateProductImageByAdminCommand;
use App\Application\Catalog\UseCase\Command\UpdateProductImageByAdmin\UpdateProductImageByAdminCommandHandler;
use App\Application\Shared\Port\FileInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\CatalogDomainException;
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

final class UpdateProductImageByAdminTest extends TestCase
{
    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440001';

    private ProductRepositoryInterface&MockObject $productRepository;

    private CategoryRepositoryInterface&MockObject $categoryRepository;

    private TransactionalInterface&MockObject $transactional;

    private UpdateProductImageByAdminCommandHandler $handler;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);
        $this->handler = new UpdateProductImageByAdminCommandHandler(
            $this->productRepository,
            $this->categoryRepository,
            $this->transactional,
        );
    }

    public function testHandleUpdatesImageWhenProductExists(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $product = $this->createProduct($productId);
        $category = $this->createCategory($product->getCategoryId());
        $file = $this->createStub(FileInterface::class);
        $file->method('isValid')->willReturn(true);

        $command = new UpdateProductImageByAdminCommand(
            productId: $productId->toString(),
            imageFile: $file,
        );

        $this->productRepository->expects($this->once())
            ->method('updateImage')
            ->with($productId, $file)
            ->willReturn($product);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($product->getCategoryId())
            ->willReturn($category);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $output = $this->handler->handle($command);

        $this->assertSame($product->getId()->toString(), $output->id);
        $this->assertSame($category->getId()->toString(), $output->category->id);
    }

    public function testHandleThrowsExceptionWhenProductNotFound(): void
    {
        $this->categoryRepository->expects($this->never())
            ->method('findById');

        $productId = ProductId::fromString(self::PRODUCT_ID);
        $file = $this->createStub(FileInterface::class);
        $file->method('isValid')->willReturn(true);

        $command = new UpdateProductImageByAdminCommand(
            productId: $productId->toString(),
            imageFile: $file,
        );

        $this->productRepository->expects($this->once())
            ->method('updateImage')
            ->with($productId, $file)
            ->willReturn(null);

        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product not found.');

        $this->handler->handle($command);
    }

    public function testHandleThrowsExceptionWhenCategoryNotFound(): void
    {
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $product = $this->createProduct($productId);
        $file = $this->createStub(FileInterface::class);
        $file->method('isValid')->willReturn(true);

        $command = new UpdateProductImageByAdminCommand(
            productId: $productId->toString(),
            imageFile: $file,
        );

        $this->productRepository->expects($this->once())
            ->method('updateImage')
            ->with($productId, $file)
            ->willReturn($product);

        $this->categoryRepository->expects($this->once())
            ->method('findById')
            ->with($product->getCategoryId())
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

    public function testHandleThrowsExceptionWhenImageFileIsInvalid(): void
    {
        $this->productRepository->expects($this->never())
            ->method('updateImage');
        $this->categoryRepository->expects($this->never())
            ->method('findById');
        $this->transactional->expects($this->never())
            ->method('transactional');

        $productId = ProductId::fromString(self::PRODUCT_ID);
        $file = $this->createStub(FileInterface::class);
        $file->method('isValid')->willReturn(false);

        $command = new UpdateProductImageByAdminCommand(
            productId: $productId->toString(),
            imageFile: $file,
        );

        $this->expectException(CatalogDomainException::class);
        $this->expectExceptionMessage('Invalid image file.');

        $this->handler->handle($command);
    }

    private function createProduct(ProductId $productId): Product
    {
        return Product::create(
            id: $productId,
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1299),
            slug: Slug::fromString('product-title'),
            categoryId: CategoryId::fromString(self::CATEGORY_ID),
            now: new DateTimeImmutable(),
        );
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
