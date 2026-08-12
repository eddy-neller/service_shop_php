# Infrastructure Layer – Adapters & Frameworks

> **But** : implémenter les Ports Application et encapsuler les frameworks.
> Couche `src/Infrastructure/`. Règles transverses : voir `AGENTS.md` racine.
>
> **Ce fichier décrit `service_shop`, pas le monolithe.** La persistance est MongoDB via Doctrine
> **ODM** ; il n'y a ni ORM, ni migration, ni clé étrangère, ni broker, ni Redis. Une règle reprise
> du monolithe et parlant de DQL, de `Paginator`, de `#[ORM\Index]` ou d'un `OneToMany` inverse ne
> s'applique pas ici — elle a été retirée volontairement.

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
| `Shared\Port\DomainEventBusInterface` | *aucune* | à écrire — étape B |
| `Catalog\Port\CategoryRepositoryInterface` | `MongoCategoryRepository` | `Persistence/Mongo/Catalog/` |
| `Catalog\Port\ProductRepositoryInterface` | `MongoProductRepository` | `Persistence/Mongo/Catalog/` |
| `Catalog\Port\ProductImageUrlResolverInterface` | `ProductImageUrlResolver` | `Adapter/Catalog/` |

Deux points contre-intuitifs dans ce tableau :

- **`FileInterface` est implémenté dans Presentation**, pas ici : `SymfonyFileAdapter` enveloppe un
  `UploadedFile` HTTP, il n'a de sens qu'au contact de la requête. Ce n'est pas une entorse à corriger.
- **`DomainEventBusInterface` n'a aucune implémentation.** Le port existe pour préserver la frontière,
  pas parce que le mécanisme tourne (cf. [`docs/domain_events.md`](../../docs/domain_events.md)).

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

`ProductImageStorage` (`Adapter/Catalog/`) n'a délibérément pas d'interface : un seul consommateur,
aucune variation prévue. Remplace VichUploader, qui était branché sur les événements de cycle de vie
de l'ORM.

> Avant de créer une interface dans `src/Application/…/Port`, vérifier qu'elle est bien injectée par
> un handler ou un service applicatif. Sinon → `src/Infrastructure/`.

---

## Persistance MongoDB

### Le contrat des repositories diffère du monolithe

- `save()` et `delete()` se contentent de `persist()` / `remove()` ;
- le flush unique est déclenché par `MongoTransactional::transactional()` ;
- **aucun `flush()` dans un repository.** L'ODM ne rend transactionnelles que les opérations d'un
  même flush : en ajouter un casserait l'atomicité sans faire échouer un seul test existant.

Détail et garde-fou : [`docs/src/Infrastructure/Persistence/Mongo/MongoTransactional.md`](../../docs/src/Infrastructure/Persistence/Mongo/MongoTransactional.md).

### Ce que la base ne fait plus pour nous

MongoDB n'a ni clé étrangère, ni cascade, ni nested set. Trois filets que la base posait côté
monolithe sont désormais du code explicite, et c'est là que se logent les régressions :

- **la suppression en cascade** — `MongoCategoryRepository::delete()` supprime le sous-arbre **puis**
  les produits, à la main ;
- **le `level` d'une catégorie** — calculé dans `save()`, et propagé à toute la descendance quand une
  catégorie change de parent (`shiftDescendantLevels()`) ;
- **le `nbProduct` dénormalisé** — maintenu par le **cas d'usage** (`increaseProductCount()` /
  `decreaseProductCount()`), jamais par le repository. Tout code qui crée des produits hors use case
  (un seed, une commande) doit l'incrémenter lui-même, sinon le premier `DELETE` échoue sur
  « Product count cannot be negative ».

### Les requêtes manuelles ne sont pas dans la transaction

`shiftDescendantLevels()` et les agrégations passent par le driver, pas par le flush : elles
**échappent** à `MongoTransactional`. Ne pas les compter dans un raisonnement d'atomicité, et ne pas
en introduire de nouvelles dans un chemin qui doit être atomique.

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

## Domain Events : rien n'est branché

**Il n'existe aujourd'hui ni implémentation de `DomainEventBusInterface`, ni transport, ni worker,
ni collection d'outbox, ni ledger d'idempotence.** Aucun agrégat n'appelle `recordEvent()`.

Ne pas « activer » ce qui traîne :

- les fichiers `docker/app/supervisor/conf.d/messenger-worker.conf` et la cible `make consume` sont
  repris du monolithe. Ils visent un transport AMQP et un transport `domain_events` adossé à Doctrine,
  dont aucun n'existe ici. Ils seront **réécrits**, pas rebranchés — ne pas les lire comme une spec ;
- ne pas ajouter de transport `doctrine://`, de table relationnelle, de Redis partagé ni de broker :
  le service doit continuer à démarrer seul ;
- ne pas appeler `releaseEvents()` dans un handler tant qu'aucun adaptateur transactionnel ne les
  persiste — cela viderait des événements sans les publier.

Ce que l'étape B doit construire, et pourquoi l'outbox du monolithe n'est pas reprenable (elle est
relationnelle, nos données sont dans Mongo) : [`docs/domain_events.md`](../../docs/domain_events.md).

La règle qui survivra à l'implémentation : une écriture de catalogue et les événements qu'elle libère
commitent ensemble ou pas du tout, et **aucune publication externe** (HTTP, e-mail, broker) ne doit
avoir lieu dans le callback de `TransactionalInterface`.

---

## CQRS : Messenger en adaptateur synchrone

`MessengerCommandBus` / `MessengerQueryBus` (`Symfony/Messenger/CQRS/`) exigent exactement un
`HandledStamp` et renvoient son résultat. Router un message CQRS vers un transport asynchrone
casserait ce contrat, puisqu'il n'y aurait plus de résultat immédiat.

Le cache de queries (`QueryCacheMiddleware`) et son pendant d'invalidation restent **absents**
jusqu'à l'étape B : l'invalidation est pilotée par les Domain Events, et un cache sans invalidation
renverrait des lectures périmées dès la première écriture. Les brancher séparément est une erreur.

Détail : [`docs/CQRS_messenger.md`](../../docs/CQRS_messenger.md).

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
  donc poser elle-même ce qu'ils calculent — le `level` et le `nbProduct`.
- Les titres sont tirés en `unique()` et `DataFixturesTrait::uniqueSlug()` suffixe les collisions de
  slug : sans cela la fixture échouerait une fois sur dix, au hasard du tirage.

**Il n'existe pas de fixtures `test`.** La suite pose son propre jeu — voir ci-dessous.

---

## Tests Infrastructure

Deux périmètres, jamais mélangés :

- **`tests/Infrastructure/Unit/`** — `PHPUnit\Framework\TestCase` uniquement. Aucun `bootKernel()`,
  aucun accès conteneur, aucune base : l'adapter est instancié à la main avec des doubles.
  Suites : `infra.symfony.command`, `infra.api-platform.encoder`, `infra.api-platform.serializer`.
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
ne provoqueraient plus aucun rejet et **passeraient au vert sans rien prouver**. C'est la raison pour
laquelle `drop()` ne doit pas revenir « par sécurité ».

---

## Checklist Infrastructure

- [ ] Chaque Port Application a une implémentation, ou une absence assumée et documentée.
- [ ] Aucun `flush()` hors `MongoTransactional`.
- [ ] Le mapping Domain ↔ Document passe par un mapper dédié, avec `reconstitute()`.
- [ ] Cascade, `level` et `nbProduct` maintenus explicitement — la base ne les gère pas.
- [ ] Index déclarés dans le document, nommés explicitement, posés par `make db-index`.
- [ ] Aucun code Infra ne dépend de `src/Presentation/`.
- [ ] Aucune dépendance ajoutée à un broker, un cache distribué ou une base relationnelle.
- [ ] `declare(strict_types=1);` dans tout nouveau fichier PHP.
