.DEFAULT_GOAL := help

# This Makefile runs *inside* the php container — `make shell`, or Claude Code,
# which lives there too (see the `dev` stage in docker/php/Dockerfile). Every
# tool the recipes want (php, composer, wp, phpunit, npm, mysql, node) is on
# PATH in there, so the commands are plain and local: no `docker compose exec`,
# which would not work anyway — there is no docker CLI and no socket in the
# container to drive the daemon with.
#
# The exception is the Stack section below. Those targets *are* the daemon, so
# they keep using compose and only run on the host.
COMPOSE := docker compose

# Xdebug is on `develop,debug` locally and `start_with_request=yes`, which makes
# every CLI call wait for the IDE. Anything non-interactive (tests, linters,
# composer) runs without it.
NO_XDEBUG := env XDEBUG_MODE=off

.PHONY: help up down logs shell shell-root \
		php-cs-fixer phpstan phpunit check

ADMINER_PORT     ?= 8081
MAILCATCHER_PORT ?= 1080

XDEBUG_PORT      ?= 9003

help: ## List the available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# ==============================
# Stack
#
# The one host-only group: these drive the Docker daemon or open a way into the
# container, so they are the only targets still going through compose. Run them
# from the project root on the host; inside the container they fail with
# `make: docker: No such file or directory`.

up: ## Start the stack in the background (host only)
	$(COMPOSE) up -d

down: ## Stop the stack (host only; the database survives in the db-data volume)
	$(COMPOSE) down

restart: ## Down and up again (host only)
	$(COMPOSE) down
	$(COMPOSE) up -d

logs: ## Tail Caddy + PHP output (host only)
	$(COMPOSE) logs -f php

shell: ## Open a shell in the php container (host only)
	@$(COMPOSE) exec -e TERM="$${TERM:-xterm-256color}" php bash

shell-root: ## Open a root shell in the php container (host only; for apt, chown, ...)
	@$(COMPOSE) exec -u root -e TERM="$${TERM:-xterm-256color}" php \
		bash --rcfile /var/www/.bashrc

# ==============================
# Linting and testing
#
# The work itself lives in composer scripts, so the IDE and CI can run the same
# commands without going through make.

php-cs-fixer: ## Run PHP CS Fixer
	$(NO_XDEBUG) vendor/bin/php-cs-fixer fix

phpstan: ## Run PHPStan
	$(NO_XDEBUG) vendor/bin/phpstan

phpunit: ## Run PHPUnit
	$(NO_XDEBUG) vendor/bin/phpunit --colors=always

check: ## Run all checkers (PHP CS Fixer, PHPStan, PHPUnit)
	$(MAKE) php-cs-fixer
	$(MAKE) phpstan
	$(MAKE) phpunit
