<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Cache;

use App\Domain\Catalog\Event\CatalogDomainEventInterface;
use App\Domain\Customer\Event\CustomerDomainEventInterface;
use App\Domain\Ordering\Event\OrderingDomainEventInterface;
use App\Domain\SharedKernel\Event\DomainEventInterface;

final readonly class DomainEventCacheTags
{
    public function forEvent(DomainEventInterface $event): array
    {
        return match (true) {
            $event instanceof CatalogDomainEventInterface => [
                'categories-collection',
                'products-collection',
            ],
            $event instanceof CustomerDomainEventInterface => array_values(array_filter([
                'customer-' . $event->getCustomerId()->toString(),
                null === $event->getUserAccountId()
                    ? null
                    : 'customer-of-user-' . $event->getUserAccountId()->toString(),
            ])),
            $event instanceof OrderingDomainEventInterface => [
                'cart-of-' . $event->getOwnerId()->toString(),
            ],
            default => [],
        };
    }
}
