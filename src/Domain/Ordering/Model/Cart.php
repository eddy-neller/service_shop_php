<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Model;

use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Event\Cart\CartClearedEvent;
use App\Domain\Ordering\Event\Cart\CartCreatedEvent;
use App\Domain\Ordering\Event\Cart\CartLineAddedEvent;
use App\Domain\Ordering\Event\Cart\CartLineQuantityChangedEvent;
use App\Domain\Ordering\Event\Cart\CartLineRemovedEvent;
use App\Domain\Ordering\Exception\CartLineNotFoundException;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;
use App\Domain\Ordering\ValueObject\CartLineQuantityChange;
use App\Domain\SharedKernel\Event\DomainEventTrait;
use DateTimeImmutable;

/**
 * Racine d'agregat. Une ligne est identifiee par son **produit** : deux ajouts du meme
 * produit fusionnent, et `CartLineId` ne sert qu'a distinguer les lignes en stockage.
 *
 * Le panier ne conserve aucun prix. Titre, prix et image sont relus dans le catalogue a
 * chaque affichage (`CartItemFactory`), ce qui evite un panier qui afficherait un tarif
 * perime — et interdit du meme coup de mettre sa lecture en cache.
 */
final class Cart
{
    use DomainEventTrait;

    /** @param list<CartLine> $lines */
    private function __construct(
        private readonly CartId $id,
        private readonly CustomerId $ownerId,
        private array $lines,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(CartId $id, CustomerId $ownerId, DateTimeImmutable $now): self
    {
        $cart = new self($id, $ownerId, [], $now, $now);

        $cart->recordEvent(new CartCreatedEvent($id, $ownerId, $now));

        return $cart;
    }

    /** @param list<CartLine> $lines */
    public static function reconstitute(
        CartId $id,
        CustomerId $ownerId,
        array $lines,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $ownerId, $lines, $createdAt, $updatedAt);
    }

    /**
     * Fusionne avec la ligne existante du meme produit. Le `CartLineId` fourni est alors
     * ignore : c'est le produit qui identifie la ligne, pas l'appelant.
     */
    public function addLine(
        CartLineId $lineId,
        ProductId $productId,
        CartLineQuantity $quantity,
        DateTimeImmutable $now,
    ): void {
        $line = $this->findLineByProduct($productId);

        if (null !== $line) {
            $line->increase($quantity);
        } else {
            $this->lines[] = CartLine::create($lineId, $productId, $quantity);
        }

        $this->touch($now);

        $this->recordEvent(new CartLineAddedEvent($this->id, $this->ownerId, $productId, $now));
    }

    public function changeLineQuantity(
        ProductId $productId,
        CartLineQuantityChange $quantity,
        DateTimeImmutable $now,
    ): void {
        if ($quantity->isRemoval()) {
            $this->removeLine($productId, $now);

            return;
        }

        $this->updateLine($productId, $quantity->toCartLineQuantity(), $now);
    }

    public function updateLine(ProductId $productId, CartLineQuantity $quantity, DateTimeImmutable $now): void
    {
        $this->getLineByProduct($productId)->setQuantity($quantity);
        $this->touch($now);

        $this->recordEvent(new CartLineQuantityChangedEvent($this->id, $this->ownerId, $productId, $now));
    }

    public function removeLine(ProductId $productId, DateTimeImmutable $now): void
    {
        $line = $this->getLineByProduct($productId);
        $this->lines = array_values(array_filter(
            $this->lines,
            static fn (CartLine $candidate): bool => !$candidate->getId()->equals($line->getId()),
        ));
        $this->touch($now);

        $this->recordEvent(new CartLineRemovedEvent($this->id, $this->ownerId, $productId, $now));
    }

    /**
     * Sort tot sur un panier deja vide : `DELETE /me/cart` est rejouable a volonte, et sans
     * cette garde chaque appel deposerait une ligne d'outbox pour un fait qui n'a pas eu lieu.
     */
    public function clear(DateTimeImmutable $now): void
    {
        if ([] === $this->lines) {
            return;
        }

        $this->lines = [];
        $this->touch($now);

        $this->recordEvent(new CartClearedEvent($this->id, $this->ownerId, $now));
    }

    /** @return list<CartLine> */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getId(): CartId
    {
        return $this->id;
    }

    public function getOwnerId(): CustomerId
    {
        return $this->ownerId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function getLineByProduct(ProductId $productId): CartLine
    {
        return $this->findLineByProduct($productId) ?? throw new CartLineNotFoundException();
    }

    private function findLineByProduct(ProductId $productId): ?CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->getProductId()->equals($productId)) {
                return $line;
            }
        }

        return null;
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
