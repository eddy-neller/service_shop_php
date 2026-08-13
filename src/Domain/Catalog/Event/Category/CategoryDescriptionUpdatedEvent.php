<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Event\Category;

use App\Domain\Catalog\Event\CategoryDomainEventInterface;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\SharedKernel\Event\DomainEventIdentityTrait;
use DateTimeImmutable;

final readonly class CategoryDescriptionUpdatedEvent implements CategoryDomainEventInterface
{
    use DomainEventIdentityTrait;

    public function __construct(
        private CategoryId $categoryId,
        private DateTimeImmutable $occurredOn,
    ) {
        $this->eventId = self::generateEventId();
    }

    public function getCategoryId(): CategoryId
    {
        return $this->categoryId;
    }

    public function aggregateId(): string
    {
        return $this->categoryId->toString();
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function eventName(): string
    {
        return 'shop.catalog.category.description_updated';
    }
}
