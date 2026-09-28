<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Event;

/**
 * Inbox : memorise les couples (evenement, handler) deja traites.
 *
 * Elle repond a ce que la convergence d'etat ne couvre pas. Les cas d'usage declenches par le bus
 * sont ecrits pour etre rejouables — provisionner un client deja provisionne ne fait rien — mais
 * cette propriete appartient a chaque handler, et le prochain peut ne pas l'avoir : envoyer un
 * e-mail, appeler un paiement, incrementer un compteur ne convergent pas.
 *
 * Les deux mecanismes se completent, ils ne se remplacent pas :
 * - l'inbox evite le doublon sur le chemin nominal, y compris pour un effet non convergent ;
 * - la convergence rattrape ce que l'inbox ne peut pas fermer — la panne survenue entre l'effet et
 *   son marquage, et la course entre deux workers.
 *
 * Usage actuel : `hasProcessed()` en garde d'entree, `markProcessed()` apres succes, hors
 * transaction. C'est de l'au-moins-une-fois dedoublonne. Le jour ou un handler exigera
 * l'exactement-une-fois reel, il faudra marquer **dans** la transaction de l'effet, donc faire
 * descendre l'identifiant du message jusqu'au cas d'usage — a decider a ce moment-la.
 */
interface ProcessedEventLedgerInterface
{
    public function hasProcessed(string $eventId, string $handler): bool;

    public function markProcessed(string $eventId, string $handler, ProcessedEventSource $source): void;
}
