# Cache de queries

Les queries de collection et d'item du catalogue sont servies depuis un cache local, purgé par tags
dès qu'une écriture publie un Domain Event. Le cache et son invalidation ont été livrés **ensemble**
à l'étape B : activer le premier sans le second aurait rendu les lectures obsolètes dès la première
écriture.

## Ce qui est caché

`CacheableQueryInterface` reste un contrat pur de l'Application. Il expose `cacheKey()`,
`cacheTtl()` et `cacheTags()` sans dépendre de Symfony. Les queries de collection et d'item
l'implémentent :

| Query | Clé | Tags | TTL |
|---|---|---|---|
| `DisplayListProductQuery` | `product-list-<sha256(payload)>` | `products-collection` | 3 600 s |
| `DisplayListCategoryQuery` | `category-list-<sha256(payload)>` | `categories-collection` | 3 600 s |
| `DisplayProductQuery` | `product-item-<productId>` | `products-collection`, `categories-collection` | 3 600 s |
| `DisplayCategoryQuery` | `category-item-<categoryId>` | `products-collection`, `categories-collection` | 3 600 s |
| `DisplayMyCustomerQuery` | `customer-of-user-<userAccountId>` | `customer-of-user-<userAccountId>` | 86 400 s |

La clé est un hash du payload normalisé — page, `itemsPerPage`, filtres et tri, avec `ksort()` sur
les tableaux : deux requêtes équivalentes dont les paramètres arrivent dans un ordre différent
partagent bien la même entrée.

Les entrées d'item utilisent une clé par identifiant. Elles portent les deux tags de collection :
un `ProductItem` embarque sa catégorie et un `CategoryItem` porte un arbre ainsi que `nbProduct`.
L'invalidation reste donc volontairement globale au catalogue, plutôt que de risquer une entrée
d'item périmée avec une granularité d'événements incomplète.

## Le backend est Redis, dedie au service

```yaml
# config/packages/cache.yaml
framework:
    cache:
        prefix_seed: en_shop_php_service_shop
        app: cache.adapter.redis
        default_redis_provider: '%env(REDIS_URL)%'
        pools:
            cache.tag:
                adapter: cache.adapter.redis_tag_aware
```

Redis est **dédié à `service_shop`** et ne contient que des résultats de queries recomputables. Il
partage les entrées et les invalidations entre les replicas de `app`, sans aucun accès au Redis de
`service_identity`. `prefix_seed: en_shop_php_service_shop` isole en plus les clés Symfony Cache.

`cache.adapter.redis_tag_aware` n'est pas optionnel : il rend `invalidateTags()` visible depuis
toute replica et conserve l'injection de `TagAwareCacheInterface` dans
`SymfonyTagAwareQueryCache`.

En test, `app: cache.adapter.array`. Le navigateur de test reboote le noyau entre deux requêtes,
donc aucune entrée ne franchit une frontière de test.

## La lecture : `QueryCacheMiddleware`

Enregistré sur `query.bus`, après le logging et le déballage d'exceptions. Sur un hit, **aucun
handler ne s'exécute** : il n'y a donc pas de `HandledStamp` à lire, et le middleware en pose un
lui-même pour que `MessengerQueryBus` retrouve son résultat.

Une query qui n'implémente pas `CacheableQueryInterface` traverse le middleware sans rien coûter.

## L'invalidation : `CacheInvalidationMiddleware`

Enregistré sur `command.bus`, **autour** du handler. Il s'exécute donc après le `transactional()`
de celui-ci : le commit est acquis quand les tags sont purgés. Une invalidation avant commit serait
pire que tardive — un lecteur concurrent recacherait l'état d'avant l'écriture.

C'est aussi pourquoi l'invalidation **ne passe pas par une réaction du worker**. Celui-ci se
réveille quelques dizaines de millisecondes après la réponse HTTP ; le client qui relit juste après
son écriture verrait sa propre modification manquante. Le `PublishedDomainEventCollector` donne au
middleware la liste des faits survenus sans rien retirer à l'outbox.

Sur échec, la purge a lieu quand même : une commande qui lève a pu committer une partie de son
travail. Le collecteur est vidé dans tous les cas — un worker traite des messages en série, aucun
événement ne doit fuir vers le suivant.

## Quels tags, et pourquoi les deux

`DomainEventCacheTags` purge **`categories-collection` et `products-collection`** pour tout fait du
catalogue. Ce n'est pas de la paresse : les deux read models se citent l'un l'autre.

- `ProductItem` embarque le titre de sa catégorie → renommer une catégorie périme la liste des
  produits ;
- `CategoryItem` expose `nbProduct` → créer ou supprimer un produit périme la liste des catégories.

Purger une seule des deux laisserait la moitié des lectures fausses.

Les queries d'item réutilisent les tags de collection. Aucun tag d'item supplémentaire n'est donc
nécessaire : chaque écriture du catalogue les purge avec les listes.

La classe s'appuie sur le marqueur `CatalogDomainEventInterface` plutôt que sur une liste de douze
classes. C'est la raison d'être de cette interface — sans elle, l'oubli d'un événement dans le
`match` ne se verrait qu'à la lecture périmée. Chaque contexte suit la même règle : on type sur son
marqueur, jamais sur ses événements un à un.

### `Customer` : le tag qui répare un trou hérité

`DisplayMyCustomerQuery` traduit le `sub` du jeton en `customerId`. Elle est rejouée **à chaque
requête `/me`**, d'où un TTL de 24 h justifié par l'immutabilité de l'association.

Sans invalidation, un client fraîchement provisionné resterait introuvable pendant une journée.
`Customer` et `Address` émettent donc des événements : `customer-of-user-{id}` est purgé dès qu'un
fait du contexte survient.

Les événements portent aussi `customer-{id}`, qui n'a pas encore de lecteur. Purger un tag inutilisé
ne coûte rien, et rendre une query cachable plus tard devient une ligne plutôt qu'une enquête.

### `Ordering` : rien n'est caché, et c'est délibéré

**`DisplayMyCartQuery` ne doit pas devenir cachable.** `CartItemFactory` relit prix, titre et image
dans le catalogue à chaque affichage — le panier ne fige rien. Un `CartItem` mis en cache servirait
un tarif périmé dès le premier changement de prix, et l'invalider correctement supposerait qu'un
`ProductRepricedEvent` purge **tous** les paniers : un éventail sans tag borné.

Le tag `cart-of-{ownerId}` est néanmoins émis, pour la même raison que `customer-{id}`.

## Vérification

Les lignes `doctrine.DEBUG: MongoDB command` de `var/log/dev.log` rendent le cache observable :

```bash
reads() { docker compose exec -T app sh -c 'grep -c "MongoDB command: {\"find\":\"category\"" var/log/dev.log'; }

A=$(reads); curl -s -o /dev/null 'localhost:20910/api/shop/categories?page=1&itemsPerPage=3'
B=$(reads); curl -s -o /dev/null 'localhost:20910/api/shop/categories?page=1&itemsPerPage=3'
C=$(reads)
# B-A > 0  (miss)      C-B == 0  (hit)
```

Une écriture entre les deux relectures doit faire repasser le compteur à une valeur non nulle.

Pour le cache HTTP annoncé aux clients sur les lectures publiques, voir
[`varnish_cache.md`](varnish_cache.md) — il est indépendant de celui-ci et n'est toujours pas
invalidé.
