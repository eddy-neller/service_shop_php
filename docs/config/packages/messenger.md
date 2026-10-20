# `config/packages/messenger.yaml` — service_shop

> Chaque option du fichier, et **pourquoi** elle a cette valeur ici. Le fichier de config reste la
> source de vérité ; ce document explique ce qu'un `git diff` ne dira jamais.
>
> Vue d'ensemble des bus et du découpage CQRS : [`../../CQRS_messenger.md`](../../CQRS_messenger.md).
> Outbox MongoDB, transport maison et déduplication : [`../../domain_events.md`](../../domain_events.md).

---

## `default_bus: command.bus`

Le bus utilisé quand un service type-hinte `MessageBusInterface` sans préciser lequel. Les deux
autres sont visés explicitement. Sans ce réglage, Symfony refuse de démarrer dès qu'il y a plus d'un
bus.

## `buses`

### `command.bus` — écritures

| Middleware | Rôle |
|---|---|
| `CommandLoggingMiddleware` | journalise chaque commande |
| `UnwrapHandlerFailedExceptionMiddleware` | Messenger emballe toute exception du handler dans `HandlerFailedException` ; la couche Presentation attend l'exception métier d'origine pour la mapper en statut HTTP |
| `CacheInvalidationMiddleware` | purge les tags du cache de queries |

**L'ordre est significatif** : un middleware entoure tous ceux qui le suivent. `CacheInvalidation`
est en dernier, donc il s'exécute **après** le `transactional()` du handler : les tags sont purgés
une fois le commit acquis, et avant que la réponse parte.

### `query.bus` — lectures

Même schéma, `QueryCacheMiddleware` en dernier : il sert la lecture depuis Redis, ou la calcule puis
la stocke.

### `event.bus` — réactions aux Domain Events

```yaml
default_middleware:
  allow_no_handlers: true
```

- **`allow_no_handlers: true`** : un fait métier sans réaction dédiée ne doit pas faire échouer le
  worker. On peut donc émettre un événement avant d'écrire ce qui l'exploitera.
- **Pas de `UnwrapHandlerFailedExceptionMiddleware`**, contrairement aux deux autres bus : Messenger
  s'appuie sur `HandlerFailedException` pour arbitrer retry et échec définitif. La déballer
  priverait la stratégie de retry de ce sur quoi elle décide.
- **Pas de `doctrine_ping_connection` / `doctrine_close_connection`** — contrairement à
  `service_identity`. Ces middlewares rouvrent une connexion DBAL restée ouverte des heures dans un
  worker ; le pilote MongoDB gère lui-même son pool et rétablit la connexion.

Il n'y a **pas d'`async.bus`** ici, contrairement à `service_identity` : aucun message n'est encore
routé vers `async`. Le bus arrivera avec le premier message qui le justifie.

Ce bus porte **deux sortes de messages**, et `allow_no_handlers` sert les deux : les Domain Events de
ce service, lus dans son outbox, et les **événements d'intégration** reçus de `service_identity` par
SQS. Les seconds n'y arrivent pas par un dispatch mais par un transport, et c'est leur sérialiseur
qui pose le `BusNameStamp('event.bus')` — sans lui, le `RoutableMessageBus` du worker retomberait sur
`command.bus`, où aucun handler ne répond.

---

## `failure_transport: failed_domain_events`

Réglage **global** : cible par défaut de `messenger:failed:show` et `messenger:failed:retry` sans
`--transport`. Pour l'autre file : `--transport=failed_async`.

**Ce réglage est facultatif.** Sans lui *et* sans `failure_transport` par transport, un message qui a
épuisé ses tentatives est simplement supprimé, avec une ligne de log : rien ne le conserve, rien
n'est rejouable.

---

## `transports`

### `domain_events` — outbox transactionnel

```yaml
dsn: 'mongodb-outbox://domain_events'
```

Transport **écrit à la main** (`MongoOutboxTransport`, `MongoOutboxTransportFactory`, adossés à
`DomainEventOutbox`) : aucun transport livré ne convient. `doctrine://` suppose une base
relationnelle, `amqp://` publierait hors de la transaction MongoDB, `redis://` détournerait le Redis
du cache de queries. Surtout, aucun ne sait inscrire le message **dans la transaction de l'agrégat**.

La DSN ne porte qu'un **nom de file logique**, jamais de coordonnées de serveur : la connexion est
celle de l'ODM. L'outbox et sa file d'échec partagent la collection `domain_event_outbox` et ne
different que par le champ `queueName`.

Le chemin nominal n'émet pas de `send()` : `MongoDomainEventBus` écrit le document via l'unité de
travail de l'ODM, donc dans le flush qui commite l'agrégat. Ce transport n'est là que pour le
**consommer**, et pour les chemins de reprise (retry différé, rejeu depuis la file d'échec).

#### `options.redeliver_timeout: 3600`

Un message réservé depuis plus d'une heure est repris par un autre worker. C'est ce qui rattrape un
worker tué en plein traitement — et ce qui rend la livraison **au-moins-une-fois** plutôt
qu'au-plus-une-fois. Corollaire : les handlers doivent être idempotents (déduplication par
`eventId`, voir `domain_events.md`).

#### `retry_strategy`

```yaml
max_retries: 6 · delay: 10000 · multiplier: 2 · max_delay: 300000 · jitter: 0.1
```

Attente doublée à chaque échec, plafonnée à 5 min : 10 s, 20 s, 40 s, 80 s, 160 s, 300 s — **environ
10 minutes**. `jitter: 0.1` fait varier chaque délai de ±10 %, pour que plusieurs workers ne
réessaient pas à la même seconde après une panne commune. Au-delà, le message part dans
`failed_domain_events`.

### `async` — messages asynchrones, RabbitMQ interne

```yaml
dsn: '%env(MESSENGER_TRANSPORT_DSN)%'   # amqp://…@rabbitmq:5672/%2f/messages
```

Broker **interne à ce service**, jamais partagé avec `service_identity` : le trafic entre services
passe par SNS/SQS. **Aucun message n'y est encore routé** — le transport et son worker sont en place
avant leurs consommateurs.

Retry plus long que pour les Domain Events : 30 s à 15 min, **environ 31 minutes**. Une dépendance
externe indisponible se rétablit souvent en dizaines de minutes.

En environnement `test`, `.env.test` fixe la variable à `in-memory://` : ni la suite ni la CI ne
démarrent de broker.

### `user_events` — événements d'intégration reçus de `service_identity`

```yaml
dsn: '%env(USER_EVENTS_TRANSPORT_DSN)%'   # sqs://aws-local:4566/shop-user-events?…
serializer: App\Infrastructure\Symfony\Messenger\Event\Integration\UserIntegrationEventSerializer
failure_transport: failed_user_events
```

**Le seul transport dont le contenu vienne d'un autre service.** Tout le reste en découle :

- **`serializer`** dédié, parce que le corps est un contrat JSON et non un message Messenger. Il
  traduit les types connus, acquitte ce qu'il ne sait pas lire, et refuse ce qui viole le contrat —
  détail dans [`../../integration_events.md`](../../integration_events.md).
- **`auto_setup=false`** dans le DSN : la file et son abonnement appartiennent à la brique
  `aws_local` en développement, à l'IaC en production. Les créer ici demanderait à ce service un
  droit IAM de création de file, pour une ressource qu'il ne possède pas.
- **Retry de 10 s à 5 min**, comme l'outbox. Le renvoi se fait **dans la file**, avec un
  `DelaySeconds` que **SQS plafonne à 900 s silencieusement** (`min(900, $delay)` dans le
  `Connection` du bridge) : un `max_delay` plus grand ne lèverait rien, il serait ignoré.
- **Pas de `visibility_timeout`** dans le DSN : celui de la file s'applique (30 s). Un handler plus
  long que ce délai se verrait livrer le même message deux fois — ici il dispatche une commande et
  écrit deux documents.
- **`wait_time`** vaut 20 s par défaut : le worker fait du *long polling*, donc une requête toutes
  les 20 s plutôt qu'une boucle de sondage.

Seul le conteneur `worker` rejoint le réseau `en_shop_php_bus` (cf. `docker-compose.override.yaml`).
Conséquence à connaître : **`messenger:stats` sans argument échoue depuis `app`**, qui ne peut pas
joindre SQS. C'est le cloisonnement qui fonctionne, pas un bug — lancer la commande depuis `worker`,
ou lui passer les transports locaux.

En environnement `test`, `.env.test` fixe la variable à `in-memory://` : aucune suite, et donc
aucun job de CI, ne dépend de LocalStack ni d'un jeton.

### `failed_domain_events`, `failed_async` et `failed_user_events`

Trois files d'échec distinctes, **dans la base du service** et non dans le broker : un message en
échec définitif survit ainsi à une purge ou à une perte de RabbitMQ. Elles sont consommées à la main
(`messenger:failed:show`, `messenger:failed:retry`), jamais par un worker en attente.

`failed_user_events` reste locale pour la même raison, doublée d'une autre : renvoyer un message
épuisé dans SQS le ferait reconsommer en boucle, par n'importe quel worker. La file d'échec d'un
message venu du bus appartient au service qui n'a pas su le traiter.

Elles ne reçoivent que les échecs de **handler**. Un corps qui viole le contrat échoue plus tôt, au
décodage, et suit un chemin tout autre — voir
[`../../integration_events.md`](../../integration_events.md).

---

## `routing`

```yaml
App\Domain\SharedKernel\Event\DomainEventInterface: domain_events
```

Le routage cible une **interface** : tout nouveau Domain Event part dans l'outbox sans qu'une ligne
soit à ajouter ici. Un message non listé n'est pas routé — il est traité en ligne, dans le processus
qui l'a dispatché.

Les événements d'intégration n'y figurent pas, et n'ont pas à y figurer : le routage dit où **envoyer**
un message dispatché, alors que ceux-là ne sont jamais dispatchés par ce service — ils arrivent par
leur transport. Les y ajouter ferait republier dans SQS un message qu'on vient d'en recevoir.

---

## Pourquoi il n'y a pas de `when@test`

`service_identity` remplace son transport `domain_events` par `sync://` en test. **Ne pas recopier ce
bloc ici** : les tests d'intégration de ce service exercent le transport d'outbox lui-même —
réservation, ack, redélivrance après `redeliver_timeout`, envoi différé
(`tests/Infrastructure/Integration/Persistence/DomainEventOutboxTest.php`).

Avec `sync://`, la suite tombe en erreur de deux façons : `You cannot receive messages from the
Messenger SyncTransport` (un transport `sync` ne sait pas recevoir), et `No handler for message …`
(l'événement est traité immédiatement au lieu d'être écrit dans l'outbox). Vérifié.

Le transport `async` n'a pas besoin d'être neutralisé ici non plus : `.env.test` s'en charge.
