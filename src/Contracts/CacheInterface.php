<?php
/**
 * Cache contract.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

/**
 * Minimal key/value cache with a TTL. Values must be serialisable.
 */
interface CacheInterface
{
    /**
     * @return mixed The stored value, or null on a miss.
     */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl): void;

    public function delete(string $key): void;
}
