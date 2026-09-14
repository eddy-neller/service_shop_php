# `DomainEventCacheTags`

`DomainEventCacheTags` traduit un `DomainEventInterface` en tags du cache de queries Redis à
invalider. Elle vit en Infrastructure, car elle connaît cette convention de nommage, mais ne dépend
ni de Symfony Cache ni de Redis : elle ne renvoie qu'une `list<string>`. `CatalogHttpCacheTags`
effectue la traduction distincte vers les IRIs `Cache-Tags` d'API Platform, consommés par Varnish.

## Place dans le cycle d'une commande

`CacheInvalidationMiddleware`, enregistré autour du handler sur `command.bus`, récupère les faits
publiés par la commande dans `PublishedDomainEventCollector`. Après le retour du handler — donc
après le commit de `MongoTransactional` — il demande les tags de chaque événement à cette classe,
les dédoublonne, puis appelle `QueryCacheInterface::invalidateTags()`.

```text
Commande
  -> handler : écrit les agrégats et publie les événements dans la transaction
  -> commit MongoDB
  -> CacheInvalidationMiddleware
       -> DomainEventCacheTags::forEvent()
       -> QueryCacheInterface::invalidateTags()
       -> CatalogHttpCacheTags::forEvent()
       -> PurgerInterface::purge() (BAN Varnish)
  -> réponse HTTP
```

Les deux invalidations ne passent pas par le worker d'outbox : elles doivent avoir lieu avant la
réponse pour préserver la lecture après écriture. Le worker conserve son rôle de consommation
asynchrone des événements.

Le middleware appelle également l'invalidation lorsqu'une commande lève une exception. Une partie
du travail peut déjà avoir été engagée ; il vaut alors mieux évincer une entrée que servir un état
éventuellement périmé. Le collecteur est toujours vidé afin qu'un worker Messenger ne propage pas
ses événements au message suivant.

## Contrat de classification

La classe se type sur les interfaces marqueurs de contexte, jamais sur les classes d'événements
concrètes. Ajouter un nouvel événement qui implémente le marqueur de son contexte conserve ainsi
l'invalidation sans modifier ce `match`.

| Marqueur | Tags retournés | Motivation |
|---|---|---|
| `CatalogDomainEventInterface` | `categories-collection`, `products-collection` | Les read models se citent : un produit expose sa catégorie et une catégorie expose `nbProduct`. |
| `CustomerDomainEventInterface` | `customer-<customerId>`, puis `customer-of-user-<userAccountId>` si présent | Les lectures peuvent cibler le client ou traduire le `sub` JWT vers un client. |
| `OrderingDomainEventInterface` | `cart-of-<ownerId>` | Un panier se lit par son propriétaire, jamais par son identifiant technique. |
| autre `DomainEventInterface` | aucun | Un contexte inconnu ne doit pas vider le cache par précaution. |

L'ordre des tags est intentionnel et couvert par les tests unitaires. Pour Customer, le tag du
client est toujours présent ; le tag lié au compte est omis pour un client créé sans compte. Le
filtrage réindexe la liste afin que l'adapter de cache reçoive bien une liste séquentielle.

## Granularité choisie

Un fait du catalogue évince systématiquement les deux collections. Une granularité par item serait
incomplète : renommer une catégorie périme les produits qui affichent son titre, et modifier un
produit peut périmer le compteur de produits d'une catégorie. Les vues d'item réutilisent donc ces
tags de collection.

`customer-of-user-<userAccountId>` est aujourd'hui consommé par `DisplayMyCustomerQuery`, qui est
mise en cache 24 heures. `customer-<customerId>` et `cart-of-<ownerId>` sont émis dès maintenant
pour rendre de futures queries cachables sans changer la convention d'invalidation. Aucune query de
panier ne doit toutefois être mise en cache : `CartItemFactory` relit le catalogue à chaque lecture,
notamment le prix courant.

## Évolutions et garde-fous

- Un nouvel événement d'un contexte existant doit implémenter son interface marqueur et exposer les
  identifiants contractuels de celle-ci.
- Un nouveau contexte cachable requiert un marqueur, un cas dans `forEvent()`, des tags déclarés par
  ses queries et des tests unitaires associés.
- Ne pas remplacer les tags catalogue par une invalidation d'item sans démontrer que toutes les
  dépendances croisées des read models sont couvertes.
- Ne pas déplacer cette logique vers un handler de worker : cela créerait une fenêtre de lecture
  périmée après une commande réussie.

Les cas de classification sont couverts par
`tests/Infrastructure/Unit/Adapter/Cache/DomainEventCacheTagsTest.php` et les traductions HTTP par
`CatalogHttpCacheTagsTest.php`. La coordination du commit, de la collecte et des deux purges est
couverte par le test du middleware. Pour le contrat global du cache de queries, voir
[`query_cache.md`](../../../../query_cache.md).
