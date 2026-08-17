<?php

declare(strict_types=1);

/**
 * Your base production configuration goes in this file. Environment-specific
 * overrides go in their respective config/environments/{{WP_ENV}}.php file.
 *
 * A good default policy is to deviate from the production config as little as
 * possible. Try to define as much of your configuration in this file as you
 * can.
 */

use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Roots\WPConfig\Config;

use function Env\env;

// CONVERT_* + STRIP_QUOTES + LOCAL_FIRST
Env\Env::$options
    = Env\Env::CONVERT_BOOL
    | Env\Env::CONVERT_NULL
    | Env\Env::CONVERT_INT
    | Env\Env::STRIP_QUOTES
    | Env\Env::LOCAL_FIRST;

/**
 * env() and Config::get() are both typed as mixed, so narrow once here for the
 * values this file has to use as strings.
 */
$as_string = static function (mixed $value): string {
    if (!is_scalar($value)) {
        throw new RuntimeException('Expected a scalar configuration value, got '.get_debug_type($value).'.');
    }

    return (string) $value;
};

/**
 * Directory containing all the site's files.
 */
$root_dir = dirname(__DIR__);

/**
 * Document Root.
 *
 * @var non-falsy-string $webroot_dir
 */
$webroot_dir = $root_dir.'/web';

/*
 * Use Dotenv to set required environment variables and load .env file in root
 * .env.local will override .env if it exists
 */
if (file_exists($root_dir.'/.env')) {
    $env_files = file_exists($root_dir.'/.env.local')
        ? ['.env', '.env.local']
        : ['.env'];

    $repository = RepositoryBuilder::createWithNoAdapters()
        ->addAdapter(EnvConstAdapter::class)
        ->addAdapter(PutenvAdapter::class)
        ->immutable()
        ->make()
    ;

    $dotenv = Dotenv\Dotenv::create($repository, $root_dir, $env_files, false);
    $dotenv->load();

    $dotenv->required(['WP_HOME', 'WP_SITEURL']);
    if (!env('DATABASE_URL')) {
        $dotenv->required(['DB_NAME', 'DB_USER', 'DB_PASSWORD']);
    }
}

/*
 * Set up our global environment constant and load its config first
 * Default: production
 */
$wp_env = $as_string(env('WP_ENV') ?: 'production');

define('WP_ENV', $wp_env);

// Set WP_ENVIRONMENT_TYPE if not already defined
if (!defined('WP_ENVIRONMENT_TYPE')) {
    $wp_environment_type = env('WP_ENVIRONMENT_TYPE');

    if ($wp_environment_type) {
        Config::define('WP_ENVIRONMENT_TYPE', $wp_environment_type);
    } elseif (in_array($wp_env, ['production', 'staging', 'development', 'local'], true)) {
        Config::define('WP_ENVIRONMENT_TYPE', $wp_env);
    }
}

// Set WP_DEVELOPMENT_MODE if explicitly configured
if (!defined('WP_DEVELOPMENT_MODE')) {
    $wp_development_mode = env('WP_DEVELOPMENT_MODE');

    if ($wp_development_mode) {
        Config::define('WP_DEVELOPMENT_MODE', $wp_development_mode);
    }
}

// URLs
$wp_home = $as_string(env('WP_HOME'));

Config::define('WP_HOME', $wp_home);
Config::define('WP_SITEURL', env('WP_SITEURL'));

// Custom Content Directory
$content_dir = '/app';

Config::define('CONTENT_DIR', $content_dir);
Config::define('WP_CONTENT_DIR', $webroot_dir.$content_dir);
Config::define('WP_CONTENT_URL', $wp_home.$content_dir);

// DB settings
if (env('DB_SSL')) {
    Config::define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL);
}

Config::define('DB_NAME', env('DB_NAME'));
Config::define('DB_USER', env('DB_USER'));
Config::define('DB_PASSWORD', env('DB_PASSWORD'));
Config::define('DB_HOST', env('DB_HOST') ?: 'localhost');
Config::define('DB_CHARSET', 'utf8mb4');
Config::define('DB_COLLATE', '');
$table_prefix = env('DB_PREFIX') ?: 'wp_';

if (env('DATABASE_URL')) {
    $dsn = 'DATABASE_URL'
            |> env(...)
            |> $as_string(...)
            |> parse_url(...);

    if (!is_array($dsn)) {
        throw new RuntimeException('DATABASE_URL is not a valid URL.');
    }

    Config::define('DB_NAME', substr($dsn['path'] ?? '', 1));
    Config::define('DB_USER', $dsn['user'] ?? null);
    Config::define('DB_PASSWORD', $dsn['pass'] ?? null);
    $db_host = $dsn['host'] ?? null;

    Config::define('DB_HOST', isset($dsn['port']) ? sprintf('%s:%s', $db_host, $dsn['port']) : $db_host);
}

// Authentication Unique Keys and Salts
Config::define('AUTH_KEY', env('AUTH_KEY'));
Config::define('SECURE_AUTH_KEY', env('SECURE_AUTH_KEY'));
Config::define('LOGGED_IN_KEY', env('LOGGED_IN_KEY'));
Config::define('NONCE_KEY', env('NONCE_KEY'));
Config::define('AUTH_SALT', env('AUTH_SALT'));
Config::define('SECURE_AUTH_SALT', env('SECURE_AUTH_SALT'));
Config::define('LOGGED_IN_SALT', env('LOGGED_IN_SALT'));
Config::define('NONCE_SALT', env('NONCE_SALT'));

// AMS Cloud API, read by the ams-connect plugin's Api\Http\ApiConfig
Config::define('AUTOMATIC_UPDATER_DISABLED', true);
Config::define('DISABLE_WP_CRON', env('DISABLE_WP_CRON') ?: false);

// Disable the plugin and theme file editor in the admin
Config::define('DISALLOW_FILE_EDIT', true);

// Disable plugin and theme updates and installation from the admin
Config::define('DISALLOW_FILE_MODS', true);

// Limit the number of post revisions
Config::define('WP_POST_REVISIONS', env('WP_POST_REVISIONS') ?? true);

// Disable script concatenation
Config::define('CONCATENATE_SCRIPTS', false);

// Debugging Settings
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', false);
Config::define('SCRIPT_DEBUG', false);
ini_set('display_errors', '0');

// Redis Object Cache
Config::define('WP_REDIS_HOST', 'redis');
Config::define('WP_REDIS_PORT', 6379);
Config::define('WP_REDIS_PASSWORD', null);
Config::define('WP_REDIS_DISABLED', false);

/*
 * Allow WordPress to detect HTTPS when used behind a reverse proxy or a load balancer
 * See https://codex.wordpress.org/Function_Reference/is_ssl#Notes
 */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO']) {
    $_SERVER['HTTPS'] = 'on';
}

$env_config = __DIR__.'/environments/'.$wp_env.'.php';

if (file_exists($env_config)) {
    require_once $env_config;
}

Config::apply();

// Bootstrap WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', $webroot_dir.'/wp/');
}
