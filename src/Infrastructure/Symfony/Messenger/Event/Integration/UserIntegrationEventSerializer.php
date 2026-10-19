<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event\Integration;

use DateTimeImmutable;
use Exception;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Traduit le contrat JSON publie par `service_identity` en messages Messenger.
 *
 * C'est la **frontiere du service** : au-dela, plus personne ne sait que les evenements arrivent
 * en JSON par une file SQS. Le contrat, en version 1 :
 *
 * ```json
 * { "id": "<uuid>", "type": "identity.user.registered", "version": 1,
 *   "occurredAt": "2026-09-15T12:00:00+00:00", "data": { "userAccountId": "<uuid>" } }
 * ```
 *
 * Aucune donnee personnelle n'y circule : seul l'identifiant du compte, que ce service traduit
 * ensuite en client. L'abonnement SQS est en `RawMessageDelivery=true`, donc le corps recu est
 * exactement celui publie — pas l'enveloppe de notification de SNS.
 *
 * ## Trois issues, et une seule leve
 *
 * | Entree | Issue |
 * |---|---|
 * | Type connu, version 1 | message d'integration, handler dedie |
 * | Type inconnu, ou version > 1 | `UnsupportedIntegrationEvent` : journalise puis **acquitte** |
 * | JSON invalide, champ manquant ou mal type | `MessageDecodingFailedException` |
 *
 * Le second cas est ce qui permet a l'emetteur de publier un type de plus sans attendre le
 * deploiement de ses abonnes. Le troisieme est une violation du contrat, donc un bug : il doit
 * etre bruyant.
 *
 * > **Ce que fait reellement `MessageDecodingFailedException` :** `AmazonSqsReceiver` attrape
 * > cette exception, **supprime le message de la file**, puis la relance — le worker s'arrete donc
 * > aussi, car `Worker::run()` ne protege pas `$receiver->get()`. Le message ne part ni en file
 * > d'echec (elle ne recoit que les echecs de handler) ni en DLQ (la redelivrance n'a pas lieu,
 * > il est deja supprime). **La seule trace est l'exception**, d'ou le corps tronque qu'elle
 * > transporte.
 *
 * ## Relire ses propres renvois
 *
 * Messenger renvoie un message dans la file pour le retry differe, et c'est `encode()` qui ecrit
 * ce renvoi. Il delegue donc au serialiseur standard, qui conserve les **stamps** dans les
 * en-tetes — dont le `RedeliveryStamp` qui porte le compte d'essais. Les perdre rendrait chaque
 * redelivrance indistinguable d'une premiere livraison, donc le retry infini.
 *
 * `decode()` doit par consequent reconnaitre ses propres renvois. Le marqueur est l'en-tete `type`
 * du serialiseur standard, qui porte un **nom de classe** — alors qu'un message venu de SNS porte
 * au mieux l'attribut `type` du contrat (`identity.user.registered`), publie pour permettre le
 * filtrage d'abonnement. D'ou la discrimination par `class_exists()`, et non par presence.
 *
 * > **Ne pas implementer `MessageTypeAwareSerializerInterface`** sans rendre la classe reelle pour
 * > un corps du contrat. `SigningSerializer` decore tous les serialiseurs de transport des qu'un
 * > handler declare `sign: true` — c'est le cas de `LogDomainEventHandler` — et il refuse tout
 * > message non signe dont ce `getMessageType()` rendrait `null`.
 */
final readonly class UserIntegrationEventSerializer implements SerializerInterface
{
    /** Version du contrat que ce service sait lire. Au-dela, le message est acquitte sans effet. */
    public const int VERSION = 1;

    /** Longueur du corps conserve dans l'exception de decodage : seule trace d'un message perdu. */
    private const int EXCERPT_LENGTH = 500;

    public function __construct(
        #[Autowire(service: 'messenger.transport.symfony_serializer')]
        private SerializerInterface $messengerSerializer,
    ) {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        if ($this->isOwnRedelivery($encodedEnvelope)) {
            return $this->messengerSerializer->decode($encodedEnvelope);
        }

        $body = is_string($encodedEnvelope['body'] ?? null) ? $encodedEnvelope['body'] : '';
        $payload = $this->payloadOf($body);

        $eventId = $this->stringOf($payload['id'] ?? null, 'id', $body);
        $type = $this->stringOf($payload['type'] ?? null, 'type', $body);
        $version = $this->intOf($payload['version'] ?? null, 'version', $body);

        return $this->envelope(match (true) {
            /*
             * L'ordre porte une decision : le controle de version passe avant le type. Un
             * `identity.user.registered` en version 2 part donc en `UnsupportedIntegrationEvent` —
             * on ne tente pas de lire son `data`, dont la structure a pu changer. Intervertir les
             * deux premieres lignes ferait decoder un format qu'on ne connait pas, en silence.
             */
            self::VERSION !== $version => new UnsupportedIntegrationEvent($eventId, $type, $version),
            'identity.user.registered' === $type => new UserRegisteredIntegrationEvent(
                $eventId,
                $this->userAccountIdOf($payload, $body),
                $this->occurredAtOf($payload, $body),
            ),
            'identity.user.created_by_admin' === $type => new UserCreatedByAdminIntegrationEvent(
                $eventId,
                $this->userAccountIdOf($payload, $body),
                $this->occurredAtOf($payload, $body),
            ),
            'identity.user.deleted' === $type => new UserDeletedIntegrationEvent(
                $eventId,
                $this->userAccountIdOf($payload, $body),
                $this->occurredAtOf($payload, $body),
            ),
            default => new UnsupportedIntegrationEvent($eventId, $type, $version),
        });
    }

    public function encode(Envelope $envelope): array
    {
        return $this->messengerSerializer->encode($envelope);
    }

    /**
     * @param array<string, mixed> $encodedEnvelope
     */
    private function isOwnRedelivery(array $encodedEnvelope): bool
    {
        $headers = $encodedEnvelope['headers'] ?? [];
        $type = is_array($headers) ? $headers['type'] ?? null : null;

        return is_string($type) && class_exists($type);
    }

    private function envelope(object $message): Envelope
    {
        // `event.bus` explicitement : le worker consomme par `RoutableMessageBus`, qui sans ce
        // stamp retombe sur le bus par defaut — `command.bus`, ou aucun handler ne repond.
        return new Envelope($message, [new BusNameStamp('event.bus')]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payloadOf(string $body): array
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->decodingFailure('body is not valid JSON', $body, $exception);
        }

        if (!is_array($payload)) {
            throw $this->decodingFailure('body is not a JSON object', $body);
        }

        return $payload;
    }

    private function stringOf(mixed $value, string $key, string $body): string
    {
        if (!is_string($value) || '' === $value) {
            throw $this->decodingFailure(sprintf('"%s" is missing or not a non-empty string', $key), $body);
        }

        return $value;
    }

    private function intOf(mixed $value, string $key, string $body): int
    {
        if (!is_int($value)) {
            throw $this->decodingFailure(sprintf('"%s" is missing or not an integer', $key), $body);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function userAccountIdOf(array $payload, string $body): string
    {
        $data = $payload['data'] ?? null;

        if (!is_array($data)) {
            throw $this->decodingFailure('"data" is missing or not an object', $body);
        }

        return $this->stringOf($data['userAccountId'] ?? null, 'userAccountId', $body);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function occurredAtOf(array $payload, string $body): DateTimeImmutable
    {
        $value = $this->stringOf($payload['occurredAt'] ?? null, 'occurredAt', $body);

        try {
            // Lecture permissive d'une date par ailleurs contrainte par le contrat : refuser un
            // `Z` la ou l'emetteur ecrit `+00:00` serait une panne pour rien.
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw $this->decodingFailure('"occurredAt" is not a readable date', $body, $exception);
        }
    }

    private function decodingFailure(string $reason, string $body, ?Exception $previous = null): MessageDecodingFailedException
    {
        return new MessageDecodingFailedException(
            sprintf(
                'Invalid user integration event: %s. Body (truncated): %s',
                $reason,
                mb_substr($body, 0, self::EXCERPT_LENGTH),
            ),
            previous: $previous,
        );
    }
}
