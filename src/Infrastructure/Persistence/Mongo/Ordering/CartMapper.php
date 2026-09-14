<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Ordering;

use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Customer\ValueObject\CustomerId;
use App\Domain\Ordering\Model\Cart as DomainCart;
use App\Domain\Ordering\Model\CartLine as DomainCartLine;
use App\Domain\Ordering\ValueObject\CartId;
use App\Domain\Ordering\ValueObject\CartLineId;
use App\Domain\Ordering\ValueObject\CartLineQuantity;

final readonly class CartMapper
{
    public function toDomain(CartDocument $document): DomainCart
    {
        $lines = [];
        foreach ($document->lines as $embedded) {
            $lines[] = DomainCartLine::create(
                CartLineId::fromString($embedded->id),
                ProductId::fromString($embedded->productId),
                CartLineQuantity::fromInt($embedded->quantity),
            );
        }

        return DomainCart::reconstitute(
            id: CartId::fromString($document->id),
            ownerId: CustomerId::fromString($document->customerId),
            lines: $lines,
            createdAt: $document->createdAt,
            updatedAt: $document->updatedAt,
        );
    }

    /**
     * Les lignes sont reecrites integralement, comme les adresses d'un client : un panier en
     * porte peu, et un rapprochement ligne a ligne devrait gerer la fusion par produit et les
     * suppressions — deux chemins ou une divergence passerait inapercue.
     */
    public function toDocument(DomainCart $cart, ?CartDocument $document = null): CartDocument
    {
        if (null === $document) {
            $document = new CartDocument();
            $document->id = $cart->getId()->toString();
            $document->customerId = $cart->getOwnerId()->toString();
            $document->createdAt = $cart->getCreatedAt();
        }

        $document->updatedAt = $cart->getUpdatedAt();

        $document->lines->clear();
        foreach ($cart->getLines() as $line) {
            $embedded = new CartLineEmbeddedDocument();
            $embedded->id = $line->getId()->toString();
            $embedded->productId = $line->getProductId()->toString();
            $embedded->quantity = $line->getQuantity()->toInt();

            $document->lines->add($embedded);
        }

        return $document;
    }
}
