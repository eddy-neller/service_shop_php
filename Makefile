.DEFAULT_GOAL := help

## Affiche cette aide
.PHONY: help
help:
	@printf "\n \e[1;30m############################################################\e[0m\n\n"
	@printf "\e[1;30m To change the following variables please edit makefile.conf \e[0m\n";
	@printf "\n \e[1;30m############################################################\e[0m\n\n"
	@printf "\n"
	@printf "\e[3m	Usage limited to : E.N Shop Service Shop PHP \e[0m\n\n";
	@printf "\n"
	@printf "\e[33m	Usage:\e[0m";
	@printf "   make [option]\n"

	@awk '{ \
			if ($$0 ~ /^.PHONY:/) { \
				helpCommand = substr($$0, index($$0, ":") + 2); \
				if (helpMessage) { \
					printf "\033[32m%-30s\033[0m %s\n", \
						helpCommand, helpMessage; \
					helpMessage = ""; \
				} \
			} else if ($$0 ~ /^##/) { \
				if (helpMessage) { \
					helpMessage = helpMessage"\n                               "substr($$0, 3); \
				} else { \
					helpMessage = substr($$0, 3); \
				} \
			} else { \
				if (helpMessage) { \
					print "\n"helpMessage"\n" \
				} \
				helpMessage = ""; \
			} \
		}' \
		$(MAKEFILE_LIST)
	@printf "\n\n"


#!make
include makefile.conf
export COMPOSE_BAKE = true

## Installation complete : build, up, vendors, cles de test
.PHONY: install
install:
	@echo "$(YELLOW)** Starting installation... **$(RESET)"
	@echo "$(YELLOW)** Update Git Repository **$(RESET)"
	@git fa && git plr
	@echo "$(YELLOW)** Destroy Docker Containers **$(RESET)"
	@make down-hard
	@echo "$(YELLOW)** Update Docker Images **$(RESET)"
	@docker pull php:8.4-fpm && docker pull nginx:1-alpine && docker pull postgres:18-alpine && docker pull rabbitmq:4-management-alpine && docker pull varnish:8-alpine && docker pull redis:8-alpine
	@echo "$(YELLOW)** Build & Load Docker Containers **$(RESET)"
	make binc && make up
	@echo "$(YELLOW)** Load composer install & dump-autoload **$(RESET)"
	@make ci && make cda
	@echo "$(YELLOW)** Initialise MongoDB DEV **$(RESET)"
	@make fixtures
	@echo "$(YELLOW)** Initialise MongoDB TEST **$(RESET)"
	@$(APP) bin/console doctrine:mongodb:schema:create --index --env=test
	@echo "$(YELLOW)** Load composer outdated & symfony:recipes **$(RESET)"
	@make co && make csr
	@echo "$(YELLOW)** Test du Jalon 1 **$(RESET)"
	@make jwt-test-keys
	@make db-index
	@echo "$(GREEN)** Installation completed!!! **$(RESET)"

## Re-initialise les bases MongoDB dev + test sans reconstruire les conteneurs
.PHONY: reinstall
reinstall:
	@echo "$(YELLOW)** Starting re-installation... **$(RESET)"
	@echo "$(YELLOW)** Re-initialise MongoDB DEV **$(RESET)"
	@$(APP) bin/console doctrine:mongodb:schema:drop --db --no-interaction
	@make fixtures
	@echo "$(YELLOW)** Re-initialise MongoDB TEST **$(RESET)"
	@$(APP) bin/console doctrine:mongodb:schema:drop --db --no-interaction --env=test
	@$(APP) bin/console doctrine:mongodb:schema:create --index --env=test
	@echo "$(GREEN)** Re-installation completed!!! **$(RESET)"

## Recharge les fixtures de dev sans recreer la base
.PHONY: reload-fixtures
reload-fixtures:
	@echo "$(YELLOW)** Reload DEV fixtures **$(RESET)"
	@make fixtures
	@echo "$(GREEN)** Fixtures reloaded!!! **$(RESET)"

## Execute bin/console dans le container app (Ex: make console c="debug:router")
.PHONY: console $(c)
console:
	@$(APP) sh -c "bin/console ${c}"

##--------------------------------- Docker -----------------------------------

## Execute docker compose
.PHONY: dc
dc:
	@$(DOCKER)

## Crée et demarre les containers
.PHONY: up
up:
	@$(DOCKER) up -d --remove-orphans

## Stop et détruits les containers
.PHONY: down
down:
	@$(DOCKER) down --remove-orphans

## Stop et détruits les containers
.PHONY: down-hard
down-hard:
	@$(DOCKER) down --rmi all -v --remove-orphans

## Stoppe les containers SANS les détruire (Ex: make stop s=app)
.PHONY: stop $(s)
stop:
	@$(DOCKER) stop ${s}

## Redémarre les containers stoppés (Ex: make start s=app)
.PHONY: start $(s)
start:
	@$(DOCKER) start ${s}

## Redémarre les containers (Ex: make restart s=app)
.PHONY: restart $(s)
restart:
	@$(DOCKER) restart ${s}

## Build les containers
.PHONY: bi
bi:
	@$(DOCKER) build

## Build les containers sans cache
.PHONY: binc
binc:
	@$(DOCKER) build --no-cache

## Connection au ssh du container app
.PHONY: bash-app
bash-app:
	@$(DOCKER) exec app bash

## Connection au ssh du container nginx
.PHONY: bash-nginx
bash-nginx:
	@$(DOCKER) exec nginx sh

## Connection au ssh du container db
.PHONY: bash-db
bash-db:
	@$(DOCKER) exec mongodb sh -c 'exec mongosh --username "$$MONGO_INITDB_ROOT_USERNAME" --password "$$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin "$$MONGO_INITDB_DATABASE"'

## Connection au ssh du container redis
.PHONY: bash-redis
bash-redis:
	@$(DOCKER) exec redis sh

## Connection au ssh du container varnish
.PHONY: bash-varnish
bash-varnish:
	@$(DOCKER) exec varnish sh

## Affiche les logs des containers (Ex: make logs s=app)
.PHONY: logs $(s)
logs:
	@$(DOCKER) logs -f ${s}

##--------------------------------- Composer -----------------------------------

## Execute composer
.PHONY: c
c:
	@$(APP) sh -c "composer"

## Execute composer install
.PHONY: ci
ci:
	@$(APP) sh -c "composer install"
	@$(APP) sh -c "find vendor/bin -name '*.bat' -delete"

## Execute composer install
.PHONY: ci-dry
ci-dry:
	@$(APP) sh -c "composer install --dry-run"

## Execute composer update
.PHONY: cu
cu:
	@$(APP) sh -c "composer update"

## Execute composer update dry-run
.PHONY: cu-dry
cu-dry:
	@$(APP) sh -c "composer update --dry-run"

## Execute composer outdated
.PHONY: co
co:
	@$(APP) sh -c "composer outdated"

## Execute composer dump-autoload
.PHONY: cda
cda:
	@$(APP) sh -c "composer dump-autoload"

## Execute composer require
.PHONY: creq $(p)
creq:
	@$(APP) sh -c "composer require ${p}"

## Execute composer require --dev
.PHONY: creqdev $(p)
creqdev:
	@$(APP) sh -c "composer require --dev ${p}"

## Execute composer remove
.PHONY: crem $(p)
crem:
	@$(APP) sh -c "composer remove ${p}"

## Execute composer recipes
.PHONY: cr
cr:
	@$(APP) sh -c "composer recipes"

## List outdated composer recipes
.PHONY: cro
cro:
	@$(APP) sh -c "composer recipes --outdated"

## Install a composer recipe (Ex: make cri p=stripe/stripe-php)
.PHONY: cri
cri:
	@$(APP) sh -c "composer recipes:install ${p}"

## Update a composer recipe (Ex: make cru p=stripe/stripe-php)
.PHONY: cru
cru:
	@$(APP) sh -c "composer recipes:update ${p}"

## Execute composer version
.PHONY: cv
cv:
	@$(APP) sh -c "composer -V"

##--------------------------------- Tests -----------------------------------

## Execute tous les controles QA (comme le hook de pre-commit)
.PHONY: grumphp
grumphp:
	@$(APP) sh -c "vendor/bin/grumphp run"

## Execute les controles QA sur les fichiers stages
.PHONY: grumphp-cm
grumphp-cm:
	@$(APP) sh -c "vendor/bin/grumphp git:pre-commit"

## Run phpunit tests
.PHONY: unit
unit:
	@$(APP) sh -c "vendor/bin/phpunit --display-warnings --display-deprecations --display-phpunit-deprecations --display-notices"

## Run tests for a method or class (Ex: make unit-filter f=AuthenticationFailureListenerTest)
.PHONY: unit-filter $(f)
unit-filter:
	@$(APP) sh -c "vendor/bin/phpunit --filter ${f} --display-warnings --display-deprecations --display-phpunit-deprecations --display-notices"

## Execute a suite of tests, by setting testsuite name (Ex: make unit-suite s=api.user)
.PHONY: unit-suite $(s)
unit-suite:
	@$(APP) sh -c "vendor/bin/phpunit --testsuite ${s} --display-warnings --display-deprecations --display-phpunit-deprecations --display-notices"

## Run PHPUnit with code coverage (generates HTML report in coverage/)
.PHONY: unit-coverage
unit-coverage:
	@$(APP) sh -c "XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html coverage/"

## Analyse statique PHPStan
.PHONY: stan
stan:
	@$(APP) sh -c "vendor/bin/phpstan analyse"

## Verifie le style avec PHP_CodeSniffer
.PHONY: phpcs
phpcs:
	@$(APP) sh -c "vendor/bin/phpcs"

## Affiche les sniffs PHP_CodeSniffer en detail
.PHONY: phpcs-det
phpcs-det:
	@$(APP) sh -c "vendor/bin/phpcs -s"

## Formate le code avec PHP-CS-Fixer
.PHONY: phpcsfixer
phpcsfixer:
	@$(APP) sh -c "vendor/bin/php-cs-fixer fix"

## Verifie le formatage sans modifier les fichiers
.PHONY: phpcsfixer_dry
phpcsfixer_dry:
	@$(APP) sh -c "vendor/bin/php-cs-fixer fix --dry-run --diff"

## Run phpcsfixer fix tests
.PHONY: phpcsfixer_fix
phpcsfixer_fix:
	@$(APP) sh -c "vendor/bin/php-cs-fixer fix ${f}"

## Analyse la qualite et la complexite avec PHPMD
.PHONY: phpmd
phpmd:
	@$(APP) sh -c "php -d 'error_reporting=E_ALL & ~E_DEPRECATED' vendor/bin/phpmd src text ruleset.xml"

## Applique les transformations Rector
.PHONY: rector
rector:
	@$(APP) sh -c "vendor/bin/rector process"

## Simule les transformations Rector sans modifier les fichiers
.PHONY: rector-dry
rector-dry:
	@$(APP) sh -c "vendor/bin/rector process --dry-run"

##--------------------------------- Autres -----------------------------------

## Les index ne sont pas crees par une migration : ils sont declares dans le mapping
## des documents et poses par cette commande. Sans elle, l'unicite des titres n'est
## garantie par rien — le findByTitle() des handlers est un check-then-act.
.PHONY: db-index
db-index: ## Cree les index declares dans le mapping ODM
	@$(APP) bin/console doctrine:mongodb:schema:create --index
	@echo "$(GREEN)** Index MongoDB poses **$(RESET)"

.PHONY: consume
consume: ## Depile l'outbox des Domain Events
	@$(APP) bin/console messenger:consume domain_events -vv

## Charge le catalogue de developpement : 30 categories sur 4 niveaux, 1000 produits.
## Purge la base au passage — d'ou le groupe `dev`, jamais joue ailleurs.
.PHONY: fixtures
fixtures: ## Charge les fixtures de dev (purge la base)
	@$(APP) bin/console doctrine:mongodb:fixtures:load --group=dev --no-interaction
	@make db-index
	@echo "$(GREEN)** Catalogue de developpement charge **$(RESET)"

## Genere la paire de cles DEDIEE AUX TESTS (jamais celle de production)
.PHONY: jwt-test-keys
jwt-test-keys: ## (Re)genere config/jwt/test/{private,public}.pem
	@echo "$(YELLOW)** Generation de la paire JWT de test **$(RESET)"
	@$(APP) sh -c "mkdir -p config/jwt/test && \
		openssl genpkey -algorithm RSA -out config/jwt/test/private.pem -pkeyopt rsa_keygen_bits:2048 2>/dev/null && \
		openssl pkey -in config/jwt/test/private.pem -out config/jwt/test/public.pem -pubout 2>/dev/null && \
		chmod 644 config/jwt/test/private.pem config/jwt/test/public.pem"
	@echo "$(GREEN)** Paire de test generee **$(RESET)"

## Copie la cle PUBLIQUE de l'emetteur depuis le monolithe
.PHONY: jwt-public-key
jwt-public-key: ## Copie la cle publique de service_identity (jamais la cle privee)
	@cp ../service_identity/config/jwt/public.pem config/jwt/public.pem
	@echo "$(GREEN)** Cle publique de l'emetteur copiee **$(RESET)"
