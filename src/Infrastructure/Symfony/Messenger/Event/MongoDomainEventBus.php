<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event;

use App\Application\Shared\Port\DomainEventBusInterface;
use App\Domain\SharedKernel\Event\DomainEventInterface;
use App\Infrastructure\Persistence\Mongo\Outbox\DomainEventOutbox;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Ecrit les Domain Events dans l'outbox MongoDB.
 *
 * Contrairement au monolithe, la publication ne passe **pas** par un dispatch Messenger.
 * Sur Doctrine ORM, le `SendMessageMiddleware` finissait par un INSERT emis sur la connexion
 * courante, donc dans la transaction ouverte : l'atomicite venait gratuitement.
 *
 * Rien de tel ici. Une transaction MongoDB appartient a la session portee par le flush de
 * l'ODM ; une ecriture emise par le pilote a cote de ce flush s'engage immediatement et
 * survivrait au rollback de l'agregat, sans qu'aucun test ne s'en apercoive. L'evenement est
 * donc confie a `DomainEventOutbox::enqueue()`, qui `persist()` sans flusher : c'est le flush
 * unique de `MongoTransactional` qui le commite — avec l'agregat, ou pas du tout.
 *
 * Chaque evenement est aussi remis au `PublishedDomainEventCollector`, qui permet au
 * `CacheInvalidationMiddleware` de purger les tags de cache sans attendre le worker.
 */
final readonly class MongoDomainEventBus implements DomainEventBusInterface
{
    public function __construct(
        private DomainEventOutbox $outbox,
        private PublishedDomainEventCollector $collector,
        private SerializerInterface $serializer,
    ) {
    }

    public function publishAll(array $events): void
    {
        foreach ($events as $event) {
            $this->publish($event);
        }
    }

    private function publish(DomainEventInterface $event): void
    {
        // Le `BusNameStamp` est ce qu'un `dispatch()` aurait pose. Sans lui, le
        // `RoutableMessageBus` du worker retomberait sur le bus par defaut — `command.bus`,
        // qui n'a aucun handler d'evenement.
        $envelope = new Envelope($event, [new BusNameStamp('event.bus')]);

        // Meme codec que celui du transport : `messenger.default_serializer`, faute de
        // serializer declare par transport. En declarer un pour `domain_events` sans le
        // declarer ici ecrirait des messages que le receiver ne saurait pas relire.
        $encoded = $this->serializer->encode($envelope);

        $this->outbox->enqueue(
            queueName: DomainEventOutbox::DEFAULT_QUEUE,
            body: $encoded['body'],
            headers: $encoded['headers'] ?? [],
            event: $event,
        );

        $this->collector->record($event);
    }
}
