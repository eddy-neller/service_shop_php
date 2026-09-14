<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo\Catalog;

use App\Domain\Catalog\Model\Category as DomainCategory;
use App\Domain\Catalog\ValueObject\CategoryDescription;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;

final readonly class CategoryMapper
{
    /**
     * `hasChildren` n'est pas porte par le document : il est calcule par le repository,
     * qui seul peut interroger la collection. Le mapper le recoit donc en parametre.
     */
    public function toDomain(CategoryDocument $document, bool $hasChildren): DomainCategory
    {
        return DomainCategory::reconstitute(
            id: CategoryId::fromString($document->id),
            title: CategoryTitle::fromString($document->title),
            slug: Slug::fromString($document->slug),
            createdAt: $document->createdAt,
            updatedAt: $document->updatedAt,
            parentId: null === $document->parent ? null : CategoryId::fromString($document->parent->id),
            description: CategoryDescription::fromNullableString($document->description),
            productCount: $document->nbProduct,
            level: $document->level,
            hasChildren: $hasChildren,
        );
    }

    /** Gedmo Tree maintient `path` et `level` au flush. */
    public function toDocument(DomainCategory $category, ?CategoryDocument $document = null): CategoryDocument
    {
        if (null === $document) {
            $document = new CategoryDocument();
            $document->id = $category->getId()->toString();
        }

        $document->title = $category->getTitle()->toString();
        $document->description = $category->getDescription()?->toString();
        $document->slug = $category->getSlug()->toString();
        $document->nbProduct = $category->getProductCount();
        $document->createdAt = $category->getCreatedAt();
        $document->updatedAt = $category->getUpdatedAt();

        return $document;
    }
}
