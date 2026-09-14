<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Ordering\UseCase\Query;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\Service\CartItemFactory;
use App\Application\Ordering\UseCase\Query\DisplayMyCart\DisplayMyCartQuery;
use App\Application\Ordering\UseCase\Query\DisplayMyCart\DisplayMyCartQueryHandler;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\ValueObject\CartId;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DisplayMyCartTest extends TestCase
{
    private const string CART_ID = '550e8400-e29b-41d4-a716-446655440140';

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440142';

    private CartRepositoryInterface&MockObject $repository;

    private ProductRepositoryInterface&MockObject $productRepository;

    private DisplayMyCartQueryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CartRepositoryInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->handler = new DisplayMyCartQueryHandler(
            $this->repository,
            new CartItemFactory($this->productRepository),
        );
    }

    public function testHandleReturnsCartWhenItExists(): void
    {
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $cart = Cart::create(CartId::fromString(self::CART_ID), $customerId, new DateTimeImmutable('2025-01-01 09:00:00'));
        $query = new DisplayMyCartQuery($customerId->toString());

        $this->repository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn($cart);

        $this->productRepository->expects($this->once())->method('findByIds')->willReturn([]);

        $output = $this->handler->handle($query);

        $this->assertInstanceOf(CartItem::class, $output);
        $this->assertSame(self::CART_ID, $output->id);
    }

    public function testHandleReturnsEmptyCartWhenNoneExists(): void
    {
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $query = new DisplayMyCartQuery($customerId->toString());

        $this->repository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn(null);

        $this->productRepository->expects($this->never())->method('findByIds');

        $output = $this->handler->handle($query);

        $this->assertInstanceOf(CartItem::class, $output);
        $this->assertNull($output->id);
        $this->assertSame([], $output->items);
        $this->assertSame(0, $output->totalQuantity);
    }
}
