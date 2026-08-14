<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Ordering\Cart;

use ApiPlatform\Metadata\Operation;
use App\Application\Catalog\Port\ProductImageUrlResolverInterface;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\UseCase\Command\AddToCart\AddToCartCommand;
use App\Application\Shared\CQRS\Command\CommandBusInterface;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Presentation\Ordering\ApiResource\CartResource;
use App\Presentation\Ordering\Dto\Cart\CartLinePostInput;
use App\Presentation\Ordering\Presenter\CartResourcePresenter;
use App\Presentation\Ordering\State\Cart\CartLinePostProcessor;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Presentation\Shared\State\PresentationErrorCode;
use App\Tests\Presentation\Unit\State\Customer\CustomerUserTrait;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;

final class CartLinePostProcessorTest extends TestCase
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

    public function testProcessAddsLineToCart(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())
            ->method('getUser')
            ->willReturn($this->createUser('550e8400-e29b-41d4-a716-446655440800'));

        $processor = new CartLinePostProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            $this->createCartResourcePresenter(),
        );

        $input = new CartLinePostInput();
        $input->productId = '550e8400-e29b-41d4-a716-446655440812';
        $input->quantity = 2;

        $customerId = CustomerId::fromString('550e8400-e29b-41d4-a716-446655440801');
        $customerOutput = new CurrentCustomerItem($customerId->toString());
        $output = $this->createCart();

        $this->queryBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($customerOutput): CurrentCustomerItem {
                $this->assertInstanceOf(DisplayMyCustomerQuery::class, $query);
                $this->assertSame('550e8400-e29b-41d4-a716-446655440800', $query->userAccountId);

                return $customerOutput;
            });

        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($command) use ($input, $customerId, $output): CartItem {
                $this->assertInstanceOf(AddToCartCommand::class, $command);
                $this->assertSame($customerId->toString(), $command->customerId);
                $this->assertSame($input->productId, $command->productId);
                $this->assertSame(2, $command->quantity);

                return $output;
            });

        $result = $processor->process($input, $this->operation);

        $this->assertInstanceOf(CartResource::class, $result);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440810', $result->id);
    }

    public function testProcessThrowsLogicExceptionForInvalidInput(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('getUser');

        $processor = new CartLinePostProcessor(
            $this->commandBus,
            new CurrentCustomerResolver($this->queryBus, $security),
            $this->createCartResourcePresenter(),
        );

        $this->commandBus->expects($this->never())->method('dispatch');
        $this->queryBus->expects($this->never())->method('dispatch');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PresentationErrorCode::INVALID_INPUT->value);

        $processor->process(new stdClass(), $this->operation);
    }

    private function createCart(): CartItem
    {
        return new CartItem(
            id: '550e8400-e29b-41d4-a716-446655440810',
            items: [],
            totalQuantity: 2,
            subtotal: 39.98,
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
