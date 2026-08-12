# `ProductResource`

Source : [`src/Presentation/Shop/ApiResource/Catalog/ProductResource.php`](../../../../../../src/Presentation/Shop/ApiResource/Catalog/ProductResource.php).

`ProductResource` est le contrat HTTP des produits du catalogue. Ce n'est ni un document MongoDB ni un agregat de domaine : il decrit les routes API Platform, les representations JSON et les DTO d'entree, puis delegue chaque operation a un provider ou un processor. Les lectures sont publiques ; les ecritures necessitent un JWT valide avec `ROLE_ADMIN`.

## Routes et responsabilites

| Operation | Route | Acces | Composant Presentation | Message applicatif |
| --- | --- | --- | --- | --- |
| `GET` item | `/shop/products/{id}` | public | `ProductGetProvider` | `DisplayProductQuery` |
| `GET` collection | `/shop/products` | public | `ProductCollectionProvider` | `DisplayListProductQuery` |
| `POST` | `/shop/products` | `ROLE_ADMIN` | `ProductPostProcessor` | `CreateProductByAdminCommand` |
| `PATCH` | `/shop/products/{id}` | `ROLE_ADMIN` | `ProductPatchProcessor` | `UpdateProductByAdminCommand` |
| `DELETE` | `/shop/products/{id}` | `ROLE_ADMIN` | `ProductDeleteProcessor` | `DeleteProductByAdminCommand` |
| `POST` image | `/shop/products/{id}/image` | `ROLE_ADMIN` | `ProductImageProcessor` | `UpdateProductImageByAdminCommand` |

L'identifiant de route est un UUID RFC 4122, versions 1 a 5, avec un variant valide (`RouteRequirements::UUID`). La configuration API Platform traduit les erreurs metier : titre deja utilise en `409`, produit ou categorie absent en `404`, et donnees invalides en `422` ou `400` selon leur nature.

## Flux de donnees

```text
requete HTTP
    |
    +-- GET ------> Provider --> QueryBus --> use case --> port repository
    |                                                    --> ProductItem
    |                                      Presenter --> ProductResource --> JSON
    |
    +-- POST/PATCH -> DTO d'entree --> Processor --> CommandBus --> use case
    |                                                    --> ProductItem
    |                                      Presenter --> ProductResource --> JSON
    |
    +-- POST image -> multipart DTO --> Processor --> SymfonyFileAdapter --> CommandBus
    |                                                               --> ProductItem --> JSON
    |
    `-- DELETE --------------------> Processor --> CommandBus --> use case --> 204
```

Les providers et processors sont l'unique frontiere entre HTTP et l'application. L'integration ODM d'API Platform est desactivee : la ressource ne lit ni n'ecrit directement MongoDB. `ProductResourcePresenter` transforme le `ProductItem` retourne par le cas d'usage ; il resout l'URL publique de l'image et integre la categorie sous forme de resume.

## Contrat de lecture

Les groupes `shop_product:read` servent aux collections et aux items ; `shop_product:item:read` ajoute les champs de detail.

| Champ | Collection | Item | Notes |
| --- | --- | --- | --- |
| `id`, `title`, `price`, `slug`, `imageUrl`, `createdAt` | oui | oui | resume du produit |
| `subtitle`, `description`, `updatedAt` | non | oui | details du produit |
| `category` | oui | oui | resume de categorie ; champ reserve aux administrateurs |

Les deux lectures declarent un cache HTTP de 6 heures et un cache partage de 24 heures (`max_age: 21600`, `shared_max_age: 86400`). Le cache general varie notamment selon `Authorization`, ce qui isole les representations eventuellement influencees par les droits.

### Parametres de collection

Le provider lit les filtres bruts dans `$context['filters']`, normalise `page`, `itemsPerPage` et l'ordre, puis les transmet a `DisplayListProductQuery`. Les parametres documentes sont :

| Parametre | Role |
| --- | --- |
| `title`, `subtitle`, `description` | recherche textuelle |
| `category` | filtre par UUID de categorie |
| `page`, `itemsPerPage` | pagination client |
| `order[title]`, `order[category.title]`, `order[price]`, `order[createdAt]` | tri `asc` ou `desc` |

Seuls ces quatre champs sont acceptes pour le tri (`ProductRepositoryInterface::SORT_FIELDS`) ; une cle ou direction invalide est ignoree. Sans ordre valide, le contrat de ressource declare `createdAt DESC`. Le provider renseigne `_total_items` et `_total_pages` dans la requete afin que la pagination API Platform produise ses en-tetes.

Il n'y a volontairement ni `#[ApiFilter]` ni `stateOptions` : ces mecanismes etaient relies a l'ancienne integration Doctrine ORM et ne pilotent pas les providers manuels. Les parametres OpenAPI sont donc declares directement sur l'operation de collection.

## Contrat d'ecriture

Les DTO `ProductPostInput` et `ProductPatchInput` utilisent le groupe `shop_product:write`.

| Champ | `POST` | `PATCH` | Regle |
| --- | --- | --- | --- |
| `title` | obligatoire | optionnel | 2 a 100 caracteres |
| `subtitle` | obligatoire | optionnel | 2 a 150 caracteres |
| `description` | obligatoire | optionnel | 2 a 1 000 caracteres |
| `price` | obligatoire | optionnel | nombre positif ou nul |
| `category` | obligatoire | optionnel | IRI d'une `CategoryResource` ; seul son `id` est transmis a la commande |

Le `PATCH` declare `read: false`. API Platform ne tente donc pas de charger une entite avant le processor : celui-ci utilise l'UUID present dans `$uriVariables` et le DTO deserialise. La semantique est additive : `null` est indistinguable d'un champ omis, de sorte qu'il n'est pas possible de remettre explicitement une valeur a `null` avec cette operation.

Le `DELETE` ne lit ni ne deserialize de ressource (`read: false`, `output: false`) et repond `204`. La mise a jour du compteur denormalise de la categorie est assuree par le cas d'usage et sa transaction MongoDB ; elle ne doit pas etre deplacee dans la couche Presentation.

### Upload d'image

`POST /shop/products/{id}/image` attend `multipart/form-data`, avec un champ binaire `imageFile`. `ProductImageInput` impose un fichier PNG, GIF ou JPEG/PJPEG, de 10 Mo maximum, entre 200 et 2 000 pixels dans chaque dimension. Le processor adapte `UploadedFile` en `SymfonyFileAdapter` avant de dispatcher la commande : aucun objet HTTP Symfony ne traverse la frontiere Application.

## Points de vigilance lors d'une modification

- Toute nouvelle route doit choisir explicitement un provider ou processor et son autorisation ; ne pas reactiver le CRUD automatique d'API Platform.
- Faire evoluer ensemble les filtres declares dans OpenAPI, le provider, `ProductRepositoryInterface::SORT_FIELDS` et les tests fonctionnels.
- Ne pas exposer les documents ODM ou les agregats : le presenter maintient la frontiere entre Presentation et Application.
- Ne pas effectuer de `flush()` dans cette ressource ou ses states. Le flush unique de `MongoTransactional` preserve l'atomicite entre le produit et le compteur de sa categorie.
