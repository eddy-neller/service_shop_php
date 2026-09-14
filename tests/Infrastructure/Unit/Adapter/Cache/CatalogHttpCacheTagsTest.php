<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Adapter\Cache;

use App\Domain\Catalog\Event\Category\CategoryMovedEvent;
use App\Domain\Catalog\Event\Product\ProductMovedEvent;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Domain\SharedKernel\Event\DomainEventIdentityTrait;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Infrastructure\Adapter\Cache\CatalogHttpCacheTags;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CatalogHttpCacheTagsTest extends TestCase
{
    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440041';

    private const string PREVIOUS_CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440042';

    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440040';

    private CatalogHttpCacheTags $tags;

    protected function setUp(): void
    {
        $this->tags = new CatalogHttpCacheTags();
    }

    public function testACategoryMovePurgesItsItemAndBothCollections(): void
    {
        $event = new CategoryMovedEvent(
            CategoryId::fromString(self::CATEGORY_ID),
            null,
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        self::assertSame([
            '/api/shop/categories',
            '/api/shop/products',
            '/api/shop/categories/' . self::CATEGORY_ID,
        ], $this->tags->forEvent($event));
    }

    public function testAProductMovePurgesTheProductAndBothAffectedCategories(): void
    {
        $event = new ProductMovedEvent(
            ProductId::fromString(self::PRODUCT_ID),
            CategoryId::fromString(self::CATEGORY_ID),
            CategoryId::fromString(self::PREVIOUS_CATEGORY_ID),
            new DateTimeImmutable('2025-01-01 10:00:00'),
        );

        self::assertSame([
            '/api/shop/categories',
            '/api/shop/products',
            '/api/shop/products/' . self::PRODUCT_ID,
            '/api/shop/categories/' . self::CATEGORY_ID,
            '/api/shop/categories/' . self::PREVIOUS_CATEGORY_ID,
        ], $this->tags->forEvent($event));
    }

    public function testAnUnknownFactPurgesNoHttpResponse(): void
    {
        $event = new class implements DomainEventInterface {
            use DomainEventIdentityTrait;

            public function __construct()
            {
                $this->eventId = self::generateEventId();
            }

            public function aggregateId(): string
            {
                return 'irrelevant';
            }

            public function occurredOn(): DateTimeImmutable
            {
                return new DateTimeImmutable('2025-01-01 10:00:00');
            }

            public function eventName(): string
            {
                return 'shop.unknown.thing_happened';
            }
        };

        self::assertSame([], $this->tags->forEvent($event));
    }
}
