<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Ordering\UseCase\Command;

use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Ordering\UseCase\Command\RemoveCartLine\RemoveCartLineCommand;
use App\Application\Ordering\UseCase\Command\RemoveCartLine\RemoveCartLineCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Exception\CartLineNotFoundException;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\Model\CartLine;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RemoveCartLineTest extends TestCase
{
    private const string CART_ID = '550e8400-e29b-41d4-a716-446655440120';

    private const string CART_LINE_ID = '550e8400-e29b-41d4-a716-446655440121';

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440122';

    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440123';

    private CartRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private RemoveCartLineCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CartRepositoryInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);
        $this->publishedEvents = [];
        $eventBus = $this->createStub(DomainEventBusInterface::class);
        $eventBus->method('publishAll')->willReturnCallback(function (array $events): void {
            $this->publishedEvents = [...$this->publishedEvents, ...$events];
        });

        $this->handler = new RemoveCartLineCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleThrowsWhenNoCartExists(): void
    {
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $command = new RemoveCartLineCommand($customerId->toString(), self::PRODUCT_ID);

        $this->repository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn(null);

        $this->clock->expects($this->never())->method('now');
        $this->repository->expects($this->never())->method('save');
        $this->expectTransaction();

        $this->expectException(CartLineNotFoundException::class);
        $this->expectExceptionMessage('Cart line not found.');

        $this->handler->handle($command);
    }

    public function testHandleRemovesLineFromCart(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            $customerId,
            [CartLine::create(CartLineId::fromString(self::CART_LINE_ID), $productId, CartLineQuantity::fromInt(2))],
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );
        $command = new RemoveCartLineCommand($customerId->toString(), $productId->toString());

        $this->repository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn($cart);

        $this->clock->expects($this->atLeastOnce())->method('now')->willReturn($now);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Cart $saved) use ($cart, $now): bool {
                return $saved === $cart
                    && [] === $saved->getLines()
                    && $saved->getUpdatedAt() === $now;
            }));

        $this->expectTransaction();

        $this->handler->handle($command);
    }

    private function expectTransaction(): void
    {
        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
    }
}
