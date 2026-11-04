<?php
/**
 * Configuration of the WordPress test suite.
 *
 * Everything comes from the environment so the same file works on a laptop and
 * in CI:
 *
 *   WP_CORE_DIR          path to a WordPress installation (core files only are used)
 *   WP_TESTS_DB_NAME     an EMPTY database that may be wiped (default: wordpress_test)
 *   WP_TESTS_DB_USER     default: root
 *   WP_TESTS_DB_PASSWORD default: empty
 *   WP_TESTS_DB_HOST     default: 127.0.0.1
 *
 * The suite drops every table in that database. Never point it at a real site.
 *
 * @package OddsComparison
 */

$oddscmp_core = getenv('WP_CORE_DIR');
if ($oddscmp_core === false || $oddscmp_core === '') {
    $oddscmp_core = dirname(__DIR__, 2) . '/tools/wordpress';
}

define('ABSPATH', rtrim(str_replace('\\', '/', $oddscmp_core), '/') . '/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress_test');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') !== false ? getenv('WP_TESTS_DB_PASSWORD') : '');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('AUTH_KEY', 'oddscmp-tests-auth-key');
define('SECURE_AUTH_KEY', 'oddscmp-tests-secure-auth-key');
define('LOGGED_IN_KEY', 'oddscmp-tests-logged-in-key');
define('NONCE_KEY', 'oddscmp-tests-nonce-key');
define('AUTH_SALT', 'oddscmp-tests-auth-salt');
define('SECURE_AUTH_SALT', 'oddscmp-tests-secure-auth-salt');
define('LOGGED_IN_SALT', 'oddscmp-tests-logged-in-salt');
define('NONCE_SALT', 'oddscmp-tests-nonce-salt');

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Odds Comparison Tests');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
define('WP_DEBUG', true);
