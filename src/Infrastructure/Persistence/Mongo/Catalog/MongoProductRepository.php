<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Catalog;

use App\Application\Catalog\Port\ProductRepositoryInterface;
use App\Domain\Catalog\Model\Category as DomainCategory;
use App\Domain\Catalog\Model\Product as DomainProduct;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Infrastructure\Adapter\Uuid\UuidGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Exception;
use MongoDB\BSON\Regex;

final readonly class MongoProductRepository implements ProductRepositoryInterface
{
    public function __construct(
        private DocumentManager $documentManager,
        private UuidGeneratorInterface $uuidGenerator,
        private ProductMapper $mapper,
        private CategoryMapper $categoryMapper,
    ) {
    }

    public function nextIdentity(): ProductId
    {
        return ProductId::fromString($this->uuidGenerator->generate());
    }

    public function list(array $filters, array $orderBy, int $page, int $itemsPerPage): array
    {
        $totalItems = $this->applyFilters($this->createQueryBuilder(), $filters)
            ->count()
            ->getQuery()
            ->execute();
        $totalItems = is_int($totalItems) ? $totalItems : 0;

        $totalPages = $itemsPerPage > 0 ? (int) ceil($totalItems / $itemsPerPage) : 1;

        $builder = $this->applyFilters($this->createQueryBuilder(), $filters);
        $this->applyOrdering($builder, $orderBy);

        $builder
            ->skip(max(0, ($page - 1) * $itemsPerPage))
            ->limit($itemsPerPage);

        $documents = [];
        foreach ($builder->getQuery()->execute() as $document) {
            if ($document instanceof ProductDocument) {
                $documents[] = $document;
            }
        }

        // Le join SQL n'existe plus : on resout les categories de la page en un seul
        // aller-retour supplementaire, plutot qu'une lecture par produit.
        $categories = $this->loadCategoriesOf($documents);

        $items = [];
        foreach ($documents as $document) {
            $category = $categories[$document->categoryId] ?? null;
            if (null === $category) {
                // Garde defensive pour une donnee legacy incoherente : la ressource API expose
                // une categorie non nullable, et l'invariant de suppression l'empeche en ecriture.
                continue;
            }

            $items[] = [
                'product' => $this->mapper->toDomain($document),
                'category' => $category,
            ];
        }

        return [
            'items' => $items,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Ne flushe pas : c'est `MongoTransactional` qui declenche le flush transactionnel
     * unique du cas d'usage. Flusher ici ouvrirait une transaction par `save()`.
     */
    public function save(DomainProduct $product): void
    {
        $document = $this->mapper->toDocument($product, $this->findDocument($product->getId()));

        $this->documentManager->persist($document);
    }

    public function delete(DomainProduct $product): void
    {
        $document = $this->findDocument($product->getId());
        if (null === $document) {
            return;
        }

        $this->documentManager->remove($document);
    }

    public function findById(ProductId $id): ?DomainProduct
    {
        $document = $this->findDocument($id);

        return null === $document ? null : $this->mapper->toDomain($document);
    }

    /**
     * @return array{product: DomainProduct, category: ?DomainCategory}|null
     */
    public function findWithCategoryById(ProductId $id): ?array
    {
        $document = $this->findDocument($id);
        if (null === $document) {
            return null;
        }

        $categoryDocument = $this->documentManager->find(CategoryDocument::class, $document->categoryId);

        return [
            'product' => $this->mapper->toDomain($document),
            // La vue produit n'expose pas `category.hasChildren` : un `count()` sur
            // les enfants de cette categorie serait donc une lecture sans effet.
            'category' => $categoryDocument instanceof CategoryDocument
                ? $this->categoryMapper->toDomain($categoryDocument, hasChildren: false)
                : null,
        ];
    }

    public function findByTitle(ProductTitle $title): ?DomainProduct
    {
        $document = $this->documentManager
            ->getRepository(ProductDocument::class)
            ->findOneBy(['title' => $title->toString()]);

        return $document instanceof ProductDocument ? $this->mapper->toDomain($document) : null;
    }

    /**
     * @param list<ProductDocument> $documents
     *
     * @return array<string, DomainCategory>
     */
    private function loadCategoriesOf(array $documents): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (ProductDocument $document): string => $document->categoryId,
            $documents,
        )));

        if ([] === $ids) {
            return [];
        }

        $categories = [];
        $found = $this->documentManager
            ->getRepository(CategoryDocument::class)
            ->findBy(['id' => ['$in' => $ids]]);

        foreach ($found as $document) {
            if (!$document instanceof CategoryDocument) {
                continue;
            }

            // `hasChildren` a false : une categorie affichee en resume dans une liste de
            // produits n'expose pas son arborescence. Interroger la collection par
            // categorie ferait N requetes pour une information non serialisee ici.
            $categories[$document->id] = $this->categoryMapper->toDomain($document, hasChildren: false);
        }

        return $categories;
    }

    private function findDocument(ProductId $id): ?ProductDocument
    {
        $document = $this->documentManager->find(ProductDocument::class, $id->toString());

        return $document instanceof ProductDocument ? $document : null;
    }

    private function createQueryBuilder(): Builder
    {
        return $this->documentManager->createQueryBuilder(ProductDocument::class);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $builder, array $filters): Builder
    {
        foreach (['title', 'subtitle', 'description'] as $field) {
            $value = $filters[$field] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                // Equivalent du LIKE '%...%' : une regex ancree nulle part, insensible
                // a la casse. `preg_quote` evite qu'une saisie utilisateur devienne un motif.
                $builder->field($field)->equals(
                    new Regex(preg_quote(trim($value), '/'), 'i'),
                );
            }
        }

        $categoryId = $this->normalizeCategoryFilter($filters['category'] ?? null);
        if (null !== $categoryId) {
            $builder->field('categoryId')->equals($categoryId);
        }

        return $builder;
    }

    /**
     * @param array<string, mixed> $orderBy
     */
    private function applyOrdering(Builder $builder, array $orderBy): void
    {
        // `category.title` n'est pas triable ici : la categorie vit dans une autre
        // collection et MongoDB ne trie pas sur un document joint sans pipeline. Le champ
        // reste declare dans SORT_FIELDS pour l'API, il est simplement ignore.
        $allowedFields = [
            'title' => 'title',
            'price' => 'priceAmount',
            'createdAt' => 'createdAt',
        ];

        foreach ($orderBy as $field => $direction) {
            if (!isset($allowedFields[$field])) {
                continue;
            }

            $normalizedDirection = 'desc' === strtolower((string) $direction) ? 'desc' : 'asc';

            $builder->sort($allowedFields[$field], $normalizedDirection);
        }

        $builder->sort('id', 'asc');
    }

    private function normalizeCategoryFilter(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = trim($value);
        if ('' === $candidate) {
            return null;
        }

        // Le client peut envoyer une IRI (`/shop/categories/<uuid>`) plutot qu'un UUID nu.
        $path = parse_url($candidate, PHP_URL_PATH);
        if (is_string($path) && '' !== $path) {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            if ([] !== $segments) {
                $candidate = end($segments);
            }
        }

        try {
            return CategoryId::fromString($candidate)->toString();
        } catch (Exception) {
            return null;
        }
    }
}
