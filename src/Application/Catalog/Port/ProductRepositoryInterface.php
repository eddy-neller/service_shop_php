<?php

declare(strict_types=1);

namespace App\Application\Catalog\Port;

use App\Domain\Catalog\Model\Product;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductTitle;

interface ProductRepositoryInterface
{
    public const array SORT_FIELDS = ['title', 'category.title', 'price', 'createdAt'];

    public function nextIdentity(): ProductId;

    public function list(array $filters, array $orderBy, int $page, int $itemsPerPage): array;

    public function save(Product $product): void;

    public function delete(Product $product): void;

    public function findById(ProductId $id): ?Product;

    public function findWithCategoryById(ProductId $id): ?array;

    public function findByTitle(ProductTitle $title): ?Product;

    /**
     * Resolution par lot, pour les contextes qui referencent des produits sans les posseder
     * (le panier en tient une ligne par produit). Une lecture unique plutot qu'une par ligne.
     *
     * Les identifiants introuvables sont simplement absents du resultat : l'appelant decide
     * quoi faire d'une reference morte, le repository ne tranche pas a sa place.
     *
     * @param ProductId[] $ids
     *
     * @return Product[]
     */
    public function findByIds(array $ids): array;
}
