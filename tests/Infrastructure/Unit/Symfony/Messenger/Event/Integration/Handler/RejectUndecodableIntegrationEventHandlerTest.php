<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Event\Integration\Handler;

use App\Infrastructure\Symfony\Messenger\Event\Integration\Handler\RejectUndecodableIntegrationEventHandler;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UndecodableIntegrationEvent;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserIntegrationEventSerializer;
use App\Infrastructure\Symfony\Messenger\Event\Integration\UserRegisteredIntegrationEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;

final class RejectUndecodableIntegrationEventHandlerTest extends TestCase
{
    private const string ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440011';

    /**
     * Un corps toujours illisible est **rejete sans retry** : c'est `UnrecoverableMessageHandlingException`
     * qui fait ranger le message dans `failed_user_events`, corps compris, au lieu de le rejouer
     * six fois pour rien. La raison voyage dans l'exception, donc dans le journal du worker.
     */
    public function testItRejectsABodyThatStillViolatesTheContract(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        try {
            $this->handler($bus)(new UndecodableIntegrationEvent('{"id": "casse', 'Invalid user integration event: body is not valid JSON'));
            self::fail('Un corps illisible doit etre rejete.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('body is not valid JSON', $e->getMessage());
        }
    }

    /**
     * **La reprise.** Le message conserve porte une raison d'hier, mais il est relu avec le code
     * d'aujourd'hui : apres une correction du serialiseur, `messenger:failed:retry` le fait passer, et
     * le fait est dispatche comme s'il venait d'arriver. Ici, un corps valide joue ce code corrige.
     */
    public function testItDispatchesTheFactOnceTheBodyReadsAgain(): void
    {
        $body = json_encode([
            'id' => '018f3a40-0000-7000-8000-000000000001',
            'type' => 'identity.user.registered',
            'version' => 1,
            'occurredAt' => '2026-09-15T12:00:00+00:00',
            'data' => ['userAccountId' => self::ACCOUNT_ID],
        ], JSON_THROW_ON_ERROR);

        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $envelope) use (&$dispatched): Envelope {
                $dispatched = $envelope;

                return $envelope instanceof Envelope ? $envelope : new Envelope($envelope);
            });

        $this->handler($bus)(new UndecodableIntegrationEvent($body, 'Invalid user integration event: une raison perimee'));

        self::assertInstanceOf(Envelope::class, $dispatched);
        $message = $dispatched->getMessage();
        self::assertInstanceOf(UserRegisteredIntegrationEvent::class, $message);
        self::assertSame(self::ACCOUNT_ID, $message->userAccountId);
    }

    private function handler(MessageBusInterface $bus): RejectUndecodableIntegrationEventHandler
    {
        // Le vrai serialiseur : c'est sa lecture du contrat que le handler rejoue.
        return new RejectUndecodableIntegrationEventHandler(new UserIntegrationEventSerializer(Serializer::create()), $bus);
    }
}
