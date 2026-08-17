# Bedrock Docker Setup

A [Bedrock](https://roots.io/bedrock/) WordPress project running in Docker via [FrankenPHP](https://frankenphp.dev/)
(Caddy + PHP in a single process). This is a template repo — clone it and rename it for your own project before
starting.

## Setup

Search-replace the following items

| Search               | Replace      | 
|----------------------|--------------|
| bedrock-docker-setup | project-name |
| bedrock_docker_setup | project_name |
| BEDROCK_DOCKER_SETUP | PROJECT_NAME |

Copy `.env.example` to `.env` and fill in the values (database credentials, `WP_HOME`/`WP_SITEURL`, and the
WordPress auth keys/salts — generate a set at https://roots.io/salts.php).

## Getting started

```
make up             # build the images and start the stack in the background
```

This brings up four services: `php` (FrankenPHP, serving the site), `db` (MariaDB), `redis` (object cache) and,
in local development, `adminer` and `mailcatcher`. On first boot WordPress itself still needs installing —
either through the install wizard at `http://localhost:8080/wp/wp-admin/install.php`, or non-interactively with
WP-CLI from inside the container:

```
make shell
wp core install --url=http://localhost:8080 --title="My Site" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com
```

## Services

| Service     | URL                          | Notes                                  |
|-------------|-------------------------------|-----------------------------------------|
| Site        | http://localhost:8080         | WP_HOME — same port inside and outside the container |
| Adminer     | http://localhost:8081         | DB GUI, dev only — log in with the `DB_*` values from `.env` |
| MailCatcher | http://localhost:1080         | Catches all outgoing mail, dev only     |
| MariaDB     | localhost:3306                | Dev only — exposed for connecting a local DB client |
| Redis       | localhost:6379                | Dev only; the object cache is disabled in `development` |

## Development

Claude Code is installed *inside* the `php` container's dev image, so most tooling runs there rather than on
the host:

```
make shell          # open a shell in the php container
```

Once inside (`make shell`), the linting/testing targets are plain local commands — no `docker compose exec`
involved:

```
make check           # php-cs-fixer + phpstan + phpunit
make php-cs-fixer     # fix code style
make phpstan          # static analysis
make phpunit          # run the test suite
```

`make up`, `make down`, `make restart`, `make logs` and `make shell` are the opposite: host-only, since they
drive the Docker daemon itself and fail with `docker: No such file or directory` from inside the container.

Xdebug is enabled by default in development (`XDEBUG_MODE=develop,debug` in `compose.override.yaml`); the
linting/testing targets above turn it off automatically since it's not needed there and only slows things down.

## CI

`.github/workflows/ci.yml` runs PHP CS Fixer and PHPStan against the bare PHP toolchain, then builds the same
`app` image that ships and runs the full PHPUnit suite against a live, installed WordPress instance.

