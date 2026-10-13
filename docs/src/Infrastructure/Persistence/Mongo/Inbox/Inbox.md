# Inbox

Sources :

- [`src/Infrastructure/Persistence/Mongo/Inbox/`](../../../../../../src/Infrastructure/Persistence/Mongo/Inbox/)
  — `ProcessedEventLedger`, `ProcessedEventDocument` ;
- [`src/Infrastructure/Symfony/Messenger/Event/`](../../../../../../src/Infrastructure/Symfony/Messenger/Event/)
  — `ProcessedEventLedgerInterface`, `ProcessedEventSource`.

L'inbox est le cote **reception** des evenements : elle memorise ce qui a deja ete traite, pour
qu'une redelivrance ne rejoue pas un effet. Son pendant cote **emission** est l'outbox
([`../Outbox/Outbox.md`](../Outbox/Outbox.md)).

## L'idee, en clair

Un message peut arriver **deux fois**. Pas par accident : tous ces transports preferent livrer deux
fois plutot que perdre une fois. Si un worker meurt au milieu d'un traitement, personne ne sait s'il
avait fini — le message est donc repris.

Deux facons de rendre ce doublon inoffensif :

1. **ecrire une operation qui converge** — « amene a cet etat » plutot que « applique ce
   changement ». Rejouee, elle ne fait rien de plus. Aucune memoire n'est necessaire ;
2. **se souvenir de ce qui a deja ete traite** — c'est l'inbox. Indispensable quand l'effet n'a pas
   d'etat a comparer : un e-mail envoye, un paiement appele, un compteur incremente.

```text
message ──▶ deja traite ?  ──oui──▶ on acquitte, rien d'autre
                 │
                non
                 ▼
            effet + marquage  ──▶ collection processed_event
```

Le reste de ce document detaille la mecanique, et surtout **quand** marquer — c'est la que tout se
joue.

## Le probleme resolu

Aucun transport utilise par ce service ne livre exactement une fois. Tous livrent
**au-moins-une-fois** :

- l'outbox remet une ligne en file passe `redeliver_timeout`, si le worker qui l'avait reservee est
  mort avant l'ack ;
- SQS fait de meme a l'expiration du delai de visibilite, pour les evenements d'integration recus
  de `service_identity` ;
- un `messenger:failed:retry` rejoue volontairement un message.

Le scenario type : le worker produit l'effet, puis meurt avant d'acquitter. Le message repasse, et le
handler rejoue tout.

Beaucoup de cas d'usage sont deja ecrits pour **converger** — provisionner un client deja provisionne
ne fait rien, desactiver un client deja desactive non plus. Mais cette propriete appartient a chaque
handler, et le prochain peut ne pas l'avoir : envoyer un e-mail, appeler un paiement, incrementer un
compteur ne convergent pas. L'inbox apporte la deduplication en dehors du code metier.

## Inbox et convergence se completent

Ni l'une ni l'autre ne suffit seule :

| | Inbox | Convergence du cas d'usage |
|---|---|---|
| Doublon sur le chemin nominal (redelivrance apres ack perdu) | **evite**, meme pour un effet non convergent | absorbe, si le handler converge |
| Panne entre l'effet et son marquage | **fermee** : marquage et ecriture metier partagent la transaction | **rattrape** |
| Deux workers sur la meme redelivrance, en parallele | le perdant voit sa transaction echouer, puis rejoue | **rattrape** |

L'inbox dedoublonne ; la convergence reste le filet. Et le jour ou un effet **externe** arrivera,
aucune transaction ne pourra plus fermer la premiere ligne — raison de plus pour garder les cas
d'usage convergents quand ils peuvent l'etre.

## Le contrat

```php
interface ProcessedEventLedgerInterface
{
    public function hasProcessed(string $eventId, string $handler): bool;

    // rejoint le flush de l'appelant, donc sa transaction
    public function markProcessed(string $eventId, string $handler, ProcessedEventSource $source): void;
}
```

`hasProcessed()` sert de garde d'entree, `markProcessed()` enregistre le traitement — **dans la
transaction de l'effet** :

```text
handler(message)
    |
    +-- hasProcessed(eventId, handler::class) ?  -> oui : ack, ne rien faire
    |
    `-- transactional(function () {
            ... ecriture metier ...
            markProcessed(eventId, handler::class, source)
        });                      un seul commit : les deux, ou aucun des deux
```

C'est l'**exactement-une-fois sur le chemin nominal**.

L'interface vit dans `Infrastructure/Symfony/Messenger/Event/`, a cote des handlers qui s'en servent,
et non dans `Application/` : la deduplication est une affaire de transport, pas de cas d'usage.

### La cle : `(eventId, handler)`

- **`eventId`** est frappe par l'emetteur, une seule fois, dans le constructeur de l'evenement. Il
  voyage avec le message et reste identique a chaque redelivrance. Il ne doit **jamais** etre
  regenere a la reception, sans quoi chaque redelivrance paraitrait nouvelle.
- **`handler`** est le nom de classe du handler. Un meme evenement peut declencher plusieurs
  reactions ; marquer l'une ne doit pas faire sauter les autres.

## `ProcessedEventSource` : provenance et retention

Une seule collection porte deux flux, comme l'outbox partage la sienne avec sa file d'echec :

| Cas | Valeur | Retention | Justification |
|---|---|---|---|
| `Bus` | `bus.identity` | 30 jours | SQS ne conserve pas un message plus de 14 jours ; 30 laisse une marge franche. |
| `Outbox` | `outbox` | 7 jours | La redelivrance y est de l'ordre de l'heure ; 7 jours couvrent en plus un rejeu manuel depuis la file d'echec. |

La cle `(eventId, handler)` suffit a separer les flux : un handler d'evenement d'integration n'est
jamais la meme classe qu'un handler de Domain Event interne. `source` ne sert donc **pas** a
l'unicite — seulement a lire la collection en incident et a porter la retention.

Se souvenir plus longtemps qu'une redelivrance possible ne protege de rien et fait grossir la
collection sans fin : d'ou une duree bornee par provenance.

## L'adaptateur MongoDB : `ProcessedEventLedger`

### Une seule ecriture, et elle rejoint la transaction

`markProcessed()` fait `persist()` et **ne flushe pas** : sa durabilite est celle de l'appelant,
donc, dans un callback `TransactionalInterface`, le commit de l'ecriture metier. C'est le meme
chemin que l'`enqueue()` de l'outbox.

> **Appele hors de tout flush, `markProcessed()` n'ecrit rien — et ne previent pas.** C'est la
> nature de `persist()` : sans `flush()`, le document est oublie. Le symptome serait un
> `hasProcessed()` eternellement faux, donc une deduplication qui ne deduplique pas, et aucun test
> ni aucun log ne le signalerait. **L'appel doit etre dans le callback de `TransactionalInterface`.**

**Pourquoi c'est la seule option offerte** : marquer par une ecriture propre ferait **survivre la
ligne au rollback** de l'effet. Le message serait repute traite alors qu'il n'a rien produit, et
l'effet serait perdu definitivement — sans la moindre erreur nulle part. C'est le cas que couvre le
test de rollback.

Il n'y a donc **pas** de variante hors transaction. Elle aurait la meme signature pour une garantie
plus faible : elle serait choisie par defaut par qui lit vite. Le jour ou un effet **externe**
(e-mail, paiement, appel HTTP) l'exigera — aucune transaction ne couvrant un appel reseau — elle
s'appellera `markProcessedAndFlush()`, comme l'`enqueueAndFlush()` de l'outbox dont le suffixe
signale deja « hors transaction metier ». Il faudra alors choisir sa fenetre :

| Ordre | Risque assume |
|---|---|
| Effet, puis marquage | la panne empeche le marquage -> **doublon** au rejeu |
| Marquage, puis effet | la panne empeche l'effet -> **perte** definitive |

### Un second marquage echoue, et c'est le bon comportement

Deux workers peuvent traiter la meme redelivrance et franchir ensemble `hasProcessed()`. Le second
`persist()` se heurte alors a l'index unique : **le flush echoue, donc la transaction entiere**.
L'effet n'est pas applique deux fois, le message est rejoue, et la garde le voit traite.

C'est l'index unique qui garantit l'unicite — pas la lecture `hasProcessed()` qui precede, qui n'est
qu'un check-then-act destine a eviter ce detour sur le chemin nominal.

## La collection `processed_event`

| Champ | Role |
|---|---|
| `eventId` | Identifiant de l'evenement, frappe par l'emetteur. |
| `handler` | Nom de classe du handler qui l'a traite. |
| `source` | Valeur de `ProcessedEventSource`. |
| `processedAt` | Date du premier marquage reussi. |
| `expiresAt` | `processedAt` + retention de la provenance. |

Index, poses par `make db-index` comme tous ceux du mapping :

- `processed_event_uniq` — unique sur `(eventId, handler)` : **la** garantie ;
- `processed_event_ttl_idx` — TTL sur `expiresAt`, `expireAfterSeconds: 0` : MongoDB supprime la
  ligne a la date qu'elle porte. Chaque provenance fixe ainsi sa propre retention dans une seule
  collection. Le balayage TTL passe environ toutes les minutes ; ce retard est sans consequence, la
  date etant choisie tres au-dela de toute redelivrance ;
- `processed_event_source_idx` — sur `source`, pour lire ou purger un flux en incident.

Sans `make db-index`, l'index unique manque : un double marquage ecrirait deux lignes au lieu d'etre
absorbe, et l'index TTL ne purgerait rien.

## Etat actuel

L'adaptateur existe et est teste, mais **aucun handler ne l'injecte encore** : son alias est donc
retire du conteneur a la compilation. Il sera cable par le handler du bus inter-services, qui arrive
avec le transport SQS.

Cote outbox, `LogDomainEventHandler` n'en a pas besoin : journaliser deux fois est sans consequence.

### Ce que le cablage devra resoudre

La transaction est ouverte par le **cas d'usage**, pas par le handler du bus. Pour que le marquage
y entre, l'`eventId` doit donc descendre jusqu'a la couche Application. Deux options, a trancher en
ecrivant le consommateur :

1. **la commande porte une cle d'idempotence**, et le cas d'usage marque dans son propre
   `transactional()`. L'Application ne pouvant dependre d'Infrastructure, le Port descend avec :
   une interface dans `Application/Shared/Port/`, implementee par cet adaptateur ;
2. **un cas d'usage dedie aux evenements d'integration**, qui recoit la cle et fait les deux
   ecritures. Les cas d'usage existants restent alors inchanges.

> **Ne pas imbriquer deux `transactional()`.** Si le handler du bus en ouvrait un autour du dispatch
> de la commande, le `flush()` du cas d'usage commiterait d'abord et le marquage partirait dans un
> second commit : l'atomicite serait perdue **sans qu'aucune erreur n'apparaisse**. C'est la panne
> exacte que ce chemin d'ecriture existe pour empecher.

## Ce qui le verifie

[`ProcessedEventLedgerTest`](../../../../../../tests/Infrastructure/Integration/Persistence/ProcessedEventLedgerTest.php)
(suite `infra.persist`, touche Mongo) :

- un evenement marque est reconnu ;
- **marquer deux fois** ne leve pas, ne duplique pas la ligne, et ne repousse ni `processedAt` ni
  `expiresAt` ;
- un meme evenement peut etre traite par deux handlers distincts ;
- chaque provenance porte sa propre expiration (30 et 7 jours) ;
- le marquage est **commite avec l'ecriture metier** ;
- et surtout **disparait avec elle** quand la transaction echoue — le test qui justifie le
  `persist()` ;
- un second marquage fait echouer sa transaction et laisse la premiere ligne intacte.

Le test construit l'adaptateur a la main plutot que de le tirer du conteneur, faute d'injection
pour l'instant. Il ne prouve que quelque chose s'il est **rejoue** : un test qui ne marquerait qu'une
fois passerait au vert sans index unique.

## Points de vigilance lors d'une modification

- Marquer **dans** le callback transactionnel de l'effet, jamais a cote. Deux facons de se tromper,
  toutes deux silencieuses : une ligne engagee seule survivrait au rollback et ferait perdre
  l'effet ; un `markProcessed()` appele sans aucun flush derriere n'ecrirait **rien du tout**.
- Ne pas ajouter de variante hors transaction « par commodite ». Si un effet externe l'exige, elle
  s'appelle `markProcessedAndFlush()` et arrive avec son cas d'usage et son test.
- Ne pas retirer `handler` de la cle : deux reactions au meme evenement se masqueraient l'une
  l'autre.
- Une nouvelle provenance = un nouveau cas de `ProcessedEventSource`, avec sa retention justifiee
  par le delai de redelivrance maximal de son transport.
- L'inbox ne dispense pas un handler d'etre convergent quand il le peut.

## Diagnostic

```bash
make bash-db
> db.processed_event.find({eventId: "<id>"})                       # qui a traite cet evenement ?
> db.processed_event.aggregate([{$group: {_id: "$source", n: {$sum: 1}}}])
> db.processed_event.getIndexes()                                  # les trois index doivent y etre
```
