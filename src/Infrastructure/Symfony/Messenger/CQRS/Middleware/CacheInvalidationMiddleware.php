<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\CQRS\Middleware;

use ApiPlatform\HttpCache\PurgerInterface;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Infrastructure\Adapter\Cache\CatalogHttpCacheTags;
use App\Infrastructure\Adapter\Cache\DomainEventCacheTags;
use App\Infrastructure\Adapter\Cache\QueryCacheInterface;
use App\Infrastructure\Symfony\Messenger\Event\PublishedDomainEventCollector;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Purge le cache des queries touchees par une commande, des son retour.
 *
 * Place autour du handler, il s'execute donc **apres** le `transactional()` de celui-ci :
 * le commit est acquis quand les tags sont invalides. Une invalidation avant commit serait
 * pire que tardive — un lecteur concurrent recacherait l'etat d'avant l'ecriture.
 *
 * C'est aussi pourquoi l'invalidation ne passe pas par une reaction du worker : celui-ci se
 * reveille quelques dizaines de millisecondes apres la reponse HTTP, et le client qui relit
 * juste apres son ecriture verrait sa propre modification manquante.
 */
final readonly class CacheInvalidationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PublishedDomainEventCollector $collector,
        private DomainEventCacheTags $cacheTags,
        private QueryCacheInterface $cache,
        private CatalogHttpCacheTags $httpCacheTags,
        private PurgerInterface $httpCachePurger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            // Sur echec, la commande a pu committer une partie de son travail avant de lever :
            // on purge quand meme, et on vide le collecteur dans tous les cas.
            $this->invalidate($this->collector->release());
        }
    }

    /**
     * @param list<DomainEventInterface> $events
     */
    private function invalidate(array $events): void
    {
        $tags = [];
        $httpTags = [];

        foreach ($events as $event) {
            foreach ($this->cacheTags->forEvent($event) as $tag) {
                $tags[$tag] = true;
            }

            foreach ($this->httpCacheTags->forEvent($event) as $tag) {
                $httpTags[$tag] = true;
            }
        }

        if ([] !== $tags) {
            $this->cache->invalidateTags(array_keys($tags));
        }

        if ([] !== $httpTags) {
            $this->httpCachePurger->purge(array_keys($httpTags));
        }
    }
}
