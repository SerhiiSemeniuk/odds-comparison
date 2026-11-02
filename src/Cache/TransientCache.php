<?php
/**
 * Transient-backed cache.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Cache;

use OddsComparison\Contracts\CacheInterface;

/**
 * CacheInterface on top of WordPress transients.
 *
 * Transients use the persistent object cache (Redis, Memcached…) when one is
 * installed and fall back to the options table otherwise.
 */
final class TransientCache implements CacheInterface
{
    public const PREFIX = 'oddscmp_';

    public function get(string $key): mixed
    {
        $value = get_transient($this->name($key));

        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        set_transient($this->name($key), $value, $ttl);
    }

    public function delete(string $key): void
    {
        delete_transient($this->name($key));
    }

    /**
     * Transient names are limited to 172 characters, so long keys are hashed.
     */
    private function name(string $key): string
    {
        $key = preg_replace('/[^a-z0-9_]/i', '_', $key) ?? $key;

        return self::PREFIX . (strlen($key) > 100 ? md5($key) : $key);
    }
}
