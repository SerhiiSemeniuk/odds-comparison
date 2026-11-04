<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/src/autoload.php';

// Custom tables (bookmakers, odds snapshots) and the schema version option.
\OddsComparison\Database\Schema::drop();

// Options stored by the plugin.
$oddscmp_options = [
    'odds_comparison_settings', // Settings::OPTION
    'odds_comparison_cache_ns', // OddsService transient namespace
];

foreach ($oddscmp_options as $oddscmp_option) {
    delete_option($oddscmp_option);
}

// Short-lived values (lists, locks, source status) live in transients prefixed "oddscmp_"
// (TransientCache::PREFIX). With a persistent object cache they are not in the database
// and simply expire.
global $wpdb;

foreach (['_transient_', '_transient_timeout_'] as $oddscmp_prefix) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($oddscmp_prefix . 'oddscmp_') . '%'
        )
    );
}
