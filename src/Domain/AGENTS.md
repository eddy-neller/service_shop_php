# Domain Layer – DDD & Règles Métier

> **But** : cœur métier pur, sans aucun détail technique.
> Couche `src/Domain/`. Règles transverses (stack, dépendances, commandes) : voir `AGENTS.md` racine.

---

## Périmètre

La couche Domain contient, par bounded context :

- Entités / Agrégats (`Model/`),
- Value Objects (`ValueObject/`),
- Domain Events (`Event/`),
- Exceptions métier (`Exception/`).

```text
src/Domain/
├── User/
│   ├── src/Model/
│   ├── src/ValueObject/
│   ├── src/Event/
│   └── src/Exception/
├── Catalog/
└── SharedKernel/
    └── src/Event/   # DomainEventInterface, DomainEventTrait, …
```

---

## Règles clés

- Domain utilise **uniquement** : PHP natif, SPL (`DateTimeImmutable`, exceptions standard), éventuellement `SharedKernel` (events).
- Domain **ne dépend jamais** de :
  - `App\Application\*`, `App\Infrastructure\*`, `App\Presentation\*`,
  - Symfony, Doctrine, API Platform, Ramsey, HTTP.
- Le Domain est l'**unique source de vérité pour la génération d'ID** (factory methods + VOs) ; l'Application ne fait que fournir les UUID via les Ports.

---

## Entités & Agrégats

- Aggregate Root : encapsule l'état métier, expose des **méthodes métier** (pas de `setXxx()` publics), ne contient aucun code technique.
- Constructeur **privé ou protégé** ; création via factory methods : `create()`, `register()`, `place()`, `reconstitute()`.
- Modifs d'état toujours via méthodes métier (`activate`, `cancel`, `changeEmail`, `addItem`, …) qui gèrent :
  - les invariants,
  - `updatedAt` (via `$this->touch($now);`),
  - les Domain Events.
- Les **décisions métier** vivent ici, pas dans l'Application. Ex. « quantité 0 ⇒ retirer la ligne » → méthode d'agrégat dédiée (`Cart::changeLineQuantity()`), jamais un `if` dans un handler.

---

## Value Objects

- `final`, propriétés `private` (souvent `readonly`), **immuables**.
- **Constructeur `private`** — sans exception. La création passe **toujours** par une factory nommée
  statique qui exprime l'intention et centralise la validation : `fromString`, `fromInt`, `fromArray`,
  `create`, `active`, `disabled`, `zero`, … `new Vo(...)` hors de la classe est **interdit** (impossible de
  contourner un invariant). Les witheurs (`with*`, `add`, `multiply`) et les factories appellent `new self(...)`
  en interne — seuls autorisés à instancier.
- Validation métier dans le constructeur privé / la factory (`fromString`, `fromInt`, …) : un état invalide
  ne doit jamais pouvoir exister, même via un statut « enum-like » (`fromInt` valide la borne).
- Comparaison par valeur : `equals(self $other): bool`.
- Utiliser des VOs pour : emails, montants, quantités, statuts, préférences, langues, tokens, limites, etc. — **pas de `string`/`int` bruts** pour ces concepts.
- Le VO encapsule **toute** l'arithmétique et les conversions de son concept :
  - ex. `Money` porte `fromEuros()` / `toEuros()` (conversion euros↔cents), `multiply()`, `add()`, `zero()` ;
  - ces opérations ne doivent **jamais** être réimplémentées à la main (`* 100`, `/ 100`, `round()`, devise en dur) ailleurs (handler ou service Application).

---

## Domain Events

- Représentent des faits métier : `OrderPlaced`, `OrderCancelled`, `UserRegistered`, …
- Vivent dans `src/Domain/<Context>/Event/` et implémentent `DomainEventInterface` du SharedKernel :
  `eventId()`, `aggregateId()`, `occurredOn()`, `eventName()`.
- **Obligatoire** : utiliser `DomainEventIdentityTrait` et assigner `$this->eventId = self::generateEventId();`
  dans le constructeur. Cet identifiant est la clé de déduplication côté consommateur — sans lui, une
  redélivrance rejouerait la réaction.
- `aggregateId()` retourne l'identifiant du sujet (`$this->userId->toString()`, `$this->orderId->toString()`) :
  c'est ce qui rend les journaux corrélables. **Jamais** une donnée personnelle.
- Les événements du contexte User implémentent `UserDomainEventInterface` (qui étend `DomainEventInterface`
  et expose `getUserId()`) : un consommateur peut ainsi réagir à tout fait touchant un compte sans
  énumérer les classes. Prévoir la même interface pour un futur contexte ayant le même besoin.
- L'Aggregate Root enregistre les events (`recordEvent()` de `DomainEventTrait`) et les expose (`releaseEvents()`).
- Un événement transporte des Value Objects, **jamais de secret** (token brut, mot de passe) : il est persisté
  en clair dans l'outbox et dans le transport `failed`. Il peut porter une donnée personnelle **si une
  réaction en a réellement besoin**, à condition qu'elle ne ressorte pas dans les logs. Critère : « quel
  consommateur la lit ? » — sans consommateur, la retirer de l'événement.

> Cycle de vie complet (publication transactionnelle, worker, idempotence) : [`docs/domain_events.md`](../docs/domain_events.md).

---

## Exceptions métier

**Deux axes orthogonaux, à respecter tous les deux :**

- **Axe bounded context** (héritage de classe) : chaque exception hérite de la base de son BC —
  `UserDomainException`, `CatalogDomainException`, `CustomerDomainException`, `CartDomainException`, … elles-mêmes
  sous `SharedKernel\Exception\DomainException`. Sert au regroupement par contexte (throws génériques,
  `expectException(<BC>DomainException::class)` des tests) et constitue le **fallback 400**.
- **Axe sémantique** (interface marqueur de `SharedKernel\Exception`, matchée par `is_a()`) : la feuille `implements`
  la catégorie qui décrit *ce qui s'est passé*, indépendamment du BC :
  - `InvalidArgumentInterface` → **422** (invariant violé, valeur refusée par un VO/agrégat),
  - `EntityNotFoundInterface` → **404** (agrégat/entité introuvable),
  - `ConflictInterface` → **409** (unicité violée, limite atteinte, ressource déjà existante).

Règles :

- Exceptions ciblées : une classe par cas métier (`ActivationLimitReachedException`,
  `CategoryTitleAlreadyUsedException`, …), message **métier** (pas technique).
- **Le mapping HTTP n'est plus déclaré par classe.** Une nouvelle exception qui entre dans une catégorie
  `implements` simplement l'interface correspondante — **rien à toucher dans le yaml**. Seul un status hors
  catégorie (401/403/423/429…) reçoit une ligne explicite dans `config/packages/api_platform.yaml`, placée
  **avant** le fallback `DomainException: 400`.
- Le Domain **ignore HTTP** : les interfaces portent une sémantique métier (« argument invalide »,
  « introuvable », « conflit »), jamais un code. Le lien code↔catégorie vit dans `exception_to_status`.
- Fonctionnement détaillé (ordre de résolution, `is_a()`, décision d'ajout) :
  [`docs/exception_handling.md`](../docs/exception_handling.md).

### `Exception` métier vs `Error` technique

Règle : le **type** du throwable encode sa nature. PHP distingue deux familles de `Throwable` — respecter cette sémantique.

- **Cas métier anticipé** (règle non respectée, entrée invalide, ressource introuvable, transition d'état interdite) → **exception métier** héritant de la hiérarchie Domain (`…DomainException` → 400 par défaut, ou feuille portant une interface sémantique `InvalidArgumentInterface`/`EntityNotFoundInterface`/`ConflictInterface` → 422/404/409). **Ne jamais** lever une exception SPL brute (`\InvalidArgumentException`, `\RuntimeException`, …) pour une règle métier : ces types ne sont **pas** mappés dans `exception_to_status`, donc l'API renvoie **500** au lieu du 4xx attendu — un vrai bug qui masque une erreur côté client.
- **Erreur technique / d'exécution** (invariant interne violé « qui ne devrait jamais arriver » avec un usage correct, misuse du code, violation de type) → **exception SPL brute la plus pertinente**, non mappée → **500 + log `critical`** (comportement voulu). Choisir le type le plus précis :
  - `\LogicException` et ses sous-classes (`\InvalidArgumentException`, `\LengthException`, `\OutOfRangeException`, …) pour un bug détectable à l'écriture du code (argument/état invalide produit en interne, garde de type).
  - `\RuntimeException` et ses sous-classes (`\UnexpectedValueException`, `\OutOfBoundsException`, …) pour un échec qui n'apparaît qu'à l'exécution (I/O, parsing d'une donnée stockée, service externe).
- **Ne jamais lever un `\Error` / `\TypeError` soi-même** : cette famille est réservée au moteur PHP. Un `catch (\Exception)` (middleware de bus, worker Messenger, wrapper CLI) ne l'attrape pas — préférer une exception SPL, au flux uniforme.
- Critère de tri : *cette entrée peut-elle venir d'un appel API valide ?* Oui → exception métier Domain (4xx). Non, état impossible sauf bug → exception SPL brute (500).
- Le Domain **ignore HTTP** : ne pas passer de code HTTP (`404`, `400`, …) au constructeur d'une exception Domain — le mapping vit dans `exception_to_status`, un code en dur y est mort et trompeur.

---

## Temps & timestamps

- Domain ne fait **jamais** `new \DateTimeImmutable()` en dur.
- Les méthodes métier et factory methods reçoivent toujours `DateTimeImmutable $now` en paramètre.
- `createdAt` : défini dans les factory methods, **immuable** (pas de `setCreatedAt()`).
- `updatedAt` : mis à jour dans chaque méthode métier qui modifie l'état, **toujours** via `$this->touch($now);` (jamais d'assignation directe ni de `setUpdatedAt()`).

```php
private function touch(\DateTimeImmutable $now): void
{
    $this->updatedAt = $now;
}
```

---

## Tests Domain

- Tests unitaires purs : pas de kernel Symfony, pas de DB, pas de services framework.
- Pattern : créer VOs/Agrégats → appeler méthodes métier → vérifier état, events, exceptions.
- Suites : `domain.catalog`, `domain.shared` (cf. `AGENTS.md` racine).
- Arborescence des tests : on **inverse** catégorie et contexte par rapport à `src/`
  (`src/<Context>/ValueObject/<Vo>.php` → `tests/Unit/ValueObject/<Context>/<Vo>Test.php`).
- Namespace des tests : `App\Tests\Domain\<Context>\Unit\...`, classe `final`, héritage direct de
  `PHPUnit\Framework\TestCase`.
- Les tests Domain sont des tests de comportement métier : aucune vérification de mapping Doctrine,
  sérialisation API, container Symfony, bus, repository ou adapter.
- Données de test stables : utiliser des UUID fixes en `private const string ...`, des dates fixes
  (`new DateTimeImmutable('2025-01-01 10:00:00')`) dès que l'assertion porte sur un timestamp.
- Les `new DateTimeImmutable()` sans date explicite sont acceptés seulement quand l'instant exact n'est
  pas asserté et sert uniquement à satisfaire la signature métier.

### Couverture garantie (garde-fou)

La présence d'un test par Entité/Agrégat (`Model/`) et par Value Object (`ValueObject/`) est
**vérifiée automatiquement**, pas seulement recommandée : `DomainTestCoverageTest`
(suite `domain.shared`) scanne tous les contextes et casse le build dès qu'un `Model`/`VO` est
livré sans test. Pendant de `HandlerConventionTest` côté Application.

- Tout nouveau `Model`/`VO` doit donc être accompagné de son test, sinon `domain.shared` échoue.
- Le code Domain pas encore branché peut être listé dans `DomainTestCoverageTest::EXCLUDED`
  (FQCN + commentaire) ; **retirer l'entrée dès qu'il est implémenté**.

### Pattern de test des Entités / Agrégats

Tout `Model` a un test couvrant les comportements publics de l'agrégat, pas ses détails internes :

1. **Factory de création** (`create`, `register`, `place`, …) :
   - état initial complet,
   - valeurs par défaut métier,
   - `createdAt` et `updatedAt` égaux au `$now` fourni,
   - event de création si le modèle en émet un.
2. **Factory de réhydratation** (`reconstitute`) quand elle existe :
   - restaure exactement l'état persisté,
   - conserve `createdAt` et `updatedAt`,
   - ne doit pas tester d'effet technique de persistance.
3. **Méthodes métier mutantes** :
   - vérifient l'état métier modifié,
   - vérifient que `updatedAt` vaut le `$now` passé,
   - vérifient que `createdAt` reste inchangé lorsque pertinent.
4. **Invariants et limites métier** :
   - un test dédié par règle rejetée,
   - `expectException(...)` avec l'exception Domain ciblée,
   - `expectExceptionMessage(...)` quand le message fait partie du contrat métier existant.
5. **Collections internes** :
   - tester les effets observables (`add`, fusion, retrait, vidage, limites),
   - ne pas exposer ni tester l'implémentation interne au-delà de l'ordre/du contenu retourné par l'API publique.

Les helpers privés (`createUser()`, `createProduct()`, `createCart()`, …) sont encouragés pour réduire
le bruit, à condition qu'ils construisent un objet valide via les factories publiques. Ils ne doivent pas
masquer la donnée importante du scénario : toute valeur qui explique le cas testé reste visible dans le test.

### Pattern de test des Domain Events

- Lorsqu'une méthode enregistre un event, le test vérifie au minimum :
  - le nombre d'events enregistrés,
  - la classe de l'event (`assertInstanceOf`),
  - les identifiants/VO portés par l'event,
  - `occurredOn()` égal au `$now` fourni,
  - `eventName()` quand il existe.
- Avant de tester une méthode qui suit une factory déjà événementielle, appeler `clearDomainEvents()` pour
  isoler l'event produit par l'action testée.
- Tester `releaseEvents()` / `clearDomainEvents()` uniquement si le comportement de vidage fait partie de
  l'API du modèle concerné ; sinon vérifier les events via `getDomainEvents()`.

### Pattern de test des Value Objects

Tout VO a un test couvrant **systématiquement** (cf. `Catalog`/`Customer`/`Ordering` comme références) :

1. **Construction valide** : `fromString()`/`fromInt()`/… renvoie la valeur via `toString()`/getter.
2. **Invariants** : un cas `expectException` + `expectExceptionMessage` **par règle** rejetée
   (vide, format invalide, longueur max, borne…). Le message asserté doit être le message métier exact.
3. **Égalité par valeur** : `equals()` à `true` (même valeur) **et** `false` (valeur différente).
4. **Cast en chaîne** : `(string) $vo` (méthode `testToStringCastsToString`) — `__toString()` n'est jamais laissé sans test.

Conventions de forme : `private const string UUID = …;` pour les identifiants UUID, une assertion par comportement,
nommage explicite des méthodes (`testFromStringThrowsWhenEmpty`, `testEqualsReturnsFalseForDifferentValue`, …).

Pour les VO non scalaires ou composites (`RoleSet`, `Preferences`, `Security`, `Money`, …), ajouter les cas
adaptés au comportement public :

- normalisation d'entrée (trim, uppercase, déduplication, valeurs par défaut, réindexation),
- conversions (`fromArray`, `toArray`, `jsonSerialize`, `fromInt`, `fromEuros`, …),
- immutabilité des méthodes `with*`, `add`, `multiply` : vérifier l'ancienne instance et la nouvelle,
- préservation des autres champs lors d'une copie modifiée,
- erreurs de type volontairement testées avec annotation locale (`@phpstan-ignore argument.type`) plutôt
  qu'en affaiblissant les signatures de production.

### Style d'assertions et de noms

- Méthodes de test nommées en `test<Comportement><Résultat>` :
  `testRenameUpdatesTitleSubtitleAndUpdatedAt`, `testFromStringRejectsInvalidUuid`,
  `testAddCreatesNewInstanceWithAddedRole`.
- Préférer `assertSame` pour les scalaires, tableaux et dates lorsque l'instance exacte est attendue.
- Préférer `equals()` pour comparer deux VOs métier lorsque la classe expose cette méthode.
- Grouper plusieurs assertions seulement si elles décrivent un même comportement métier cohérent ; sinon créer
  un test séparé.
- Les tests peuvent utiliser `ReflectionProperty` uniquement pour installer un état Domain difficile à atteindre
  autrement (ex. compteur déjà au maximum). Ne jamais s'en servir pour contourner une API publique qui couvre
  naturellement le scénario.

### Vérification avant livraison

- Modification dans `src/Domain/Catalog` : lancer `make unit-suite s=domain.catalog`.
- Modification dans `src/Domain/SharedKernel` ou ajout d'un `Model`/`ValueObject` : lancer
  `make unit-suite s=domain.shared`.
- Pour tout ajout de `Model` ou `ValueObject`, lancer aussi `domain.shared` afin de vérifier
  `DomainTestCoverageTest`.

---

## Checklist Domain

- [ ] Aucun `use App\Application\*`, `App\Infrastructure\*`, `App\Presentation\*`.
- [ ] Aucun import Symfony/Doctrine/API Platform/HTTP/Ramsey.
- [ ] Agrégats créés via factory methods (`create`, `register`, `place`, `reconstitute`).
- [ ] Value Objects immuables et validant leurs invariants ; **constructeur `private` + factory nommée** (aucun `new Vo(...)` hors classe) ; arithmétique/conversions encapsulées dans le VO.
- [ ] Test de VO complet : construction valide, un cas par invariant rejeté (message exact), `equals()` vrai/faux, cast `(string)`.
- [ ] Toute méthode métier sensible reçoit un `DateTimeImmutable $now`.
- [ ] `createdAt` immuable, `updatedAt` mis à jour via `$this->touch($now);`.
- [ ] Aucun `setXxx()` public sur les agrégats.
- [ ] Domain Events pour les changements importants.
- [ ] Les tests Domain tournent sans framework.
- [ ] Tout nouveau `Model`/`ValueObject` a son test au chemin attendu par `DomainTestCoverageTest`.
- [ ] Les tests d'agrégats couvrent création/réhydratation, transitions, exceptions, timestamps et events.
- [ ] Les dates assertées sont fixes et injectées explicitement.
