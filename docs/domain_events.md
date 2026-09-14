# Domain Events

Le catalogue publie ses Domain Events dans un **outbox transactionnel MongoDB**, et les consomme
dans un worker Messenger séparé. Ce document décrit le cycle complet : de l'enregistrement d'un
fait métier dans un agrégat jusqu'à l'exécution de ses réactions, en passant par la garantie
d'atomicité et la déduplication des redélivrances.

Pour la mécanique CQRS elle-même (bus de commandes/requêtes, middlewares, adapters), voir
[`CQRS_messenger.md`](CQRS_messenger.md).

## La contrainte transactionnelle MongoDB

Une transaction MongoDB appartient à la session portée par le flush de l'ODM
(`MongoTransactional`, `['withTransaction' => true]`). Une écriture émise par le pilote à côté de ce
flush — `insertOne()`, ou un `SenderInterface` qui écrit immédiatement — s'engage seule et
**survivrait au rollback de l'agrégat**, sans lever la moindre erreur.

D'où la différence structurelle : la publication ne passe pas par un dispatch Messenger. Elle
`persist()` un document, comme n'importe quelle écriture du service.

```text
┌─ Command handler (Application) ─────────────────────────────┐
│                                                             │
│  $this->transactional->transactional(function () {          │
│      $this->repository->save($product);          ──┐        │
│      $this->eventBus->publishAll(                  │ même   │
│          $product->releaseEvents()                 │ flush  │
│      );                                          ──┘        │
│  });                                                        │
│                         │                                   │
└─────────────────────────┼───────────────────────────────────┘
                          │ flush(['withTransaction' => true])
                          ▼
             ┌───────────────────────────────────────────────┐
             │  CacheInvalidationMiddleware  (command.bus)    │
             │  purge les tags, après commit, avant réponse   │
             └────────────┬──────────────────────────────────┘
                          ▼
             ┌────────────────────────────┐
             │  domain_event_outbox       │  collection MongoDB
             │  queueName=domain_events   │
             └────────────┬───────────────┘
                          │  messenger:consume domain_events
                          ▼
┌─ Worker (Infrastructure) ───────────────────────────────────┐
│  LogDomainEventHandler            tous les événements       │
└─────────────────────────────────────────────────────────────┘
                          │ échec définitif après 6 tentatives (~10 min)
                          ▼
             ┌──────────────────────────────────┐
             │  queueName=failed_domain_events   │
             └──────────────────────────────────┘
```

## 1. Enregistrer un fait métier

Les événements vivent dans `src/Domain/Catalog/Event/`. Ils sont `final readonly`, ne dépendent
d'aucun framework, et implémentent `DomainEventInterface` :

```php
interface DomainEventInterface
{
    public function eventId(): string;      // identité stable, clé de déduplication
    public function aggregateId(): string;  // sujet de l'événement, pour corréler les journaux
    public function occurredOn(): DateTimeImmutable;
    public function eventName(): string;    // 'shop.catalog.product.created'
}
```

`DomainEventIdentityTrait` fournit `eventId()` et son générateur. L'événement l'assigne **dans son
constructeur**, une seule fois :

```php
final readonly class CategoryCreatedEvent implements CategoryDomainEventInterface
{
    use DomainEventIdentityTrait;

    public function __construct(
        private CategoryId $categoryId,
        private DateTimeImmutable $occurredOn,
    ) {
        $this->eventId = self::generateEventId();
    }
    // ...
}
```

`generateEventId()` fait `bin2hex(random_bytes(16))` — du PHP core, donc aucune dépendance Symfony
ni Ramsey n'entre dans le Domain. L'identifiant voyage avec l'événement lors de la sérialisation et
reste identique à chaque redélivrance : c'est ce qui rend la déduplication possible.

### Trois interfaces, pour trois questions différentes

| Interface | Ce qu'elle rend contractuel | Qui s'en sert |
|---|---|---|
| `CatalogDomainEventInterface` | rien — marqueur « fait du catalogue » | `DomainEventCacheTags` |
| `CategoryDomainEventInterface` | `getCategoryId()` | consommateurs orientés catégorie |
| `ProductDomainEventInterface` | `getProductId()` **et** `getCategoryId()` | `DomainEventCacheTags` |

Le marqueur n'est pas décoratif. Sans lui, `DomainEventCacheTags` devrait énumérer les douze
événements, et l'oubli d'un seul ne se verrait qu'à la lecture périmée — jamais à la compilation,
jamais dans un test qui n'a pas pensé à ce cas.

`ProductDomainEventInterface` n'étend pas `CategoryDomainEventInterface` : un produit n'est pas une
catégorie, et un consommateur abonné aux faits de catégorie ne doit pas recevoir les siens. Il
expose quand même la catégorie de rattachement, parce que `nbProduct` y est dénormalisé — tout fait
produit peut donc périmer une lecture de catégorie.

### Les douze événements

| Événement | `eventName()` | Enregistré par | Réaction |
|---|---|---|---|
| `CategoryCreatedEvent` | `shop.catalog.category.created` | `Category::create()` | — |
| `CategoryRenamedEvent` | `shop.catalog.category.renamed` | `Category::rename()` | — |
| `CategoryDescriptionUpdatedEvent` | `shop.catalog.category.description_updated` | `Category::describe()` | — |
| `CategoryMovedEvent` | `shop.catalog.category.moved` | `Category::moveTo()` | — |
| `CategoryDeletedEvent` | `shop.catalog.category.deleted` | `Category::delete()` | — |
| `ProductCreatedEvent` | `shop.catalog.product.created` | `Product::create()` | — |
| `ProductRenamedEvent` | `shop.catalog.product.renamed` | `Product::rename()` | — |
| `ProductRepricedEvent` | `shop.catalog.product.repriced` | `Product::reprice()` | — |
| `ProductDescriptionUpdatedEvent` | `shop.catalog.product.description_updated` | `Product::rewrite()` | — |
| `ProductMovedEvent` | `shop.catalog.product.moved` | `Product::moveToCategory()` | — |
| `ProductImageUpdatedEvent` | `shop.catalog.product.image_updated` | `Product::updateImage()` | — |
| `ProductDeletedEvent` | `shop.catalog.product.deleted` | `Product::delete()` | — |

Tous déclenchent en outre la journalisation (`LogDomainEventHandler`) dans le worker et
l'invalidation du cache de queries (`CacheInvalidationMiddleware`) dans la requête : la colonne
« Réaction » ne liste que les réactions spécifiques.

### Ce qui n'émet volontairement rien

- **`Category::increaseProductCount()` / `decreaseProductCount()`.** Le compteur bouge en
  conséquence d'un fait produit déjà publié dans la même transaction. Émettre ici publierait le
  même fait sous deux noms.
- **`Product::reSlug()`.** Le slug suit mécaniquement le titre, et `rename()` a déjà publié.
- **Les suppressions refusées.** Une catégorie qui porte des produits ou des enfants ne produit pas
  de `CategoryDeletedEvent` : `Category::delete()` lève un conflit avant toute écriture.
- **`reconstitute()`.** Recharger un agrégat n'est pas un fait métier. Un test qui fabrique un
  agrégat par `create()` pour simuler un chargement doit appeler `clearDomainEvents()`.

### Granularité : pourquoi un PATCH peut publier trois événements

`UpdateCategoryByAdminCommandHandler` appelle `rename()`, `describe()` et `moveTo()` selon ce que
la requête porte. Un PATCH qui touche les trois publie donc trois événements, et écrit trois lignes
d'outbox.

C'est voulu. Ce sont trois décisions distinctes, même si l'appelant les a groupées dans une requête
HTTP ; le journal dit exactement ce qui a changé, et l'invalidation de cache dédoublonne ses tags
de toute façon. Un `CategoryUpdatedEvent` unique coûterait moins de lignes et dirait moins de choses.

### Ce qu'un événement ne doit pas transporter

**Aucun secret.** Un événement est persisté en clair dans `domain_event_outbox`, et y reste tant
qu'il n'est pas consommé — voire indéfiniment dans `failed_domain_events`.

**Aucune donnée personnelle non nécessaire.** Le test est « quel consommateur la lit ? ». Les
événements de produit ne portent pas de nom de fichier : chaque image appartient à un seul produit,
et le handler de commande la supprime directement après le commit.

## 2. Publier dans la transaction

L'Application publie via un Port, `DomainEventBusInterface` :

```php
interface DomainEventBusInterface
{
    /** @param DomainEventInterface[] $events */
    public function publishAll(array $events): void;
}
```

**La règle** : `publishAll()` est appelé **à l'intérieur** du callback
`TransactionalInterface::transactional()`, après le `save()` (ou le `delete()`) de l'agrégat.

```php
return $this->transactional->transactional(function () use (...): ProductItem {
    // ...
    $this->productRepository->save($product);

    $category->increaseProductCount($now);
    $this->categoryRepository->save($category);

    $this->eventBus->publishAll($product->releaseEvents());

    return ProductItem::fromProduct($product, $category);
});
```

`MongoDomainEventBus` encode l'envelope avec le codec Messenger, puis confie le résultat à
`DomainEventOutbox::enqueue()`, qui `persist()` un `DomainEventDocument` **sans flusher**. La ligne
rejoint donc le flush unique piloté par `MongoTransactional` : l'agrégat et ses événements sont
commités ensemble, ou pas du tout.

Le découpage suit la règle « aucune référence à Doctrine hors `Persistence/` » : le bus et le
transport ne manipulent que des chaînes sérialisées, `DomainEventOutbox` est seul à voir le
`DocumentManager`.

Chaque envelope reçoit un `BusNameStamp('event.bus')`. C'est ce qu'un `dispatch()` aurait posé ;
sans lui, le `RoutableMessageBus` du worker retomberait sur le bus par défaut — `command.bus`, qui
n'a aucun handler d'événement.

**Corollaire** : la publication ne doit jamais déclencher d'I/O externe. Un appel HTTP ou un envoi
d'e-mail dans le chemin de publication rallongerait la transaction et rendrait le commit dépendant
d'un tiers.

### Ce que le test protège

`tests/Infrastructure/Integration/Persistence/DomainEventOutboxTest.php` existe pour une raison
précise : **cette garantie disparaîtrait en silence**. Si la publication quittait l'unité de
travail, tout continuerait de fonctionner — les événements arriveraient dans la collection, le
worker les consommerait, aucune erreur nulle part. Seul le cas du rollback révélerait la faute, en
publiant un `CategoryCreatedEvent` pour une catégorie qui n'existe pas.

Deux de ses cas écrivent donc un agrégat valide **puis** provoquent l'échec du flush, et vérifient
qu'aucune ligne d'outbox n'a survécu. Ils sont à lire comme ceux de `MongoTransactionalTest` :
remplacer le `persist()` par un `insertOne()` les fait passer au rouge, et rien d'autre.

## 3. L'outbox MongoDB

```yaml
# config/packages/messenger.yaml
domain_events:
  dsn: 'mongodb-outbox://domain_events'
  options:
    redeliver_timeout: 3600
  retry_strategy:
    max_retries: 6
    delay: 10000
    multiplier: 2
    max_delay: 300000
    jitter: 0.1
failed_domain_events:
  dsn: 'mongodb-outbox://failed_domain_events'
```

Le transport est écrit à la main (`MongoOutboxTransport`, `MongoOutboxTransportFactory`, adossés à
`DomainEventOutbox`) : aucun transport livré ne convient. `doctrine://` suppose une base relationnelle ;
`amqp://` suppose un broker absent ; et `redis://` détournerait le Redis dédié au cache de queries en broker.
Surtout, aucun de ces transports ne sait inscrire le message dans la transaction MongoDB de l'agrégat.

### La collection

| Champ | Rôle |
|---|---|
| `queueName` | file logique — `domain_events` ou `failed_domain_events`, dans la même collection |
| `body`, `headers` | le message sérialisé par le codec Messenger (`PhpSerializer` par défaut) |
| `eventName`, `eventId`, `aggregateId`, `occurredOn` | **dénormalisés**, pour lire la collection dans `mongosh` sans désérialiser un blob PHP |
| `createdAt`, `availableAt` | un retry replace une copie plus loin dans le temps via `availableAt` |
| `deliveredAt` | non nul = réservé par un worker |

Deux index, posés par `make db-index` : `outbox_claim_idx` (`queueName`, `availableAt`) et
`outbox_delivered_idx` (`queueName`, `deliveredAt`).

### Réservation et redélivrance

`get()` fait un `findOneAndUpdate` atomique : il prend le plus ancien message éligible
(`availableAt <= now`) qui n'est pas réservé — ou dont la réservation date de plus de
`redeliver_timeout` — et pose `deliveredAt`. Deux workers ne peuvent donc pas se voir attribuer la
même ligne.

Le `redeliver_timeout` est ce qui rattrape un worker tué en plein traitement : sa ligne redevient
éligible au bout d'une heure. La journalisation est idempotente ; elle ne requiert aucun ledger.

`ack()` et `reject()` suppriment la ligne. Pour `reject()`, la copie vers la file d'échec a déjà été
faite en amont par `SendFailedMessageToFailureTransportListener` ; garder la ligne la ferait
redélivrer indéfiniment.

### `send()` n'est pas le chemin nominal

C'est le point à ne pas confondre. Les Domain Events sont écrits par `MongoDomainEventBus`, pas par
`send()`. Celui-ci ne sert qu'aux chemins de reprise — un retry différé, une copie vers la file
d'échec, un `messenger:failed:retry` — qui s'exécutent tous **hors transaction métier** et pour
lesquels le message doit être durable immédiatement. D'où l'appel à `enqueueAndFlush()`, qui
n'aurait aucun sens dans le chemin nominal.

Le transport implémente aussi `ListableReceiverInterface` et `MessageCountAwareInterface`, ce qui
fait fonctionner `messenger:stats`, `messenger:failed:show` et `messenger:failed:retry`.

### Le bus

```yaml
event.bus:
  default_middleware:
    allow_no_handlers: true
```

- `allow_no_handlers: true` : un fait métier sans réaction dédiée ne doit pas faire échouer le
  worker. Dix des douze événements sont dans ce cas, et c'est normal.
- **Pas de `UnwrapHandlerFailedExceptionMiddleware`**, contrairement à `command.bus` et `query.bus` :
  Messenger s'appuie sur `HandlerFailedException` pour arbitrer retry et échec définitif. La
  déballer dégraderait la stratégie de retry.

Le routage cible l'**interface**, donc tout nouvel événement est couvert sans configuration. Il ne
sert qu'aux chemins de reprise, le chemin nominal n'étant pas un dispatch :

```yaml
routing:
  App\Domain\SharedKernel\Event\DomainEventInterface: domain_events
```

### Les tests n'ont pas de transport `sync://`

`sync://` agit au `send()`, et le chemin nominal n'en émet pas. Les événements iraient quand même
dans la collection.

Les tests d'API laissent donc les événements s'accumuler dans l'outbox — `BaseTest` les purge avec
le reste. Le transport est couvert par `DomainEventOutboxTest`.

## 4. Le worker et ses handlers

```bash
make consume
# bin/console messenger:consume domain_events -vv
```

En conteneur, Supervisor lance **deux** processus
(`docker/app/supervisor/conf.d/messenger-worker.conf`), relancés après leur message en cours au
bout d'une heure, 256 Mo, 100 messages ou 5 échecs. Deux workers sont sûrs : la réservation est
atomique, et leur seul handler est la journalisation idempotente.

> Ce fichier est **copié dans l'image**. Le modifier impose `docker compose build app` puis
> `docker compose up -d app`, pas seulement un redémarrage. Surveiller
> `messenger:failed:show --transport=failed_domain_events`.

Les handlers vivent dans `src/Infrastructure/Symfony/Messenger/Event/Handler/`, annotés
`#[AsMessageHandler(bus: 'event.bus')]`. Un handler peut couvrir plusieurs événements en portant
l'attribut sur plusieurs méthodes.

`LogDomainEventHandler` est typé sur `DomainEventInterface` : il journalise **tout** événement
consommé sans qu'aucune déclaration ne soit nécessaire pour un nouvel événement.

## 5. Images de produit

Les noms sont aléatoires et une image appartient à un seul produit. Le cas d'usage dépose le nouveau
fichier avant la transaction, supprime ce nouveau fichier si celle-ci échoue, puis supprime l'ancienne
image seulement après un commit réussi. Une catégorie ne peut être supprimée que vide et sans enfant,
donc sa suppression n'implique aucun fichier. Aucun événement, worker ou comptage de références n'est
nécessaire pour cet effet de bord.

### Pourquoi une collection plutôt que `DeduplicateStamp`

Symfony fournit un `DeduplicateStamp` et son `DeduplicateMiddleware`, activé dès que le composant
Lock est configuré — il l'est ici. Il ne convient pas, et pas par défaut : les deux mécanismes ne
résolvent pas le même problème.

À l'émission, le middleware acquiert un verrou et n'envoie pas si l'acquisition échoue ; à la
consommation, il ne teste rien et relâche. Il empêche donc qu'un **second message** portant la même
clé soit émis pendant qu'un premier est en vol : c'est de l'exclusion mutuelle.

Or le scénario qui nous occupe est la **redélivrance du même message**. Un worker meurt après avoir
effacé le fichier mais avant l'ack, `redeliver_timeout` remet la ligne en file, le message repasse —
et il porte alors un `ReceivedStamp`, donc le middleware se contente de relâcher. Le handler
rejouerait intégralement. Le verrou n'a rien empêché.

## 6. Diagnostic

```bash
bin/console debug:messenger event.bus     # les handlers et les événements qu'ils couvrent
bin/console messenger:stats               # profondeur de l'outbox et de la file d'échec
bin/console messenger:failed:show --transport=failed_domain_events
make bash-db
> db.domain_event_outbox.find({}, {eventName: 1, aggregateId: 1, availableAt: 1, deliveredAt: 1})
```

Une file `domain_events` qui ne redescend pas alors que les workers tournent signale un handler qui
échoue en boucle : `messenger:failed:show` dit lequel.
