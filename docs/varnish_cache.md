# Cache HTTP — état actuel

Le service n'embarque ni Varnish, ni CDN configuré, ni mécanisme BAN/PURGE. La pile Docker est
simplement :

```text
client → nginx → app → MongoDB
```

Il n'existe donc pas de `docker/varnish/`, de `VARNISH_URL`, de VCL ou d'invalidation API Platform
à maintenir dans ce dépôt.

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

Les écritures du catalogue ne déclenchent aucune invalidation distante aujourd'hui. Un proxy qui
honore `s-maxage` peut donc servir une représentation antérieure jusqu'à son expiration. C'est le
contrat actuel des lectures publiques ; ne pas documenter de BAN automatique qui n'existe pas.

## Évolution éventuelle

L'ajout d'un Varnish ou d'un CDN demanderait une stratégie d'invalidation propre au catalogue,
validée avec les Domain Events de l'étape B. Les réponses portant `Authorization` doivent rester
privées et ne jamais être partagées par un cache intermédiaire.
