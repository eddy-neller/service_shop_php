<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence\Catalog;

use App\Domain\Catalog\Model\Category;
use App\Tests\Infrastructure\Integration\Persistence\MongoPersistenceTestCase;

final class CategoryRepositoryTest extends MongoPersistenceTestCase
{
    public function testFindTreeByIdBatchLoadsTheHasChildrenStateOfDirectChildren(): void
    {
        $root = $this->aCategory('Root');
        $category = $this->aCategory('Category', $root->getId());
        $leaf = $this->aCategory('Leaf', $category->getId());
        $branch = $this->aCategory('Branch', $category->getId());
        $this->transactional->transactional(function () use ($root, $category, $leaf, $branch): void {
            $this->categories->save($root);
            $this->categories->save($category);
            $this->categories->save($leaf);
            $this->categories->save($branch);
            $this->categories->save($this->aCategory('Grandchild', $branch->getId()));
        });

        $tree = $this->categories->findTreeById($category->getId());

        self::assertNotNull($tree);
        self::assertTrue($tree['category']->hasChildren());
        self::assertInstanceOf(Category::class, $tree['parent']);
        self::assertTrue($tree['parent']->hasChildren());
        self::assertNotNull($tree['children']);

        $children = [];
        foreach ($tree['children'] as $child) {
            $children[$child->getTitle()->toString()] = $child;
        }

        self::assertFalse($children['Leaf']->hasChildren());
        self::assertTrue($children['Branch']->hasChildren());
    }
}
