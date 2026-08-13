<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence\Catalog;

use App\Tests\Infrastructure\Integration\Persistence\MongoPersistenceTestCase;

/**
 * Version MongoDB du test repris du monolithe.
 *
 * Le `countNbProductByCategory()` de l'ancien repository ORM n'existe plus : la seule
 * lecture qui compte des produits par categorie passe desormais par le filtre
 * `category` de `list()`. C'est donc lui qui est verifie ici, contre un vrai MongoDB —
 * un mock ne dirait rien du mapping ni de la normalisation du filtre.
 */
final class ProductRepositoryTest extends MongoPersistenceTestCase
{
    public function testFindWithCategoryByIdLoadsTheProductCategoryWithoutItsTreeState(): void
    {
        $root = $this->aCategory('Root');
        $guitares = $this->aCategory('Guitares', $root->getId());
        $product = $this->aProduct('Stratocaster', $guitares->getId());

        $this->transactional->transactional(function () use ($root, $guitares, $product): void {
            $this->categories->save($root);
            $this->categories->save($guitares);
            $this->categories->save($this->aCategory('Electric guitars', $guitares->getId()));

            $this->products->save($product);
        });

        $item = $this->products->findWithCategoryById($product->getId());

        self::assertNotNull($item);
        self::assertSame($product->getId()->toString(), $item['product']->getId()->toString());
        self::assertNotNull($item['category']);
        self::assertSame($guitares->getId()->toString(), $item['category']->getId()->toString());
        self::assertFalse($item['category']->hasChildren());
    }

    public function testCountNbProductByCategory(): void
    {
        $guitares = $this->aCategory('Guitares');
        $basses = $this->aCategory('Basses');

        $this->transactional->transactional(function () use ($guitares, $basses): void {
            $this->categories->save($guitares);
            $this->categories->save($basses);

            $this->products->save($this->aProduct('Stratocaster', $guitares->getId()));
            $this->products->save($this->aProduct('Telecaster', $guitares->getId()));
            $this->products->save($this->aProduct('Jazz Bass', $basses->getId()));
        });

        $res = $this->products->list(
            filters: ['category' => $guitares->getId()->toString()],
            orderBy: [],
            page: 1,
            itemsPerPage: 10,
        );

        self::assertSame(2, $res['totalItems']);
        self::assertCount(2, $res['items']);
    }

    /**
     * Le client envoie une IRI, pas un UUID nu : le filtre doit compter la meme chose.
     */
    public function testTheCategoryFilterAcceptsAnIri(): void
    {
        $guitares = $this->aCategory('Guitares');

        $this->transactional->transactional(function () use ($guitares): void {
            $this->categories->save($guitares);
            $this->products->save($this->aProduct('Stratocaster', $guitares->getId()));
        });

        $res = $this->products->list(
            filters: ['category' => '/shop/categories/' . $guitares->getId()->toString()],
            orderBy: [],
            page: 1,
            itemsPerPage: 10,
        );

        self::assertSame(1, $res['totalItems']);
    }
}
