<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Transport;

use App\Application\Shared\Port\ClockInterface;
use App\Infrastructure\Persistence\Mongo\Outbox\DomainEventOutbox;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Fabrique les transports `mongodb-outbox://<file>`.
 *
 * Le nom de file est la seule chose que porte la DSN : l'outbox et sa file d'echec partagent
 * la collection `domain_event_outbox` et ne different que par ce champ, comme le faisaient
 * `queue_name=domain_events` et `queue_name=failed_domain_events` cote monolithe. Aucune
 * coordonnee de serveur n'y figure — la connexion est celle de l'ODM.
 *
 * Enregistree par autoconfiguration (`messenger.transport_factory`) : aucun tag a ecrire.
 */
final readonly class MongoOutboxTransportFactory implements TransportFactoryInterface
{
    private const string SCHEME = 'mongodb-outbox://';

    private const int DEFAULT_REDELIVER_TIMEOUT = 3600;

    public function __construct(
        private DomainEventOutbox $outbox,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $queueName = substr($dsn, strlen(self::SCHEME));

        if ('' === $queueName) {
            throw new InvalidArgumentException(sprintf('The "%s" transport DSN must name a queue, e.g. "%sdomain_events".', $dsn, self::SCHEME));
        }

        $redeliverTimeout = $options['redeliver_timeout'] ?? self::DEFAULT_REDELIVER_TIMEOUT;

        return new MongoOutboxTransport(
            outbox: $this->outbox,
            serializer: $serializer,
            clock: $this->clock,
            queueName: $queueName,
            redeliverTimeout: is_numeric($redeliverTimeout)
                ? (int) $redeliverTimeout
                : self::DEFAULT_REDELIVER_TIMEOUT,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::SCHEME);
    }
}
