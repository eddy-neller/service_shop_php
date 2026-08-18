# Infrastructure Layer – Adapters & Frameworks

> **But** : implémenter les Ports Application et encapsuler les frameworks.
> Couche `src/Infrastructure/`. Règles transverses : voir `AGENTS.md` racine.
>
> **Ce fichier décrit `service_shop`.** La persistance est MongoDB via Doctrine
> **ODM** ; il n'y a ni ORM, ni migration, ni clé étrangère, ni broker. Redis est autorisé seulement
> pour le cache applicatif dédié et partagé entre les replicas de ce service. DQL, `Paginator`,
> `#[ORM\Index]` et un `OneToMany` inverse ne s'appliquent pas ici.

---

## Rôle

- Implémenter **tous les Ports** Application : repositories, horloge, slug, résolution d'URL d'image.
- Encapsuler : Doctrine ODM, Symfony (services, events, console), Ramsey (UUID), système de fichiers.
- Implémenter les buses CQRS avec les bus Symfony Messenger **synchrones** et héberger leurs
  middlewares techniques.

---

## Dépendances

- Infrastructure peut dépendre de : `App\Application\...\Port\...Interface` (Ports seulement),
  `App\Domain\...` (agrégats, VOs, events), frameworks & libs externes.
- Infrastructure ne doit **jamais** dépendre de `App\Presentation\*`.

---

## Ports → Implémentations

| Port (Application) | Implémentation | Emplacement |
|---|---|---|
| `Shared\Port\ClockInterface` | `SystemClock` | `Adapter/Time/` |
| `Shared\Port\TransactionalInterface` | `MongoTransactional` | `Persistence/Mongo/` |
| `Shared\Port\SlugGeneratorInterface` | `SymfonySlugGenerator` | `Adapter/Catalog/` |
| `Shared\Port\FileInterface` | `SymfonyFileAdapter` | **`src/Presentation/Shared/Adapter/`** |
| `Shared\Port\DomainEventBusInterface` | `MongoDomainEventBus` | `Symfony/Messenger/Event/` |
| `Catalog\Port\CategoryRepositoryInterface` | `MongoCategoryRepository` | `Persistence/Mongo/Catalog/` |
| `Catalog\Port\ProductRepositoryInterface` | `MongoProductRepository` | `Persistence/Mongo/Catalog/` |
| `Catalog\Port\ProductImageUrlResolverInterface` | `ProductImageUrlResolver` | `Adapter/Catalog/Storage/` |
| `Catalog\Port\ProductImageStorageInterface` | `ProductImageStorage` | `Adapter/Catalog/Storage/` |
| `Catalog\Port\ProductImageValidatorInterface` | `NativeProductImageValidator` | `Adapter/Catalog/Storage/` |

Deux points contre-intuitifs dans ce tableau :

- **`FileInterface` est implémenté dans Presentation**, pas ici : `SymfonyFileAdapter` enveloppe un
  `UploadedFile` HTTP, il n'a de sens qu'au contact de la requête. Ce n'est pas une entorse à corriger.
- **`MongoDomainEventBus` vit dans `Symfony/Messenger/`, pas dans `Persistence/`**, alors qu'il
  écrit un document. Il n'est pas un repository : il encode un message avec le codec Messenger et
  délègue l'écriture à `Persistence/Mongo/Outbox/DomainEventOutbox`, seul endroit qui connaisse le
  `DocumentManager`. C'est cette séparation qui permet au `send()` du transport de réutiliser
  exactement le même encodage.

### Le binding est implicite, et c'est fragile

`config/services.yaml` ne déclare **aucun alias** Port → Implémentation. Symfony les crée seul :
lorsqu'une interface n'a **qu'une seule** implémentation enregistrée, il l'aliase automatiquement.

Conséquence à connaître avant d'ajouter une seconde implémentation d'un Port existant (un décorateur,
un cache, un double) : l'alias automatique disparaît et l'injection casse au moment de la compilation
du conteneur. Il faut alors déclarer l'alias explicitement. Ne pas en conclure qu'il faut aliaser
préventivement — le comportement par défaut est correct tant qu'il n'y a qu'une implémentation.

### Contrats internes à Infrastructure (≠ Ports)

Une interface dont **aucun** use case ni service applicatif n'est consommateur n'est pas un Port :
elle reste dans `src/Infrastructure/`, à côté de son implémentation.

| Contrat interne | Implémentation | Consommateurs |
|---|---|---|
| `Adapter\Uuid\UuidGeneratorInterface` | `RamseyUuidGenerator` | repositories Mongo |
| `Adapter\Cache\QueryCacheInterface` | `SymfonyTagAwareQueryCache` | les deux middlewares de cache |
`QueryCacheInterface` est un contrat interne **par test** : aucun use case ne consomme son adapter.
`CacheableQueryInterface`, côté Application, décrit ce qui est cachable — jamais où ni comment.

`ProductImageStorage` a une interface parce que les handlers de commande déposent puis, après un
commit réussi, effacent les fichiers. Auparavant le dépôt vivait dans
`MongoProductRepository::updateImage()` — un repository qui écrivait sur le disque, et un agrégat dont
l'image changeait sans qu'il le sache, donc sans pouvoir enregistrer d'événement.

Les images de produit reçoivent un nom aléatoire et une extension canonique dérivée du MIME vérifié ;
le stockage fixe les permissions à `0644`. Un nom appartient à un seul produit, donc l'ancienne image
peut être supprimée directement après le commit, sans comptage ni worker.

> Avant de créer une interface dans `src/Application/…/Port`, vérifier qu'elle est bien injectée par
> un handler ou un service applicatif. Sinon → `src/Infrastructure/`.

---

## Persistance MongoDB

### Contrat des repositories

- `save()` et `delete()` se contentent de `persist()` / `remove()` ;
- le flush unique est déclenché par `MongoTransactional::transactional()` ;
- **aucun `flush()` dans un repository.** L'ODM ne rend transactionnelles que les opérations d'un
  même flush : en ajouter un casserait l'atomicité sans faire échouer un seul test existant.

Détail et garde-fou : [`docs/src/Infrastructure/Persistence/Mongo/MongoTransactional.md`](../../docs/src/Infrastructure/Persistence/Mongo/MongoTransactional.md).

### Ce que la base ne fait plus pour nous

MongoDB n'a ni clé étrangère, ni cascade. La suppression d'une catégorie est donc autorisée par
l'agrégat uniquement si elle est vide et sans enfant ; le repository retire alors son seul document.
Gedmo Tree maintient le materialized path, le `level` et la propagation des descendants dans le flush
unique de `MongoTransactional`. Le parent est une `ReferenceOne` ODM stockée comme identifiant ; ne
pas réintroduire un champ persistant `parentId` en parallèle. L'invariant qui nécessite encore du
code explicite est :

- **le `nbProduct` dénormalisé** — maintenu par le **cas d'usage** (`increaseProductCount()` /
  `decreaseProductCount()`), jamais par le repository. Tout code qui crée des produits hors use case
  (un seed, une commande) doit l'incrémenter lui-même, sinon le premier `DELETE` échoue sur
  « Product count cannot be negative ».

### Index & contraintes

- Déclarés **dans le document** via les attributs `#[MongoDB\UniqueIndex]` / `#[MongoDB\Index]`,
  nommés explicitement (`category_title_uniq`, `product_category_idx`).
- **Aucune migration ne les pose** : `make db-index` (`doctrine:mongodb:schema:create --index`).
  C'est une étape manuelle, et elle fait partie de `make install`.
- Ce sont eux — et non les `findByTitle()` des handlers, qui sont des check-then-act — qui
  garantissent réellement l'unicité des titres et des slugs.

### Mapping Domain ↔ Document

- Documents ODM **≠** agrégats Domain, mappers dédiés (`CategoryMapper`, `ProductMapper`).
- Le mapper consomme des VOs Domain et appelle `reconstitute()` pour reconstruire l'agrégat **sans
  événements**, en préservant les timestamps Domain.
- Les documents sont exclus du conteneur (`config/services.yaml`) : ce sont des structures de données,
  pas des services.

---

## Domain Events : l'outbox rejoint le flush

Trois classes, trois responsabilités qu'il ne faut pas fondre :

| Classe | Emplacement | Rôle |
|---|---|---|
| `MongoDomainEventBus` | `Symfony/Messenger/Event/` | implémente le Port, encode, pose le `BusNameStamp` |
| `MongoOutboxTransport` | `Symfony/Messenger/Transport/` | traduit `OutboxRecord` ↔ `Envelope`, acquitte |
| `DomainEventOutbox` | `Persistence/Mongo/Outbox/` | **seul à connaître Doctrine** : `persist()`, `claim()`, `remove()` |

Le découpage n'est pas cosmétique : il fait tenir la règle « aucune référence à Doctrine hors
`Persistence/` ». Les deux classes `Symfony/` ne manipulent que des chaînes déjà sérialisées et des
`OutboxRecord`.

**La règle** : `DomainEventOutbox::enqueue()` fait `persist()` et **ne flushe pas**, donc la ligne
rejoint le flush unique de `MongoTransactional` et commite avec l'agrégat. Son pendant
`enqueueAndFlush()` engage tout de suite, et n'est appelé que par le `send()` du transport. Toute écriture émise par le pilote à côté de ce
flush — `insertOne()`, ou un `SenderInterface` qui écrit tout de suite — s'engagerait seule et
survivrait au rollback, **sans lever d'erreur**.

Ne pas transposer la logique d'un transport SQL : `doctrine://` rejoint la transaction ouverte parce
qu'il emprunte la connexion DBAL courante.

`enqueueAndFlush()` **flushe**, seule exception à la règle « seul `MongoTransactional` flushe ». Il ne sert qu'aux chemins de reprise, exécutés hors transaction métier : retry différé,
copie vers `failed_domain_events`, `messenger:failed:retry`. Le chemin nominal ne l'emprunte pas.

Le transport et son stamp sont **exclus de l'autowiring** (`config/services.yaml`) : leurs arguments
scalaires viennent de la DSN, via `MongoOutboxTransportFactory`.

Cycle complet, retry, idempotence : [`docs/domain_events.md`](../../docs/domain_events.md).

---

## CQRS : Messenger en adaptateur synchrone

`MessengerCommandBus` / `MessengerQueryBus` (`Symfony/Messenger/CQRS/`) exigent exactement un
`HandledStamp` et renvoient son résultat. Router un message CQRS vers un transport asynchrone
casserait ce contrat, puisqu'il n'y aurait plus de résultat immédiat.

`QueryCacheMiddleware` (sur `query.bus`) et `CacheInvalidationMiddleware` (sur `command.bus`) sont
actifs. Les brancher **séparément** est une erreur : le cache sans son invalidation servirait des
lectures périmées dès la première écriture.

L'invalidation vit dans un middleware et non dans une réaction du worker, pour purger après le commit
et **avant la réponse** : confiée au worker, elle arriverait après que le client a relu.

Le pool `cache.tag` utilise le Redis dédié du service et `cache.adapter.redis_tag_aware` : les
résultats et leurs invalidations sont communs aux replicas. Il ne contient que des données
recomputables et ne doit jamais être mutualisé avec `service_identity`.

Détail : [`docs/CQRS_messenger.md`](../../docs/CQRS_messenger.md) et
[`docs/query_cache.md`](../../docs/query_cache.md).

---

## Gestion du temps

`SystemClock` (`Adapter/Time/`) implémente `ClockInterface`.

`new DateTimeImmutable()` est **autorisé** dans Infrastructure — seule couche où l'horloge réelle est
instanciée : `SystemClock::now()`, event subscribers, commandes console. Domain et Application ne
doivent **jamais** l'instancier : ils reçoivent `$now` ou injectent `ClockInterface`.

---

## Fixtures

Un seul jeu, `dev`, sous `Symfony/DataFixtures/dev/` : 30 catégories sur 4 niveaux, 1000 produits.
`make fixtures` **purge la base**, d'où le groupe, qui n'est joué nulle part ailleurs.

- Enregistrées sous `when@dev` uniquement : elles dépendent de `fakerphp/faker`, dépendance de dev.
  Les déclarer inconditionnellement casserait la compilation du conteneur après un
  `composer install --no-dev`.
- Le bundle ODM fournit sa propre chaîne (`doctrine:mongodb:fixtures:load`, base
  `Doctrine\Bundle\MongoDBBundle\Fixture\Fixture`, tag `doctrine.fixture.odm.mongodb`) :
  `doctrine/doctrine-fixtures-bundle` est spécifique à l'ORM et **n'est pas installé**.
- **Une fixture écrit des documents, pas des agrégats** : elle court-circuite les repositories et doit
  donc poser elle-même le `nbProduct`. Gedmo Tree calcule `path` et `level` au flush depuis `parent`.
- Les titres sont tirés en `unique()` et `DataFixturesTrait::uniqueSlug()` suffixe les collisions de
  slug : sans cela la fixture échouerait une fois sur dix, au hasard du tirage.

**Il n'existe pas de fixtures `test`.** La suite pose son propre jeu — voir
[`docs/api_test_database.md`](../../docs/api_test_database.md) pour la raison et les contraintes de ce seed.

---

## Tests Infrastructure

Deux périmètres, jamais mélangés :

- **`tests/Infrastructure/Unit/`** — `PHPUnit\Framework\TestCase` uniquement. Aucun `bootKernel()`,
  aucun accès conteneur, aucune base : l'adapter est instancié à la main avec des doubles.
  Suites : `infra.symfony.command`, `infra.symfony.messenger`, `infra.api-platform.encoder`,
  `infra.api-platform.serializer`, `infra.adapter.catalog`.
- **`tests/Infrastructure/Integration/`** — `KernelTestCase`, via `MongoPersistenceTestCase` : ce
  qu'on ne peut vérifier qu'avec un vrai MongoDB (mapping, index, transactions). Suite :
  `infra.persist`.

> Si un test a besoin du kernel, il n'a rien à faire dans `Unit/`. Inversement, un test qui boote le
> kernel sans jamais s'en servir doit passer en `TestCase`.

### La remise à zéro n'utilise pas `drop()`

`MongoPersistenceTestCase::resetDatabase()` fait un `deleteMany()` et pose les index **une fois par
processus**. Mesure faite : `drop()` + `ensureIndexes()` coûte **155 ms** par test, `deleteMany()` en
coûte **0,8** — c'était 90 % du temps de la suite.

Les index doivent malgré tout exister : sans eux, les deux cas de rollback de `MongoTransactionalTest`
et ceux de `DomainEventOutboxTest` ne provoqueraient plus aucun rejet et **passeraient au vert sans
rien prouver**. C'est la raison pour laquelle `drop()` ne doit pas revenir « par sécurité ».

`resetDatabase()` purge aussi `domain_event_outbox` : sans cela, les événements d'un test se
compteraient dans le suivant.

---

## Checklist Infrastructure

- [ ] Chaque Port Application a une implémentation, ou une absence assumée et documentée.
- [ ] Aucun `flush()` hors `MongoTransactional` — sauf `MongoOutboxTransport::send()`, hors transaction.
- [ ] Les Domain Events sont écrits par `persist()`, jamais par le pilote, dans le chemin nominal.
- [ ] Le mapping Domain ↔ Document passe par un mapper dédié, avec `reconstitute()`.
- [ ] La suppression sans enfant est gardée par l'agrégat ; Gedmo Tree maintient `path` et `level`.
- [ ] `nbProduct` est maintenu explicitement par les cas d'usage.
- [ ] Index déclarés dans le document, nommés explicitement, posés par `make db-index`.
- [ ] Aucun code Infra ne dépend de `src/Presentation/`.
- [ ] Aucun broker ni base relationnelle ajoutés ; Redis reste dédié au cache applicatif partagé.
- [ ] `declare(strict_types=1);` dans tout nouveau fichier PHP.
