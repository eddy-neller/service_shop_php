# CQRS synchrone avec Symfony Messenger

Ce service utilise Messenger uniquement comme adaptateur synchrone pour les commandes et les
queries du catalogue. Il n'y a actuellement ni transport asynchrone, ni worker, ni outbox active.

## Architecture

```text
Presentation (provider / processor)
        ↓
CommandBusInterface / QueryBusInterface              Application
        ↓
MessengerCommandBus / MessengerQueryBus              Infrastructure
        ↓
command.bus / query.bus                              Symfony Messenger
        ↓
*CommandHandler::handle() / *QueryHandler::handle()  Application
```

L'Application ne dépend pas de Symfony. Les adapters de bus, l'extraction du résultat et les
middlewares Messenger sont confinés à `src/Infrastructure/Messenger/CQRS/`.

## Bus configurés

`config/packages/messenger.yaml` déclare deux bus :

| Bus | Rôle | Transport |
|---|---|---|
| `command.bus` | commandes d'écriture | aucun : exécution dans le processus HTTP |
| `query.bus` | queries de lecture | aucun : exécution dans le processus HTTP |

Les adapters exigent exactement un `HandledStamp` et renvoient son résultat. Une commande dont
le handler retourne `void` produit donc légitimement `null`. Router par erreur un message CQRS vers
un transport casserait ce contrat, puisqu'il n'y aurait plus de résultat immédiat.

## Enregistrement des handlers

Les handlers implémentent `CommandHandlerInterface` ou `QueryHandlerInterface` et exposent une
méthode `handle()`. `config/services.yaml` les rattache automatiquement au bon bus :

```yaml
_instanceof:
    App\Application\Shared\CQRS\Command\CommandHandlerInterface:
        tags:
            - { name: messenger.message_handler, bus: command.bus, method: handle }
    App\Application\Shared\CQRS\Query\QueryHandlerInterface:
        tags:
            - { name: messenger.message_handler, bus: query.bus, method: handle }
```

La convention `FooCommand` → `FooCommandHandler` et `BarQuery` → `BarQueryHandler` est vérifiée
par les tests de présentation. `handle()` reste explicite pour les tests unitaires des use cases,
qui appellent directement le handler sans passer par Messenger.

## Middlewares

```text
command.bus : logging → exception unwrapping → middlewares Messenger → handler
query.bus   : logging → exception unwrapping → middlewares Messenger → handler
```

`UnwrapHandlerFailedExceptionMiddleware` relance l'unique exception métier enveloppée par
Messenger dans `HandlerFailedException`. Les mappings d'erreur d'API Platform reçoivent ainsi les
exceptions du domaine (`CategoryNotFoundException`, etc.) plutôt qu'une exception d'infrastructure.

Le middleware de cache de queries n'est volontairement pas enregistré. Les interfaces
`CacheableQueryInterface` et les métadonnées des listes de produits/catégories préparent l'étape B,
mais un cache sans invalidation pilotée par les Domain Events servirait des lectures périmées après
la première écriture. Voir [`redis_query_cache.md`](redis_query_cache.md).

## Transactions des commandes

Les handlers de commande utilisent `TransactionalInterface`, alias de `MongoTransactional`. La
transaction ODM porte le **flush unique** effectué à la sortie du callback : les repositories font
seulement `persist()` ou `remove()` et ne doivent jamais flusher eux-mêmes.

MongoDB ne rend transactionnelles que les écritures d'un même flush. Ainsi, la création d'un
produit et l'incrément de `nbProduct` de sa catégorie sont validés ensemble. Les lectures dans le
callback restent hors transaction ; les index uniques MongoDB, posés par `make db-index`, assurent
l'unicité réelle face à la concurrence. Le replica set `rs0` de `docker-compose.yaml` est donc un
prérequis fonctionnel, pas une option de haute disponibilité.

## Diagnostic

À exécuter dans le conteneur `app` :

```bash
bin/console debug:messenger command.bus
bin/console debug:messenger query.bus
```

Chaque Command ou Query applicative doit apparaître une seule fois, sur son bus respectif, avec la
méthode `handle`.
