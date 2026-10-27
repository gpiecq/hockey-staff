# Hockey Staff — Makefile
# Usage: make <target>
# Run `make help` to see all available targets

.DEFAULT_GOAL := help

# —— Docker ————————————————————————————————————————————————————————————————
DOCKER_COMP = docker compose
PHP_CONT    = $(DOCKER_COMP) exec php
PHP         = $(PHP_CONT) php
COMPOSER    = $(PHP_CONT) composer
SYMFONY     = $(PHP) bin/console

# —— Colors ————————————————————————————————————————————————————————————————
GREEN  = \033[32m
YELLOW = \033[33m
RESET  = \033[0m

## —— Hockey Staff Makefile—————————————————————————————————————————————————
help: ## Show this help
	@grep -E '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^##)' Makefile | awk 'BEGIN {FS = ":.*?## "}{printf "$(GREEN)%-20s$(RESET) %s\n", $$1, $$2}' | sed -e 's/##/\n——/'

## —— Docker ———————————————————————————————————————————————————————————————
build: ## Build Docker images
	$(DOCKER_COMP) build --pull --no-cache

up: ## Start containers in detached mode
	$(DOCKER_COMP) up --detach

down: ## Stop and remove containers
	$(DOCKER_COMP) down --remove-orphans

start: build up ## Build and start containers

stop: ## Stop containers
	$(DOCKER_COMP) stop

restart: down up ## Restart containers

logs: ## Show live logs
	$(DOCKER_COMP) logs --tail=0 --follow

ps: ## Show running containers
	$(DOCKER_COMP) ps

## —— Shell ————————————————————————————————————————————————————————————————
sh: ## Open a shell in the PHP container
	$(PHP_CONT) sh

bash: ## Open bash in the PHP container (with history)
	$(PHP_CONT) bash

## —— Composer —————————————————————————————————————————————————————————————
composer: ## Run Composer command (usage: make composer c="require package")
	$(COMPOSER) $(c)

vendor: ## Install vendors from composer.lock
	$(COMPOSER) install --prefer-dist --no-progress --no-interaction

## —— Symfony ——————————————————————————————————————————————————————————————
sf: ## Run Symfony console command (usage: make sf c="command")
	$(SYMFONY) $(c)

cc: ## Clear Symfony cache
	$(SYMFONY) cache:clear

## —— Database —————————————————————————————————————————————————————————————
db-create: ## Create the database
	$(SYMFONY) doctrine:database:create --if-not-exists

db-migrate: ## Run database migrations
	$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing

db-fixtures: ## Load data fixtures
	$(SYMFONY) doctrine:fixtures:load --no-interaction

db-reset: ## Drop, create, migrate and load fixtures
	$(SYMFONY) doctrine:database:drop --force --if-exists
	$(SYMFONY) doctrine:database:create
	$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing
	$(SYMFONY) doctrine:fixtures:load --no-interaction

db-diff: ## Generate a migration by comparing schema to entities
	$(SYMFONY) doctrine:migrations:diff

db-validate: ## Validate the Doctrine schema
	$(SYMFONY) doctrine:schema:validate

## —— Quality ——————————————————————————————————————————————————————————————
test: ## Run PHPUnit tests (usage: make test or make test c="--filter=TestName")
	$(PHP_CONT) php bin/phpunit $(c)

test-coverage: ## Run tests with HTML coverage report
	$(PHP_CONT) php -d xdebug.mode=coverage bin/phpunit --coverage-html var/coverage $(c)

stan: ## Run PHPStan static analysis
	$(PHP_CONT) php -d memory_limit=512M vendor/bin/phpstan analyse $(c)

cs-fix: ## Run PHP-CS-Fixer
	$(PHP_CONT) vendor/bin/php-cs-fixer fix $(c)

cs-check: ## Run PHP-CS-Fixer in dry-run mode
	$(PHP_CONT) vendor/bin/php-cs-fixer fix --dry-run --diff $(c)

qa: cs-fix stan test ## Run full quality pipeline (cs-fix + stan + test)

## —— Assets ———————————————————————————————————————————————————————————————
assets: ## Install importmap assets
	$(SYMFONY) importmap:install

assets-compile: ## Compile assets for production
	$(SYMFONY) asset-map:compile

## —— i18n —————————————————————————————————————————————————————————————————
translations: ## Extract translation keys
	$(SYMFONY) translation:extract --force fr
	$(SYMFONY) translation:extract --force en

.PHONY: help build up down start stop restart logs ps sh bash composer vendor sf cc db-create db-migrate db-fixtures db-reset db-diff db-validate test test-coverage stan cs-fix cs-check qa assets assets-compile translations
