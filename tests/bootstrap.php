<?php
/**
 * PHPUnit bootstrap.
 *
 * The unit tests run without WordPress. The few WordPress functions the code
 * under test calls are replaced by small stand-ins below, close enough to the
 * real ones for what is being tested (options in memory, URL sanitising that
 * strips curly braces like esc_url_raw(), and so on).
 *
 * @package OddsComparison
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

/** @var array<string,mixed> $GLOBALS['oddscmp_options'] In-memory options table. */
$GLOBALS['oddscmp_options'] = [];

/** @var array<string,callable> $GLOBALS['oddscmp_filters'] Filters a test installs: tag => callable. */
$GLOBALS['oddscmp_filters'] = [];

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function _n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    return $number === 1 ? $single : $plural;
}

function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    $filter = $GLOBALS['oddscmp_filters'][$tag] ?? null;

    return $filter !== null ? $filter($value, ...$args) : $value;
}

function add_action(string $tag, mixed $callback, int $priority = 10, int $args = 1): void
{
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['oddscmp_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['oddscmp_options'][$name] = $value;

    return true;
}

function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags);
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'unit-test-salt-' . $scheme;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}

function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
}

/**
 * Mirrors esc_url_raw(): characters outside the allowed set (curly braces among
 * them) are removed and only safe protocols survive.
 */
function esc_url_raw(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = (string) preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url);

    if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m) === 1 && !in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel', 'ftp'], true)) {
        return '';
    }

    return $url;
}

// Autoload the plugin and the test support classes.
require dirname(__DIR__) . '/src/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'OddsComparison\\Tests\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});
