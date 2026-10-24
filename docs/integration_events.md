# Événements d'intégration : ce que le shop reçoit de `service_identity`

> Côté réception uniquement. Les Domain Events **de** ce service, son outbox et son worker :
> [`domain_events.md`](domain_events.md). La déduplication :
> [`src/Infrastructure/Persistence/Mongo/Inbox/Inbox.md`](src/Infrastructure/Persistence/Mongo/Inbox/Inbox.md).
> Les options du transport, ligne par ligne :
> [`config/packages/messenger.md`](config/packages/messenger.md).
>
> Sources : [`src/Infrastructure/Symfony/Messenger/Event/Integration/`](../src/Infrastructure/Symfony/Messenger/Event/Integration/).

## Le trou que ça rebranche

Depuis le jalon 3, un compte créé dans `service_identity` n'avait **plus de client** ici : le
provisionnement passait par un port dont l'unique adaptateur journalisait. Ce document décrit la
moitié **consommateur** de son remplacement. L'autre moitié — la publication SNS côté émetteur —
n'existe pas encore : rien n'arrive donc dans la file en dehors d'une publication à la main.

## Le contrat, version 1

```json
{ "id": "<uuid>", "type": "identity.user.registered", "version": 1,
  "occurredAt": "2026-09-15T12:00:00+00:00", "data": { "userAccountId": "<uuid>" } }
```

| Type | Réaction ici |
|---|---|
| `identity.user.registered` | provisionner le client du compte |
| `identity.user.created_by_admin` | la même — le shop a besoin du même client |
| `identity.user.deleted` | désactiver le client, ou poser une pierre tombale |

**Aucune donnée personnelle n'y circule** : seul l'identifiant du compte. Le shop ne connaît ni
l'e-mail ni le nom — il n'en a pas besoin, et un bus est un endroit durable.

`id` est frappé par l'émetteur et reste identique à chaque redélivrance : c'est la clé de
déduplication de l'inbox. Le régénérer à la réception ferait passer chaque doublon pour un fait
nouveau.

Le contrat n'est écrit nulle part en PHP — **aucune classe n'est partagée entre les deux services**.
Il est décrit par la documentation de l'émetteur, et tenu ici par les fixtures JSON de
`UserIntegrationEventSerializerTest`.

## Ce que ce service garantit à l'émetteur

Trois tolérances, et ce sont elles qui permettent à `service_identity` de déployer **sans nous
prévenir**. Les durcir, c'est reprendre cette liberté :

| L'émetteur fait… | Ce service… | Testé par |
|---|---|---|
| ajoute un champ au contrat | **l'ignore** et traite l'événement | `testItIgnoresFieldsItDoesNotKnow` |
| publie un type de plus | journalise un `warning` et **acquitte** | `testItTurnsAnUnknownTypeIntoAnAcknowledgedMessage` |
| passe en `version: 2` | journalise et **acquitte**, sans rien deviner | `testItTurnsALaterContractVersionIntoAnAcknowledgedMessage` |

Corollaire côté émetteur, écrit dans son `UserIntegrationEvent` : **un ajout de champ n'incrémente
pas `version`.** L'incrémenter nous ferait acquitter sans agir — le provisionnement s'arrêterait en
silence. `version` est réservée à ce qui casse un lecteur existant, et dans ce cas les consommateurs
se livrent d'abord.

> **Ce que rien ne vérifie.** L'émetteur décrit le même contrat de son côté, dans
> `UserIntegrationEventTest`. **Aucun test ne compare les deux rives** : les dépôts sont séparés, et
> changer la forme en mettant à jour le test de l'autre dépôt laisse les deux suites vertes. Seule
> la vérification de bout en bout, plus bas, attrape la rupture. Un vrai test *consumer-driven* —
> ces fixtures publiées, vérifiées par la CI du producteur — reste à écrire, et le deviendra
> nécessaire au deuxième consommateur.

## Le chemin complet d'un message

```text
service_identity
      │ publie (SNS, topic identity-user-events)
      ▼
   SNS ──▶ SQS shop-user-events          RawMessageDelivery : le corps arrive tel quel
      │
      ▼  worker : messenger:consume user_events
UserIntegrationEventSerializer           contrat JSON ──▶ message + BusNameStamp('event.bus')
      │
      ▼  event.bus
ProvisionCustomerOnUserCreatedHandler    hasProcessed ? ──▶ dispatch ──▶ markProcessedAndFlush
      │
      ▼  command.bus
ProvisionCustomerForAccountCommandHandler   transactional : client + Domain Events (outbox)
```

Deux propriétés portent tout le reste, et aucune ne se voit à la lecture du résultat.

### 1. Le handler traduit, il ne décide pas

Il transforme un fait de l'émetteur en commande de ce service, et rien d'autre : pas de lecture, pas
de règle, **pas de transaction**. Les cas d'usage sont déjà écrits pour être **commutatifs et
idempotents** — un client déjà rattaché arrête le provisionnement, et une suppression arrivée avant
la création pose une pierre tombale que le provisionnement tardif respecte.

> **Ne jamais ouvrir un `transactional()` autour du dispatch.** Le cas d'usage ouvre et valide la
> sienne ; une transaction englobante verrait son `flush()` commiter d'abord, et l'atomicité serait
> perdue **sans qu'aucune erreur n'apparaisse**.

### 2. L'inbox est marquée après l'effet

La transaction appartenant au cas d'usage, le handler ne peut pas y entrer son marquage : il utilise
donc `markProcessedAndFlush()`, **après** le dispatch. La garantie est l'au-moins-une-fois
dédoublonné — une panne entre le commit et le marquage fait rejouer la commande, que sa convergence
rend inoffensive. L'ordre inverse échangerait ce doublon contre une perte définitive.

C'est aussi pourquoi la convergence des cas d'usage n'est pas un luxe : les rendre non convergents
sans revoir ce choix ferait dupliquer un rejeu, et l'inbox ne l'arrêterait pas.

## Trois issues au décodage, et aucune ne perd le message

| Entrée | Issue | Trace |
|---|---|---|
| Type connu, version 1 | message d'intégration, handler dédié | logs du worker |
| Type inconnu, ou version > 1 | `UnsupportedIntegrationEvent` → **journalisé puis acquitté** | `warning` applicatif |
| JSON invalide, champ manquant ou mal typé | `UndecodableIntegrationEvent` → **rejeté sans retry, conservé** dans `failed_user_events` | `critical` « Removing from transport », corps complet dans la file d'échec |

Le deuxième cas est ce qui rend un topic SNS utilisable : l'émetteur peut publier un type de plus, ou
une version de plus, sans attendre le déploiement de ses abonnés. Échouer là-dessus remplirait la
file d'échec à chaque événement qui ne nous concerne pas.

### Un corps invalide est conservé, plus perdu

Le sérialiseur ne lève plus sur un corps qui viole le contrat : il rend un
`UndecodableIntegrationEvent`, qui porte le **corps complet** et la raison du refus. Son handler,
`RejectUndecodableIntegrationEventHandler`, le rejette par `UnrecoverableMessageHandlingException` :
pas de retry, Messenger le range directement dans `failed_user_events`, puis le message SQS est
acquitté. Le worker continue.

**Avant**, vérifié et contre-intuitif : une exception levée au décodage était attrapée par
`AmazonSqsReceiver`, qui **supprimait le message de la file** puis la relançait, et le worker mourait
(`Worker::run()` ne protège pas `$receiver->get()`). Le message ne partait ni en file d'échec, qui ne
reçoit que les échecs de handler, ni en DLQ, la redélivrance n'ayant pas lieu. La seule trace était
l'exception, avec un extrait de 500 caractères du corps.

**La reprise est le chemin ordinaire.** Le handler relit le corps avec le code **courant** à chaque
passage. Si le refus venait de notre lecture du contrat — le cas le plus probable —, il suffit de
la corriger puis de lancer `messenger:failed:retry --transport=failed_user_events` : le fait est
traité comme s'il venait d'arriver, inbox comprise. S'il est toujours illisible, il retourne dans
`failed_user_events` — grâce à `KeepFailedReplayInFailureTransportListener` : sans lui, Messenger
l'envoyait dans `failed_domain_events`, d'où un second rejeu raté l'aurait **supprimé**. Un corps réellement faux chez l'émetteur reste là, lisible par
`messenger:failed:show --transport=failed_user_events -vv`, pour être compris et republié.

Seul le **contrat** est traité ainsi. Un renvoi de retry illisible, écrit par ce service lui-même,
lève toujours : ce n'est pas l'émetteur qui s'est trompé.

Un échec **de handler** — MongoDB indisponible, conflit de verrou — suit le chemin normal : retry
différé de 10 s à 5 min, puis `failed_user_events`, dans la base de ce service.

## Deux pièges du transport, payés d'avance

- **Ne jamais faire implémenter `DomainEventInterface` à un message d'intégration.** Ce ne sont pas
  des événements de ce domaine, et la conséquence serait silencieuse mais totale :
  `LogDomainEventHandler` déclare `sign: true`, donc Messenger exige une signature HMAC de
  `kernel.secret` pour tout message de ce type. `service_identity` n'a pas notre `APP_SECRET` —
  chaque message serait refusé à la lecture, donc supprimé de la file. Pour la même raison, **pas de
  `sign: true` sur les handlers d'intégration**.
- **`encode()` délègue au sérialiseur standard**, parce que c'est lui qui écrit les renvois de retry.
  Les stamps voyagent alors dans les en-têtes, dont le `RedeliveryStamp` qui porte le compte
  d'essais : le perdre rendrait chaque redélivrance indistinguable d'une première livraison, donc le
  retry infini. `decode()` reconnaît ces renvois à l'en-tête `type`, qui porte un **nom de classe** —
  là où un message venu de SNS porte au mieux l'attribut `type` du contrat. D'où la discrimination
  par `class_exists()`, et non par simple présence.

## Exploitation

```bash
make consume-user-events                 # dépiler à la main (brique `aws_local` démarrée requise)
docker compose exec worker bin/console messenger:stats          # depuis `worker`, pas `app`
docker compose exec app bin/console messenger:failed:show --transport=failed_user_events -vv   # corps compris
docker compose exec app bin/console messenger:failed:retry --transport=failed_user_events       # relu avec le code courant
```

**`messenger:stats` sans argument échoue depuis `app`** : seul le `worker` rejoint le réseau du bus,
donc seul lui peut joindre SQS. C'est le cloisonnement qui fonctionne, pas une panne — lancer la
commande depuis `worker`, ou lui passer les transports locaux.

**Brique éteinte, le consommateur reste en vie et attend.** `BusOutageTolerantTransport`, qui
décore le transport `user_events`, reconnaît un bus injoignable (réseau, 5xx, throttling),
journalise un `warning`, attend — 1 s, doublé à chaque échec, plafonné à 30 s — puis rend « aucun
message ». Le worker ne meurt pas : la file est reprise au plus 30 s après le retour du bus, quelle
qu'ait été la durée de la panne. Le service, lui, continue de répondre : `/health` et l'API restent en
200.

**Une coupure sans réponse est traitée comme les autres.** Un bus qui refuse la connexion ou dont le
nom ne se résout plus lève aussitôt ; une connexion coupée en silence — réseau partitionné, interface
retirée — ne levait **jamais** : le consommateur attendait une réponse qui ne viendrait pas, sans un
avertissement, et ne reprenait pas au retour du bus. Trois réglages le ferment, et chacun manquait :

- **`BoundedSqsTransportFactory` borne chaque requête à 30 s**, au-dessus des 20 s du long polling.
  Les 300 s de `default_socket_timeout` ne jouaient pas : le transport interroge sa réponse par
  `resolve(0.1)`, et ce délai remplace celui de la requête.
- **`BusOutageTolerantTransport` jette la réception ratée** (`reset()`) : AsyncAws mémorise l'erreur
  et la relance à chaque lecture, si bien que la `Connection` n'émettait plus jamais de requête.
- **Le retour du bus se prouve** par `getMessageCount()`, qui attend sa réponse : une réception
  encore en attente rend « aucun message » sans lever, et la prendre pour un retour remettrait
  l'attente à 1 s à chaque essai.

Pire cas d'une partition : 30 s au plus avant le premier avertissement, puis un essai toutes les
30 à 60 s — l'attente, plafonnée à 30 s, plus la requête, bornée à 30 s.

Avant, `Worker::run()` ne protégeant pas `$receiver->get()`, la commande mourait à chaque essai.
Supervisor la relançait, mais une commande qui meurt avant `startsecs` compte comme un démarrage
raté : au bout de ses 50 essais, une vingtaine de minutes, il la passait `FATAL`, et seul un
`docker compose restart worker` la remettait en route.

Une erreur qui n'est **pas** une indisponibilité — file inexistante, droits refusés — fait toujours
mourir le processus : attendre ne la résoudrait pas. Un corps qui viole le contrat, lui, n'arrive
plus jusque-là : il est conservé dans la file d'échec (section précédente).

## Vérification de bout en bout

Les deux stacks démarrées (`aws_local` puis `service_shop`) :

```bash
# 1. Un compte naît : le client apparaît sans intervention
ACCOUNT=$(cat /proc/sys/kernel/random/uuid)
cd ../aws_local && make aws c="sns publish \
  --topic-arn arn:aws:sns:eu-west-3:000000000000:identity-user-events \
  --message '{\"id\":\"$(cat /proc/sys/kernel/random/uuid)\",\"type\":\"identity.user.registered\",\"version\":1,\"occurredAt\":\"2026-10-14T00:00:00+00:00\",\"data\":{\"userAccountId\":\"'$ACCOUNT'\"}}'"
cd ../service_shop && make bash-db
> db.customer.findOne({userAccountId: "<ACCOUNT>"})        # status 1 (actif)
> db.processed_event.findOne({})                           # source "bus.identity", expire a 30 j

# 2. Redélivrance du MÊME `id` : acquittée sans « Dispatching command » dans les logs
# 3. Commutativité : publier `deleted` puis `registered` pour un compte jamais provisionné
#    -> un seul client, `status` 2 (desactive), `version` 1 — la pierre tombale n'est pas ressuscitee
# 4. Type inconnu (`identity.user.renamed`) -> WARNING « Unsupported integration event acknowledged »
# 5. Corps invalide (`{"id": "casse`) -> `critical` « Removing from transport », le worker garde son
#    PID, file et DLQ a zero (make queues), et le corps COMPLET est dans `failed_user_events` :
docker compose exec app bin/console messenger:failed:show --transport=failed_user_events -vv
# 6. Isolation : `make down` sur la brique -> `/health` 200, et le consommateur `user_events`
#    garde le MEME PID pendant toute la panne (il attend, il ne meurt plus). Ancrer le motif sur
#    `^php` : sans lui, `pgrep -f` trouve aussi le `sh -c` qui le lance, dont la ligne le contient.
docker compose exec worker sh -c 'ps -o pid,etime -p $(pgrep -f "^php .*messenger:consume user_events")'
grep -oE 'waits [0-9]+ s before attempt [0-9]+' var/log/dev.log | tail -3     # 1, 2, 4… plafond 30 s
```

Ces six scénarios ont tous été exécutés. Le point 6, refait le 2026-10-23 avec
`BusOutageTolerantTransport` : bus éteint plus de 5 minutes, le même processus est resté en vie
d'un bout à l'autre (attentes de 1, 2, 4, 8, 16 puis 30 s), a journalisé « reachable again after 9
failed attempts » au retour du bus, et le client d'un événement publié aussitôt a été créé en 4 s.

Le même point, en **coupure silencieuse**, le 2026-10-24 : `docker network disconnect
en_shop_php_bus` sur le worker, LocalStack intact. Avant le correctif, sa connexion vers SQS est
restée `ESTABLISHED`, aucun avertissement en dix minutes, et un message publié après le
rebranchement est resté dans la file : seul `--time-limit=3600` l'aurait débloqué. Après, sur une
coupure de 2 min 30 : premier avertissement à 17 s (« Operation timed out after 30000
milliseconds »), attentes de 1, 2, 4, 8 puis 16 s, un seul « reachable again » une seconde après le
rebranchement, le message consommé dans les 3 s — même PID d'un bout à l'autre.

```bash
docker network disconnect en_shop_php_bus en_shop_php_service_shop-worker-1   # la coupure
docker network connect en_shop_php_bus en_shop_php_service_shop-worker-1      # le retour
```

Le point 5, refait le même jour avec `UndecodableIntegrationEvent` : deux corps cassés publiés sur le
vrai bus, l'un avant le correctif (en retry dans SQS), l'autre après. Les deux ont fini dans
`failed_user_events`, corps **complet** lisible par `messenger:failed:show -vv`, sans que le
consommateur ne meure, SQS et DLQ à zéro. Un `messenger:failed:retry` du corps toujours faux l'a
relu, refusé, puis rangé de nouveau dans `failed_user_events`.
