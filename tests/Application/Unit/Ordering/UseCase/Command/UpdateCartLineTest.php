<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Ordering\UseCase\Command;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\Service\CartItemFactory;
use App\Application\Ordering\UseCase\Command\UpdateCartLine\UpdateCartLineCommand;
use App\Application\Ordering\UseCase\Command\UpdateCartLine\UpdateCartLineCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Exception\CartLineNotFoundException;
use App\Domain\Ordering\Exception\CartQuantityExceededException;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\Model\CartLine;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UpdateCartLineTest extends TestCase
{
    private const string CART_ID = '550e8400-e29b-41d4-a716-446655440130';

    private const string CART_LINE_ID = '550e8400-e29b-41d4-a716-446655440131';

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440132';

    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440133';

    private CartRepositoryInterface&MockObject $repository;

    private ProductRepositoryInterface&MockObject $productRepository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private UpdateCartLineCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CartRepositoryInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);

        $this->publishedEvents = [];
        $eventBus = $this->createStub(DomainEventBusInterface::class);
        $eventBus->method('publishAll')->willReturnCallback(function (array $events): void {
            $this->publishedEvents = [...$this->publishedEvents, ...$events];
        });

        $this->handler = new UpdateCartLineCommandHandler(
            $this->repository,
            new CartItemFactory($this->productRepository),
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleThrowsWhenNoCartExists(): void
    {
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $command = new UpdateCartLineCommand($customerId->toString(), self::PRODUCT_ID, 3);

        $this->repository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn(null);

        $this->clock->expects($this->never())->method('now');
        $this->productRepository->expects($this->never())->method('findByIds');
        $this->repository->expects($this->never())->method('save');
        $this->expectTransaction();

        $this->expectException(CartLineNotFoundException::class);
        $this->expectExceptionMessage('Cart line not found.');

        $this->handler->handle($command);
    }

    public function testHandleRejectsInvalidQuantityBeforeTransaction(): void
    {
        $command = new UpdateCartLineCommand(self::CUSTOMER_ID, self::PRODUCT_ID, 100);

        $this->repository->expects($this->never())->method('findByOwner');
        $this->productRepository->expects($this->never())->method('findByIds');
        $this->clock->expects($this->never())->method('now');
        $this->transactional->expects($this->never())->method('transactional');

        $this->expectException(CartQuantityExceededException::class);
        $this->expectExceptionMessage('Cart line quantity change must be between 0 and 99.');

        $this->handler->handle($command);
    }

    public function testHandleUpdatesLineQuantity(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart = $this->cartWithLine($customerId, $productId);
        $command = new UpdateCartLineCommand($customerId->toString(), $productId->toString(), 5);

        $this->repository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn($cart);

        $this->clock->expects($this->atLeastOnce())->method('now')->willReturn($now);
        $this->productRepository->expects($this->once())->method('findByIds')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Cart $saved) use ($productId, $now): bool {
                $lines = $saved->getLines();

                return 1 === count($lines)
                    && $lines[0]->getProductId()->equals($productId)
                    && 5 === $lines[0]->getQuantity()->toInt()
                    && $saved->getUpdatedAt() === $now;
            }));

        $this->expectTransaction();

        $output = $this->handler->handle($command);

        $this->assertInstanceOf(CartItem::class, $output);
        $this->assertSame(self::CART_ID, $output->id);
    }

    public function testHandleRemovesLineWhenQuantityIsZero(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart = $this->cartWithLine($customerId, $productId);
        $command = new UpdateCartLineCommand($customerId->toString(), $productId->toString(), 0);

        $this->repository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn($cart);

        $this->clock->expects($this->atLeastOnce())->method('now')->willReturn($now);
        $this->productRepository->expects($this->once())->method('findByIds')->willReturn([]);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Cart $saved) use ($now): bool {
                return [] === $saved->getLines()
                    && $saved->getUpdatedAt() === $now;
            }));

        $this->expectTransaction();

        $output = $this->handler->handle($command);

        $this->assertInstanceOf(CartItem::class, $output);
    }

    private function cartWithLine(CustomerId $customerId, ProductId $productId): Cart
    {
        return Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            $customerId,
            [CartLine::create(CartLineId::fromString(self::CART_LINE_ID), $productId, CartLineQuantity::fromInt(2))],
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );
    }

    private function expectTransaction(): void
    {
        $this->transactional->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
    }
}
