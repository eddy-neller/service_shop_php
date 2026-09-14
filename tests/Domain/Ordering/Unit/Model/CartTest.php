<?php

declare(strict_types=1);

namespace App\Tests\Domain\Ordering\Unit\Model;

use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Event\Cart\CartClearedEvent;
use App\Domain\Ordering\Event\Cart\CartCreatedEvent;
use App\Domain\Ordering\Event\Cart\CartLineAddedEvent;
use App\Domain\Ordering\Event\Cart\CartLineQuantityChangedEvent;
use App\Domain\Ordering\Event\Cart\CartLineRemovedEvent;
use App\Domain\Ordering\Exception\CartLineNotFoundException;
use App\Domain\Ordering\Exception\CartQuantityExceededException;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use App\Domain\Ordering\ValueObject\CartLineQuantityChange;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    private const string CART_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440001';

    private const string LINE_ID = '550e8400-e29b-41d4-a716-446655440002';

    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440003';

    public function testAddLineMergesTheSameProduct(): void
    {
        $cart = $this->createCart();
        $now = new DateTimeImmutable();

        $cart->addLine(
            CartLineId::fromString(self::LINE_ID),
            ProductId::fromString(self::PRODUCT_ID),
            CartLineQuantity::fromInt(2),
            $now,
        );
        $cart->addLine(
            CartLineId::fromString('550e8400-e29b-41d4-a716-446655440004'),
            ProductId::fromString(self::PRODUCT_ID),
            CartLineQuantity::fromInt(3),
            $now,
        );

        self::assertCount(1, $cart->getLines());
        self::assertSame(5, $cart->getLines()[0]->getQuantity()->toInt());
    }

    public function testUpdateRemoveAndClearLines(): void
    {
        $cart = $this->createCart();
        $now = new DateTimeImmutable();
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart->addLine(CartLineId::fromString(self::LINE_ID), $productId, CartLineQuantity::fromInt(1), $now);

        $cart->updateLine($productId, CartLineQuantity::fromInt(4), $now);
        self::assertSame(4, $cart->getLines()[0]->getQuantity()->toInt());

        $cart->removeLine($productId, $now);
        self::assertSame([], $cart->getLines());

        $cart->addLine(CartLineId::fromString(self::LINE_ID), $productId, CartLineQuantity::fromInt(1), $now);
        $cart->clear($now);
        self::assertSame([], $cart->getLines());
    }

    public function testMissingLineThrows(): void
    {
        $this->expectException(CartLineNotFoundException::class);

        $this->createCart()->removeLine(ProductId::fromString(self::PRODUCT_ID), new DateTimeImmutable());
    }

    public function testMergedQuantityCannotExceedMaximum(): void
    {
        $cart = $this->createCart();
        $now = new DateTimeImmutable();
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart->addLine(CartLineId::fromString(self::LINE_ID), $productId, CartLineQuantity::fromInt(99), $now);

        $this->expectException(CartQuantityExceededException::class);
        $cart->addLine(CartLineId::fromString(self::LINE_ID), $productId, CartLineQuantity::fromInt(1), $now);
    }

    public function testCreateRecordsTheCartCreatedEvent(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $cart = Cart::create(
            CartId::fromString(self::CART_ID),
            CustomerId::fromString(self::CUSTOMER_ID),
            $now,
        );

        $events = $cart->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CartCreatedEvent::class, $events[0]);
        self::assertSame('shop.ordering.cart.created', $events[0]->eventName());
        self::assertSame(self::CART_ID, $events[0]->aggregateId());
        self::assertSame(self::CUSTOMER_ID, $events[0]->getOwnerId()->toString());
        self::assertSame($now, $events[0]->occurredOn());
    }

    public function testReconstituteRecordsNothing(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');

        $cart = Cart::reconstitute(
            CartId::fromString(self::CART_ID),
            CustomerId::fromString(self::CUSTOMER_ID),
            [],
            $now,
            $now,
        );

        self::assertSame([], $cart->releaseEvents());
    }

    /**
     * La fusion ne masque pas le fait : deux ajouts du meme produit font une seule ligne,
     * mais bien deux evenements — sinon le journal perdrait le second geste du client.
     */
    public function testEachAddRecordsItsOwnEventEvenWhenLinesMerge(): void
    {
        $cart = $this->createCart();
        $cart->clearDomainEvents();

        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);

        $cart->addLine(CartLineId::fromString(self::LINE_ID), $productId, CartLineQuantity::fromInt(2), $now);
        $cart->addLine(CartLineId::fromString(self::LINE_ID), $productId, CartLineQuantity::fromInt(3), $now);

        self::assertCount(1, $cart->getLines());

        $events = $cart->releaseEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(CartLineAddedEvent::class, $events[0]);
        self::assertSame('shop.ordering.cart.line_added', $events[0]->eventName());
        self::assertSame(self::PRODUCT_ID, $events[0]->getProductId()->toString());
    }

    public function testUpdateAndRemoveRecordTheirEvents(): void
    {
        $cart = $this->createCart();
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart->addLine(CartLineId::fromString(self::LINE_ID), $productId, CartLineQuantity::fromInt(1), $now);
        $cart->clearDomainEvents();

        $cart->updateLine($productId, CartLineQuantity::fromInt(4), $now);
        $cart->removeLine($productId, $now);

        $events = $cart->releaseEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(CartLineQuantityChangedEvent::class, $events[0]);
        self::assertSame('shop.ordering.cart.line_quantity_changed', $events[0]->eventName());
        self::assertInstanceOf(CartLineRemovedEvent::class, $events[1]);
        self::assertSame('shop.ordering.cart.line_removed', $events[1]->eventName());
    }

    /**
     * `changeLineQuantity(0)` supprime la ligne : c'est un retrait, pas un changement de
     * quantite, et l'evenement doit le dire.
     */
    public function testChangingTheQuantityToZeroRecordsARemoval(): void
    {
        $cart = $this->createCart();
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $productId = ProductId::fromString(self::PRODUCT_ID);
        $cart->addLine(CartLineId::fromString(self::LINE_ID), $productId, CartLineQuantity::fromInt(2), $now);
        $cart->clearDomainEvents();

        $cart->changeLineQuantity($productId, CartLineQuantityChange::fromInt(0), $now);

        self::assertSame([], $cart->getLines());

        $events = $cart->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CartLineRemovedEvent::class, $events[0]);
    }

    public function testClearRecordsTheEventWhenTheCartHadLines(): void
    {
        $cart = $this->createCart();
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $cart->addLine(
            CartLineId::fromString(self::LINE_ID),
            ProductId::fromString(self::PRODUCT_ID),
            CartLineQuantity::fromInt(1),
            $now,
        );
        $cart->clearDomainEvents();

        $cart->clear($now);

        $events = $cart->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CartClearedEvent::class, $events[0]);
        self::assertSame('shop.ordering.cart.cleared', $events[0]->eventName());
    }

    /**
     * `DELETE /me/cart` est rejouable a volonte. Sans la garde, chaque appel deposerait une
     * ligne d'outbox pour un fait qui n'a pas eu lieu.
     */
    public function testClearingAnEmptyCartRecordsNothingAndDoesNotTouchIt(): void
    {
        $cart = $this->createCart();
        $cart->clearDomainEvents();

        $untouched = $cart->getUpdatedAt();

        $cart->clear(new DateTimeImmutable('2030-01-01 10:00:00'));

        self::assertSame([], $cart->releaseEvents());
        self::assertSame($untouched, $cart->getUpdatedAt());
    }

    private function createCart(): Cart
    {
        return Cart::create(
            CartId::fromString(self::CART_ID),
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable(),
        );
    }
}
