<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Catalog\Product;

use ApiPlatform\Metadata\Operation;
use App\Application\Catalog\Port\ProductImageUrlResolverInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Catalog\UseCase\Command\CreateProductByAdmin\CreateProductByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductImage;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use App\Presentation\Catalog\ApiResource\CategoryResource;
use App\Presentation\Catalog\ApiResource\ProductResource;
use App\Presentation\Catalog\Dto\Product\ProductPostInput;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Catalog\Presenter\ProductResourcePresenter;
use App\Presentation\Catalog\State\Product\ProductPostProcessor;
use App\Presentation\Shared\State\PresentationErrorCode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ProductPostProcessorTest extends TestCase
{
    private CommandBusInterface&MockObject $commandBus;

    private ProductImageUrlResolverInterface&MockObject $productImageUrlResolver;

    private Operation&MockObject $operation;

    private ProductPostProcessor $processor;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->productImageUrlResolver = $this->createMock(ProductImageUrlResolverInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())
            ->method('getName');

        $presenter = new ProductResourcePresenter(
            $this->productImageUrlResolver,
            new CategoryResourcePresenter(),
        );

        $this->processor = new ProductPostProcessor(
            $this->commandBus,
            $presenter,
        );
    }

    public function testProcessWithValidInput(): void
    {
        $input = new ProductPostInput();
        $input->title = 'New product';
        $input->subtitle = 'Product subtitle';
        $input->description = 'Product description';
        $input->price = 19.99;
        $input->category = $this->createCategoryResource('550e8400-e29b-41d4-a716-446655440001');

        $output = $this->createProductView();

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($input, $output): ProductItem {
                $this->assertInstanceOf(CreateProductByAdminCommand::class, $command);
                $this->assertSame($input->title, $command->title);
                $this->assertSame($input->subtitle, $command->subtitle);
                $this->assertSame($input->description, $command->description);
                $this->assertSame($input->price, $command->price);
                $this->assertSame($input->category->id, $command->categoryId);

                return $output;
            });

        $this->productImageUrlResolver->expects($this->once())
            ->method('resolve')
            ->with('product.jpg')
            ->willReturn('/uploads/product.jpg');

        $result = $this->processor->process($input, $this->operation);

        $this->assertInstanceOf(ProductResource::class, $result);
        $this->assertSame('New product', $result->title);
        $this->assertSame('/uploads/product.jpg', $result->imageUrl);
    }

    public function testProcessThrowsLogicExceptionForInvalidInput(): void
    {
        $invalidInput = new stdClass();

        $this->commandBus->expects($this->never())
            ->method('dispatch');
        $this->productImageUrlResolver->expects($this->never())
            ->method('resolve');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process($invalidInput, $this->operation);
    }

    private function createProductView(): ProductItem
    {
        $now = new DateTimeImmutable('2024-01-01 10:00:00');
        $category = Category::create(
            id: CategoryId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            title: CategoryTitle::fromString('Category title'),
            slug: Slug::fromString('category-title'),
            now: $now,
        );

        $product = Product::reconstitute(
            id: ProductId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            title: ProductTitle::fromString('New product'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1999),
            slug: Slug::fromString('new-product'),
            categoryId: CategoryId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            image: ProductImage::create('product.jpg'),
            createdAt: $now,
            updatedAt: $now,
        );

        return ProductItem::fromProduct($product, $category);
    }

    private function createCategoryResource(string $id): CategoryResource
    {
        $resource = new CategoryResource();
        $resource->id = $id;

        return $resource;
    }
}
