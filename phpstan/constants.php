<?php

declare(strict_types=1);

/**
 * Bedrock's constants, declared for PHPStan. Listed in `scanFiles`, never executed.
 *
 * PHPStan only records a `define()` while scanning when it can evaluate the value
 * expression, and it does not understand `Roots\WPConfig\Config::define()` at all.
 * Bedrock uses a variable for WP_ENV and Config::define() for everything else, so
 * scanning config/application.php finds none of them and every use reports
 * `constant.notFound`.
 *
 * The values here are chosen for their *type* only — deliberately not literals.
 * A literal would let PHPStan narrow WP_ENV to a single environment and then call
 * every `WP_ENV === 'development'` branch in the codebase dead code.
 *
 * Add a constant here when code starts reading it. Note that this file overrides
 * the szepeviktor/phpstan-wordpress bootstrap, so redeclaring a constant WordPress
 * itself defines (ABSPATH, WP_CONTENT_DIR, WP_DEBUG, SCRIPT_DEBUG, ...) silently
 * wins over the extension — only do that to correct a type, as below.
 */

// config/application.php — defined in every environment.
define('WP_ENV', (string) getenv('WP_ENV'));
define('WP_HOME', (string) getenv('WP_HOME'));
define('WP_SITEURL', (string) getenv('WP_SITEURL'));
define('CONTENT_DIR', (string) getenv('CONTENT_DIR'));
define('WP_CONTENT_URL', (string) getenv('WP_CONTENT_URL'));

/*
 * WordPress takes true, false or a log path here (a path since 5.1), and
 * config/environments/development.php hands it env('WP_DEBUG_LOG') ?? true — so the
 * value really is "whatever is in .env". php-stubs/wordpress-stubs declares a plain
 * bool, which turns the is_string() a reader has to do into reported dead code.
 * Mixed is the honest type; narrow it at the point of use.
 */
define('WP_DEBUG_LOG', $_ENV['WP_DEBUG_LOG'] ?? true);
