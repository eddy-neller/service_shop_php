<?php

declare(strict_types=1);

namespace App\Presentation\Catalog\Presenter;

use App\Application\Catalog\ReadModel\Catalog\CategoryItem;
use App\Presentation\Catalog\ApiResource\CategoryResource;

final readonly class CategoryResourcePresenter
{
    public function toResource(CategoryItem $categoryItem): CategoryResource
    {
        $resource = $this->mapCategory($categoryItem);

        $resource->parent = null === $categoryItem->parent ? null : $this->mapCategory($categoryItem->parent);
        $resource->children = null === $categoryItem->children ? null : array_map(
            fn (CategoryItem $child): CategoryResource => $this->mapCategory($child),
            $categoryItem->children,
        );

        return $resource;
    }

    public function toSummaryResource(CategoryItem $category): CategoryResource
    {
        return $this->mapCategory($category);
    }

    /**
     * Flat mapping to prevent parent/children recursion in list/get payloads.
     */
    private function mapCategory(CategoryItem $category): CategoryResource
    {
        $resource = new CategoryResource();

        $resource->id = $category->id;
        $resource->title = $category->title;
        $resource->description = $category->description;
        $resource->nbProduct = $category->nbProduct;
        $resource->slug = $category->slug;
        $resource->level = $category->level;
        $resource->hasChildren = $category->hasChildren;
        $resource->createdAt = $category->createdAt;
        $resource->updatedAt = $category->updatedAt;

        return $resource;
    }
}
