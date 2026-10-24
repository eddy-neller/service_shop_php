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
 * ## Trois issues, et aucune ne leve
 *
 * | Entree | Issue |
 * |---|---|
 * | Type connu, version 1 | message d'integration, handler dedie |
 * | Type inconnu, ou version > 1 | `UnsupportedIntegrationEvent` : journalise puis **acquitte** |
 * | JSON invalide, champ manquant ou mal type | `UndecodableIntegrationEvent` : **conserve** dans `failed_user_events` |
 *
 * Le second cas est ce qui permet a l'emetteur de publier un type de plus sans attendre le
 * deploiement de ses abonnes. Le troisieme est une violation du contrat, donc un bug : il doit
 * etre bruyant, **et ne rien perdre**.
 *
 * > **Pourquoi ne pas lever.** Une exception levee pendant le decodage est attrapee par
 * > `AmazonSqsReceiver`, qui **supprime le message de la file** puis la relance — et le worker
 * > meurt, `Worker::run()` ne protegeant pas `$receiver->get()`. Le message ne partait ni en file
 * > d'echec (elle ne recoit que les echecs de handler) ni en DLQ (il etait deja supprime) : la
 * > seule trace etait l'exception, avec un extrait du corps. Rendu comme un message ordinaire, le
 * > corps fautif suit au contraire le chemin des echecs de handler : son handler le rejette sans
 * > retry, et Messenger le range dans la file d'echec, corps complet compris.
 *
 * Seul le **contrat** est traite ainsi. Un renvoi de retry illisible — ecrit par ce service lui-meme
 * — leve toujours : ce n'est pas l'emetteur qui a viole le contrat, c'est un bug d'ici.
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

        try {
            return $this->envelope($this->messageOf($body));
        } catch (MessageDecodingFailedException $exception) {
            return $this->envelope(new UndecodableIntegrationEvent($body, $exception->getMessage()));
        }
    }

    public function encode(Envelope $envelope): array
    {
        return $this->messengerSerializer->encode($envelope);
    }

    /**
     * Le contrat, lu strictement. Leve `MessageDecodingFailedException` des qu'il est viole : c'est
     * `decode()` qui en fait un message conserve, et non une perte.
     */
    private function messageOf(string $body): object
    {
        $payload = $this->payloadOf($body);

        $eventId = $this->stringOf($payload['id'] ?? null, 'id');
        $type = $this->stringOf($payload['type'] ?? null, 'type');
        $version = $this->intOf($payload['version'] ?? null, 'version');

        return match (true) {
            /*
             * L'ordre porte une decision : le controle de version passe avant le type. Un
             * `identity.user.registered` en version 2 part donc en `UnsupportedIntegrationEvent` —
             * on ne tente pas de lire son `data`, dont la structure a pu changer. Intervertir les
             * deux premieres lignes ferait decoder un format qu'on ne connait pas, en silence.
             */
            self::VERSION !== $version => new UnsupportedIntegrationEvent($eventId, $type, $version),
            'identity.user.registered' === $type => new UserRegisteredIntegrationEvent(
                $eventId,
                $this->userAccountIdOf($payload),
                $this->occurredAtOf($payload),
            ),
            'identity.user.created_by_admin' === $type => new UserCreatedByAdminIntegrationEvent(
                $eventId,
                $this->userAccountIdOf($payload),
                $this->occurredAtOf($payload),
            ),
            'identity.user.deleted' === $type => new UserDeletedIntegrationEvent(
                $eventId,
                $this->userAccountIdOf($payload),
                $this->occurredAtOf($payload),
            ),
            default => new UnsupportedIntegrationEvent($eventId, $type, $version),
        };
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
            throw $this->decodingFailure('body is not valid JSON', $exception);
        }

        if (!is_array($payload)) {
            throw $this->decodingFailure('body is not a JSON object');
        }

        return $payload;
    }

    private function stringOf(mixed $value, string $key): string
    {
        if (!is_string($value) || '' === $value) {
            throw $this->decodingFailure(sprintf('"%s" is missing or not a non-empty string', $key));
        }

        return $value;
    }

    private function intOf(mixed $value, string $key): int
    {
        if (!is_int($value)) {
            throw $this->decodingFailure(sprintf('"%s" is missing or not an integer', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function userAccountIdOf(array $payload): string
    {
        $data = $payload['data'] ?? null;

        if (!is_array($data)) {
            throw $this->decodingFailure('"data" is missing or not an object');
        }

        return $this->stringOf($data['userAccountId'] ?? null, 'userAccountId');
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function occurredAtOf(array $payload): DateTimeImmutable
    {
        $value = $this->stringOf($payload['occurredAt'] ?? null, 'occurredAt');

        try {
            // Lecture permissive d'une date par ailleurs contrainte par le contrat : refuser un
            // `Z` la ou l'emetteur ecrit `+00:00` serait une panne pour rien.
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw $this->decodingFailure('"occurredAt" is not a readable date', $exception);
        }
    }

    /**
     * La raison seule : le corps n'est plus une trace a sauver, `UndecodableIntegrationEvent` le
     * garde en entier.
     */
    private function decodingFailure(string $reason, ?Exception $previous = null): MessageDecodingFailedException
    {
        return new MessageDecodingFailedException(sprintf('Invalid user integration event: %s', $reason), previous: $previous);
    }
}
