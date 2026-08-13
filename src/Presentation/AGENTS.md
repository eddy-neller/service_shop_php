# Presentation Layer – API / HTTP

> **But** : exposer l'API, valider, sécuriser et transformer les données.
> Couche `src/Presentation/`. Règles transverses : voir `AGENTS.md` racine.

---

## Rôle & dépendances

- Gère : ressources API Platform, DTOs d'entrée (Input), Processors / Providers, Presenters, validators, sécurité.
- Peut dépendre de :
  - `CommandBusInterface`, `QueryBusInterface`, DTOs Application (Commands/Queries/Outputs),
  - Domain uniquement pour les **constantes publiques** de Value Objects,
  - Symfony (validation, sécurité, sérialisation), API Platform.
- Ne doit **pas** dépendre de : repositories Doctrine, services `src/Infrastructure/*` (hashers, FS, …), implémentations concrètes des Ports.
  - **Exception** : `stateOptions: new Options(entityClass: ...)` dans `#[ApiResource]` peut référencer une entité Doctrine — couplage imposé par l'ORM bridge d'API Platform, acceptable **uniquement** pour ce paramètre `entityClass`.

---

## Flux typique

**Écriture** (POST/PUT/PATCH/DELETE) :
```
HTTP Request → Input DTO (validation) → Processor → Command → CommandBus → Handler → Domain/Ports → Output → Resource
```

**Lecture** (GET/collection) :
```
HTTP Request → Provider → Query → QueryBus → Handler → Read model → Presenter → Resource
```

---

## Structure recommandée

```text
src/Presentation/
├── User/
│   ├── ApiResource/   # Endpoints API Platform
│   ├── Dto/           # DTOs d'entrée (Input + validation)
│   ├── State/         # Processors / Providers (CQRS côté API)
│   ├── Presenter/     # Domain/Output → Resource
│   ├── Security/      # Traits & helpers de sécurité
│   └── Validator/     # Validateurs personnalisés Symfony
└── Shared/
    ├── Adapter/       # SymfonyFileAdapter → FileInterface
    └── State/         # Providers/Processors génériques
```

---

## Providers / Processors (State)

- Valider le type de `$data` (ou la présence des `$uriVariables`) et lever `LogicException(PresentationErrorCode::INVALID_INPUT->value)` si incohérent.
- Construire un `...Command` / `...Query` et dispatcher via les Buses (`CommandBusInterface` / `QueryBusInterface`) — **jamais** d'appel direct à `handle()`.
- Convertir les outputs Domain en ressources via un Presenter (ex. `UserResourcePresenter`).
- Presentation ne crée ni n'injecte de Handlers.
- La logique de **rendu** (mapping/formatage) se fait ici (Presenter), **pas** dans un handler Application.

---

## Validation & Sécurité

- Validation dans les DTOs Presentation (`Assert\*`, validators custom) — côté HTTP uniquement, **pas de logique métier**.
- Les Value Objects Domain ne sont autorisés dans Presentation **que pour leurs constantes publiques** : rôles dans les expressions de sécurité, choix Symfony (`Assert\Choice`) ou valeurs par défaut API. Presentation ne construit, ne type ni ne transporte jamais une instance de VO Domain (`new`, `from*()`, propriété/paramètre/retour typé VO).
- Les Inputs et les `Command`/`Query` restent scalaires (sauf exceptions transverses explicitement admises, comme `Pagination` et `FileInterface`) ; la conversion scalaire → VO et la validation des invariants métier se font dans le handler Application.
- Sécurité : `security` / `security_post_denormalize` sur les opérations API Platform ; `Security` Symfony dans les Processors/Providers si besoin.
- Endpoints `/me` : utiliser `UserMeSecurityTrait` (garantit 401/403 correct, entry point JWT) — ne pas lever d'exception HTTP directe.
- Rôles centralisés via `RoleSet` (ex. `RoleSet::ROLE_ADMIN`) dans les expressions `security`.

---

## API Platform

- `shortName` sur `#[ApiResource]`, `name` stable sur chaque `Operation`.
- UUID : `App\Presentation\RouteRequirements::UUID` pour les paramètres `{id}`.
- **Variable d'URI ≠ identifiant de la ressource** : si l'`uriTemplate` a une variable qui ne correspond pas à l'identifiant (ex. `/cart/items/{productId}` sur `ShopCart` identifié par `id`), API Platform génère par défaut une `uriVariable` nommée `id` → `InvalidIdentifierException` → **404 « Invalid uri variables »**. Déclarer alors explicitement : `uriVariables: ['productId' => new Link(fromClass: SelfResource::class, identifiers: ['productId'])]`. La propriété (`productId`) n'existant pas sur la ressource, aucune transformation n'est tentée et la valeur brute (string) arrive dans le Processor/Provider. Ajouter aussi `read: false` quand l'opération n'a ni `provider` ni `stateOptions` (cible résolue par le Processor, ex. via le client courant).
- Endpoints sécurisés : `security` + OpenAPI `security: [['ApiKeyAuth' => []]]`.
- Pagination : `PaginatedCollectionProvider` → attributs Request `_total_items` / `_total_pages` → `PaginationHeaderListener` produit `X-Total-Count` / `X-Total-Pages`. **Ne pas recalculer/poser manuellement** ces headers.
- Pas d'endpoints hors API Platform si `ApiResource` + `Provider/Processor` suffit.

---

## Sérialisation & groupes

- Convention `snake_case` basée sur `shortName` : `user:read`, `send_mail:write`.
- Admin-only : groupe `{shortName}:admin` (ajouté dynamiquement par `AdminGroup` si `ROLE_ADMIN`).
- Ne pas créer de groupes ad-hoc non liés au `shortName`/opération.

---

## Uploads & fichiers (multipart)

- Déclarer `inputFormats: ['multipart' => ['multipart/form-data']]` et documenter le `RequestBody` OpenAPI (`format: binary`).
- Désérialisation via `MultipartDecoder` + `UploadedFileDenormalizer`.
- URLs fichiers : s'appuyer sur la couche de normalisation / upload Vich, sans calcul d'URL à la main dans les ressources.
- Adapter `File|UploadedFile` → `FileInterface` via `SymfonyFileAdapter` **avant** d'appeler Application — ne jamais faire transiter `UploadedFile` dans Application/Domain.

---

## Tests Presentation

Suites : `pres.state.catalog`, `pres.state.shared` (garde-fou `StateTestCoverageTest`) ; API (exécutables seulement si la stack Docker tourne, cf. `AGENTS.md` racine) : `api.catalog.category`, `api.catalog.product`.

L'intégration Doctrine d'API Platform étant coupée dans ce service, **tous** les providers et processors sont écrits à la main : `pres.state.shared` couvre donc l'intégralité du chemin HTTP de lecture et d'écriture, pas un résidu.

- Ne jamais modifier `tests/Presentation/Api/BaseTest.php` pour faire passer un test API spécifique. Ce helper est transverse. Le faire si demande explicite de refactor global de `BaseTest`.
- Tests API : ne pas utiliser `ApiTestCase::findIriBy()` pour résoudre l'IRI d'une fixture quand les `ApiResource` Presentation sont séparées des entités Doctrine (`stateOptions: entityClass`). API Platform reçoit alors l'entité Doctrine, qui n'est pas une ressource exposée, et peut générer une IRI Skolem (`/.well-known/genid/...`). Résoudre l'entité avec `getInstance(...)`, asserter son type, puis construire l'IRI attendue depuis la route API réelle (`self::URL_API_OPE . '/' . $entity->getId()->toString()`). `findIriByHttp()` reste réservé aux cas où la valeur recherchée dépend réellement du rendu HTTP, notamment les champs traduits.

### Conventions de test (à suivre pour tout nouveau test)

Tout nouveau test de State **doit** reproduire les conventions des tests existants (`State/Shop/Customer`, `State/Shop/Catalog`, `State/Shop/Ordering`). Ne pas réinventer un autre style.

- **Test unitaire pur** : `final class …Test extends PHPUnit\Framework\TestCase` (pas de `KernelTestCase`/conteneur). Namespace = miroir de `src/` (cf. règle d'arborescence ci-dessous).
- **Doubles** :
  - **Mocker** les Buses (`CommandBusInterface`, `QueryBusInterface`) et l'`Operation`.
  - **Instancier les vrais collaborateurs** `CurrentCustomerResolver` et les `Presenter` (ne **pas** les mocker) : ils font partie du comportement à vérifier.
  - `Security` mocké/stub ; utiliser `CustomerUserTrait::createUser($uuid)` pour les endpoints `/me`.
  - `createMock(...)` quand on pose des attentes ; `createStub(...)` sur les chemins négatifs où le collaborateur ne doit pas être sollicité.
  - Verrou systématique dans `setUp()` : `$this->operation->expects($this->never())->method('getName');`.
- **Assertions sur le dispatch** : via `willReturnCallback`, vérifier dans la closure (1) `assertInstanceOf(…Command|…Query::class, $arg)`, (2) l'égalité des Value Objects avec `->equals(…)` (jamais `assertSame` sur deux VOs), (3) `assertSame` sur les scalaires. Quand un même Bus reçoit plusieurs messages (ex. résolution du client + requête métier), brancher sur `instanceof` et `exactly(N)`.
- **Cas couverts par State** : au minimum le **chemin nominal** (résultat = bonne `Resource` + champs clés mappés) **et** chaque **chemin d'erreur** levant `LogicException(PresentationErrorCode::INVALID_INPUT->value)` (`expectException` + `expectExceptionMessage`). Sur les chemins d'erreur, asserter que les Buses **ne sont pas** appelés (`expects($this->never())->method('dispatch')`).
- **Garde d'`uriVariable` identifiante** : valider l'identifiant brut avec `!is_string($raw) || '' === $raw` (rejette à la fois le non-string **et** la chaîne vide), puis le réutiliser pour construire le VO. Tester les **deux** rejets : variable absente (`[]` → `null`) et chaîne vide (`['productId' => '']`). Ne pas se contenter d'un seul `!is_string(...)`, qui laisse passer `''`.
- **Données de test** : UUID littéraux fixes et lisibles ; construire les modèles Domain via `reconstitute(...)` (arguments nommés) et les read models via leur constructeur (arguments nommés). Factoriser la création d'un agrégat dans une méthode privée `create…()` du test (cf. `createAddress`, `createCart`).
- **Retour `void`** (Delete/Clear) : appeler `process(...)` sans capturer le retour ni `assertNull` (PHPStan rejette `assertNull` sur `void`) ; la couverture vient des attentes sur les mocks (`expects(...)`) et des assertions dans les callbacks. Pour un Processor retournant `?object`, en revanche, `assertNull($result)` reste valide.

### Couverture garantie (garde-fou)

La présence d'un test par **State** (Provider/Processor API Platform) est **vérifiée automatiquement** :
`StateTestCoverageTest` (suite `pres.state.shared`) scanne `src/Presentation/**/State` et casse le build
dès qu'un Provider/Processor est livré sans test.

- Tout nouveau Provider/Processor doit donc être accompagné de son test, sinon `pres.state.shared` échoue.
- Les **Presenters ne sont pas garde-fous** : ils sont exercés indirectement par les tests des States qui les consomment.
- L'arborescence des tests **reflète `src/`**, en remontant le segment `State` :
  `src/<Context>/State/<rest>/<Name>.php` → `tests/Unit/State/<Context>/<rest>/<Name>Test.php`
  (ex. `Catalog/State/Category/CategoryGetProvider` → `State/Catalog/Category/CategoryGetProviderTest`).
- Le code pas encore branché peut être listé dans `StateTestCoverageTest::EXCLUDED` (FQCN + commentaire) ;
  **retirer l'entrée dès qu'il est implémenté**.

---

## Checklist Presentation

- [ ] Aucune dépendance vers les repos / services de `src/Infrastructure/`.
- [ ] Communication avec Application **uniquement** via `CommandBusInterface` / `QueryBusInterface`.
- [ ] Input HTTP → Input DTO → Command/Query — pas de Domain direct dans les endpoints.
- [ ] Output Application/Domain → Presenter → Resource API.
- [ ] Chaque State (Provider/Processor) a son test (garde-fou `StateTestCoverageTest`, suite `pres.state.shared`), conforme aux **conventions de test** (Buses mockés, Resolver/Presenter réels, chemins nominal + erreur).
- [ ] Validation & sécurité gérées ici, pas dans Application/Infra.
