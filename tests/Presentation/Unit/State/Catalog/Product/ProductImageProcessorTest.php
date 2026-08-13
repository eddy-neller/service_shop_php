<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Catalog\Product;

use ApiPlatform\Metadata\Operation;
use App\Application\Catalog\Port\ProductImageUrlResolverInterface;
use App\Application\Catalog\ReadModel\Catalog\ProductItem;
use App\Application\Catalog\UseCase\Command\UpdateProductImageByAdmin\UpdateProductImageByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\Port\FileInterface;
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
use App\Presentation\Catalog\ApiResource\ProductResource;
use App\Presentation\Catalog\Dto\Product\ProductImageInput;
use App\Presentation\Catalog\Presenter\CategoryResourcePresenter;
use App\Presentation\Catalog\Presenter\ProductResourcePresenter;
use App\Presentation\Catalog\State\Product\ProductImageProcessor;
use App\Presentation\Shared\State\PresentationErrorCode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductImageProcessorTest extends TestCase
{
    private CommandBusInterface&MockObject $commandBus;

    private ProductImageUrlResolverInterface&MockObject $productImageUrlResolver;

    private Operation&MockObject $operation;

    private ProductImageProcessor $processor;

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

        $this->processor = new ProductImageProcessor(
            $this->commandBus,
            $presenter,
        );
    }

    public function testProcessWithValidInput(): void
    {
        $input = new ProductImageInput();
        $input->imageFile = $this->createUploadedFile(true);

        $productId = '550e8400-e29b-41d4-a716-446655440000';
        $output = $this->createProductView();

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($productId, $output): ProductItem {
                $this->assertInstanceOf(UpdateProductImageByAdminCommand::class, $command);
                $this->assertSame($productId, $command->productId);
                $this->assertInstanceOf(FileInterface::class, $command->imageFile);
                $this->assertSame('product.jpg', $command->imageFile->getClientOriginalName());
                $this->assertTrue($command->imageFile->isValid());

                return $output;
            });

        $this->productImageUrlResolver->expects($this->once())
            ->method('resolve')
            ->with('product.jpg')
            ->willReturn('/uploads/product.jpg');

        $result = $this->processor->process($input, $this->operation, ['id' => $productId]);

        $this->assertInstanceOf(ProductResource::class, $result);
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

        $this->processor->process($invalidInput, $this->operation, ['id' => 'id']);
    }

    public function testProcessThrowsLogicExceptionWhenImageFileMissing(): void
    {
        $input = new ProductImageInput();

        $this->commandBus->expects($this->never())
            ->method('dispatch');
        $this->productImageUrlResolver->expects($this->never())
            ->method('resolve');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process($input, $this->operation, ['id' => 'id']);
    }

    public function testProcessThrowsLogicExceptionWhenIdIsMissing(): void
    {
        $input = new ProductImageInput();
        $input->imageFile = $this->createUploadedFile(false);

        $this->commandBus->expects($this->never())
            ->method('dispatch');
        $this->productImageUrlResolver->expects($this->never())
            ->method('resolve');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process($input, $this->operation, []);
    }

    public function testProcessThrowsLogicExceptionWhenIdIsNotString(): void
    {
        $input = new ProductImageInput();
        $input->imageFile = $this->createUploadedFile(false);

        $this->commandBus->expects($this->never())
            ->method('dispatch');
        $this->productImageUrlResolver->expects($this->never())
            ->method('resolve');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process($input, $this->operation, ['id' => 123]);
    }

    private function createUploadedFile(bool $shouldBeUsed): UploadedFile
    {
        /** @var UploadedFile&MockObject $mockFile */
        $mockFile = $this->createMock(UploadedFile::class);
        if ($shouldBeUsed) {
            $mockFile->expects($this->once())
                ->method('getClientOriginalName')
                ->willReturn('product.jpg');
            $mockFile->expects($this->never())
                ->method('getClientOriginalExtension');
            $mockFile->expects($this->once())
                ->method('isValid')
                ->willReturn(true);
        } else {
            $mockFile->expects($this->never())
                ->method('getClientOriginalName');
            $mockFile->expects($this->never())
                ->method('getClientOriginalExtension');
            $mockFile->expects($this->never())
                ->method('isValid');
        }

        return $mockFile;
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
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1299),
            slug: Slug::fromString('product-title'),
            categoryId: CategoryId::fromString('550e8400-e29b-41d4-a716-446655440001'),
            imageName: 'product.jpg',
            createdAt: $now,
            updatedAt: $now,
        );

        return ProductItem::fromProduct($product, $category);
    }
}
