<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence\Ordering;

use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use App\Domain\SharedKernel\Exception\ConcurrentModificationException;
use App\Infrastructure\Persistence\Mongo\Ordering\CartDocument;
use App\Tests\Infrastructure\Integration\Persistence\MongoPersistenceTestCase;
use DateTimeImmutable;
use Throwable;

final class CartRepositoryTest extends MongoPersistenceTestCase
{
    private const string CUSTOMER_ID = '550e8400-e29b-41d4-a716-446655440010';

    private const string PRODUCT_A = '550e8400-e29b-41d4-a716-446655440030';

    private const string PRODUCT_B = '550e8400-e29b-41d4-a716-446655440031';

    public function testLinesSurviveTheRoundTrip(): void
    {
        $cart = $this->aCart();
        $this->addLine($cart, self::PRODUCT_A, 2);
        $this->addLine($cart, self::PRODUCT_B, 3);

        $this->transactional->transactional(function () use ($cart): void {
            $this->carts->save($cart);
        });
        $this->documentManager->clear();

        $reloaded = $this->carts->findByOwner(CustomerId::fromString(self::CUSTOMER_ID));

        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->getLines());
        self::assertSame(self::PRODUCT_A, $reloaded->getLines()[0]->getProductId()->toString());
        self::assertSame(2, $reloaded->getLines()[0]->getQuantity()->toInt());
        self::assertSame(3, $reloaded->getLines()[1]->getQuantity()->toInt());
    }

    /**
     * La fusion par produit doit etre persistee comme **une** ligne, pas deux : c'est
     * l'agregat qui fusionne, mais rien ne le prouve tant qu'on n'a pas relu le document.
     */
    public function testMergedLinesArePersistedAsASingleOne(): void
    {
        $cart = $this->aCart();
        $this->addLine($cart, self::PRODUCT_A, 2);
        $this->addLine($cart, self::PRODUCT_A, 3);

        $this->transactional->transactional(function () use ($cart): void {
            $this->carts->save($cart);
        });
        $this->documentManager->clear();

        $reloaded = $this->carts->findByOwner(CustomerId::fromString(self::CUSTOMER_ID));

        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getLines());
        self::assertSame(5, $reloaded->getLines()[0]->getQuantity()->toInt());
    }

    public function testClearingIsPersisted(): void
    {
        $cart = $this->aCart();
        $this->addLine($cart, self::PRODUCT_A, 2);

        $this->transactional->transactional(function () use ($cart): void {
            $this->carts->save($cart);
        });

        $cart->clear(new DateTimeImmutable('2025-03-01 10:00:00'));
        $this->transactional->transactional(function () use ($cart): void {
            $this->carts->save($cart);
        });
        $this->documentManager->clear();

        $reloaded = $this->carts->findByOwner(CustomerId::fromString(self::CUSTOMER_ID));

        self::assertNotNull($reloaded);
        self::assertSame([], $reloaded->getLines());
    }

    /**
     * L'index unique remplace un verrou pessimiste sur la ligne du
     * client avant toute creation. Deux paniers pour un meme proprietaire sont impossibles.
     */
    public function testACustomerCannotHaveTwoCarts(): void
    {
        $this->transactional->transactional(function (): void {
            $this->carts->save($this->aCart());
        });

        $failed = null;

        try {
            $this->transactional->transactional(function (): void {
                $this->documentManager->persist(
                    $this->rawSecondCartFor(CustomerId::fromString(self::CUSTOMER_ID)),
                );
            });
        } catch (Throwable $exception) {
            $failed = $exception;
        }

        self::assertInstanceOf(Throwable::class, $failed);
        self::assertSame(1, $this->countIn('cart', ['customerId' => self::CUSTOMER_ID]));
    }

    public function testAWriteLosingTheRaceIsRejectedAsAConflict(): void
    {
        $cart = $this->aCart();
        $this->addLine($cart, self::PRODUCT_A, 1);
        $this->transactional->transactional(function () use ($cart): void {
            $this->carts->save($cart);
        });
        $this->documentManager->clear();

        $mine = $this->carts->findByOwner(CustomerId::fromString(self::CUSTOMER_ID));
        self::assertNotNull($mine);

        // Un autre processus ajoute sa propre ligne et commite.
        $this->database()->selectCollection('cart')->updateOne(
            ['customerId' => self::CUSTOMER_ID],
            ['$inc' => ['version' => 1]],
        );

        $this->addLine($mine, self::PRODUCT_B, 4);

        $failed = null;

        try {
            $this->transactional->transactional(function () use ($mine): void {
                $this->carts->save($mine);
            });
        } catch (Throwable $exception) {
            $failed = $exception;
        }

        self::assertInstanceOf(ConcurrentModificationException::class, $failed);
    }

    public function testFindByOwnerReturnsNullWhenThereIsNoCart(): void
    {
        self::assertNull($this->carts->findByOwner(
            CustomerId::fromString('550e8400-e29b-41d4-a716-4466554400ff'),
        ));
    }

    private function aCart(): Cart
    {
        return Cart::create(
            $this->carts->nextIdentity(),
            CustomerId::fromString(self::CUSTOMER_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );
    }

    private function addLine(Cart $cart, string $productId, int $quantity): void
    {
        $cart->addLine(
            $this->carts->nextLineIdentity(),
            ProductId::fromString($productId),
            CartLineQuantity::fromInt($quantity),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );
    }

    /**
     * Construit un second panier pour le meme proprietaire en court-circuitant le depot :
     * `save()` retrouverait le panier existant par son `customerId` et se contenterait de le
     * mettre a jour, ce qui ne solliciterait jamais l'index.
     */
    private function rawSecondCartFor(CustomerId $ownerId): CartDocument
    {
        $document = new CartDocument();
        $document->id = $this->carts->nextIdentity()->toString();
        $document->customerId = $ownerId->toString();
        $document->createdAt = new DateTimeImmutable('2025-02-01 10:00:00');
        $document->updatedAt = new DateTimeImmutable('2025-02-01 10:00:00');

        return $document;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function countIn(string $collection, array $filter): int
    {
        return $this->database()->selectCollection($collection)->countDocuments($filter);
    }
}
