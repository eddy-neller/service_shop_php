.DEFAULT_GOAL := help

#!make
include makefile.conf
export COMPOSE_BAKE = true

## Affiche cette aide
.PHONY: help
help:
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## /{printf "\033[32m%-22s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

## Installation complete : build, up, vendors, cles de test
.PHONY: install
install: ## Build + demarrage + vendors + cles JWT de test
	@echo "$(YELLOW)** Build des images **$(RESET)"
	@$(DOCKER) build
	@make up
	@echo "$(YELLOW)** composer install **$(RESET)"
	@$(APP) composer install
	@make jwt-test-keys
	@echo "$(GREEN)** Installation terminee — http://localhost:$${NGINX_EXPOSED_PORT:-20910}/health **$(RESET)"

.PHONY: up
up: ## Demarre les conteneurs
	@$(DOCKER) up -d

.PHONY: down
down: ## Arrete les conteneurs
	@$(DOCKER) down --remove-orphans

.PHONY: bash-app
bash-app: ## Shell dans le conteneur applicatif
	@$(APP) bash

.PHONY: logs
logs: ## Logs du service applicatif
	@$(DOCKER) logs -f app

.PHONY: ci
ci: ## composer install
	@$(APP) composer install

.PHONY: test
test: ## Suite de tests fonctionnels
	@$(APP) vendor/bin/phpunit -c phpunit.dist.xml

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
jwt-public-key: ## Copie api/config/jwt/public.pem (jamais la cle privee)
	@cp ../../api/config/jwt/public.pem config/jwt/public.pem
	@echo "$(GREEN)** Cle publique de l'emetteur copiee **$(RESET)"
