# CQRS synchrone avec Symfony Messenger

Ce service utilise Messenger de deux façons, qu'il ne faut pas confondre : comme **adaptateur
synchrone** pour les commandes et les queries du catalogue, décrit ici, et comme **consommateur
asynchrone** de l'outbox des Domain Events, décrit dans [`domain_events.md`](domain_events.md).

Les deux ne partagent que le composant. `command.bus` et `query.bus` n'ont pas de transport et
s'exécutent dans le processus HTTP ; `event.bus` n'est jamais dispatché depuis une requête, il est
alimenté par la collection `domain_event_outbox` et consommé par un worker.

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
| `event.bus` | réactions aux Domain Events | `mongodb-outbox://domain_events`, consommé par un worker |

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
command.bus : logging → exception unwrapping → invalidation de cache → handler
query.bus   : logging → exception unwrapping → cache de queries      → handler
event.bus   : middlewares Messenger par défaut, allow_no_handlers    → handler
```

`UnwrapHandlerFailedExceptionMiddleware` relance l'unique exception métier enveloppée par
Messenger dans `HandlerFailedException`. Les mappings d'erreur d'API Platform reçoivent ainsi les
exceptions du domaine (`CategoryNotFoundException`, etc.) plutôt qu'une exception d'infrastructure.

`QueryCacheMiddleware` sert les queries de collection et d'item depuis le pool `cache.tag`, et
`CacheInvalidationMiddleware` purge leurs tags dès qu'une commande publie un Domain Event. Les deux
ont été livrés **ensemble** : l'un sans l'autre servirait des lectures périmées dès la première
écriture. Voir [`query_cache.md`](query_cache.md).

`event.bus` n'a **pas** d'`UnwrapHandlerFailedExceptionMiddleware`, contrairement aux deux autres :
Messenger s'appuie sur `HandlerFailedException` pour arbitrer retry et échec définitif.

## Transactions des commandes

Les handlers de commande utilisent `TransactionalInterface`, alias de `MongoTransactional`. La
transaction ODM porte le **flush unique** effectué à la sortie du callback : les repositories font
seulement `persist()` ou `remove()` et ne doivent jamais flusher eux-mêmes.

MongoDB ne rend transactionnelles que les écritures d'un même flush. Ainsi, la création d'un
produit, l'incrément de `nbProduct` de sa catégorie et la ligne d'outbox de `ProductCreatedEvent`
sont validés ensemble — c'est pour cette dernière que `MongoDomainEventBus` `persist()` au lieu
d'écrire par le pilote. Les lectures dans le
callback restent hors transaction ; les index uniques MongoDB, posés par `make db-index`, assurent
l'unicité réelle face à la concurrence. Le replica set `rs0` de `docker-compose.yaml` est donc un
prérequis fonctionnel, pas une option de haute disponibilité.

## Diagnostic

À exécuter dans le conteneur `app` :

```bash
bin/console debug:messenger command.bus
bin/console debug:messenger query.bus
bin/console debug:messenger event.bus
```

Chaque Command ou Query applicative doit apparaître une seule fois, sur son bus respectif, avec la
méthode `handle`. Sur `event.bus`, `LogDomainEventHandler` apparaît une fois, typé sur
`DomainEventInterface`.
