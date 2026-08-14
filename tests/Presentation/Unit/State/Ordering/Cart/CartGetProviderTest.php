<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Unit\State\Ordering\Cart;

use ApiPlatform\Metadata\Get;
use App\Application\Catalog\Port\ProductImageUrlResolverInterface;
use App\Application\Customer\ReadModel\CurrentCustomerItem;
use App\Application\Customer\UseCase\Query\DisplayMyCustomer\DisplayMyCustomerQuery;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\ReadModel\CartLineItem;
use App\Application\Ordering\UseCase\Query\DisplayMyCart\DisplayMyCartQuery;
use App\Application\Shared\CQRS\Query\QueryBusInterface;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Presentation\Ordering\ApiResource\CartResource;
use App\Presentation\Ordering\Presenter\CartResourcePresenter;
use App\Presentation\Ordering\State\Cart\CartGetProvider;
use App\Presentation\Shared\State\CurrentCustomerResolver;
use App\Tests\Presentation\Unit\State\Customer\CustomerUserTrait;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class CartGetProviderTest extends TestCase
{
    use CustomerUserTrait;

    public function testItReturnsCartResource(): void
    {
        $queryBus = $this->createMock(QueryBusInterface::class);
        $security = $this->createMock(Security::class);

        $security->expects($this->once())
            ->method('getUser')
            ->willReturn($this->createUser('550e8400-e29b-41d4-a716-446655440700'));

        $customerId = CustomerId::fromString('550e8400-e29b-41d4-a716-446655440701');
        $customerOutput = new CurrentCustomerItem($customerId->toString());
        $cartOutput = $this->createCart();
        $imageUrlResolver = $this->createMock(ProductImageUrlResolverInterface::class);
        $imageUrlResolver->expects($this->once())
            ->method('resolve')
            ->with('mug.png')
            ->willReturn('/uploads/images/shop/product/mug.png');

        $queryBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($query) use ($customerId, $customerOutput, $cartOutput): CurrentCustomerItem|CartItem {
                if ($query instanceof DisplayMyCustomerQuery) {
                    $this->assertSame('550e8400-e29b-41d4-a716-446655440700', $query->userAccountId);

                    return $customerOutput;
                }

                if ($query instanceof DisplayMyCartQuery) {
                    $this->assertSame($customerId->toString(), $query->customerId);

                    return $cartOutput;
                }

                $this->fail('Unexpected query dispatched.');
            });

        $provider = new CartGetProvider(
            $queryBus,
            new CurrentCustomerResolver($queryBus, $security),
            new CartResourcePresenter($imageUrlResolver),
        );

        $result = $provider->provide(new Get(name: 'shop-cart-get'));

        $this->assertInstanceOf(CartResource::class, $result);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440710', $result->id);
        $this->assertSame(3, $result->totalQuantity);
        $this->assertEqualsWithDelta(59.97, $result->subtotal, PHP_FLOAT_EPSILON);
        $this->assertSame('EUR', $result->currency);
        $this->assertCount(1, $result->items);
        $this->assertSame('Mug', $result->items[0]->productTitle);
        $this->assertSame('/uploads/images/shop/product/mug.png', $result->items[0]->imageUrl);
        $this->assertSame(3, $result->items[0]->quantity);
    }

    private function createCart(): CartItem
    {
        return new CartItem(
            id: '550e8400-e29b-41d4-a716-446655440710',
            items: [
                new CartLineItem(
                    id: '550e8400-e29b-41d4-a716-446655440711',
                    productId: '550e8400-e29b-41d4-a716-446655440712',
                    productTitle: 'Mug',
                    productSlug: 'mug',
                    image: 'mug.png',
                    unitPrice: 19.99,
                    quantity: 3,
                    lineTotal: 59.97,
                ),
            ],
            totalQuantity: 3,
            subtotal: 59.97,
            currency: 'EUR',
            createdAt: new DateTimeImmutable('2025-01-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2025-01-02 10:00:00'),
        );
    }
}
