<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event;

use App\Domain\SharedKernel\Event\DomainEventInterface;

/**
 * Memorise les Domain Events publies pendant le traitement d'une commande.
 *
 * L'outbox ecrit les evenements dans la meme transaction que l'agregat : leurs reactions
 * ne s'executent donc qu'une fois le worker reveille, quelques dizaines de millisecondes
 * apres la reponse HTTP. C'est le comportement voulu pour les effets externes, mais pas
 * pour l'invalidation du cache de lecture : le client qui relit juste apres son ecriture
 * obtiendrait une reponse perimee.
 *
 * Ce collecteur donne au `CacheInvalidationMiddleware` la liste des faits metier survenus,
 * pour qu'il purge les tags **apres le commit** et **avant la reponse**, sans rien retirer
 * a l'outbox.
 */
final class PublishedDomainEventCollector
{
    /** @var list<DomainEventInterface> */
    private array $events = [];

    public function record(DomainEventInterface $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Vide le collecteur et retourne son contenu.
     *
     * Toujours appele, y compris quand la commande echoue : un worker traite des messages
     * en serie, aucun evenement ne doit fuir d'un message vers le suivant.
     *
     * @return list<DomainEventInterface>
     */
    public function release(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
