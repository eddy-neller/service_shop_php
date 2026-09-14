<?php

declare(strict_types=1);

namespace App\Tests\Application\Unit\Ordering\Service;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\Service\CartItemFactory;
use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\Model\CartLine;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CartItemFactoryTest extends TestCase
{
    private const string CART_ID = '550e8400-e29b-41d4-a716-446655440200';

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440201';

    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440202';

    private const string PRODUCT_ID_A = '550e8400-e29b-41d4-a716-446655440210';

    private const string PRODUCT_ID_B = '550e8400-e29b-41d4-a716-446655440211';

    private const string LINE_ID_A = '550e8400-e29b-41d4-a716-446655440220';

    private const string LINE_ID_B = '550e8400-e29b-41d4-a716-446655440221';

    private ProductRepositoryInterface&MockObject $productRepository;

    private CartItemFactory $factory;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);

        $this->factory = new CartItemFactory($this->productRepository);
    }

    public function testCreateReturnsEmptyCartItemWhenCartIsNull(): void
    {
        $this->productRepository->expects($this->never())->method('findByIds');
        $cartItem = $this->factory->create(null);

        $this->assertNull($cartItem->id);
        $this->assertSame([], $cartItem->items);
        $this->assertSame(0, $cartItem->totalQuantity);
        $this->assertEqualsWithDelta(0.0, $cartItem->subtotal, PHP_FLOAT_EPSILON);
        $this->assertSame('EUR', $cartItem->currency);
        $this->assertNull($cartItem->createdAt);
        $this->assertNull($cartItem->updatedAt);
    }

    public function testCreateBuildsLinesAndAggregatesTotals(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01 09:00:00');
        $updatedAt = new DateTimeImmutable('2025-01-02 10:00:00');

        $productA = $this->createProduct(self::PRODUCT_ID_A, 'Coffee Mug', 'mug', Money::fromEuros(12.50), 'mug.jpg');
        $productB = $this->createProduct(self::PRODUCT_ID_B, 'Tea Pot', 'pot', Money::fromEuros(20.00), null);

        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            CustomerId::fromString(self::CUSTOMER_ID),
            [
                CartLine::create(CartLineId::fromString(self::LINE_ID_A), ProductId::fromString(self::PRODUCT_ID_A), CartLineQuantity::fromInt(2)),
                CartLine::create(CartLineId::fromString(self::LINE_ID_B), ProductId::fromString(self::PRODUCT_ID_B), CartLineQuantity::fromInt(3)),
            ],
            $createdAt,
            $updatedAt,
        );

        $this->productRepository->expects($this->once())
            ->method('findByIds')
            ->willReturn([$productA, $productB]);

        $cartItem = $this->factory->create($cart);

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertSame(self::CART_ID, $cartItem->id);
        $this->assertSame(5, $cartItem->totalQuantity);
        // 2 * 12.50 + 3 * 20.00
        $this->assertEqualsWithDelta(85.0, $cartItem->subtotal, PHP_FLOAT_EPSILON);
        $this->assertSame('EUR', $cartItem->currency);
        $this->assertSame($createdAt, $cartItem->createdAt);
        $this->assertSame($updatedAt, $cartItem->updatedAt);

        $this->assertCount(2, $cartItem->items);

        $first = $cartItem->items[0];
        $this->assertSame(self::LINE_ID_A, $first->id);
        $this->assertSame(self::PRODUCT_ID_A, $first->productId);
        $this->assertSame('Coffee Mug', $first->productTitle);
        $this->assertSame('mug', $first->productSlug);
        $this->assertSame('mug.jpg', $first->image);
        $this->assertEqualsWithDelta(12.50, $first->unitPrice, PHP_FLOAT_EPSILON);
        $this->assertSame(2, $first->quantity);
        $this->assertEqualsWithDelta(25.0, $first->lineTotal, PHP_FLOAT_EPSILON);

        $second = $cartItem->items[1];
        $this->assertSame(self::PRODUCT_ID_B, $second->productId);
        $this->assertNull($second->image);
        $this->assertEqualsWithDelta(60.0, $second->lineTotal, PHP_FLOAT_EPSILON);
    }

    public function testCreateSkipsLinesWhoseProductIsMissing(): void
    {
        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            CustomerId::fromString(self::CUSTOMER_ID),
            [
                CartLine::create(CartLineId::fromString(self::LINE_ID_A), ProductId::fromString(self::PRODUCT_ID_A), CartLineQuantity::fromInt(2)),
                CartLine::create(CartLineId::fromString(self::LINE_ID_B), ProductId::fromString(self::PRODUCT_ID_B), CartLineQuantity::fromInt(4)),
            ],
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );

        // Only product A is resolved by the repository; B has been deleted.
        $productA = $this->createProduct(self::PRODUCT_ID_A, 'Coffee Mug', 'mug', Money::fromEuros(12.50), 'mug.jpg');

        $this->productRepository->expects($this->once())
            ->method('findByIds')
            ->willReturn([$productA]);

        $cartItem = $this->factory->create($cart);

        $this->assertCount(1, $cartItem->items);
        $this->assertSame(self::PRODUCT_ID_A, $cartItem->items[0]->productId);
        $this->assertSame(2, $cartItem->totalQuantity);
        $this->assertEqualsWithDelta(25.0, $cartItem->subtotal, PHP_FLOAT_EPSILON);
    }

    public function testCreateReturnsEmptyItemsForCartWithoutLines(): void
    {
        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            CustomerId::fromString(self::CUSTOMER_ID),
            [],
            new DateTimeImmutable('2025-01-01 09:00:00'),
            new DateTimeImmutable('2025-01-01 09:00:00'),
        );

        $this->productRepository->expects($this->once())
            ->method('findByIds')
            ->with([])
            ->willReturn([]);

        $cartItem = $this->factory->create($cart);

        $this->assertSame([], $cartItem->items);
        $this->assertSame(0, $cartItem->totalQuantity);
        $this->assertEqualsWithDelta(0.0, $cartItem->subtotal, PHP_FLOAT_EPSILON);
    }

    private function createProduct(string $id, string $title, string $slug, Money $price, ?string $imageName): Product
    {
        $now = new DateTimeImmutable('2025-01-01 08:00:00');

        return Product::reconstitute(
            ProductId::fromString($id),
            ProductTitle::fromString($title),
            ProductSubtitle::fromString('Subtitle'),
            ProductDescription::fromString('A description.'),
            $price,
            Slug::fromString($slug),
            CategoryId::fromString(self::CATEGORY_ID),
            $imageName,
            $now,
            $now,
        );
    }
}
