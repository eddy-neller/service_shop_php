vcl 4.0;

import std;

backend default {
  .host = "shop-nginx";
  .port = "80";
}

# Les conteneurs du reseau Compose peuvent invalider. L'endpoint public ne peut
# pas vider le cache ; les BAN nominaux sont emis par `app` apres un commit.
acl invalidators {
  "localhost";
  "172.16.0.0"/12;
}

sub vcl_recv {
  # BAN doit etre traite AVANT le filtre des methodes cacheables : le placer
  # apres `return (pass)` rendrait l'invalidation silencieusement inoperante.
  if (req.method == "BAN") {
    if (client.ip !~ invalidators) {
      return (synth(405, "Not allowed"));
    }

    if (!req.http.ApiPlatform-Ban-Regex) {
      return (synth(400, "ApiPlatform-Ban-Regex HTTP header must be set."));
    }

    ban("obj.http.Cache-Tags ~ " + req.http.ApiPlatform-Ban-Regex);

    return (synth(200, "Ban added"));
  }

  if (req.restarts > 0) {
    set req.hash_always_miss = true;
  }

  # Authentification et cookies ne traversent jamais le cache partage.
  if (req.http.Authorization || req.http.Cookie) {
    return (pass);
  }

  if (req.method != "GET" && req.method != "HEAD") {
    return (pass);
  }

  unset req.http.Forwarded;
}

sub vcl_hash {
  hash_data(req.url);

  if (req.http.host) {
    hash_data(req.http.host);
  } else {
    hash_data(server.ip);
  }

  # Les CORS generes par Symfony varient aussi avec Origin.
  if (req.http.Origin) {
    hash_data(req.http.Origin);
  }

  return (lookup);
}

sub vcl_hit {
  if (obj.ttl >= 0s) {
    return (deliver);
  }

  if (std.healthy(req.backend_hint)) {
    return (restart);
  }

  if (obj.ttl + obj.grace > 0s) {
    return (deliver);
  }

  return (synth(503, "API is down"));
}

sub vcl_deliver {
  unset resp.http.url;
}

sub vcl_backend_response {
  # BAN-lurker-friendly URL and a short resilience window when nginx is down.
  set beresp.http.url = bereq.url;
  set beresp.grace = 1h;
}
