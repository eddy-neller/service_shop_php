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
 * `hasProcessed()` sert de garde d'entree. Le marquage, lui, a **deux chemins d'ecriture, et le
 * choix depend de qui detient la transaction de l'effet** :
 *
 * | Chemin | Quand | Garantie |
 * |---|---|---|
 * | `markProcessed()` | le handler ouvre lui-meme la transaction de l'effet | exactement-une-fois sur le chemin nominal |
 * | `markProcessedAndFlush()` | la transaction appartient a quelqu'un d'autre, ou l'effet est externe | au-moins-une-fois dedoublonne |
 *
 * Les deux noms disent ce qu'ils font : un suffixe `AndFlush` signale « hors transaction metier »,
 * comme l'`enqueueAndFlush()` de l'outbox. **Ne jamais ajouter de troisieme variante qui aurait la
 * signature de `markProcessed()` pour la durabilite de l'autre** : elle serait choisie par defaut
 * par qui lit vite, et une ligne qui survit au rollback de son effet fait passer pour traite un
 * message qui n'a rien produit.
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
     * donc une deduplication qui ne deduplique pas, sans erreur ni log. Hors transaction metier,
     * c'est {@see self::markProcessedAndFlush()} qu'il faut.
     */
    public function markProcessed(string $eventId, string $handler, ProcessedEventSource $source): void;

    /**
     * Enregistre le traitement par une ecriture durable immediate, hors transaction metier.
     *
     * Pour les handlers qui ne detiennent pas la transaction de leur effet :
     * - **l'effet est externe** — e-mail, paiement, appel HTTP : aucune transaction ne peut le
     *   couvrir ;
     * - **l'effet est commite par quelqu'un d'autre** — le handler du bus inter-services dispatche
     *   une commande, et c'est le cas d'usage qui ouvre et valide la transaction. Le handler ne
     *   doit surtout pas en ouvrir une autour du dispatch : le flush du cas d'usage commiterait
     *   d'abord, et l'atomicite serait perdue sans qu'aucune erreur n'apparaisse.
     *
     * **A appeler apres l'effet, jamais avant.** Les deux ordres n'echangent pas le meme risque :
     *
     * | Ordre | Panne entre les deux |
     * |---|---|
     * | Effet, puis marquage | l'effet est **rejoue** — inoffensif si le handler converge |
     * | Marquage, puis effet | l'effet est **perdu** definitivement, et rien ne le signale |
     *
     * La garantie tombe donc a l'au-moins-une-fois dedoublonne sur le chemin nominal, et c'est la
     * convergence du handler qui ferme le reste. C'est le prix d'un effet dont on ne tient pas la
     * transaction, pas un relachement gratuit.
     *
     * Un marquage concurrent se heurte a l'index unique et fait echouer cet appel : le message est
     * alors rejoue, et la garde `hasProcessed()` le voit traite.
     */
    public function markProcessedAndFlush(string $eventId, string $handler, ProcessedEventSource $source): void;
}
