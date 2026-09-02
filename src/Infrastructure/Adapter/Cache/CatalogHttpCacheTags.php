<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Cache;

use App\Domain\Catalog\Event\CatalogDomainEventInterface;
use App\Domain\Catalog\Event\CategoryDomainEventInterface;
use App\Domain\Catalog\Event\Product\ProductMovedEvent;
use App\Domain\Catalog\Event\ProductDomainEventInterface;
use App\Domain\SharedKernel\Event\DomainEventInterface;

/**
 * Traduit les faits du catalogue en tags HTTP API Platform.
 *
 * Varnish ne connait pas les tags Redis : il indexe les reponses par leurs IRIs
 * dans l'en-tete `Cache-Tags`. Les collections sont toujours presentes pour
 * evincer une page qui ne contient pas encore (creation) ou ne contient plus
 * (suppression) l'item concerne.
 */
final readonly class CatalogHttpCacheTags
{
    /**
     * @return list<string>
     */
    public function forEvent(DomainEventInterface $event): array
    {
        if (!$event instanceof CatalogDomainEventInterface) {
            return [];
        }

        $tags = [
            '/api/shop/categories',
            '/api/shop/products',
        ];

        if ($event instanceof CategoryDomainEventInterface) {
            $tags[] = '/api/shop/categories/' . $event->getCategoryId()->toString();
        }

        if ($event instanceof ProductDomainEventInterface) {
            $tags[] = '/api/shop/products/' . $event->getProductId()->toString();
            $tags[] = '/api/shop/categories/' . $event->getCategoryId()->toString();
        }

        if ($event instanceof ProductMovedEvent) {
            $tags[] = '/api/shop/categories/' . $event->getPreviousCategoryId()->toString();
        }

        return array_values(array_unique($tags));
    }
}
