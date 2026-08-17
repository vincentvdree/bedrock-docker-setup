<?php

declare(strict_types=1);

require_once ABSPATH.'wp-admin/includes/plugin.php';

$plugins = [
    'wp-crontrol/wp-crontrol.php',
    'redis-cache/redis-cache.php',
    'query-monitor/query-monitor.php',
    'simply-show-hooks/index.php',
];

foreach ($plugins as $plugin) {
    if (is_plugin_active($plugin)) {
        return;
    }

    if (!file_exists(sprintf("%s/%s", WP_PLUGIN_DIR, $plugin))) {
        error_log(sprintf("Plugin not found: %s", $plugin));

        return;
    }

    $result = activate_plugin($plugin, '', is_multisite());

    if (is_wp_error($result)) {
        error_log('Activation failed: '.$result->get_error_message());
    }
}
