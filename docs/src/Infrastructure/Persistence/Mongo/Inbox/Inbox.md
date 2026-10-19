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
| Panne entre l'effet et son marquage | fermee **seulement** quand les deux partagent une transaction | **rattrape** |
| Deux workers sur la meme redelivrance, en parallele | le perdant est rejete par l'index unique, puis rejoue | **rattrape** |

L'inbox dedoublonne ; la convergence reste le filet. Et elle n'est pas optionnelle : des que le
handler ne detient pas la transaction de son effet — effet externe, ou effet commite par la commande
qu'il dispatche — plus aucune transaction ne ferme la deuxieme ligne. **C'est le cas de tous les
handlers du bus inter-services**, d'ou l'insistance sur des cas d'usage commutatifs et idempotents.

## Le contrat

```php
interface ProcessedEventLedgerInterface
{
    public function hasProcessed(string $eventId, string $handler): bool;

    // rejoint le flush de l'appelant, donc sa transaction
    public function markProcessed(string $eventId, string $handler, ProcessedEventSource $source): void;

    // engage tout de suite, hors transaction metier
    public function markProcessedAndFlush(string $eventId, string $handler, ProcessedEventSource $source): void;
}
```

`hasProcessed()` sert de garde d'entree. Le marquage, lui, a **deux chemins d'ecriture**, et le bon
depend d'une seule question : **le handler detient-il la transaction de son effet ?**

### Oui : `markProcessed()`, dans la transaction

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

C'est l'**exactement-une-fois sur le chemin nominal**, et le seul chemin qui l'atteigne.

### Non : `markProcessedAndFlush()`, apres l'effet

Deux handlers ne tiennent pas cette transaction : celui dont l'effet est **externe** — un e-mail,
un paiement, qu'aucune transaction ne peut couvrir — et celui dont l'effet est **commite par
quelqu'un d'autre**, comme le handler du bus inter-services, qui dispatche une commande dont le cas
d'usage ouvre et valide sa propre transaction.

```text
handler(message)
    |
    +-- hasProcessed(eventId, handler::class) ?  -> oui : ack, ne rien faire
    |
    +-- l'effet                       (commande dispatchee, e-mail envoye...)
    |
    `-- markProcessedAndFlush(...)    ecriture durable immediate, son propre commit
```

La garantie tombe alors a l'**au-moins-une-fois dedoublonne** : une panne entre les deux fait
rejouer l'effet. C'est ce que la convergence du handler rattrape — et c'est pourquoi le marquage
vient **apres** l'effet, jamais avant :

| Ordre | Panne entre les deux |
|---|---|
| Effet, puis marquage | l'effet est **rejoue** — inoffensif si le handler converge |
| Marquage, puis effet | l'effet est **perdu** definitivement, et rien ne le signale |

> **Ne pas remplacer le second cas par une transaction englobante.** Si le handler du bus ouvrait un
> `transactional()` autour du dispatch, le `flush()` du cas d'usage commiterait d'abord et le
> marquage partirait dans un second commit : l'atomicite serait perdue **sans qu'aucune erreur
> n'apparaisse**. Deux `transactional()` imbriques ne donnent pas une transaction imbriquee.

L'interface vit dans `Infrastructure/Symfony/Messenger/Event/`, a cote des handlers qui s'en servent,
et non dans `Application/` : la deduplication est une affaire de transport, pas de cas d'usage. Un
cas d'usage ne recoit donc **pas** de cle d'idempotence — voir « L'option ecartee » plus bas.

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

### Deux ecritures, et le suffixe dit laquelle

`markProcessed()` fait `persist()` et **ne flushe pas** : sa durabilite est celle de l'appelant,
donc, dans un callback `TransactionalInterface`, le commit de l'ecriture metier. C'est le meme
chemin que l'`enqueue()` de l'outbox.

> **Appele hors de tout flush, `markProcessed()` n'ecrit rien — et ne previent pas.** C'est la
> nature de `persist()` : sans `flush()`, le document est oublie. Le symptome serait un
> `hasProcessed()` eternellement faux, donc une deduplication qui ne deduplique pas, et aucun log
> ne le signalerait. C'est le piege que couvre `testMarkProcessedAloneWritesNothing`.

`markProcessedAndFlush()` ajoute le `flush()`, et c'est tout — meme decoupage que l'`enqueue()` /
`enqueueAndFlush()` de l'outbox, ou le suffixe signale deja « hors transaction metier ». Les deux
methodes sont donc **distinguees par leur nom, jamais par leur seule signature** :

> **Ne jamais offrir une troisieme variante qui aurait la signature de `markProcessed()` pour la
> durabilite de l'autre.** Elle serait choisie par defaut par qui lit vite, et une ligne engagee
> seule **survit au rollback** de son effet : le message passerait pour traite alors qu'il n'a rien
> produit, et l'effet serait perdu definitivement — sans la moindre erreur nulle part. C'est le cas
> que couvre le test de rollback.

Le corollaire tient en une phrase : `markProcessedAndFlush()` ne s'appelle **qu'apres** l'effet.
Avant, il echangerait le doublon au rejeu contre cette perte definitive.

### Un second marquage echoue, et c'est le bon comportement

Deux workers peuvent traiter la meme redelivrance et franchir ensemble `hasProcessed()`. Le second
`persist()` se heurte alors a l'index unique : **le flush echoue**, et avec lui la transaction qui
le portait. L'effet n'est pas applique deux fois, le message est rejoue, et la garde le voit traite.

Par `markProcessedAndFlush()`, l'echec n'annule rien — l'effet est deja commite — mais l'exception
remonte au worker, le message est rejoue, et la garde l'ecarte. Dans les deux cas, le resultat est
une seule ligne et un seul effet.

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

## Qui appelle quel chemin

Un seul critere, et il ne depend pas de l'etat du code : **qui detient la transaction de l'effet ?**

| Appelant | Methode | Provenance |
|---|---|---|
| Handler d'evenement d'integration : il dispatche une commande, dont le cas d'usage ouvre **et valide** sa propre transaction | `markProcessedAndFlush()`, apres le dispatch | `bus.identity` |
| Handler qui ouvre lui-meme la transaction de son effet | `markProcessed()`, dans le callback | `outbox` |

Cote outbox, `LogDomainEventHandler` n'en a pas besoin : journaliser deux fois est sans consequence.
Le premier handler de Domain Event porteur d'un effet non convergent relevera de la seconde ligne.

### L'option ecartee : la cle d'idempotence dans la couche Application

Pour atteindre l'exactement-une-fois, il fallait que le marquage entre dans la transaction du cas
d'usage — donc que l'`eventId` descende jusqu'a lui. Essaye, puis abandonne : la commande portait
une `idempotencyKey`, le cas d'usage marquait dans son propre `transactional()`, un Port
`IdempotencyLedgerInterface` descendait dans `Application/Shared/Port/`, et un adaptateur de
delegation y rhabillait `ProcessedEventSource::Bus`.

Ce que ca achetait, mesure sur les deux cas d'usage reels : un worker qui meurt entre le commit et
le marquage ne rejoue pas. Or ces cas d'usage **convergent** — c'est tout l'objet de leur ecriture.
Ce que le rejeu produit dans ce trou est donc exactement : un `findByUserAccountId()`, un `return`.

Quatre fichiers, une cle de transport dans deux commandes metier et une subtilite d'ordre
(« marquer avant la relecture de convergence ») pour economiser **une lecture MongoDB par
redelivrance**. Le signe qui aurait du alerter plus tot : l'adaptateur de delegation n'existait que
pour rhabiller une valeur d'enum.

Et l'option n'ouvre rien pour la suite : le jour ou ces handlers gagneront un effet non convergent,
ce sera un e-mail ou un appel — donc un effet **externe**, que la transaction du cas d'usage ne
couvrirait de toute facon pas. `markProcessedAndFlush()` est deja la bonne forme pour lui.

**Ce qu'on accepte en echange, et qu'il faut savoir :** la fenetre « effet commite, evenement non
marque » est fermee par la convergence seule. Rendre ces cas d'usage non convergents sans revoir ce
choix ferait dupliquer le rejeu, et l'inbox ne l'arreterait pas. Les tests « ne fait rien quand le
client existe deja » de la suite `appli.customer` sont ce qui monte la garde.

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
- un second marquage fait echouer sa transaction et laisse la premiere ligne intacte ;
- **`markProcessed()` seul n'ecrit rien** — le test qui justifie l'existence de deux methodes ;
- `markProcessedAndFlush()` engage sans transaction, **survit a un rollback ulterieur** (la
  propriete qui impose de ne l'appeler qu'apres l'effet), et se heurte lui aussi a l'index unique
  au second appel.

Le test construit l'adaptateur a la main plutot que de le tirer du conteneur : c'est **lui** qu'on
verifie, pas son cablage. Il ne prouve quelque chose que s'il est **rejoue** : un test qui ne
marquerait qu'une fois passerait au vert sans index unique.

## Points de vigilance lors d'une modification

- **Les deux facons de se tromper sont silencieuses** : une ligne engagee seule survit au rollback
  de son effet, qui est alors perdu ; un `markProcessed()` sans flush derriere n'ecrit **rien du
  tout**, et la deduplication cesse de dedupliquer. Ni erreur, ni log, dans aucun des deux cas.
- Choisir le chemin d'ecriture sur une seule question : **qui detient la transaction de l'effet ?**
  Le handler -> `markProcessed()`, dans son callback. Quelqu'un d'autre, ou personne ->
  `markProcessedAndFlush()`, **apres** l'effet. Ne pas ajouter de troisieme variante.
- Ne jamais ouvrir un `transactional()` autour d'un dispatch pour « rapatrier » le marquage : le
  flush du cas d'usage commite d'abord, et l'atomicite est perdue en silence.
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
