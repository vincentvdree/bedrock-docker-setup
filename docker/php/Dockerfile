# FrankenPHP — Caddy + PHP in a single process, so there is no separate
# web server container and no FastCGI hop.
# The image is ZTS (thread-safe), which FrankenPHP requires.
#
# Two stages: `app` is what gets deployed (compose.yaml pins target: app), `dev`
# adds local-only tooling on top of it (compose.override.yaml pins target: dev).
# Both targets are named explicitly, so adding a stage below never silently
# changes what a bare `docker build` produces.
FROM dunglas/frankenphp:php8.5 AS app

# Match the container's web user to the host user so bind-mounted files
# (uploads, plugins, logs) stay writable from both sides.
ARG PHP_UID=1000
ARG PHP_GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        less \
        default-mysql-client \
        # Tab completion for git, composer, apt and friends in `make shell`.
        bash-completion \
        # Imagick delegates PDF and EPS rasterisation to Ghostscript, and core's
        # Site Health / debug data shells out to `gs --version` to report it.
        ghostscript \
    && rm -rf /var/lib/apt/lists/*

# install-php-extensions resolves build deps and compiles against ZTS correctly.
RUN install-php-extensions \
        mysqli \
        pdo_mysql \
        zip \
        gd \
        intl \
        exif \
        opcache \
        # WordPress prefers Imagick over GD: better resizing quality, and it is
        # the only editor that can handle PDF thumbnails.
        imagick \
        xdebug \
    # Keep Xdebug inert unless a caller opts in (compose.override.yaml sets
    # XDEBUG_MODE for local development). conf.d is read alphabetically, so the
    # bind-mounted xdebug.ini sorts after this file and wins when present.
    && echo 'xdebug.mode=off' >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

COPY docker/php/Caddyfile /etc/frankenphp/Caddyfile

# Interactive shell config for `make shell`. www-data's HOME is /var/www, so an
# interactive bash reads /var/www/.bashrc; that file is only a loader which
# prefers the project copy. In development the project is bind-mounted at /app,
# so prompt/alias edits apply to the next shell with no rebuild — and unlike a
# single-file bind mount, this survives editors that replace the file's inode.
COPY docker/php/bashrc /etc/bedrock/bashrc
RUN printf '%s\n' \
        'if [ -r /app/docker/php/bashrc ]; then' \
        '    . /app/docker/php/bashrc' \
        'elif [ -r /etc/bedrock/bashrc ]; then' \
        '    . /etc/bedrock/bashrc' \
        'fi' \
        > /var/www/.bashrc \
    # `make root-shell` passes --rcfile explicitly; give an interactive root
    # bash the same prompt if it is started any other way.
    && cp /var/www/.bashrc /root/.bashrc

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# WP-CLI — the project ships a wp-cli.yml, so the binary belongs in the image.
#
# This deliberately uses the nightly build, not stable. WP-CLI 2.12.0 is not
# PHP 8.5 clean: every command that prints a colorised "Success:" trips an
# E_DEPRECATED in its bundled php-cli-tools, and because WP_DEBUG resets
# error_reporting to E_ALL after WordPress boots, each one dumps ~240 KB of
# Xdebug stack trace into web/app/debug.log. 2.13.0-alpha is clean (verified
# against 2.13.0-alpha-5315443).
#
# Switch back to .../phar/wp-cli.phar once 2.13 is released:
#   docker compose build --build-arg WP_CLI_PHAR_URL=<stable-url> php
ARG WP_CLI_PHAR_URL=https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli-nightly.phar
RUN curl -fsSL -o /usr/local/bin/wp "${WP_CLI_PHAR_URL}" \
    && chmod +x /usr/local/bin/wp \
    # Tab completion for wp subcommands; the phar does not carry this script.
    && curl -fsSL -o /usr/local/share/wp-completion.bash \
        https://raw.githubusercontent.com/wp-cli/wp-cli/main/utils/wp-completion.bash

# Run the server unprivileged. CAP_NET_BIND_SERVICE lets it still bind
# privileged ports (:80/:443) when SERVER_NAME asks for them,
# and Caddy needs to own its state/config directories.
RUN usermod -u "${PHP_UID}" www-data \
    && groupmod -g "${PHP_GID}" www-data \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    # www-data's home must be writable or Composer/WP-CLI run without a cache.
    # Pre-creating .cache also seeds the composer-cache volume's ownership.
    && mkdir -p /var/www/.cache/composer \
    && chown -R www-data:www-data /var/www /data/caddy /config/caddy

USER www-data

WORKDIR /app

# ==========================================================================
# dev — everything above, plus tooling that must never reach a deployed image.

FROM app AS dev

# Node.js and npm, for Vite/Tailwind/Stimulus builds
# (`npm run dev|build|watch` in web/app/theme/my-custom-theme). Nothing at
# runtime needs them — FrankenPHP serves the *built* assets out of the plugin's
# dist/ — so they stay out of the deployable `app` stage.
#
# Copied from the official image instead of installed from apt: Debian's nodejs
# package trails what Vite requires, and the tag pins the version. Only the two
# real paths are copied — /usr/local/bin/{npm,npx} are symlinks into
# node_modules over there, and copying all of /usr/local would bury FrankenPHP,
# PHP and Composer, which live in the same prefix here. The tag's Debian release
# matches this image's, so the binaries link against the same glibc.

#USER root
#COPY --from=node:24-trixie-slim /usr/local/bin/node /usr/local/bin/node
#COPY --from=node:24-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
#RUN ln -sf ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
#    && ln -sf ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
#    # npm writes its cache under $HOME by default, which would be lost on every
#    # container recreation. /var/www/.cache is the composer-cache volume, so
#    # putting it there gives npm the same persistence Composer already has.
#    && mkdir -p /var/www/.cache/npm \
#    && chown www-data:www-data /var/www/.cache/npm
#USER www-data
#ENV npm_config_cache=/var/www/.cache/npm

# Claude Code, installed *into the container* so the agent's blast radius is the
# container: it reaches this project, the db/redis on the `wordpress` network and
# the internet, and nothing else on the host. There is no Docker socket mounted,
# so it cannot escape by driving the daemon. Note that /app is a bind mount in
# development — the project directory itself is still shared with the host.
#
# The native installer needs no Node.js; it drops a static binary in
# ~/.local/bin/claude with its versions under ~/.local/share/claude. Both live
# under www-data's home, which compose.override.yaml keeps in a named volume, so
# the login and background auto-updates survive `docker compose up --build`.
#
# Keep every piece of Claude's state (settings, credentials, session history) in
# one directory, so a single named volume persists all of it. This must be set
# *before* the install: without it the OAuth session lands in ~/.claude.json,
# outside any volume, and every container recreation would demand a fresh login.
ENV CLAUDE_CONFIG_DIR="/var/www/.claude"

# Pinning: `| bash -s stable` tracks the stable channel, `| bash -s 2.1.89` a
# fixed version. Left on `latest` deliberately — it self-updates in place.
#
# The XDG overrides are load-bearing: see docker/php/claude for why the install
# fails without them, and why they are set here instead of as image-wide ENV.
RUN export XDG_DATA_HOME=/var/www/.local/share \
    && export XDG_CONFIG_HOME=/var/www/.config \
    && curl -fsSL https://claude.ai/install.sh | bash

# The wrapper that applies those same overrides at runtime. In /usr/local/bin so
# it wins over ~/.local/bin/claude for every entry point — `make claude`, a bare
# `claude` in `make shell`, or a plain `docker compose exec`.
USER root
COPY --chmod=755 docker/php/claude /usr/local/bin/claude
USER www-data

# Both directories are named-volume mount points in development. Docker seeds a
# fresh named volume from the image, but only creates the mount point itself —
# root-owned — when the image has nothing there. Creating them here as www-data
# is what makes the seeded volumes writable on a first `docker compose up`.
RUN mkdir -p /var/www/.claude /var/www/.local/bin /var/www/.local/share

WORKDIR /app
