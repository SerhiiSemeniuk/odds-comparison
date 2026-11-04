<?php
/**
 * Bootstrap of the integration tests: boots a real WordPress with the plugin loaded.
 *
 * Needs a WordPress installation and an empty, disposable database; see
 * wp-tests-config.php for the environment variables.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

$oddscmp_root = dirname(__DIR__, 2);

require $oddscmp_root . '/vendor/autoload.php';

define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');

$oddscmp_tests_dir = $oddscmp_root . '/vendor/wp-phpunit/wp-phpunit';

require $oddscmp_tests_dir . '/includes/functions.php';

// Load the plugin the way WordPress would, before WordPress finishes booting.
tests_add_filter('muplugins_loaded', static function () use ($oddscmp_root): void {
    require $oddscmp_root . '/odds-comparison.php';
});

require $oddscmp_tests_dir . '/includes/bootstrap.php';

// The test support classes (fakes) are shared with the unit tests.
spl_autoload_register(static function (string $class) use ($oddscmp_root): void {
    $prefix = 'OddsComparison\\Tests\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = $oddscmp_root . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});
