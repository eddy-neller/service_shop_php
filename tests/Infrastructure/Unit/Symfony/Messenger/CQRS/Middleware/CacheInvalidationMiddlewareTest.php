<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\CQRS\Middleware;

use ApiPlatform\HttpCache\PurgerInterface;
use App\Domain\Catalog\Event\Category\CategoryRenamedEvent;
use App\Domain\Catalog\Event\Product\ProductCreatedEvent;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\ProductId;
use App\Infrastructure\Adapter\Cache\CatalogHttpCacheTags;
use App\Infrastructure\Adapter\Cache\DomainEventCacheTags;
use App\Infrastructure\Adapter\Cache\QueryCacheInterface;
use App\Infrastructure\Symfony\Messenger\CQRS\Middleware\CacheInvalidationMiddleware;
use App\Infrastructure\Symfony\Messenger\Event\PublishedDomainEventCollector;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class CacheInvalidationMiddlewareTest extends TestCase
{
    private const string CATEGORY_ID = '550e8400-e29b-41d4-a716-446655440000';

    private const string PRODUCT_ID = '550e8400-e29b-41d4-a716-446655440001';

    private PublishedDomainEventCollector $collector;

    private QueryCacheInterface&MockObject $cache;

    private PurgerInterface&MockObject $httpCachePurger;

    private CacheInvalidationMiddleware $middleware;

    protected function setUp(): void
    {
        $this->collector = new PublishedDomainEventCollector();
        $this->cache = $this->createMock(QueryCacheInterface::class);
        $this->httpCachePurger = $this->createMock(PurgerInterface::class);

        $this->middleware = new CacheInvalidationMiddleware(
            $this->collector,
            new DomainEventCacheTags(),
            $this->cache,
            new CatalogHttpCacheTags(),
            $this->httpCachePurger,
        );
    }

    public function testItPurgesBothCollectionsOnceForSeveralEvents(): void
    {
        $this->collector->record($this->categoryRenamed());
        $this->collector->record($this->productCreated());

        $this->cache->expects($this->once())
            ->method('invalidateTags')
            ->with($this->callback(static function (array $tags): bool {
                sort($tags);

                return ['categories-collection', 'products-collection'] === $tags;
            }));
        $this->httpCachePurger->expects($this->once())
            ->method('purge')
            ->with($this->callback(static function (array $tags): bool {
                sort($tags);

                return [
                    '/api/shop/categories',
                    '/api/shop/categories/' . self::CATEGORY_ID,
                    '/api/shop/products',
                    '/api/shop/products/' . self::PRODUCT_ID,
                ] === $tags;
            }));

        $this->middleware->handle(new Envelope(new stdClass()), $this->passthroughStack());
    }

    public function testItPurgesNothingWhenNoEventWasPublished(): void
    {
        $this->cache->expects($this->never())->method('invalidateTags');
        $this->httpCachePurger->expects($this->never())->method('purge');

        $this->middleware->handle(new Envelope(new stdClass()), $this->passthroughStack());
    }

    /**
     * Une commande qui leve a pu committer une partie de son travail : la purge doit avoir
     * lieu quand meme, et le collecteur repartir vide pour le message suivant du worker.
     */
    public function testItPurgesAndEmptiesTheCollectorEvenWhenTheCommandFails(): void
    {
        $this->collector->record($this->categoryRenamed());

        $this->cache->expects($this->once())->method('invalidateTags');
        $this->httpCachePurger->expects($this->once())->method('purge');

        try {
            $this->middleware->handle(new Envelope(new stdClass()), $this->throwingStack());
            $this->fail("L'exception du handler aurait du etre propagee.");
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertSame([], $this->collector->release());
    }

    private function categoryRenamed(): CategoryRenamedEvent
    {
        return new CategoryRenamedEvent(
            CategoryId::fromString(self::CATEGORY_ID),
            new DateTimeImmutable('2024-05-01 08:00:00'),
        );
    }

    private function productCreated(): ProductCreatedEvent
    {
        return new ProductCreatedEvent(
            ProductId::fromString(self::PRODUCT_ID),
            CategoryId::fromString(self::CATEGORY_ID),
            new DateTimeImmutable('2024-05-01 08:00:00'),
        );
    }

    private function passthroughStack(): StackInterface
    {
        return $this->stack(static fn (Envelope $envelope): Envelope => $envelope);
    }

    private function throwingStack(): StackInterface
    {
        return $this->stack(static function (): Envelope {
            throw new RuntimeException('Echec du handler.');
        });
    }

    private function stack(Closure $next): StackInterface
    {
        $middleware = new class($next) implements MiddlewareInterface {
            public function __construct(private readonly Closure $next)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return ($this->next)($envelope);
            }
        };

        return new class($middleware) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $middleware)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this->middleware;
            }
        };
    }
}
