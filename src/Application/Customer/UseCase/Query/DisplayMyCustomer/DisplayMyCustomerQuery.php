<?php

declare(strict_types=1);

namespace App\Application\Customer\UseCase\Query\DisplayMyCustomer;

use App\Application\Shared\CQRS\Query\CacheableQueryInterface;

/**
 * Resout le client courant d'une operation `/api/shop/me/*` : association `userAccountId` ->
 * `customerId`, **reservee a un client actif**. Un client desactive leve
 * `CustomerDisabledException` (403).
 *
 * Elle est jouee a **chaque** requete `/me` avant la commande ou la query utile, d'ou le cache :
 * c'est la seule lecture du contexte qui merite d'etre servie sans aller en base. Le TTL long
 * tient a deux choses : l'association est immuable, et seul un client actif est stocke. Sa
 * desactivation purge l'entree (voir plus bas) : la requete suivante retourne en base, et le
 * handler refuse.
 *
 * Ne pas la reutiliser pour une route qui doit rester ouverte a un client desactive (consulter
 * ses donnees avant suppression, par exemple) : elle le refuserait. Ecrire une autre query.
 *
 * Le cas « client pas encore provisionne » reste correct : `CustomerNotFoundException`
 * traverse le callback, et rien n'est stocke quand celui-ci leve — la requete suivante
 * retentera en base. C'est ce qui rend supportable la fenetre pendant laquelle le relais
 * n'a pas encore depose le client.
 *
 * Un tag non invalide ne permettrait pas de garantir la fraicheur de la lecture, parce que
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
