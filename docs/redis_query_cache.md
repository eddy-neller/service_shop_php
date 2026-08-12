# Cache de queries — non activé au jalon 2A

`service_shop` ne possède ni Redis, ni pool de cache distribué, ni middleware de cache de queries.
Les lectures du catalogue interrogent MongoDB à chaque requête. C'est volontaire : aucune
invalidation fiable ne peut exister avant les Domain Events du jalon 2, étape B.

## Ce qui est déjà présent

`CacheableQueryInterface` reste un contrat pur de l'Application. Il expose `cacheKey()`,
`cacheTtl()` et `cacheTags()` sans dépendre de Symfony ou de Redis. Seules les deux queries de
collection l'implémentent aujourd'hui :

| Query | Clé | Tags prévus | TTL prévu |
|---|---|---|---|
| `DisplayListProductQuery` | `product-list-<sha256(payload)>` | `products-collection` | 3 600 s |
| `DisplayListCategoryQuery` | `category-list-<sha256(payload)>` | `categories-collection` | 3 600 s |

Ces valeurs sont des métadonnées sans effet tant que `QueryCacheMiddleware` n'est pas enregistré
sur `query.bus`. Il n'y a pas de `cache.yaml`, de `REDIS_URL`, de service Redis, de cache Symfony
tag-aware, ni de commande de purge dans ce dépôt.

## Condition d'activation

Le cache et son invalidation doivent être livrés ensemble à l'étape B : les événements de création,
mise à jour et suppression de produit ou catégorie devront invalider les tags concernés après le
commit. Activer seulement le cache rendrait les listes obsolètes dès la première écriture.

Le cache futur doit rester local à ce service ou être justifié par un besoin métier explicite ; il
ne doit pas introduire un état partagé avec `service_identity`.

Pour le cache HTTP déjà annoncé aux clients sur les lectures publiques du catalogue, voir
[`varnish_cache.md`](varnish_cache.md).
