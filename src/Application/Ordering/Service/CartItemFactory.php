<?php

declare(strict_types=1);

namespace App\Application\Ordering\Service;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Application\Ordering\ReadModel\CartItem;
use App\Application\Ordering\ReadModel\CartLineItem;
use App\Domain\Ordering\Model\Cart;
use App\Domain\Ordering\Model\CartLine;
use App\Domain\SharedKernel\ValueObject\Money;

/**
 * Reconstitue la vue d'un panier en relisant le catalogue.
 *
 * **Rien n'est fige dans le panier** : ni prix, ni titre, ni image. Chaque affichage relit
 * les produits, ce qui garantit qu'un tarif modifie est visible immediatement — et interdit
 * du meme coup de mettre `DisplayMyCartQuery` en cache, puisqu'aucun tag ne saurait dire
 * quels paniers purger apres un changement de prix.
 *
 * Une ligne dont le produit a disparu est **ignoree silencieusement**, comportement repris
 * tel quel du monolithe. Attention toutefois : la-bas une cle etrangere en cascade retirait
 * la ligne avec le produit. Ici il n'y en a plus, donc la ligne survit indefiniment dans le
 * document, invisible a l'API et jamais nettoyee.
 */
final readonly class CartItemFactory
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
    ) {
    }

    public function create(?Cart $cart): CartItem
    {
        $subtotal = Money::zero();

        if (null === $cart) {
            return new CartItem(null, [], 0, $subtotal->toEuros(), $subtotal->currency(), null, null);
        }

        $items = [];
        $totalQuantity = 0;
        $products = [];

        $productIds = array_map(
            static fn (CartLine $line) => $line->getProductId(),
            $cart->getLines(),
        );

        foreach ($this->productRepository->findByIds($productIds) as $product) {
            $products[$product->getId()->toString()] = $product;
        }

        foreach ($cart->getLines() as $line) {
            $product = $products[$line->getProductId()->toString()] ?? null;
            if (null === $product) {
                continue;
            }

            $unitPrice = $product->getPrice();
            $quantity = $line->getQuantity()->toInt();
            $lineTotal = $unitPrice->multiply($quantity);
            $totalQuantity += $quantity;
            $subtotal = $subtotal->add($lineTotal);

            $items[] = new CartLineItem(
                id: $line->getId()->toString(),
                productId: $product->getId()->toString(),
                productTitle: $product->getTitle()->toString(),
                productSlug: $product->getSlug()->toString(),
                image: $product->getImageName(),
                unitPrice: $unitPrice->toEuros(),
                quantity: $quantity,
                lineTotal: $lineTotal->toEuros(),
            );
        }

        return new CartItem(
            id: $cart->getId()->toString(),
            items: $items,
            totalQuantity: $totalQuantity,
            subtotal: $subtotal->toEuros(),
            currency: $subtotal->currency(),
            createdAt: $cart->getCreatedAt(),
            updatedAt: $cart->getUpdatedAt(),
        );
    }
}
