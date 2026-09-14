<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Cache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Adosse le cache de queries au pool `cache.tag`.
 *
 * Le pool Redis est dedie a ce service et partage entre ses replicas. Il ne contient que des
 * donnees de lecture recomputables et n'est jamais mutualise avec service_identity.
 *
 * @codeCoverageIgnore
 */
final readonly class SymfonyTagAwareQueryCache implements QueryCacheInterface
{
    public function __construct(
        #[Autowire(service: 'cache.tag')]
        private TagAwareCacheInterface $cache,
    ) {
    }

    public function get(string $key, int $ttlSeconds, array $tags, callable $callback): mixed
    {
        return $this->cache->get($key, static function (ItemInterface $item) use ($ttlSeconds, $tags, $callback): mixed {
            $item->expiresAfter($ttlSeconds);

            if ([] !== $tags) {
                $item->tag($tags);
            }

            return $callback();
        });
    }

    public function invalidateTags(array $tags): void
    {
        if ([] === $tags) {
            return;
        }

        $this->cache->invalidateTags($tags);
    }
}
