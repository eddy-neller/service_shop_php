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
 * `hasProcessed()` sert de garde d'entree, `markProcessed()` enregistre le traitement — et il le
 * fait **dans la transaction de l'effet** : les deux sont commites ensemble, ou aucun des deux.
 * C'est l'exactement-une-fois sur le chemin nominal.
 *
 * Il n'y a volontairement **pas** de variante hors transaction. Elle aurait la meme signature pour
 * une garantie plus faible, donc serait choisie par defaut par qui lit vite — et une ligne qui
 * survit au rollback de son effet fait passer pour traite un message qui n'a rien produit. Le jour
 * ou un effet externe (e-mail, paiement) l'exigera, elle s'appellera `markProcessedAndFlush()`,
 * comme l'`enqueueAndFlush()` de l'outbox dont le suffixe signale deja « hors transaction metier ».
 */
interface ProcessedEventLedgerInterface
{
    public function hasProcessed(string $eventId, string $handler): bool;

    /**
     * Enregistre le traitement en rejoignant le flush en cours, sans le declencher.
     *
     * A appeler **dans** le callback de `TransactionalInterface`, comme l'`enqueue()` de l'outbox :
     * la ligne est alors commitee avec l'ecriture metier, sous la meme transaction.
     *
     * Si le couple existe deja, c'est le flush qui echoue, donc toute la transaction : l'effet
     * n'est pas applique deux fois. D'ou la garde `hasProcessed()` en amont, qui evite d'en arriver
     * la sur le chemin nominal.
     *
     * **Appele hors de tout flush, cet appel n'ecrit rien et ne previent pas** : sans `flush()`,
     * le document persiste est oublie. Le symptome serait un `hasProcessed()` eternellement faux,
     * donc une deduplication qui ne deduplique pas, sans erreur ni log.
     */
    public function markProcessed(string $eventId, string $handler, ProcessedEventSource $source): void;
}
