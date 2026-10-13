# Outbox

Sources : [`src/Infrastructure/Persistence/Mongo/Outbox/`](../../../../../../src/Infrastructure/Persistence/Mongo/Outbox/)
— `DomainEventOutbox`, `DomainEventDocument`, `OutboxRecord`.

L'outbox est le cote **emission** des Domain Events : il garantit qu'un evenement est ecrit si et
seulement si l'agregat qui l'a produit est ecrit. Son pendant cote **reception** est l'inbox
([`../Inbox/Inbox.md`](../Inbox/Inbox.md)).

Le cycle complet des evenements — enregistrement dans l'agregat, worker, handlers — est decrit dans
[`docs/domain_events.md`](../../../../../domain_events.md). Ce document se limite a ce que fait la
couche de persistance, et a pourquoi elle le fait ainsi.

## L'idee, en clair

Un produit est cree. Deux choses doivent se passer : **l'enregistrer en base**, et **prevenir qui
s'y interesse** (vider un cache, plus tard envoyer un e-mail). Fait separement, il existe toujours
un instant ou l'une a reussi et l'autre pas :

| Ce qui casse | Resultat |
|---|---|
| On previent, puis la base echoue | tout le monde reagit a un produit qui n'existe pas |
| On enregistre, puis prevenir echoue | le produit existe, personne ne le sait, et rien ne le rattrapera |

**La parade est de n'ecrire qu'une seule fois.** Le message n'est pas envoye : il est **ecrit dans
la base, a cote du produit, dans la meme transaction**. Les deux reussissent ensemble ou echouent
ensemble ; aucun etat intermediaire n'existe. Cette table de messages en attente est l'**outbox**,
et un processus separe — le **worker** — la relit, execute les reactions, puis supprime la ligne.

Le schema et le vocabulaire sont dans
[`docs/domain_events.md`](../../../../../domain_events.md#lidee-en-clair) ; le prix du decouplage —
un message repris, donc une reaction rejouee — est le sujet de l'inbox
([`../Inbox/Inbox.md`](../Inbox/Inbox.md)).

Le reste de ce document explique **comment** c'est tenu dans la couche de persistance, et ce qui
casserait silencieusement si on s'en ecartait.

## Le probleme resolu

Un cas d'usage modifie un agregat **et** annonce ce qu'il a fait. Deux ecritures independantes
ouvrent deux fenetres d'incoherence :

- l'agregat est commite, puis la publication echoue : le fait a eu lieu, personne ne le saura ;
- l'evenement est publie, puis le commit echoue : on annonce un fait qui n'a jamais existe.

Le patron outbox ferme ces deux fenetres en ecrivant l'evenement **dans la meme base et la meme
transaction** que l'agregat. Un processus separe (le worker) le relit ensuite et le livre. La
livraison est decouplee de la transaction metier, mais ne peut plus diverger d'elle.

## Vue d'ensemble

```text
┌─ Requete HTTP ───────────────────────────────────────────────────────┐
│  MongoTransactional::transactional(function () {                     │
│      repository->save($aggregate);              persist()  ──┐       │
│      eventBus->publishAll($aggregate->releaseEvents());      │       │
│        └─ MongoDomainEventBus                                │ meme  │
│             └─ DomainEventOutbox::enqueue()     persist()  ──┤ flush │
│  });                                                         │       │
│                               flush(withTransaction: true) ◄─┘       │
└──────────────────────────────────────┬───────────────────────────────┘
                                       ▼
                   collection `domain_event_outbox`
                   queueName = domain_events | failed_domain_events
                                       │
┌─ Worker (messenger:consume domain_events) ───────────────────────────┐
│  MongoOutboxTransport::get()  → DomainEventOutbox::claim()           │
│  handler(s) de event.bus                                             │
│  ack() / reject()             → DomainEventOutbox::remove()          │
└──────────────────────────────────────────────────────────────────────┘
```

## Les trois classes

| Classe | Role |
|---|---|
| `DomainEventDocument` | Une ligne de l'outbox, mappee sur `domain_event_outbox`. Equivalent MongoDB de `messenger_messages`. |
| `DomainEventOutbox` | **Seul** acces a la collection, et seul endroit de l'outbox qui connaisse Doctrine. |
| `OutboxRecord` | Ce que l'outbox rend au transport : `id`, `body`, `headers`. Volontairement pauvre. |

`MongoDomainEventBus` (ecriture) et `MongoOutboxTransport` (lecture) vivent dans
`Infrastructure/Symfony/Messenger/` et ne manipulent que des chaines deja serialisees. C'est ce qui
maintient la regle « aucune reference a Doctrine hors `Persistence/` ».

## Deux facons d'ecrire, et toute la difference est la

### `enqueue()` — le chemin nominal

`enqueue()` fait `persist()` et **ne flushe pas**. La ligne attend le flush de l'appelant, c'est-a-dire
le flush unique et transactionnel de `MongoTransactional` (voir
[`../MongoTransactional.md`](../MongoTransactional.md)). L'agregat et ses evenements sont commites
ensemble, ou pas du tout.

C'est la regle qui ne doit jamais bouger : **une transaction MongoDB appartient a la session portee
par le flush de l'ODM**. Une ecriture emise par le pilote a cote de ce flush (`insertOne()`) n'a pas
cette session : elle s'engage seule, survit au rollback, et rien ne le signale. Les evenements
arriveraient dans la collection, le worker les consommerait — seul un rollback trahirait la faute,
en publiant un evenement pour un agregat qui n'existe pas.

C'est aussi pourquoi la publication ne passe pas par un `dispatch()` Messenger : sur Doctrine ORM,
`doctrine://` rejoignait la transaction gratuitement ; sur MongoDB, aucun transport ne le peut.

### `enqueueAndFlush()` — les chemins de reprise

`enqueueAndFlush()` engage immediatement. Il n'est appele que par `MongoOutboxTransport::send()`,
lui-meme reserve aux chemins qui s'executent **hors transaction metier** :

- un retry differe (copie replacee plus loin via `availableAt`) ;
- la copie vers la file d'echec `failed_domain_events` ;
- un rejeu manuel `messenger:failed:retry`.

Dans ces cas, le message doit etre durable tout de suite — il n'y a aucun flush metier a attendre.
Utiliser `enqueueAndFlush()` dans le chemin nominal couperait l'unite de travail en deux.

## Lire et acquitter

Les lectures et l'acquittement passent par le **pilote**, pas par l'unite de travail : reserver un
message demande un `findOneAndUpdate` atomique, que l'ODM ne sait pas exprimer.

### `claim()` — reserver un message

`claim()` prend, dans une file donnee, le plus ancien message (`sort: availableAt`) qui est :

- eligible : `availableAt <= now` ;
- libre : `deliveredAt` nul, **ou** pose depuis plus de `redeliver_timeout` secondes.

et pose `deliveredAt = now` dans la meme operation atomique. Deux workers ne peuvent donc pas se voir
remettre la meme ligne.

Le `redeliver_timeout` (3600 s, `config/packages/messenger.yaml`) rattrape un worker tue en plein
traitement : sa ligne redevient eligible une heure plus tard. C'est ce qui fait de l'outbox une
livraison **au-moins-une-fois** — et c'est exactement le doublon que l'inbox est la pour absorber.

### `remove()` — acquitter

`ack()` comme `reject()` suppriment la ligne. Pour `reject()`, la copie vers la file d'echec a deja
ete faite en amont par Messenger ; garder la ligne la ferait redelivrer indefiniment.

Un corps illisible (`MessageDecodingFailedException`) est retire par le transport avant de propager
l'erreur : il ne se reparera pas au retry et empoisonnerait la file.

### `count()`, `all()`, `find()`

Utilises par `messenger:stats`, `messenger:failed:show` et `messenger:failed:retry`, via
`MessageCountAwareInterface` et `ListableReceiverInterface` du transport.

## La collection `domain_event_outbox`

| Champ | Role |
|---|---|
| `queueName` | File logique. L'outbox et sa file d'echec partagent la collection, distinguees par ce seul champ. |
| `body`, `headers` | Le message serialise par le codec Messenger — tout ce dont le transport a besoin. |
| `eventName`, `eventId`, `aggregateId`, `occurredOn` | **Denormalises** depuis l'evenement, pour lire la collection dans `mongosh` sans deserialiser un blob PHP. Inutiles au transport. |
| `createdAt` | Date d'ecriture. |
| `availableAt` | Date d'eligibilite. Un retry differe la repousse. |
| `deliveredAt` | Non nul = reserve par un worker. |

Index, poses par `make db-index` comme tous ceux du mapping :

- `outbox_claim_idx` — `(queueName, availableAt)`, sert `claim()` ;
- `outbox_delivered_idx` — `(queueName, deliveredAt)`, sert la detection des reservations abandonnees.

Une ligne ne vit que le temps d'etre consommee : l'outbox se vide d'elle-meme, et une file
`domain_events` qui ne redescend pas signale un handler qui echoue en boucle.

## Ce qui le verifie

[`DomainEventOutboxTest`](../../../../../../tests/Infrastructure/Integration/Persistence/DomainEventOutboxTest.php)
(suite `infra.persist`, touche Mongo) :

- un evenement publie dans la transaction est commite avec son agregat ;
- **un rollback emporte les evenements** — et une exception levee par le callback ne publie rien ;
- deux agregats publient leurs evenements dans le meme commit ;
- le transport restitue l'evenement publie ;
- un message reserve n'est pas remis une seconde fois ;
- `ack()` retire le message ;
- un message abandonne par son worker est remis apres `redeliver_timeout` ;
- un `send()` differe n'est pas eligible avant son heure.

Les cas de rollback sont le garde-fou de la regle centrale : remplacer le `persist()` d'`enqueue()`
par un `insertOne()` les fait passer au rouge, et rien d'autre dans la suite ne bronche.

## Points de vigilance lors d'une modification

- Ne jamais ecrire dans l'outbox par le pilote dans le chemin nominal : `enqueue()` `persist()`, et
  c'est tout.
- Ne jamais ajouter de `flush()` dans `enqueue()` ; seul `MongoTransactional` flushe le chemin
  nominal. `enqueueAndFlush()` est la seule exception, et seulement pour les chemins de reprise.
- Publier **dans** le callback transactionnel, **apres** le `save()` de l'agregat.
- Ne jamais placer d'I/O externe dans le chemin de publication : il rallongerait la transaction et
  rendrait le commit dependant d'un tiers. Les effets externes appartiennent aux handlers du worker.
- Un evenement est stocke en clair : ni secret, ni donnee personnelle non necessaire.

## Diagnostic

```bash
make console c="messenger:stats"                                         # profondeur des deux files
make console c="messenger:failed:show --transport=failed_domain_events"
make bash-db
> db.domain_event_outbox.find({}, {queueName: 1, eventName: 1, aggregateId: 1, availableAt: 1, deliveredAt: 1})
```
