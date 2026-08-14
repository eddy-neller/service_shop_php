<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Ordering\UseCase\Command;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Ordering\Port\CartRepositoryInterface;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\Service\CartItemFactory;
use App\Application\Ordering\UseCase\Command\AddToCart\AddToCartCommand;
use App\Application\Ordering\UseCase\Command\AddToCart\AddToCartCommandHandler;
use App\Application\Shared\Port\ClockInterface;
use App\Application\Shared\Port\DomainEventBusInterface;
use App\Application\Shared\Port\TransactionalInterface;
use App\Domain\Catalog\Exception\ProductNotFoundException;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Exception\CartQuantityExceededException;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\Model\CartLine;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AddToCartTest extends TestCase
{
    private const string CART_ID = '550e8400-e29b-41d4-a716-446655440100';

    private const string CART_LINE_ID = '550e8400-e29b-41d4-a716-446655440101';

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440102';

    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440103';

    private CartRepositoryInterface&MockObject $cartRepository;

    private ProductRepositoryInterface&MockObject $productRepository;

    private ClockInterface&MockObject $clock;

    private TransactionalInterface&MockObject $transactional;

    /** @var list<DomainEventInterface> */
    private array $publishedEvents = [];

    private AddToCartCommandHandler $handler;

    protected function setUp(): void
    {
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->transactional = $this->createMock(TransactionalInterface::class);

        $this->publishedEvents = [];
        $eventBus = $this->createStub(DomainEventBusInterface::class);
        $eventBus->method('publishAll')->willReturnCallback(function (array $events): void {
            $this->publishedEvents = [...$this->publishedEvents, ...$events];
        });

        $this->handler = new AddToCartCommandHandler(
            $this->cartRepository,
            $this->productRepository,
            new CartItemFactory($this->productRepository),
            $this->clock,
            $this->transactional,
            $eventBus,
        );
    }

    public function testHandleThrowsWhenProductNotFound(): void
    {
        $command = new AddToCartCommand(
            self::CUSTOMER_ID,
            self::PRODUCT_ID,
            2,
        );

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($command->productId)
            ->willReturn(null);

        $this->cartRepository->expects($this->once())
            ->method('nextIdentity')
            ->willReturn(CartId::fromString(self::CART_ID));
        $this->cartRepository->expects($this->once())
            ->method('nextLineIdentity')
            ->willReturn(CartLineId::fromString(self::CART_LINE_ID));
        $this->clock->expects($this->never())->method('now');
        $this->cartRepository->expects($this->never())->method('save');
        $this->expectTransaction();

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product not found.');

        $this->handler->handle($command);
    }

    public function testHandleRejectsInvalidQuantityBeforeTransaction(): void
    {
        $command = new AddToCartCommand(self::CUSTOMER_ID, self::PRODUCT_ID, 100);

        $this->cartRepository->expects($this->never())->method('nextIdentity');
        $this->cartRepository->expects($this->never())->method('nextLineIdentity');
        $this->productRepository->expects($this->never())->method('findById');
        $this->clock->expects($this->never())->method('now');
        $this->transactional->expects($this->never())->method('transactional');

        $this->expectException(CartQuantityExceededException::class);
        $this->expectExceptionMessage('Cart line quantity must be between 1 and 99.');

        $this->handler->handle($command);
    }

    public function testHandleCreatesCartWhenNoneExists(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $cartId = CartId::fromString(self::CART_ID);
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $command = new AddToCartCommand($customerId->toString(), $productId->toString(), 3);

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($this->createProduct($productId));

        $this->productRepository->expects($this->once())->method('findByIds')->willReturn([]);

        $this->cartRepository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn(null);

        $this->cartRepository->expects($this->once())->method('nextIdentity')->willReturn($cartId);
        $this->cartRepository->expects($this->once())
            ->method('nextLineIdentity')
            ->willReturn(CartLineId::fromString(self::CART_LINE_ID));
        $this->clock->expects($this->atLeastOnce())->method('now')->willReturn($now);

        $this->cartRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Cart $cart) use ($cartId, $customerId, $productId, $now): bool {
                $lines = $cart->getLines();

                return $cart->getId()->equals($cartId)
                    && $cart->getOwnerId()->equals($customerId)
                    && 1 === count($lines)
                    && $lines[0]->getProductId()->equals($productId)
                    && 3 === $lines[0]->getQuantity()->toInt()
                    && $cart->getCreatedAt() === $now
                    && $cart->getUpdatedAt() === $now;
            }));

        $this->expectTransaction();

        $output = $this->handler->handle($command);

        $this->assertInstanceOf(CartItem::class, $output);
        $this->assertSame(self::CART_ID, $output->id);
    }

    public function testHandleAddsLineToExistingCart(): void
    {
        $now = new DateTimeImmutable('2025-01-01 11:00:00');
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart = Cart::create(CartId::fromString(self::CART_ID), $customerId, new DateTimeImmutable('2025-01-01 09:00:00'));
        $command = new AddToCartCommand($customerId->toString(), $productId->toString(), 2);

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($this->createProduct($productId));

        $this->productRepository->expects($this->once())->method('findByIds')->willReturn([]);

        $this->cartRepository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn($cart);

        $this->cartRepository->expects($this->once())
            ->method('nextIdentity')
            ->willReturn(CartId::fromString(self::CART_ID));
        $this->cartRepository->expects($this->once())
            ->method('nextLineIdentity')
            ->willReturn(CartLineId::fromString(self::CART_LINE_ID));
        $this->clock->expects($this->atLeastOnce())->method('now')->willReturn($now);

        $this->cartRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Cart $saved) use ($cart, $productId, $now): bool {
                $lines = $saved->getLines();

                return $saved === $cart
                    && 1 === count($lines)
                    && $lines[0]->getProductId()->equals($productId)
                    && 2 === $lines[0]->getQuantity()->toInt()
                    && $saved->getUpdatedAt() === $now;
            }));

        $this->expectTransaction();

        $this->handler->handle($command);
    }

    public function testHandleIncrementsQuantityWhenProductAlreadyInCart(): void
    {
        $now = new DateTimeImmutable('2025-01-01 12:00:00');
        $customerId = CustomerId::fromString(self::CUSTOMER_ID);
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            $customerId,
            [CartLine::create(CartLineId::fromString(self::CART_LINE_ID), $productId, CartLineQuantity::fromInt(2))],
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );
        $command = new AddToCartCommand($customerId->toString(), $productId->toString(), 3);

        $this->productRepository->expects($this->once())
            ->method('findById')
            ->with($productId)
            ->willReturn($this->createProduct($productId));

        $this->productRepository->expects($this->once())->method('findByIds')->willReturn([]);

        $this->cartRepository->expects($this->once())
            ->method('findByOwner')
            ->with($customerId)
            ->willReturn($cart);

        $this->cartRepository->expects($this->once())
            ->method('nextIdentity')
            ->willReturn(CartId::fromString(self::CART_ID));
        $this->cartRepository->expects($this->once())
            ->method('nextLineIdentity')
            ->willReturn(CartLineId::fromString(self::CART_LINE_ID));
        $this->clock->expects($this->atLeastOnce())->method('now')->willReturn($now);

        $this->cartRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Cart $saved) use ($productId): bool {
                $lines = $saved->getLines();

                return 1 === count($lines)
                    && $lines[0]->getProductId()->equals($productId)
                    && 5 === $lines[0]->getQuantity()->toInt();
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

    private function createProduct(ProductId $productId): Product
    {
        return Product::create(
            id: $productId,
            title: ProductTitle::fromString('Product title'),
            subtitle: ProductSubtitle::fromString('Product subtitle'),
            description: ProductDescription::fromString('Product description'),
            price: Money::fromInt(1299),
            slug: Slug::fromString('product-title'),
            categoryId: CategoryId::fromString('550e8400-e29b-41d4-a716-446655440104'),
            now: new DateTimeImmutable('2024-01-01 09:00:00'),
        );
    }
}
