<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Ordering\Cart;

use ApiPlatform\Metadata\Operation;
use App\Application\Catalog\Port\ProductImageUrlResolverInterface;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\UseCase\Command\UpdateCartLine\UpdateCartLineCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Presentation\Ordering\ApiResource\CartResource;
use App\Presentation\Ordering\Dto\Cart\CartLinePatchInput;
use App\Presentation\Ordering\Presenter\CartResourcePresenter;
use App\Presentation\Ordering\State\Cart\CartLinePatchProcessor;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use App\Tests\Presentation\Unit\State\Customer\CustomerUserTrait;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;

final class CartLinePatchProcessorTest extends TestCase
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

    public function testProcessUpdatesCartLineQuantity(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())
            ->method('getUser')
            ->willReturn($this->createUser('550e8400-e29b-41d4-a716-446655440900'));

        $processor = new CartLinePatchProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            $this->createCartResourcePresenter(),
        );

        $input = new CartLinePatchInput();
        $input->quantity = 5;

        $customerId = CustomerId::fromString('550e8400-e29b-41d4-a716-446655440901');
        $customerOutput = new CurrentCustomerItem($customerId->toString());
        $output = $this->createCart();

        $this->queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($customerOutput): CurrentCustomerItem {
                $this->assertInstanceOf(DisplayMyCustomerQuery::class, $query);
                $this->assertSame('550e8400-e29b-41d4-a716-446655440900', $query->userAccountId);

                return $customerOutput;
            });

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($customerId, $output): CartItem {
                $this->assertInstanceOf(UpdateCartLineCommand::class, $command);
                $this->assertSame($customerId->toString(), $command->customerId);
                $this->assertSame('550e8400-e29b-41d4-a716-446655440912', $command->productId);
                $this->assertSame(5, $command->quantity);

                return $output;
            });

        $result = $processor->process(
            $input,
            $this->operation,
            ['productId' => '550e8400-e29b-41d4-a716-446655440912'],
        );

        $this->assertInstanceOf(CartResource::class, $result);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440910', $result->id);
    }

    public function testProcessThrowsLogicExceptionForInvalidInput(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        $processor = new CartLinePatchProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            $this->createCartResourcePresenter(),
        );

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process(new stdClass(), $this->operation, ['productId' => '550e8400-e29b-41d4-a716-446655440912']);
    }

    public function testProcessThrowsLogicExceptionForMissingProductId(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        $processor = new CartLinePatchProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            $this->createCartResourcePresenter(),
        );

        $input = new CartLinePatchInput();
        $input->quantity = 5;

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process($input, $this->operation, []);
    }

    public function testProcessThrowsLogicExceptionForEmptyProductId(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        $processor = new CartLinePatchProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            $this->createCartResourcePresenter(),
        );

        $input = new CartLinePatchInput();
        $input->quantity = 5;

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process($input, $this->operation, ['productId' => '']);
    }

    private function createCart(): CartItem
    {
        return new CartItem(
            id: '550e8400-e29b-41d4-a716-446655440910',
            items: [],
            totalQuantity: 5,
            subtotal: 99.95,
            currency: 'EUR',
            createdAt: null,
            updatedAt: null,
        );
    }

    private function createCartResourcePresenter(): CartResourcePresenter
    {
        return new CartResourcePresenter($this->createStub(ProductImageUrlResolverInterface::class));
    }
}
