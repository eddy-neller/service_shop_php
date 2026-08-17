<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Catalog;

use App\Application\Catalog\Port\CategoryRepositoryInterface;
use App\Domain\Catalog\Model\Category as DomainCategory;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Infrastructure\Adapter\Uuid\UuidGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Ramsey\Uuid\Uuid;

final readonly class MongoCategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private DocumentManager $documentManager,
        private UuidGeneratorInterface $uuidGenerator,
        private CategoryMapper $mapper,
    ) {
    }

    public function nextIdentity(): CategoryId
    {
        return CategoryId::fromString($this->uuidGenerator->generate());
    }

    /**
     * @return array{items: list<DomainCategory>, totalItems: int, totalPages: int}
     */
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
            if ($document instanceof CategoryDocument) {
                $documents[] = $document;
            }
        }

        $withChildren = $this->parentIdsHavingChildren($documents);

        $items = [];
        foreach ($documents as $document) {
            $items[] = $this->mapper->toDomain(
                $document,
                hasChildren: in_array($document->id, $withChildren, true),
            );
        }

        return [
            'items' => $items,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Ne flushe pas : voir `MongoTransactional`.
     *
     * `level` est calcule ici, et nulle part ailleurs. Un nested set Gedmo s'en chargerait,
     * mais il n'a pas d'equivalent ODM dans cette stack, et un
     * arbre de catalogue est assez peu profond pour qu'un calcul explicite soit plus
     * lisible qu'une renumerotation d'intervalles.
     */
    public function save(DomainCategory $category): void
    {
        $document = $this->mapper->toDocument($category, $this->findDocument($category->getId()));

        $previousLevel = $document->level;
        $document->level = $this->levelOf($category->getParentId());

        $this->documentManager->persist($document);

        // Deplacer une categorie decale le niveau de toute sa descendance. Sans cette
        // propagation, le filtre `?level=` renverrait des resultats faux des le premier
        // deplacement, sans qu'aucune erreur ne le signale.
        if ($previousLevel !== $document->level) {
            $this->shiftDescendantLevels($document->id, $document->level);
        }
    }

    public function delete(DomainCategory $category): void
    {
        $document = $this->findDocument($category->getId());
        if (null === $document) {
            return;
        }

        $this->documentManager->remove($document);
    }

    public function findById(CategoryId $id): ?DomainCategory
    {
        $document = $this->findDocument($id);

        return null === $document ? null : $this->toDomainWithChildren($document);
    }

    public function findByTitle(CategoryTitle $title): ?DomainCategory
    {
        $document = $this->documentManager
            ->getRepository(CategoryDocument::class)
            ->findOneBy(['title' => $title->toString()]);

        return $document instanceof CategoryDocument ? $this->toDomainWithChildren($document) : null;
    }

    /**
     * @return array{category: DomainCategory, parent: ?DomainCategory, children: ?list<DomainCategory>}|null
     */
    public function findTreeById(CategoryId $id): ?array
    {
        $document = $this->findDocument($id);
        if (null === $document) {
            return null;
        }

        $parentDocument = null === $document->parentId
            ? null
            : $this->documentManager->find(CategoryDocument::class, $document->parentId);

        $childDocuments = [];
        $children = $this->documentManager
            ->getRepository(CategoryDocument::class)
            ->findBy(['parentId' => $document->id]);

        foreach ($children as $child) {
            if ($child instanceof CategoryDocument) {
                $childDocuments[] = $child;
            }
        }

        // La vue d'item expose `hasChildren` sur chaque enfant direct. Une requete
        // groupee conserve cette information sans faire un `count()` par enfant.
        $childIdsHavingChildren = array_fill_keys($this->parentIdsHavingChildren($childDocuments), true);

        $children = [];
        foreach ($childDocuments as $child) {
            $children[] = $this->mapper->toDomain(
                $child,
                hasChildren: isset($childIdsHavingChildren[$child->id]),
            );
        }

        return [
            'category' => $this->mapper->toDomain($document, hasChildren: [] !== $children),
            'parent' => $parentDocument instanceof CategoryDocument
                // La categorie courante est necessairement un enfant direct de ce parent :
                // inutile de refaire un `count(parentId = parent.id)` uniquement pour
                // recalculer une information deja prouvee par la relation chargee ci-dessus.
                ? $this->mapper->toDomain($parentDocument, hasChildren: true)
                : null,
            'children' => [] === $children ? null : $children,
        ];
    }

    private function toDomainWithChildren(CategoryDocument $document): DomainCategory
    {
        return $this->mapper->toDomain($document, hasChildren: $this->hasChildren($document->id));
    }

    private function hasChildren(string $categoryId): bool
    {
        $count = $this->createQueryBuilder()
            ->field('parentId')->equals($categoryId)
            ->count()
            ->getQuery()
            ->execute();

        return is_int($count) && $count > 0;
    }

    /**
     * Une seule requete pour toute la page, au lieu d'un `hasChildren()` par ligne.
     *
     * @param list<CategoryDocument> $documents
     *
     * @return list<string>
     */
    private function parentIdsHavingChildren(array $documents): array
    {
        $ids = array_map(static fn (CategoryDocument $document): string => $document->id, $documents);
        if ([] === $ids) {
            return [];
        }

        $children = $this->documentManager
            ->getRepository(CategoryDocument::class)
            ->findBy(['parentId' => ['$in' => $ids]]);

        $parents = [];
        foreach ($children as $child) {
            if ($child instanceof CategoryDocument && null !== $child->parentId) {
                $parents[$child->parentId] = true;
            }
        }

        return array_keys($parents);
    }

    private function levelOf(?CategoryId $parentId): int
    {
        if (null === $parentId) {
            return 0;
        }

        $parent = $this->documentManager->find(CategoryDocument::class, $parentId->toString());

        return $parent instanceof CategoryDocument ? $parent->level + 1 : 0;
    }

    private function shiftDescendantLevels(string $categoryId, int $parentLevel): void
    {
        $children = $this->documentManager
            ->getRepository(CategoryDocument::class)
            ->findBy(['parentId' => $categoryId]);

        foreach ($children as $child) {
            if (!$child instanceof CategoryDocument) {
                continue;
            }

            $child->level = $parentLevel + 1;
            $this->documentManager->persist($child);

            $this->shiftDescendantLevels($child->id, $child->level);
        }
    }

    private function findDocument(CategoryId $id): ?CategoryDocument
    {
        $document = $this->documentManager->find(CategoryDocument::class, $id->toString());

        return $document instanceof CategoryDocument ? $document : null;
    }

    private function createQueryBuilder(): Builder
    {
        return $this->documentManager->createQueryBuilder(CategoryDocument::class);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $builder, array $filters): Builder
    {
        $levelValue = filter_var($filters['level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (false !== $levelValue) {
            $builder->field('level')->equals((int) $levelValue);
        }

        $parent = $filters['parent'] ?? null;
        if (is_string($parent) && Uuid::isValid($parent)) {
            $builder->field('parentId')->equals($parent);
        }

        return $builder;
    }

    /**
     * @param array<string, mixed> $orderBy
     */
    private function applyOrdering(Builder $builder, array $orderBy): void
    {
        $allowedFields = [
            'title' => 'title',
            'level' => 'level',
            'nbProduct' => 'nbProduct',
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
}
