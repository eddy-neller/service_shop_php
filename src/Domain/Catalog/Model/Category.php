<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Model;

use App\Domain\Catalog\ValueObject\CategoryDescription;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryTitle;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;
use InvalidArgumentException;

final class Category
{
    private function __construct(
        private CategoryId $id,
        private CategoryTitle $title,
        private ?CategoryDescription $description,
        private Slug $slug,
        private ?CategoryId $parentId,
        private int $productCount,
        private int $level,
        private bool $hasChildren,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        CategoryId $id,
        CategoryTitle $title,
        Slug $slug,
        DateTimeImmutable $now,
        ?CategoryId $parentId = null,
        ?CategoryDescription $description = null,
    ): self {
        return new self(
            id: $id,
            title: $title,
            description: $description,
            slug: $slug,
            parentId: $parentId,
            productCount: 0,
            level: 0,
            hasChildren: false,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        CategoryId $id,
        CategoryTitle $title,
        Slug $slug,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?CategoryId $parentId = null,
        ?CategoryDescription $description = null,
        int $productCount = 0,
        int $level = 0,
        bool $hasChildren = false,
    ): self {
        self::assertLevel($level);

        if ($productCount < 0) {
            throw new InvalidArgumentException('Product count cannot be negative.');
        }

        return new self(
            id: $id,
            title: $title,
            description: $description,
            slug: $slug,
            parentId: $parentId,
            productCount: $productCount,
            level: $level,
            hasChildren: $hasChildren,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function delete(DateTimeImmutable $now): void
    {
        $this->touch($now);
    }

    public function rename(CategoryTitle $title, Slug $slug, DateTimeImmutable $now): void
    {
        $this->title = $title;
        $this->slug = $slug;
        $this->touch($now);
    }

    public function describe(?CategoryDescription $description, DateTimeImmutable $now): void
    {
        $this->description = $description;
        $this->touch($now);
    }

    public function moveTo(?CategoryId $parentId, DateTimeImmutable $now): void
    {
        $this->parentId = $parentId;
        $this->touch($now);
    }

    public function increaseProductCount(DateTimeImmutable $now): void
    {
        ++$this->productCount;
        $this->touch($now);
    }

    public function decreaseProductCount(DateTimeImmutable $now): void
    {
        if (0 === $this->productCount) {
            throw new InvalidArgumentException('Product count cannot be negative.');
        }

        --$this->productCount;
        $this->touch($now);
    }

    public function getId(): CategoryId
    {
        return $this->id;
    }

    public function getTitle(): CategoryTitle
    {
        return $this->title;
    }

    public function getDescription(): ?CategoryDescription
    {
        return $this->description;
    }

    public function getSlug(): Slug
    {
        return $this->slug;
    }

    public function getParentId(): ?CategoryId
    {
        return $this->parentId;
    }

    public function getProductCount(): int
    {
        return $this->productCount;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function hasChildren(): bool
    {
        return $this->hasChildren;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function assertLevel(int $level): void
    {
        if ($level < 0) {
            throw new InvalidArgumentException('Category level must be positive.');
        }
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
