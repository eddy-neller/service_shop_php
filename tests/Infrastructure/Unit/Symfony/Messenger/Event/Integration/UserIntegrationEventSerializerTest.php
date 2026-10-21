<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Event\Integration;

use App\Infrastructure\Symfony\Messenger\Event\Integration\UnsupportedIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserCreatedByAdminIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserDeletedIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserIntegrationEventSerializer;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserRegisteredIntegrationEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;

/**
 * Fixtures du contrat publie par `service_identity`, cote consommateur.
 *
 * Ce test est le seul endroit du depot ou le contrat est ecrit en JSON. C'est donc ici que se
 * constate une divergence avec l'emetteur — et non dans un handler ou, pire, nulle part.
 *
 * > **Ce qu'il ne fait pas, malgre les apparences.** Il ne verifie rien de l'emetteur : les deux
 * > depots sont separes, et `service_identity` decrit le meme contrat de son cote dans
 * > `UserIntegrationEventTest`. Changer sa forme en mettant a jour son propre test laisse les deux
 * > suites vertes ; seule la verification de bout en bout attrape la rupture. Un vrai test
 * > *consumer-driven* — ces fixtures publiees, verifiees par la CI du producteur — reste a ecrire.
 *
 * Ce que ce test tient, en revanche, c'est la **tolerance** du lecteur : ce qu'il accepte sans
 * broncher borne ce que l'emetteur peut changer sans coordonner un deploiement.
 */
final class UserIntegrationEventSerializerTest extends TestCase
{
    private const string EVENT_ID = '018f3a40-0000-7000-8000-000000000001';

    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    private const string OCCURRED_AT = '2026-09-15T12:00:00+00:00';

    private UserIntegrationEventSerializer $serializer;

    protected function setUp(): void
    {
        // Le serialiseur standard de Messenger, celui-la meme que le conteneur injecte : c'est lui
        // qui porte l'aller-retour des renvois de retry, stamps compris.
        $this->serializer = new UserIntegrationEventSerializer(Serializer::create());
    }

    /**
     * @param class-string<UserIntegrationEvent> $expected
     */
    #[DataProvider('contractTypes')]
    public function testItDecodesEachTypeOfTheContract(string $type, string $expected): void
    {
        $envelope = $this->serializer->decode(['body' => $this->contract(['type' => $type])]);

        $message = $envelope->getMessage();
        self::assertInstanceOf($expected, $message);
        self::assertSame(self::EVENT_ID, $message->eventId);
        self::assertSame(self::ACCOUNT_ID, $message->userAccountId);
        self::assertSame(self::OCCURRED_AT, $message->occurredAt->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string, class-string<UserIntegrationEvent>}>
     */
    public static function contractTypes(): iterable
    {
        yield 'inscription' => ['identity.user.registered', UserRegisteredIntegrationEvent::class];
        yield 'creation admin' => ['identity.user.created_by_admin', UserCreatedByAdminIntegrationEvent::class];
        yield 'suppression' => ['identity.user.deleted', UserDeletedIntegrationEvent::class];
    }

    /**
     * Le worker consomme par `RoutableMessageBus` : sans ce stamp, le message partirait sur
     * `command.bus`, ou aucun handler ne repond.
     */
    public function testItRoutesTheMessageToTheEventBus(): void
    {
        $envelope = $this->serializer->decode(['body' => $this->contract()]);

        self::assertSame('event.bus', $envelope->last(BusNameStamp::class)?->getBusName());
    }

    /**
     * L'emetteur doit pouvoir publier un type de plus sans attendre le deploiement de ses abonnes :
     * ce qu'on ne sait pas lire est journalise puis acquitte, jamais rejoue.
     */
    public function testItTurnsAnUnknownTypeIntoAnAcknowledgedMessage(): void
    {
        $envelope = $this->serializer->decode(['body' => $this->contract(['type' => 'identity.user.renamed'])]);

        $message = $envelope->getMessage();
        self::assertInstanceOf(UnsupportedIntegrationEvent::class, $message);
        self::assertSame('identity.user.renamed', $message->type);
        self::assertSame(self::EVENT_ID, $message->eventId);
    }

    /** Meme traitement pour une version posterieure du contrat, type connu ou non. */
    public function testItTurnsALaterContractVersionIntoAnAcknowledgedMessage(): void
    {
        $envelope = $this->serializer->decode(['body' => $this->contract(['version' => 2])]);

        $message = $envelope->getMessage();
        self::assertInstanceOf(UnsupportedIntegrationEvent::class, $message);
        self::assertSame(2, $message->version);
    }

    /**
     * **La retrocompatibilite ascendante, celle qui permet a l'emetteur d'avancer seul.** Un champ
     * ajoute au contrat ne doit rien casser ici : le serialiseur ne lit que les cles qu'il connait.
     *
     * C'est ce qui rend l'ajout d'un champ **non versionnant** cote emetteur. Durcir ce lecteur —
     * valider le schema, refuser une cle inconnue — retirerait cette propriete sans qu'aucun autre
     * test ne rougisse, et imposerait desormais de deployer les deux services ensemble.
     */
    public function testItIgnoresFieldsItDoesNotKnow(): void
    {
        $envelope = $this->serializer->decode(['body' => self::contract([
            'correlationId' => '018f3a40-0000-7000-8000-00000000beef',
            'data' => [
                'userAccountId' => self::ACCOUNT_ID,
                'locale' => 'fr_FR',
            ],
        ])]);

        $message = $envelope->getMessage();
        self::assertInstanceOf(UserRegisteredIntegrationEvent::class, $message);
        self::assertSame(self::ACCOUNT_ID, $message->userAccountId);
    }

    #[DataProvider('brokenBodies')]
    public function testItRefusesABodyThatViolatesTheContract(string $body): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => $body]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenBodies(): iterable
    {
        yield 'json invalide' => ['{"id": '];
        yield "json qui n'est pas un objet" => ['"identity.user.registered"'];
        yield 'corps vide' => [''];
        yield 'id manquant' => [self::contractWithout('id')];
        yield 'type manquant' => [self::contractWithout('type')];
        yield 'version manquante' => [self::contractWithout('version')];
        yield 'occurredAt manquant' => [self::contractWithout('occurredAt')];
        yield 'data manquant' => [self::contractWithout('data')];
        yield 'version non entiere' => [self::contract(['version' => '1'])];
        yield 'date illisible' => [self::contract(['occurredAt' => 'hier'])];
        yield 'userAccountId manquant' => [self::contract(['data' => ['userId' => self::ACCOUNT_ID]])];
        yield 'userAccountId vide' => [self::contract(['data' => ['userAccountId' => '']])];
    }

    /**
     * Le message fautif est supprime de la file par le receiver, et le worker s'arrete : l'exception
     * est la seule trace qui reste. Elle doit donc porter de quoi reconnaitre le corps.
     */
    public function testTheRefusalCarriesAnExcerptOfTheBody(): void
    {
        try {
            $this->serializer->decode(['body' => '{"id": "' . self::EVENT_ID . '", "type": 42}']);
            self::fail('Le corps fautif aurait du etre refuse.');
        } catch (MessageDecodingFailedException $exception) {
            self::assertStringContainsString('"type" is missing or not a non-empty string', $exception->getMessage());
            self::assertStringContainsString(self::EVENT_ID, $exception->getMessage());
        }
    }

    /**
     * **Le test du retry.** Messenger renvoie le message dans la file pour le reessayer plus tard,
     * et c'est ce serialiseur qui ecrit puis relit ce renvoi. Perdre le `RedeliveryStamp` rendrait
     * chaque redelivrance indistinguable d'une premiere livraison : le retry n'aurait plus de fin.
     */
    public function testItReadsBackItsOwnRedeliveries(): void
    {
        $original = $this->serializer->decode(['body' => $this->contract()]);

        $encoded = $this->serializer->encode($original->with(new RedeliveryStamp(2)));
        $redelivered = $this->serializer->decode($encoded);

        self::assertEquals($original->getMessage(), $redelivered->getMessage());
        self::assertSame(2, $redelivered->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertSame('event.bus', $redelivered->last(BusNameStamp::class)?->getBusName());
    }

    /**
     * L'attribut de message `type` publie par SNS — celui qui permet le filtrage d'abonnement —
     * arrive dans les **memes** en-tetes que le `type` du serialiseur standard. Confondre les deux
     * ferait lire un corps du contrat comme un renvoi de retry.
     */
    public function testItDoesNotMistakeTheSnsTypeAttributeForARedelivery(): void
    {
        $envelope = $this->serializer->decode([
            'body' => $this->contract(),
            'headers' => ['type' => 'identity.user.registered'],
        ]);

        self::assertInstanceOf(UserRegisteredIntegrationEvent::class, $envelope->getMessage());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function contract(array $overrides = []): string
    {
        return json_encode([
            'id' => self::EVENT_ID,
            'type' => 'identity.user.registered',
            'version' => 1,
            'occurredAt' => self::OCCURRED_AT,
            'data' => ['userAccountId' => self::ACCOUNT_ID],
            ...$overrides,
        ], JSON_THROW_ON_ERROR);
    }

    private static function contractWithout(string $key): string
    {
        $payload = json_decode(self::contract(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        unset($payload[$key]);

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
