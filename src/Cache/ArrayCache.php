<?php
/**
 * In-memory cache.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Cache;

use OddsComparison\Contracts\CacheInterface;

/**
 * Request-lifetime cache. Used in tests and as a no-persistence fallback.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string,array{0:mixed,1:int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        if (!isset($this->items[$key])) {
            return null;
        }

        [$value, $expires] = $this->items[$key];
        if ($expires < time()) {
            unset($this->items[$key]);

            return null;
        }

        return $value;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->items[$key] = [$value, time() + $ttl];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
