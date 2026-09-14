<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Catalog\Product;

use ApiPlatform\Metadata\Operation;
use App\Application\Catalog\UseCase\Command\DeleteProductByAdmin\DeleteProductByAdminCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Presentation\Catalog\State\Product\ProductDeleteProcessor;
use App\Presentation\Shared\State\PresentationErrorCode;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProductDeleteProcessorTest extends TestCase
{
    private CommandBusInterface&MockObject $commandBus;

    private Operation&MockObject $operation;

    private ProductDeleteProcessor $processor;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())
            ->method('getName');
        $this->processor = new ProductDeleteProcessor($this->commandBus);
    }

    public function testProcessWithValidIdDispatchesCommand(): void
    {
        $productId = '550e8400-e29b-41d4-a716-446655440000';
        $productIdVo = ProductId::fromString($productId);

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($command) use ($productIdVo): bool {
                $this->assertInstanceOf(DeleteProductByAdminCommand::class, $command);
                $this->assertSame($productIdVo->toString(), $command->productId);

                return true;
            }));

        $this->processor->process(null, $this->operation, ['id' => $productId]);
    }

    public function testProcessThrowsLogicExceptionWhenIdIsMissing(): void
    {
        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process(null, $this->operation, []);
    }

    public function testProcessThrowsLogicExceptionWhenIdIsNotString(): void
    {
        $this->commandBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $this->processor->process(null, $this->operation, ['id' => 123]);
    }
}
