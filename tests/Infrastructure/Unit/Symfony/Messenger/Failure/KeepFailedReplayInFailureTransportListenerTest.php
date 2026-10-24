<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Messenger\Failure;

use App\Infrastructure\Symfony\Messenger\Failure\KeepFailedReplayInFailureTransportListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * L'ecouteur est eprouve avec le **vrai** `SendFailedMessageToFailureTransportListener` de Messenger,
 * aux memes priorites qu'en production : c'est leur interaction qui compte — le renvoi dans la bonne
 * file, et la neutralisation de l'envoi vers la file globale.
 *
 * Les transports reproduisent la configuration du service : `user_events` echoue vers
 * `failed_user_events`, et toute file d'echec, faute de reglage propre, vers la globale
 * `failed_domain_events` — qui est donc sa propre file d'echec.
 */
final class KeepFailedReplayInFailureTransportListenerTest extends TestCase
{
    private InMemoryTransport $failedUserEvents;

    private InMemoryTransport $failedDomainEvents;

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->failedUserEvents = new InMemoryTransport();
        $this->failedDomainEvents = new InMemoryTransport();

        $transports = new ServiceLocator([
            'failed_user_events' => fn (): InMemoryTransport => $this->failedUserEvents,
            'failed_domain_events' => fn (): InMemoryTransport => $this->failedDomainEvents,
        ]);

        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(
            WorkerMessageFailedEvent::class,
            new KeepFailedReplayInFailureTransportListener($transports, new NullLogger()),
            -50,
        );
        $this->dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            new ServiceLocator([
                'user_events' => fn (): InMemoryTransport => $this->failedUserEvents,
                'failed_user_events' => fn (): InMemoryTransport => $this->failedDomainEvents,
                'failed_domain_events' => fn (): InMemoryTransport => $this->failedDomainEvents,
            ]),
            null,
            [
                'user_events' => 'failed_user_events',
                'failed_user_events' => 'failed_domain_events',
                'failed_domain_events' => 'failed_domain_events',
            ],
        ));
    }

    /**
     * Rejoue depuis `failed_user_events` et toujours en echec : il y retourne, au lieu de finir dans la
     * file des Domain Events, ou un second rejeu rate l'aurait efface.
     */
    public function testAReplayThatFailsAgainGoesBackToItsFailureTransport(): void
    {
        $this->failOn('failed_user_events', $this->replayedFrom('user_events'));

        self::assertCount(1, $this->failedUserEvents->getSent());
        self::assertCount(0, $this->failedDomainEvents->getSent(), 'Aucun doublon dans la file globale.');
        self::assertSame(
            'user_events',
            $this->failedUserEvents->getSent()[0]->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            'Le transport d origine reste celui qu affiche messenger:failed:show.',
        );
    }

    /**
     * **La perte d'origine.** La file globale est sa propre file d'echec : Messenger renonce a l'y
     * renvoyer, et le worker retire le message. Ici, il y retourne.
     */
    public function testAReplayFromTheGlobalFailureTransportIsNotDropped(): void
    {
        $this->failOn('failed_domain_events', $this->replayedFrom('domain_events'));

        self::assertCount(1, $this->failedDomainEvents->getSent());
    }

    /**
     * Le temoin : un echec ordinaire, hors rejeu, garde le chemin de Messenger.
     */
    public function testAFirstFailureKeepsTheOrdinaryPath(): void
    {
        $this->failOn('user_events', new Envelope(new stdClass()));

        self::assertCount(1, $this->failedUserEvents->getSent());
        self::assertCount(0, $this->failedDomainEvents->getSent());
    }

    /**
     * Un rejeu que Messenger va reessayer est deja renvoye dans sa file par le retry : l'ecouteur ne
     * fait rien, sans quoi il le doublerait.
     */
    public function testAReplayThatWillBeRetriedIsLeftToTheRetry(): void
    {
        $event = new WorkerMessageFailedEvent($this->replayedFrom('user_events'), 'failed_user_events', new RuntimeException('again'));
        $event->setForRetry();

        $this->dispatcher->dispatch($event);

        self::assertCount(0, $this->failedUserEvents->getSent());
        self::assertCount(0, $this->failedDomainEvents->getSent());
    }

    private function replayedFrom(string $originalTransport): Envelope
    {
        return new Envelope(new stdClass(), [new SentToFailureTransportStamp($originalTransport)]);
    }

    private function failOn(string $receiverName, Envelope $envelope): void
    {
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, $receiverName, new RuntimeException('again')));
    }
}
