# Domain Events — état du jalon 2A

Les Domain Events ne sont pas encore actifs dans `service_shop`. Il n'existe aujourd'hui ni
implémentation de `DomainEventBusInterface`, ni transport Messenger, ni worker, ni collection
d'outbox. Le catalogue (`Catalog`) persiste ses commandes directement dans MongoDB via
`MongoTransactional`.

Les types du Shared Kernel — `DomainEventInterface`, `DomainEventIdentityTrait` et
`DomainEventTrait` — ainsi que le port `DomainEventBusInterface` sont présents pour préserver la
frontière de l'architecture et préparer le jalon 2, étape B. Ils ne doivent pas être confondus avec
une mécanique d'événements opérationnelle.

## Ce qui arrive à l'étape B

L'outbox du monolithe ne peut pas être reprise : elle utilise le transport Messenger `doctrine://`
et une base relationnelle, alors que les données de ce service appartiennent à MongoDB. L'étape B
devra introduire :

- une collection d'événements MongoDB ;
- un adaptateur de `DomainEventBusInterface` qui ajoute les événements au même `DocumentManager` ;
- un flush transactionnel unique avec l'agrégat dans `MongoTransactional` ;
- un transport Messenger et un worker propres au service ;
- un ledger d'idempotence MongoDB, dans le même store que l'effet qu'il protège.

L'atomicité recherchée est la suivante : une écriture de catalogue et les événements qu'elle libère
sont tous les deux commités, ou aucun ne l'est. Une publication HTTP, e-mail, Redis ou broker ne
doit jamais se produire dans le callback de `TransactionalInterface`.

## Règles à préserver en attendant

- Ne pas ajouter un transport `doctrine://`, une table PostgreSQL, Redis partagé ou un broker pour
  « activer » ces événements : le service doit continuer à démarrer de façon autonome.
- Ne pas appeler `releaseEvents()` dans les handlers tant qu'aucun adaptateur transactionnel ne les
  persiste ; cela viderait des événements sans les publier.
- Conserver les transactions courtes et le flush centralisé dans `MongoTransactional`. Les
  repositories ne doivent pas appeler `flush()`.

Le détail de la transaction ODM et de ses limites est documenté dans
[`CQRS_messenger.md`](CQRS_messenger.md) et dans `AGENTS.md`.
