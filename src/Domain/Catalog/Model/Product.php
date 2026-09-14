<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Model;

use App\Domain\Catalog\Event\Product\ProductCreatedEvent;
use App\Domain\Catalog\Event\Product\ProductDeletedEvent;
use App\Domain\Catalog\Event\Product\ProductDescriptionUpdatedEvent;
use App\Domain\Catalog\Event\Product\ProductImageUpdatedEvent;
use App\Domain\Catalog\Event\Product\ProductMovedEvent;
use App\Domain\Catalog\Event\Product\ProductRenamedEvent;
use App\Domain\Catalog\Event\Product\ProductRepricedEvent;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductDescription;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\Catalog\ValueObject\ProductSubtitle;
use App\Domain\Catalog\ValueObject\ProductTitle;
use App\Domain\SharedKernel\Event\DomainEventTrait;
use App\Domain\SharedKernel\ValueObject\Money;
use App\Domain\SharedKernel\ValueObject\Slug;
use DateTimeImmutable;

final class Product
{
    use DomainEventTrait;

    private function __construct(
        private ProductId $id,
        private ProductTitle $title,
        private ProductSubtitle $subtitle,
        private ProductDescription $description,
        private Money $price,
        private Slug $slug,
        private CategoryId $categoryId,
        private ?string $imageName,
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
        $product = new self(
            id: $id,
            title: $title,
            subtitle: $subtitle,
            description: $description,
            price: $price,
            slug: $slug,
            categoryId: $categoryId,
            imageName: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $product->recordEvent(new ProductCreatedEvent($id, $categoryId, $now));

        return $product;
    }

    public static function reconstitute(
        ProductId $id,
        ProductTitle $title,
        ProductSubtitle $subtitle,
        ProductDescription $description,
        Money $price,
        Slug $slug,
        CategoryId $categoryId,
        ?string $imageName,
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
            imageName: $imageName,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    public function delete(DateTimeImmutable $now): void
    {
        $this->touch($now);

        $this->recordEvent(
            new ProductDeletedEvent($this->id, $this->categoryId, $now),
        );
    }

    public function rename(ProductTitle $title, ProductSubtitle $subtitle, DateTimeImmutable $now): void
    {
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->touch($now);

        $this->recordEvent(new ProductRenamedEvent($this->id, $this->categoryId, $now));
    }

    public function reprice(Money $price, DateTimeImmutable $now): void
    {
        $this->price = $price;
        $this->touch($now);

        $this->recordEvent(new ProductRepricedEvent($this->id, $this->categoryId, $price, $now));
    }

    public function rewrite(ProductDescription $description, DateTimeImmutable $now): void
    {
        $this->description = $description;
        $this->touch($now);

        $this->recordEvent(new ProductDescriptionUpdatedEvent($this->id, $this->categoryId, $now));
    }

    public function moveToCategory(CategoryId $categoryId, DateTimeImmutable $now): void
    {
        $previousCategoryId = $this->categoryId;
        $this->categoryId = $categoryId;
        $this->touch($now);

        $this->recordEvent(
            new ProductMovedEvent($this->id, $categoryId, $previousCategoryId, $now),
        );
    }

    /**
     * N'emet rien : le slug suit mecaniquement le titre, et `rename()` a deja publie le fait.
     */
    public function reSlug(Slug $slug, DateTimeImmutable $now): void
    {
        $this->slug = $slug;
        $this->touch($now);
    }

    public function updateImage(string $imageName, DateTimeImmutable $now): void
    {
        $this->imageName = $imageName;
        $this->touch($now);

        $this->recordEvent(
            new ProductImageUpdatedEvent($this->id, $this->categoryId, $now),
        );
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

    public function getImageName(): ?string
    {
        return $this->imageName;
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
