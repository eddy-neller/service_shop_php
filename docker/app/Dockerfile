FROM php:8.4-fpm

ENV TZ=Europe/Paris
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update

# Volontairement minimal : la seule dependance de donnees est MongoDB (jalon 2, Catalog).
# Pas de pdo_pgsql, pas d'amqp, pas de redis, pas de supervisor — le cache des queries
# est un pool filesystem, l'outbox vit dans Mongo.
RUN apt-get install -y --no-install-recommends \
    supervisor procps acl \
    iputils-ping net-tools telnet nano \
    git cron curl wget zip unzip \
    libpq-dev libzip-dev libxml2-dev libicu-dev \
    librabbitmq-dev libssl-dev \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install apcu xdebug mongodb amqp redis
RUN docker-php-ext-install xml
RUN docker-php-ext-install zip
RUN docker-php-ext-install sockets
RUN docker-php-ext-install opcache
RUN docker-php-ext-install intl
RUN docker-php-ext-enable apcu xdebug amqp redis xml zip sockets opcache intl mongodb

# Ajouter le php.ini modifié
COPY docker/app/php-fpm/php.ini /usr/local/etc/php/php.ini
COPY docker/app/cron/crontab /etc/crontab
COPY --from=composer:2.10.2 /usr/bin/composer /usr/bin/composer
RUN composer --version

# |--------------------------------------------------------------------------
# | .bashrc updating
# |--------------------------------------------------------------------------
# |
# | Let's update the .bashrc to add nice aliases
# |

RUN echo 'eval "$(symfony-autocomplete)"' > ~/.bash_profile

RUN { \
        echo "alias ls='ls --color=auto'"; \
        echo "alias ll='ls --color=auto -alF'"; \
        echo "alias la='ls --color=auto -A'"; \
        echo "alias l='ls --color=auto -CF'"; \
    } >> ~/.bashrc

# config apcu
RUN echo "apc.enabled=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini \
    && echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini

# config xdebug
RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Add Supervisor configurations
COPY docker/app/supervisor/supervisor.conf /etc/supervisor/supervisord.conf
COPY docker/app/supervisor/conf.d/ /etc/supervisor/conf.d/

WORKDIR /var/www
COPY . .
RUN git config --global --add safe.directory /var/www

COPY docker/app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]

# Command to start Supervisor
CMD ["/usr/bin/supervisord","-n","-c","/etc/supervisor/supervisord.conf"]
