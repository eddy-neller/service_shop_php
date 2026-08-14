<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayMyCustomer;

use App\Application\Shared\CQRS\Query\CacheableQueryInterface;

/**
 * Resout l'association `userAccountId` -> `customerId`, immuable une fois le client cree.
 *
 * Elle est jouee a **chaque** requete `/api/shop/me/*` avant la commande ou la query utile,
 * d'ou le cache : c'est la seule lecture du contexte qui merite d'etre servie sans aller en
 * base, et son TTL long se justifie par l'immutabilite de l'association.
 *
 * Le cas « client pas encore provisionne » reste correct : `CustomerNotFoundException`
 * traverse le callback, et rien n'est stocke quand celui-ci leve — la requete suivante
 * retentera en base. C'est ce qui rend supportable la fenetre pendant laquelle le relais
 * n'a pas encore depose le client.
 *
 * Le monolithe portait ici un avertissement : son tag n'etait invalide par rien, parce que
 * `DomainEventCacheTags` n'y connaissait que les evenements du contexte User. **Cet
 * avertissement est leve** : les evenements de `Customer` portent leur `userAccountId`, et
 * `DomainEventCacheTags` purge `customer-of-user-{id}` a chaque fait du contexte.
 */
final readonly class DisplayMyCustomerQuery implements CacheableQueryInterface
{
    private const int CACHE_TTL_SECONDS = 86400;

    public function __construct(
        public string $userAccountId,
    ) {
    }

    public function cacheKey(): string
    {
        return 'customer-of-user-' . $this->userAccountId;
    }

    public function cacheTtl(): int
    {
        return self::CACHE_TTL_SECONDS;
    }

    public function cacheTags(): array
    {
        return ['customer-of-user-' . $this->userAccountId];
    }
}
