<?php

declare(strict_types=1);

namespace App\Tests\Domain\Catalog\Unit\Model;

use App\Domain\Catalog\Exception\CategoryNotEmptyException;
use App\Domain\Catalog\Model\Category;
use App\Domain\Catalog\ValueObject\CategoryDescription;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string PARENT_ID = '550e8400-e29b-41d4-a716-446655440001';

    public function testCreateSetsDefaults(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $category = Category::create(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('My category'),
            slug: Slug::fromString('my-category'),
            now: $now,
        );

        $this->assertTrue($category->getId()->equals(CategoryId::fromString(self::CATEGORY_ID)));
        $this->assertSame('My category', $category->getTitle()->toString());
        $this->assertSame('my-category', $category->getSlug()->toString());
        $this->assertNull($category->getDescription());
        $this->assertNull($category->getParentId());
        $this->assertSame(0, $category->getProductCount());
        $this->assertSame(0, $category->getLevel());
        $this->assertSame($now, $category->getCreatedAt());
        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testCreateWithParentAndDescriptionSetsValues(): void
    {
        $now = new DateTimeImmutable('2025-01-01 10:00:00');
        $description = CategoryDescription::fromString('A nice category');

        $category = Category::create(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('My category'),
            slug: Slug::fromString('my-category'),
            now: $now,
            parentId: CategoryId::fromString(self::PARENT_ID),
            description: $description,
        );

        $this->assertSame($description, $category->getDescription());
        $this->assertTrue($category->getParentId()?->equals(CategoryId::fromString(self::PARENT_ID)));
    }

    public function testRenameUpdatesTitleAndSlugAndUpdatedAt(): void
    {
        $category = $this->createCategory();

        $renameNow = new DateTimeImmutable('2025-01-02 10:00:00');
        $category->rename(
            CategoryTitle::fromString('New category'),
            Slug::fromString('new-category'),
            $renameNow,
        );

        $this->assertSame('New category', $category->getTitle()->toString());
        $this->assertSame('new-category', $category->getSlug()->toString());
        $this->assertSame($renameNow, $category->getUpdatedAt());
    }

    public function testDescribeUpdatesDescriptionAndUpdatedAt(): void
    {
        $category = $this->createCategory();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');
        $description = CategoryDescription::fromString('New description');

        $category->describe($description, $now);

        $this->assertSame($description, $category->getDescription());
        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testDescribeAllowsClearingDescription(): void
    {
        $category = $this->createCategoryWithDescription();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $category->describe(null, $now);

        $this->assertNull($category->getDescription());
        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testMoveToUpdatesParentIdAndUpdatedAt(): void
    {
        $category = $this->createCategory();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');
        $parentId = CategoryId::fromString(self::PARENT_ID);

        $category->moveTo($parentId, $now);

        $this->assertTrue($category->getParentId()?->equals($parentId));
        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testMoveToAllowsClearingParentId(): void
    {
        $category = $this->createCategoryWithParent();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $category->moveTo(null, $now);

        $this->assertNull($category->getParentId());
        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testIncreaseProductCountIncrementsAndTouchesUpdatedAt(): void
    {
        $category = $this->createCategory();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $category->increaseProductCount($now);

        $this->assertSame(1, $category->getProductCount());
        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testDecreaseProductCountDecrementsAndTouchesUpdatedAt(): void
    {
        $category = $this->createCategory();
        $category->increaseProductCount(new DateTimeImmutable('2025-01-01 12:00:00'));

        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $category->decreaseProductCount($now);

        $this->assertSame(0, $category->getProductCount());
        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testDecreaseProductCountThrowsWhenZero(): void
    {
        $category = $this->createCategory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product count cannot be negative.');

        $category->decreaseProductCount(new DateTimeImmutable('2025-01-02 10:00:00'));
    }

    public function testDeleteTouchesUpdatedAt(): void
    {
        $category = $this->createCategory();
        $now = new DateTimeImmutable('2025-01-02 10:00:00');

        $category->delete($now);

        $this->assertSame($now, $category->getUpdatedAt());
    }

    public function testDeleteThrowsWhenTheCategoryContainsProducts(): void
    {
        $category = $this->reconstituteCategory(productCount: 1);

        $this->expectException(CategoryNotEmptyException::class);
        $this->expectExceptionMessage('Category must have no products or children before deletion.');

        $category->delete(new DateTimeImmutable('2025-01-02 10:00:00'));
    }

    public function testDeleteThrowsWhenTheCategoryHasChildren(): void
    {
        $category = $this->reconstituteCategory(hasChildren: true);

        $this->expectException(CategoryNotEmptyException::class);
        $this->expectExceptionMessage('Category must have no products or children before deletion.');

        $category->delete(new DateTimeImmutable('2025-01-02 10:00:00'));
    }

    public function testReconstituteRestoresState(): void
    {
        $createdAt = new DateTimeImmutable('2024-12-01 10:00:00');
        $updatedAt = new DateTimeImmutable('2024-12-10 10:00:00');
        $parentId = CategoryId::fromString(self::PARENT_ID);
        $description = CategoryDescription::fromString('Stored description');

        $category = Category::reconstitute(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('Stored category'),
            slug: Slug::fromString('stored-category'),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            parentId: $parentId,
            description: $description,
            productCount: 5,
            level: 2,
        );

        $this->assertTrue($category->getId()->equals(CategoryId::fromString(self::CATEGORY_ID)));
        $this->assertSame('Stored category', $category->getTitle()->toString());
        $this->assertSame('stored-category', $category->getSlug()->toString());
        $this->assertSame($description, $category->getDescription());
        $this->assertTrue($category->getParentId()?->equals($parentId));
        $this->assertSame(5, $category->getProductCount());
        $this->assertSame(2, $category->getLevel());
        $this->assertSame($createdAt, $category->getCreatedAt());
        $this->assertSame($updatedAt, $category->getUpdatedAt());
    }

    public function testReconstituteThrowsWhenLevelIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category level must be positive.');

        Category::reconstitute(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('Stored category'),
            slug: Slug::fromString('stored-category'),
            createdAt: new DateTimeImmutable('2024-12-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2024-12-10 10:00:00'),
            level: -1,
        );
    }

    public function testReconstituteThrowsWhenProductCountIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product count cannot be negative.');

        Category::reconstitute(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('Stored category'),
            slug: Slug::fromString('stored-category'),
            createdAt: new DateTimeImmutable('2024-12-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2024-12-10 10:00:00'),
            productCount: -1,
        );
    }

    private function createCategory(): Category
    {
        return Category::create(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('My category'),
            slug: Slug::fromString('my-category'),
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
        );
    }

    private function createCategoryWithDescription(): Category
    {
        return Category::create(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('My category'),
            slug: Slug::fromString('my-category'),
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
            description: CategoryDescription::fromString('A description'),
        );
    }

    private function createCategoryWithParent(): Category
    {
        return Category::create(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('My category'),
            slug: Slug::fromString('my-category'),
            now: new DateTimeImmutable('2025-01-01 10:00:00'),
            parentId: CategoryId::fromString(self::PARENT_ID),
        );
    }

    private function reconstituteCategory(int $productCount = 0, bool $hasChildren = false): Category
    {
        return Category::reconstitute(
            id: CategoryId::fromString(self::CATEGORY_ID),
            title: CategoryTitle::fromString('Stored category'),
            slug: Slug::fromString('stored-category'),
            createdAt: new DateTimeImmutable('2024-12-01 10:00:00'),
            updatedAt: new DateTimeImmutable('2024-12-10 10:00:00'),
            productCount: $productCount,
            hasChildren: $hasChildren,
        );
    }
}
