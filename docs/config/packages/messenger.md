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

### `failed_domain_events` et `failed_async`

Deux files d'échec distinctes, **dans la base du service** et non dans le broker : un message en
échec définitif survit ainsi à une purge ou à une perte de RabbitMQ. Elles sont consommées à la main
(`messenger:failed:show`, `messenger:failed:retry`), jamais par un worker en attente.

---

## `routing`

```yaml
App\Domain\SharedKernel\Event\DomainEventInterface: domain_events
```

Le routage cible une **interface** : tout nouveau Domain Event part dans l'outbox sans qu'une ligne
soit à ajouter ici. Un message non listé n'est pas routé — il est traité en ligne, dans le processus
qui l'a dispatché.

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
