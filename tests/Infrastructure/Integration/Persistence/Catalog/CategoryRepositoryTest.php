<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Integration\Persistence\Catalog;

use App\Domain\Catalog\Model\Category;
use App\Infrastructure\Persistence\Mongo\Catalog\CategoryDocument;
use App\Tests\Infrastructure\Integration\Persistence\MongoPersistenceTestCase;
use DateTimeImmutable;

final class CategoryRepositoryTest extends MongoPersistenceTestCase
{
    public function testGedmoMaterializedPathMaintainsPathAndLevelWhenACategoryMoves(): void
    {
        $root = $this->aCategory('Root');
        $child = $this->aCategory('Child', $root->getId());
        $grandchild = $this->aCategory('Grandchild', $child->getId());

        $this->transactional->transactional(function () use ($root, $child, $grandchild): void {
            $this->categories->save($root);
            $this->categories->save($child);
            $this->categories->save($grandchild);
        });

        $this->documentManager->clear();
        $storedRoot = $this->documentManager->find(CategoryDocument::class, $root->getId()->toString());
        $storedChild = $this->documentManager->find(CategoryDocument::class, $child->getId()->toString());
        $storedGrandchild = $this->documentManager->find(CategoryDocument::class, $grandchild->getId()->toString());

        self::assertInstanceOf(CategoryDocument::class, $storedRoot);
        self::assertInstanceOf(CategoryDocument::class, $storedChild);
        self::assertInstanceOf(CategoryDocument::class, $storedGrandchild);
        self::assertSame($storedRoot->id . '/', $storedRoot->path);
        self::assertSame($storedRoot->path . $storedChild->id . '/', $storedChild->path);
        self::assertSame($storedChild->path . $storedGrandchild->id . '/', $storedGrandchild->path);
        self::assertSame(1, $storedRoot->level);
        self::assertSame(2, $storedChild->level);
        self::assertSame(3, $storedGrandchild->level);

        $movedChild = $this->categories->findById($child->getId());
        self::assertNotNull($movedChild);
        $movedChild->moveTo(null, new DateTimeImmutable());
        $this->transactional->transactional(function () use ($movedChild): void {
            $this->categories->save($movedChild);
        });

        $this->documentManager->clear();
        $storedChild = $this->documentManager->find(CategoryDocument::class, $child->getId()->toString());
        $storedGrandchild = $this->documentManager->find(CategoryDocument::class, $grandchild->getId()->toString());

        self::assertInstanceOf(CategoryDocument::class, $storedChild);
        self::assertInstanceOf(CategoryDocument::class, $storedGrandchild);
        self::assertNull($storedChild->parent);
        self::assertSame($storedChild->id . '/', $storedChild->path);
        self::assertSame(1, $storedChild->level);
        self::assertSame($storedChild->path . $storedGrandchild->id . '/', $storedGrandchild->path);
        self::assertSame(2, $storedGrandchild->level);
    }

    public function testRootFilterAndDescendantCheckUseTheMaterializedPath(): void
    {
        $root = $this->aCategory('Root');
        $child = $this->aCategory('Child', $root->getId());
        $grandchild = $this->aCategory('Grandchild', $child->getId());
        $otherRoot = $this->aCategory('Other root');

        $this->transactional->transactional(function () use ($root, $child, $grandchild, $otherRoot): void {
            $this->categories->save($root);
            $this->categories->save($child);
            $this->categories->save($grandchild);
            $this->categories->save($otherRoot);
        });

        $roots = $this->categories->list(['root' => 'true'], ['title' => 'ASC'], 1, 10);

        self::assertSame(2, $roots['totalItems']);
        self::assertTrue($this->categories->isDescendantOf($grandchild->getId(), $root->getId()));
        self::assertFalse($this->categories->isDescendantOf($root->getId(), $grandchild->getId()));
        self::assertFalse($this->categories->isDescendantOf($root->getId(), $root->getId()));
    }

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
