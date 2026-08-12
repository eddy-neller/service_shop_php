# `CategoryResource`

Source : [`src/Presentation/Shop/ApiResource/Catalog/CategoryResource.php`](../../../../../../src/Presentation/Shop/ApiResource/Catalog/CategoryResource.php).

`CategoryResource` est l'adaptateur HTTP du catalogue de categories. Ce n'est ni un document MongoDB ni un modele de domaine : il declare le contrat public d'API Platform, selectionne les DTO d'entree et relie chaque operation a un provider ou un processor. Les lectures sont publiques ; toute ecriture requiert `ROLE_ADMIN` et un JWT valide.

## Routes et responsabilites

| Operation | Route | Acces | Composant Presentation | Message applicatif |
| --- | --- | --- | --- | --- |
| `GET` item | `/shop/categories/{id}` | public | `CategoryGetProvider` | `DisplayCategoryQuery` |
| `GET` collection | `/shop/categories` | public | `CategoryCollectionProvider` | `DisplayListCategoryQuery` |
| `POST` | `/shop/categories` | `ROLE_ADMIN` | `CategoryPostProcessor` | `CreateCategoryByAdminCommand` |
| `PATCH` | `/shop/categories/{id}` | `ROLE_ADMIN` | `CategoryPatchProcessor` | `UpdateCategoryByAdminCommand` |
| `DELETE` | `/shop/categories/{id}` | `ROLE_ADMIN` | `CategoryDeleteProcessor` | `DeleteCategoryByAdminCommand` |

L'identifiant de route doit etre un UUID RFC 4122, versions 1 a 5, avec un variant valide (`RouteRequirements::UUID`). Les erreurs de domaine sont traduites par la configuration API Platform : conflit d'unicite en `409`, categorie absente en `404` et entree de domaine invalide en `422` ou `400` selon son type.

## Flux de donnees

```text
requete HTTP
    |
    +-- GET ------> Provider --> QueryBus --> use case --> port repository
    |                                                    --> CategoryItem
    |                                      Presenter --> CategoryResource --> JSON
    |
    +-- POST/PATCH -> DTO d'entree --> Processor --> CommandBus --> use case
    |                                                    --> CategoryItem
    |                                      Presenter --> CategoryResource --> JSON
    |
    `-- DELETE --------------------> Processor --> CommandBus --> use case --> 204
```

Les providers et processors constituent volontairement l'unique passerelle entre HTTP et l'application. L'integration Doctrine d'API Platform est desactivee : cette ressource ne peut donc pas etre lue ou ecrite directement depuis MongoDB par un provider par defaut. Le choix MongoDB reste confine a l'infrastructure derriere les ports applicatifs.

`CategoryResourcePresenter` effectue le mapping de `CategoryItem` vers la ressource. Il mappe `parent` et `children` seulement pour une vue detaillee, puis les laisse plats afin d'eviter toute recursion. Une collection utilise `toSummaryResource()`, donc elle ne charge ni parent ni enfants dans sa representation HTTP.

## Contrat de lecture

Les reponses de collection et d'item exposent les groupes de serialisation `shop_category:read` ; une vue d'item ajoute `shop_category:item:read`.

| Champ | Collection | Item | Notes |
| --- | --- | --- | --- |
| `id`, `title`, `nbProduct`, `slug`, `level`, `hasChildren`, `createdAt` | oui | oui | resume de categorie |
| `description`, `parent`, `children`, `updatedAt` | non | oui | details de la categorie |

`parent` et `children` sont limites a une profondeur de un (`MaxDepth(1)`). Cette limite, ainsi que `ENABLE_MAX_DEPTH`, protege les reponses contre les cycles ou une expansion involontaire de l'arbre.

Les deux lectures annoncent un cache HTTP de 6 heures et un cache partage de 24 heures (`max_age: 21600`, `shared_max_age: 86400`). Le cache general varie notamment selon `Authorization`, ce qui preserve la separation avec les reponses authentifiees, meme si ces routes sont publiques.

### Parametres de collection

Le provider recupere les filtres bruts dans `$context['filters']`, normalise les parametres de pagination et transmet le tout au use case. Les parametres documentes sont :

| Parametre | Role |
| --- | --- |
| `level` | filtre sur le niveau calcule de la categorie |
| `parent` | filtre par identifiant UUID du parent |
| `page`, `itemsPerPage` | pagination client |
| `order[title]`, `order[level]`, `order[nbProduct]`, `order[createdAt]` | tri `asc` ou `desc` |

Seuls ces quatre champs de tri sont conserves par le normaliseur (`CategoryRepositoryInterface::SORT_FIELDS`) ; une cle ou direction invalide est ignoree. En l'absence de tri valide, le use case applique `createdAt DESC`. Le provider renseigne `_total_items` et `_total_pages` dans la requete pour que la pagination API Platform puisse construire sa reponse.

Il n'y a volontairement ni `#[ApiFilter]` ni `stateOptions` : ces attributs etaient lies a l'ancienne integration Doctrine ORM et ne piloteraient pas les providers manuels.

## Contrat d'ecriture

Les DTO `CategoryPostInput` et `CategoryPatchInput` portent le groupe `shop_category:write` :

| Champ | `POST` | `PATCH` | Regle |
| --- | --- | --- | --- |
| `title` | obligatoire | optionnel | 2 a 100 caracteres |
| `description` | optionnel | optionnel | 2 a 1 000 caracteres lorsqu'elle est fournie |
| `parent` | optionnel | optionnel | IRI d'une `CategoryResource` ; seul son `id` est transmis a la commande |

Les processors ne manipulent pas les documents MongoDB. Ils extraient l'identifiant de la route, convertissent eventuellement l'IRI `parent` en `parentId`, puis dispatchent la commande. La reponse `POST` ou `PATCH` est reconstruite via le presenter a partir du read-model retourne par le cas d'usage.

`PATCH` ne lit pas l'element avant son processor (`read: false`). Sans cette option, API Platform tenterait d'utiliser son `ReadProvider` par defaut — absent depuis le retrait de l'ORM — et repondrait `404` avant l'execution de la commande. Le processor travaille a la place avec l'UUID de `$uriVariables` et le DTO deserialise.

La semantique actuelle du `PATCH` est additive : une valeur `null` est indistinguable d'un champ omis pour le cas d'usage. Il est donc possible d'ajouter ou de changer un parent, mais pas de remettre explicitement `description` ou `parent` a `null` via cette operation.

`DELETE` declare `output: false`, ne deserialize aucun modele (`read: false`) et renvoie `204`. La suppression du sous-arbre et des produits rattaches est une responsabilite du repository Mongo appele par le cas d'usage, execute dans sa transaction ; elle ne doit pas etre deplacee dans cette ressource.

## Points de vigilance lors d'une modification

- Ajouter une route implique de choisir explicitement son provider/processor et son niveau d'autorisation ; ne pas retablir le chemin CRUD automatique d'API Platform.
- Si le contrat de filtre evolue, mettre a jour ensemble la declaration OpenAPI, le provider, les champs autorises du port et les tests fonctionnels.
- Ne pas exposer directement les documents ODM ou les agregats : cette classe est le contrat de transport, et le presenter maintient la frontiere entre Presentation et Application.
- Les ecritures ne doivent jamais effectuer un `flush()` ici. La transaction et le flush unique appartiennent a `MongoTransactional`, afin de conserver l'atomicite entre les modifications de categorie et d'autres agregats du catalogue.
