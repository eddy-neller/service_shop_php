<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Ordering\UseCase\Command;

use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Ordering\UseCase\Command\ClearCart\ClearCartCommand;
use App\Application\Ordering\UseCase\Command\ClearCart\ClearCartCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Event\Cart\CartClearedEvent;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\Model\CartLine;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ClearCartTest extends TestCase
{
    private const string CART_ID = '550e8400-e29b-41d4-a716-446655440110';

    private const string CART_LINE_ID = '550e8400-e29b-41d4-a716-446655440111';

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440112';

    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440113';

    private CartRepositoryInterface&MockObject $repository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private ClearCartCommandHandler $handler;

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

        $this->handler = new ClearCartCommandHandler(
            $this->repository,
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleDoesNothingWhenNoCartExists(): void
    {
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $command = new ClearCartCommand($customerId->toString());

        $this->repository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn(null);

        $this->clock->expects($this->never())->method('now');
        $this->repository->expects($this->never())->method('save');
        $this->expectTransaction();

        $this->handler->handle($command);
    }

    public function testHandleClearsExistingCart(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            $customerId,
            [CartLine::create(CartLineId::fromString(self::CART_LINE_ID), ProductId::fromString(self::PRODUCT_ID), CartLineQuantity::fromInt(2))],
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );
        $command = new ClearCartCommand($customerId->toString());

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

    /**
     * Vider un panier deja vide ne publie rien : `Cart::clear()` sort tot. Sans cette garde,
     * chaque `DELETE /me/cart` rejoue deposerait une ligne d'outbox pour un fait qui n'a pas
     * eu lieu.
     */
    public function testHandleOnAnAlreadyEmptyCartPublishesNothing(): void
    {
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            $customerId,
            [],
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );

        $this->repository->expects($this->once())->method('findByOwner')->willReturn($cart);
        $this->clock->expects($this->atLeastOnce())->method('now')
            ->willReturn(new DateTimeImmutable('2025-02-01 10:00:00'));
        $this->repository->expects($this->once())->method('save')->with($cart);
        $this->expectTransaction();

        $this->handler->handle(new ClearCartCommand($customerId->toString()));

        self::assertSame([], $this->publishedEvents);
    }

    public function testHandlePublishesTheClearedEventWhenTheCartHadLines(): void
    {
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            $customerId,
            [CartLine::create(
                CartLineId::fromString(self::CART_LINE_ID),
                ProductId::fromString(self::PRODUCT_ID),
                CartLineQuantity::fromInt(2),
            )],
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );

        $this->repository->expects($this->once())->method('findByOwner')->willReturn($cart);
        $this->clock->expects($this->atLeastOnce())->method('now')
            ->willReturn(new DateTimeImmutable('2025-02-01 10:00:00'));
        $this->repository->expects($this->once())->method('save')->with($cart);
        $this->expectTransaction();

        $this->handler->handle(new ClearCartCommand($customerId->toString()));

        self::assertCount(1, $this->publishedEvents);
        self::assertInstanceOf(CartClearedEvent::class, $this->publishedEvents[0]);
    }
}
