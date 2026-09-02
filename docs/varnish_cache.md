# Cache HTTP — Varnish

Le cache HTTP partagé est assuré par Varnish, placé devant nginx :

```text
client → Varnish → nginx → app → MongoDB
```

Varnish est construit depuis `docker/varnish/`. En production,
`docker-compose.prod.yaml` lui donne l'alias réseau `service-shop` visé par Kong. En développement,
nginx reçoit directement cet alias et le port 20910 : le profiler Symfony voit alors la requête
courante, sans en-têtes de profiler resservis par un hit. Varnish reste démarré sur le réseau interne
pour recevoir les BAN des commandes.

Le backend Varnish est nginx sous l'alias privé unique `shop-nginx` : le nom générique `nginx` est
ambigu sur le réseau partagé avec `service_identity`. MongoDB et Redis ne sont joints ni par Varnish
ni par Kong.

## En-têtes de cache

Les opérations `GET` publiques de `CategoryResource` et `ProductResource` annoncent :

```text
Cache-Control: max-age=21600, s-maxage=86400
```

Soit six heures pour un cache client et vingt-quatre heures pour un cache partagé installé en aval
du service. Ces en-têtes sont définis par opération dans :

- `src/Presentation/Shop/ApiResource/Catalog/CategoryResource.php` ;
- `src/Presentation/Shop/ApiResource/Catalog/ProductResource.php`.

API Platform active également les ETags dans ses en-têtes par défaut
(`config/packages/api_platform.yaml`). Aucun listener `Last-Modified` n'est installé dans ce
service.

Après le commit MongoDB, `CacheInvalidationMiddleware` traduit les Domain Events du catalogue en
IRIs API Platform et envoie un `BAN` à `VARNISH_URL` (`http://varnish`). Les collections sont toujours
invalidées ; l'item affecté et les catégories concernées le sont aussi. Ainsi un déplacement de
catégorie purge le cache partagé avant la réponse HTTP. Le VCL traite les `BAN` avant le filtre des
méthodes cacheables et n'autorise que le réseau interne Docker.

Les réponses avec `Authorization` ou `Cookie` sont systématiquement transmises à nginx sans être
stockées. Le cache Varnish ne corrige pas une réponse déjà présente dans le navigateur :
`max-age=21600` reste le contrat navigateur actuel.

## Vérification

Après `make up`, deux GET publics identiques doivent faire apparaître deux identifiants dans
`X-Varnish` sur le second : il est servi depuis le proxy. Après un PATCH de catégorie, un GET
identique doit redevenir un miss avec un seul identifiant : la commande a émis le
`CategoryMovedEvent`, puis le middleware a envoyé le BAN.
