# Gestion des exceptions → statuts HTTP

Les exceptions métier du catalogue sont traduites en réponses HTTP par API Platform. Le Domain ne
connaît ni HTTP ni Symfony : la correspondance est définie uniquement dans
`config/packages/api_platform.yaml`, sous `api_platform.exception_to_status`.

## Mapping actuel

| Catégorie du Domain | Statut HTTP | Cas du catalogue |
|---|---:|---|
| `InvalidArgumentInterface` | 422 | UUID, slug, titres, sous-titres et descriptions invalides, montant invalide |
| `ConflictInterface` | 409 | titre de catégorie ou de produit déjà utilisé |
| `EntityNotFoundInterface` | 404 | catégorie ou produit absent |
| `DomainException` | 400 | fallback pour toute exception métier non catégorisée |

Les interfaces marqueur vivent dans `src/Domain/SharedKernel/Exception/`. Les exceptions du
catalogue héritent de `CatalogDomainException`, elle-même rattachée à `ShopDomainException` puis à
`DomainException`. Elles implémentent en plus l'interface qui décrit leur sens HTTP : l'héritage
reste propre au bounded context et la catégorie est transversale.

```text
DomainException                              fallback 400
        ↑
ShopDomainException
        ↑
CatalogDomainException
        ↑
CategoryNotFoundException ─ implements EntityNotFoundInterface → 404
```

## Ordre du mapping

API Platform parcourt `exception_to_status` dans l'ordre et retient le premier type compatible.
Les catégories sémantiques doivent donc précéder le fallback générique :

```yaml
exception_to_status:
    App\Domain\SharedKernel\Exception\InvalidArgumentInterface: 422
    App\Domain\SharedKernel\Exception\ConflictInterface: 409
    App\Domain\SharedKernel\Exception\EntityNotFoundInterface: 404
    App\Domain\SharedKernel\Exception\DomainException: 400
```

Une exception technique non mappée reste une erreur 500 : elle ne doit pas être artificiellement
transformée en erreur métier.

## Ajouter une exception métier

1. La placer sous `src/Domain/Catalog/.../Exception/` et la faire hériter de l'exception de bounded
   context appropriée.
2. Lui faire implémenter `InvalidArgumentInterface`, `ConflictInterface` ou
   `EntityNotFoundInterface` si l'une de ces catégories convient.
3. Pour un statut réellement hors catégorie, ajouter une ligne explicite avant le fallback dans
   `exception_to_status`.
4. Ajouter les tests Domain et fonctionnels de l'opération API concernée.

Exemple :

```php
final class ProductTitleAlreadyUsedException extends CatalogDomainException implements ConflictInterface
{
}
```

Cette exception devient automatiquement une réponse 409 ; aucun code HTTP n'est ajouté au Domain.

`config/routes/api_platform.yaml` ne gère pas les erreurs : il déclare seulement les routes API
Platform, sans préfixe `/api`, car ce service expose directement `/shop/...`.
