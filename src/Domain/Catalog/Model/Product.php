<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Model;

use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductImage;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;

final class Product
{
    private function __construct(
        private ProductId $id,
        private ProductTitle $title,
        private ProductSubtitle $subtitle,
        private ProductDescription $description,
        private Money $price,
        private Slug $slug,
        private CategoryId $categoryId,
        private ProductImage $image,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        ProductId $id,
        ProductTitle $title,
        ProductSubtitle $subtitle,
        ProductDescription $description,
        Money $price,
        Slug $slug,
        CategoryId $categoryId,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: $id,
            title: $title,
            subtitle: $subtitle,
            description: $description,
            price: $price,
            slug: $slug,
            categoryId: $categoryId,
            image: ProductImage::create(),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        ProductId $id,
        ProductTitle $title,
        ProductSubtitle $subtitle,
        ProductDescription $description,
        Money $price,
        Slug $slug,
        CategoryId $categoryId,
        ProductImage $image,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            id: $id,
            title: $title,
            subtitle: $subtitle,
            description: $description,
            price: $price,
            slug: $slug,
            categoryId: $categoryId,
            image: $image,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function delete(DateTimeImmutable $now): void
    {
        $this->touch($now);
    }

    public function rename(ProductTitle $title, ProductSubtitle $subtitle, DateTimeImmutable $now): void
    {
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->touch($now);
    }

    public function reprice(Money $price, DateTimeImmutable $now): void
    {
        $this->price = $price;
        $this->touch($now);
    }

    public function rewrite(ProductDescription $description, DateTimeImmutable $now): void
    {
        $this->description = $description;
        $this->touch($now);
    }

    public function moveToCategory(CategoryId $categoryId, DateTimeImmutable $now): void
    {
        $this->categoryId = $categoryId;
        $this->touch($now);
    }

    public function reSlug(Slug $slug, DateTimeImmutable $now): void
    {
        $this->slug = $slug;
        $this->touch($now);
    }

    public function updateImage(ProductImage $image, DateTimeImmutable $now): void
    {
        $this->image = $image;
        $this->touch($now);
    }

    public function getId(): ProductId
    {
        return $this->id;
    }

    public function getTitle(): ProductTitle
    {
        return $this->title;
    }

    public function getSubtitle(): ProductSubtitle
    {
        return $this->subtitle;
    }

    public function getDescription(): ProductDescription
    {
        return $this->description;
    }

    public function getPrice(): Money
    {
        return $this->price;
    }

    public function getSlug(): Slug
    {
        return $this->slug;
    }

    public function getCategoryId(): CategoryId
    {
        return $this->categoryId;
    }

    public function getImage(): ProductImage
    {
        return $this->image;
    }

    public function getImageName(): ?string
    {
        return $this->image->fileName();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
