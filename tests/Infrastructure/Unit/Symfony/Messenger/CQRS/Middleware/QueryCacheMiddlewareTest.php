<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\CQRS\Middleware;

use App\Application\Catalog\UseCase\Query\DisplayCategory\DisplayCategoryQuery;
use App\Application\Catalog\UseCase\Query\DisplayProduct\DisplayProductQuery;
use App\Infrastructure\Adapter\Cache\QueryCacheInterface;
use App\Infrastructure\Symfony\Messenger\CQRS\HandledResultExtractor;
use App\Infrastructure\Symfony\Messenger\CQRS\Middleware\QueryCacheMiddleware;
use Closure;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class QueryCacheMiddlewareTest extends TestCase
{
    private QueryCacheInterface&MockObject $cache;

    private QueryCacheMiddleware $middleware;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(QueryCacheInterface::class);
        $this->middleware = new QueryCacheMiddleware($this->cache, new HandledResultExtractor());
    }

    public function testItReturnsAnItemQueryFromTheCacheWithoutCallingItsHandler(): void
    {
        $query = new DisplayProductQuery('550e8400-e29b-41d4-a716-446655440000');
        $result = new stdClass();

        $this->cache->expects($this->once())
            ->method('get')
            ->with(
                'product-item-550e8400-e29b-41d4-a716-446655440000',
                3600,
                ['categories-collection', 'products-collection'],
                $this->isCallable(),
            )
            ->willReturn($result);

        $output = $this->middleware->handle(new Envelope($query), $this->stack(static function (): Envelope {
            self::fail("Le handler ne doit pas etre appele lors d'un cache hit.");
        }));

        $stamp = $output->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $stamp);
        self::assertSame($result, $stamp->getResult());
    }

    public function testItCachesTheHandlerResultForAnItemQuery(): void
    {
        $query = new DisplayCategoryQuery('550e8400-e29b-41d4-a716-446655440001');
        $result = new stdClass();
        $handledEnvelope = new Envelope($query, [new HandledStamp($result, 'handler')]);

        $this->cache->expects($this->once())
            ->method('get')
            ->with(
                'category-item-550e8400-e29b-41d4-a716-446655440001',
                3600,
                ['categories-collection', 'products-collection'],
                $this->isCallable(),
            )
            ->willReturnCallback(static function (string $key, int $ttl, array $tags, callable $callback): mixed {
                return $callback();
            });

        $output = $this->middleware->handle(new Envelope($query), $this->stack(
            static fn (): Envelope => $handledEnvelope,
        ));

        self::assertSame($handledEnvelope, $output);
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
