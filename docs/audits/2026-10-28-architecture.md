# Audit d'architecture — service_shop, 2026-10-28

> Instantané au commit `098e457` (« Name the /me security trait of this service », 2026-10-28).
> Non normatif : les règles font foi dans les `AGENTS.md` de couche. Produit par le skill
> `audit-archi` (`back_php/.claude/skills/`) ; précédent : aucun.

## En bref

Les garde-fous sont tous verts : règle de dépendance, forme tactique du Domain, convention CQRS,
purge du cache. Rien n'enfreint une règle écrite dans le code. Trois décisions restent à prendre :
le compteur de produits des catégories n'est protégé par aucun verrou optimiste, le sens des
dépendances entre les modules `Catalog`, `Customer` et `Ordering` n'est écrit nulle part, et le
garde-fou d'enregistrement des handlers d'identity n'a pas d'équivalent ici. La doc de
`MongoTransactional` ignore la traduction du conflit en 409.

## À corriger

- **`docs/src/Infrastructure/Persistence/Mongo/MongoTransactional.md` — ne décrit pas la traduction
  de `LockException` en `ConcurrentModificationException` (409).** Règle : `AGENTS.md`, « Domain
  Events : un outbox qui doit rejoindre le flush » (`docs/src/` reflète le code). Effet : la doc
  date de l'extraction (2026-08-12) ; la traduction est arrivée avec `Customer` et `Ordering`
  (`4f768c6`, 2026-08-14) et n'est décrite que dans le docblock de `MongoTransactional.php:35`.
  Remède : une section « Conflit de version → 409 » (skill `doc-archi`). (piste `doc-en-retard`)

## À trancher

- **`src/Infrastructure/Persistence/Mongo/Catalog/CategoryDocument.php:26` — le compteur
  `nbProduct` se perd sous concurrence.** `Category::increaseProductCount()` et
  `decreaseProductCount()` (`Category.php:133`, `:139`) décident sur le contenu du document, depuis
  `CreateProductByAdmin` (`:71`), `DeleteProductByAdmin` (`:50`) et `UpdateProductByAdmin`
  (`:136-138`) ; sans `#[MongoDB\Version]`, deux créations simultanées dans la même catégorie
  écrivent chacune N+1. La règle n'est écrite que dans le docblock de `MongoTransactional.php:38`,
  pas dans un `AGENTS.md`. Options :
  1. `#[MongoDB\Version]` sur `CategoryDocument` — le perdant reçoit un 409 et rejoue, comme pour
     `CustomerDocument` et `CartDocument` ;
  2. tolérer : écritures d'administration, rares, et `app:shop:nb-product` répare le compteur.
  Recommandation : 1, et écrire la règle dans `src/Infrastructure/AGENTS.md`, pour que le prochain
  document à compteur la trouve. (piste `document-sans-version`)
- **`src/Application/Ordering/` — le sens des dépendances entre modules n'est écrit nulle part.**
  `Ordering` importe `Domain\Catalog` (`ProductId`, `ProductNotFoundException`, 10 fichiers, dont
  `AddToCartCommandHandler.php`), `Domain\Customer` (`CustomerId`, 15 fichiers, dont
  `Port/CartRepositoryInterface.php`) et deux Ports de `Application\Catalog` (3 fichiers, dont
  `Service/CartItemFactory.php`). Aucun import dans l'autre sens : la dépendance est saine, mais
  rien ne la protège. Le service est **un** bounded context (`Shop`), ces imports ne sont pas
  interdits. Recommandation : écrire dans `src/Domain/AGENTS.md` que `Ordering` dépend de `Catalog`
  et `Customer` par identifiants et Ports de lecture, jamais l'inverse, et le tenir par une règle
  PHPat (skill `regle-dependance`). (piste `import-entre-contextes`)
- **`MessengerHandlerRegistrationTest` — absent ici, présent dans identity.** Il vérifie que chaque
  handler est enregistré une fois, sur le bon bus, et compte les handlers en dur pour forcer à
  constater chaque ajout ou retrait — c'est lui qui a confirmé la suppression d'un cas d'usage mort
  dans identity (!41). `HandlerConventionTest` couvre le nommage et le contrat, pas
  l'enregistrement effectif. Recommandation : le porter, en l'écrivant pour ce service (aucun code
  partagé). (piste `garde-fou-absent`)

## Tenu

| Règle | Preuve |
|---|---|
| Règle de dépendance | `regle-dependance.sh` : PHPat aucune violation, compléments rien à examiner |
| Forme tactique du Domain | `DomainConventionTest` conforme |
| Convention CQRS, test de chaque handler | `HandlerConventionTest` conforme |
| Cache purgé par les événements | `tags-cache.php` : chaque tag des queries cachées est émis par `DomainEventCacheTags` |
| Transaction et publication | `pistes.php` : aucune `ecriture-hors-transaction`, `publication-hors-transaction` ni `io-sous-transaction` |
| Un seul flush, celui de `MongoTransactional` | `pistes.php` : aucun `flush-hors-transactional` dans les repositories |
| Queries en lecture seule, handlers sans agrégat rendu | `pistes.php` : aucune `query-qui-ecrit` ni `handler-rend-le-domain` |
| Presentation sans décision, Ports consommés, cas d'usage appelés | `pistes.php` : aucune `decision-dans-presentation`, `port-sans-consommateur` ni `cas-d-usage-sans-appelant` |
| Verrou optimiste sur les documents qui décident sur leur contenu | `#[MongoDB\Version]` sur `CustomerDocument` (plafond d'adresses) et `CartDocument` (fusion de lignes) |
| Unicité des titres et slugs | index uniques sur `CategoryDocument.php:21-22` et `ProductDocument.php:21-22` |

Cas tolérés ou légitimes, déjà jugés :

- `lecture-hors-transaction`, `CreateCategoryByAdmin` — relecture après le flush, seule à voir
  l'arbre Gedmo (`revue-archi`, cas jugés).
- `document-sans-version`, `ProductDocument` — les écritures fixent leurs valeurs, l'unicité tient
  par index (idem).
- `decision-dans-handler`, `UpdateCategoryByAdmin`, `UpdateProductByAdmin`,
  `DisableCustomerForAccount` — unicité par repository, court-circuits (`ddd-tactique`, signaux
  tranchés).
- `exception-sans-categorie`, `CustomerWithoutAccountException` — 400 voulu (idem).
- `spl-dans-domain`, `Category` — états impossibles hors bug (idem).
- `primitive-metier`, `Address::$phone` — champ libre, seule règle une longueur (idem).

## Évolution depuis le précédent

Premier rapport : toutes les pistes sont nouvelles. Corrigés le même jour, avant l'audit : le Port
`ConfigInterface`, sans consommateur, et le trait `/me` mal nommé dans `src/Presentation/AGENTS.md`
(!27).

## Inventaire

| Contexte | Modele | VO | Evenements | Exceptions | Commandes | Queries | Ports | Read models |
|---|---|---|---|---|---|---|---|---|
| Catalog | 2 | 7 | 15 | 12 | 7 | 4 | 5 | 4 |
| Customer | 2 | 4 | 9 | 9 | 8 | 5 | 1 | 5 |
| Ordering | 2 | 4 | 7 | 3 | 4 | 1 | 1 | 2 |
| Shared | 0 | 3 | 3 | 8 | 0 | 0 | 5 | 1 |

Presentation : 6 ressources, 17 Processors, 9 Providers. Infrastructure : 76 fichiers.
Tests : Application 31, Domain 26, Infrastructure 35, Presentation 35.

Garde-fous : `DependencyRule`, `DomainConventionTest`, `DomainTestCoverageTest`,
`HandlerConventionTest`, `StateTestCoverageTest`.

<!-- empreinte audit-archi
decision-dans-handler	src/Application/Catalog/UseCase/Command/UpdateCategoryByAdmin/UpdateCategoryByAdminCommandHandler.php	legitime
decision-dans-handler	src/Application/Catalog/UseCase/Command/UpdateProductByAdmin/UpdateProductByAdminCommandHandler.php	legitime
decision-dans-handler	src/Application/Customer/UseCase/Command/DisableCustomerForAccount/DisableCustomerForAccountCommandHandler.php	legitime
doc-en-retard	docs/src/Infrastructure/Persistence/Mongo/MongoTransactional.md	a-corriger
document-sans-version	src/Infrastructure/Persistence/Mongo/Catalog/CategoryDocument.php	a-trancher
document-sans-version	src/Infrastructure/Persistence/Mongo/Catalog/ProductDocument.php	tolere
exception-sans-categorie	src/Domain/Customer/Exception/CustomerWithoutAccountException.php	tolere
garde-fou-absent	tests/**/MessengerHandlerRegistrationTest.php	a-trancher
import-entre-contextes	src/Application/Ordering/Port/CartRepositoryInterface.php	a-trancher
import-entre-contextes	src/Application/Ordering/Service/CartItemFactory.php	a-trancher
import-entre-contextes	src/Application/Ordering/UseCase/Command/AddToCart/AddToCartCommandHandler.php	a-trancher
lecture-hors-transaction	src/Application/Catalog/UseCase/Command/CreateCategoryByAdmin/CreateCategoryByAdminCommandHandler.php	tolere
primitive-metier	src/Domain/Customer/Model/Address.php	tolere
spl-dans-domain	src/Domain/Catalog/Model/Category.php	legitime
-->
