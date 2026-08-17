# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A [Bedrock](https://roots.io/bedrock/) WordPress project (composer-managed WP core, plugins and theme under
`web/app/`, `web/wp/` as the WordPress install dir) running in Docker via FrankenPHP (Caddy + PHP in one
process, no separate web server container). This is a template repo — `bedrock-docker-setup` /
`bedrock_docker_setup` / `BEDROCK_DOCKER_SETUP` are placeholders meant to be search-replaced with the real
project name (see README.md).

## Running inside the container

**You (Claude Code) run inside the `php` container itself** — it's installed in the `dev` stage of
`docker/php/Dockerfile` specifically so the agent's blast radius is the container (this project, `db`/`redis`
on the `wordpress` network, and the internet — no Docker socket, so no escaping to the host). Because of that:

- `make` targets below run directly (no `docker compose exec` needed, and it wouldn't work anyway — there's
  no docker CLI in the container).
- The **Stack** targets (`up`, `down`, `restart`, `logs`) are the exception — they drive the Docker daemon
  from the host and will fail with `docker: No such file or directory` if run from in here.

## Common commands

```
make check         # php-cs-fixer + phpstan + phpunit — run before considering work done
make php-cs-fixer   # fix code style (config/, tests/)
make phpstan        # static analysis, level 10, config/ and tests/
make phpunit        # run the test suite
```

Single test: `vendor/bin/phpunit --filter testHomepage tests/SmokeTest.php` (prefix with `XDEBUG_MODE=off`
for speed, as the Makefile does — Xdebug is on by default in this environment and slows non-interactive
runs).

Host-only (not from inside this container): `make up` / `make down` / `make restart` / `make logs` /
`make shell`.

CI (`.github/workflows/ci.yml`) runs two independent jobs: `php-cs-fixer --dry-run` + `phpstan` on the bare
PHP toolchain, and a full Docker + WordPress + PHPUnit run against the same `app` image that ships.

## Architecture

**Config loading.** `config/application.php` is the single source of truth for base WP config; it loads
`.env`/`.env.local` via `vlucas/phpdotenv`, defines all `Config::define()` WordPress constants, then requires
`config/environments/{WP_ENV}.php` for environment-specific overrides before calling `Config::apply()`.
`WP_ENV` is read from the environment (`production` if unset). Only `development` and `staging` environment
override files exist; production config lives entirely in `application.php`.

**Docker build stages** (`docker/php/Dockerfile`, `FROM dunglas/frankenphp:php8.5`):
- `app` — the deployable image (WP-CLI, Composer, PHP extensions, Caddyfile). `compose.yaml` pins
  `target: app` and expects `/app` to already contain the built application (populated from outside the repo
  in production).
- `dev` — `app` plus Node.js (commented out — not currently wired up) and Claude Code, installed with
  `CLAUDE_CONFIG_DIR=/var/www/.claude`. `compose.override.yaml` pins `target: dev` and bind-mounts the repo
  over `/app` so host edits are live. Never build this stage as a deployable image.

Both stages are named explicitly in the Dockerfile so adding a stage never silently changes what a bare
`docker build` produces.

**Compose layering**: `compose.yaml` (base, deployable `app` target) → `compose.override.yaml` (local dev:
`dev` target, bind mount, Xdebug, Adminer at `:8081`, MailCatcher at `:1080`, port 5173 for a future Vite dev
server) is applied automatically by plain `docker compose` commands. CI instead uses
`docker/ci/compose.ci.yaml` as an explicit overlay on top of `compose.yaml` (bind-mounts the checkout over
`/app` without pulling in dev-only tooling like Claude Code) — see the comments at the top of that file.

**Services**: `php` (FrankenPHP, port 8080 — same inside/outside because WP_HOME, wp-cron and loopback
requests all resolve it from inside the container), `db` (MariaDB 11.8), `redis` (object cache backend, only
enabled outside `development` — see `WP_REDIS_DISABLED` below).

**WordPress content dir**: Bedrock's `CONTENT_DIR`/`WP_CONTENT_DIR` point at `/app` (i.e. the repo root, not
`web/app`) — see `config/application.php`. Plugins, the theme and mu-plugins install via Composer per
`composer.json`'s `installer-paths` into `web/app/{mu-plugins,plugins,themes}/{name}`. Custom/private plugins
come from a private Composer repo (`wp-packages`); WordPress core itself is pinned via `roots/wordpress`.

**mu-plugins**: `bedrock-autoloader.php` auto-activates regular plugins dropped into `web/app/plugins`, and
`bedrock-auto-enable-plugins.php` complements it. `bedrock-disallow-indexing/` is a full plugin installed as
an mu-plugin so `DISALLOW_INDEXING` (set in `development`/`staging` configs) always takes effect.

**Testing**: `tests/SmokeTest.php` is currently the only test — it asserts the live site (`WP_HOME`) returns
a 200 HTML response with no DB-connection error, so PHPUnit needs a running, WordPress-installed stack behind
it (`make up`, or CI's install step) rather than being purely unit-level.

**Static analysis**: PHPStan runs at level 10 against `config/` and `tests/` only (not `web/`, which is
vendored WP core + Composer-managed plugins/theme). `phpstan/constants.php` hand-declares the WordPress
constants that `config/application.php` defines dynamically via `Roots\WPConfig\Config::define()` /
a variable-driven `WP_ENV`, since PHPStan can't evaluate those and would otherwise report every use as
`constant.notFound`. Read the comments in that file before adding a constant — types are intentionally kept
non-literal so branches like `WP_ENV === 'development'` don't get analyzed as dead code.

**Environment variables**: required ones are enforced in `config/application.php` via `$dotenv->required()`:
`WP_HOME`, `WP_SITEURL`, plus `DB_NAME`/`DB_USER`/`DB_PASSWORD` (skipped if `DATABASE_URL` is set instead,
which is parsed to populate the individual DB constants). `.env` is git-ignored; copy `.env.example` to
`.env` for local setup.

**Xdebug**: on (`develop,debug`) by default in the dev container per `compose.override.yaml`, targeting
PhpStorm via `PHP_IDE_CONFIG` (server name must match `.idea/php.xml`). Non-interactive tooling (tests,
linters, Composer) should run with `XDEBUG_MODE=off` to avoid waiting on an IDE connection — this is what
every Makefile recipe under "Linting and testing" already does.
