<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Ordering\Cart;

use ApiPlatform\Metadata\Operation;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Ordering\UseCase\Command\RemoveCartLine\RemoveCartLineCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Presentation\Ordering\State\Cart\CartLineDeleteProcessor;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use App\Tests\Presentation\Unit\State\Customer\CustomerUserTrait;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class CartLineDeleteProcessorTest extends TestCase
{
    use CustomerUserTrait;

    private CommandBusInterface&MockObject $commandBus;

    private QueryBusInterface&MockObject $queryBus;

    private Operation&MockObject $operation;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBusInterface::class);
        $this->queryBus = $this->createMock(QueryBusInterface::class);
        $this->operation = $this->createMock(Operation::class);
        $this->operation->expects($this->never())->method('getName');
    }

    public function testProcessRemovesCartLine(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())
            ->method('getUser')
            ->willReturn($this->createUser('550e8400-e29b-41d4-a716-446655441000'));

        $processor = new CartLineDeleteProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
        );

        $customerId = CustomerId::fromString('550e8400-e29b-41d4-a716-446655441001');
        $customerOutput = new CurrentCustomerItem($customerId->toString());

        $this->queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($customerOutput): CurrentCustomerItem {
                $this->assertInstanceOf(DisplayMyCustomerQuery::class, $query);
                $this->assertSame('550e8400-e29b-41d4-a716-446655441000', $query->userAccountId);

                return $customerOutput;
            });

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($customerId): mixed {
                $this->assertInstanceOf(RemoveCartLineCommand::class, $command);
                $this->assertSame($customerId->toString(), $command->customerId);
                $this->assertSame('550e8400-e29b-41d4-a716-446655441012', $command->productId);

                return null;
            });

        $processor->process(null, $this->operation, ['productId' => '550e8400-e29b-41d4-a716-446655441012']);
    }

    public function testProcessThrowsOnMissingProductId(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        $processor = new CartLineDeleteProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
        );

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process(null, $this->operation, []);
    }

    public function testProcessThrowsOnEmptyProductId(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        $processor = new CartLineDeleteProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
        );

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process(null, $this->operation, ['productId' => '']);
    }
}
