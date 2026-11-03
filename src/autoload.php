<?php
/**
 * PSR-4 style autoloader for the OddsComparison namespace (no Composer needed).
 *
 * @package OddsComparison
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'OddsComparison\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});
