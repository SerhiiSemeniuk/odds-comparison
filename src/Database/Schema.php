<?php
/**
 * Database schema.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Database;

/**
 * Custom tables of the plugin and their versioned installation.
 *
 *  - oddscmp_bookmakers: bookmakers each source provides, with the dashboard
 *    choices (visibility, order, name, link);
 *  - oddscmp_odds: the latest odds snapshot per source, event and market.
 *
 * The schema version is stored in an option; whenever the code ships a newer
 * version, maybeUpgrade() re-runs dbDelta() on the next request, so updates
 * migrate without a manual step. Settings stay in wp_options (tiny, bounded)
 * and short-lived values (locks, source status) in transients.
 */
final class Schema
{
    public const VERSION = '1';

    private const OPTION = 'odds_comparison_db_version';

    public static function bookmakers(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'oddscmp_bookmakers';
    }

    public static function odds(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'oddscmp_odds';
    }

    /**
     * Create or update the tables.
     */
    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset    = $wpdb->get_charset_collate();
        $bookmakers = self::bookmakers();
        $odds       = self::odds();

        // dbDelta() is picky: one column per line, two spaces after PRIMARY KEY.
        // Key lengths stay under the 767-byte limit of older MySQL/utf8mb4 setups.
        dbDelta("CREATE TABLE {$bookmakers} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id varchar(32) NOT NULL,
  bookmaker_id varchar(64) NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  url text NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY source_bookmaker (source_id,bookmaker_id)
) {$charset};");

        dbDelta("CREATE TABLE {$odds} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id varchar(32) NOT NULL,
  event_id varchar(120) NOT NULL,
  market_id varchar(32) NOT NULL,
  payload longtext NOT NULL,
  fetched_at bigint(20) unsigned NOT NULL,
  fresh_until bigint(20) unsigned NOT NULL,
  starts_at bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY entry (source_id,event_id,market_id),
  KEY starts_at (starts_at),
  KEY fetched_at (fetched_at)
) {$charset};");

        update_option(self::OPTION, self::VERSION);
    }

    /**
     * Install when the stored schema version is missing or older.
     * Costs one (autoloaded) option read on a normal request.
     */
    public static function maybeUpgrade(): void
    {
        if (get_option(self::OPTION) !== self::VERSION) {
            self::install();
        }
    }

    /**
     * Remove the tables (uninstall).
     */
    public static function drop(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS ' . self::bookmakers()); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DROP TABLE IF EXISTS ' . self::odds()); // phpcs:ignore WordPress.DB.PreparedSQL
        delete_option(self::OPTION);
    }
}
