<?php
/**
 * Per-host circuit breaker.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use OddsComparison\Contracts\CacheInterface;

/**
 * Stops hammering a host that keeps refusing us.
 *
 * After `threshold` consecutive failures the circuit opens for `cooldown`
 * seconds (or the server's Retry-After, when larger). While open, requests to
 * the host fail immediately, which protects both the site's speed and our
 * standing with the scraped service.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $threshold = 3,
        private readonly int $cooldown = 900,
    ) {
    }

    /**
     * Seconds until the circuit closes again, or 0 when it is closed.
     */
    public function remaining(string $host): int
    {
        $state = $this->state($host);

        return max(0, $state['until'] - time());
    }

    public function isOpen(string $host): bool
    {
        return $this->remaining($host) > 0;
    }

    /**
     * @param int|null $retryAfter Seconds requested by the server, if any.
     */
    public function recordFailure(string $host, ?int $retryAfter = null): void
    {
        $state = $this->state($host);
        $fails = $state['fails'] + 1;
        $until = $state['until'];

        // A Retry-After header opens the circuit immediately, honouring the server.
        if ($retryAfter !== null && $retryAfter > 0) {
            $until = time() + $retryAfter;
        } elseif ($fails >= $this->threshold) {
            $until = time() + $this->cooldown;
        }

        $this->cache->set($this->key($host), ['fails' => $fails, 'until' => $until], max($this->cooldown, $retryAfter ?? 0) * 2);
    }

    public function recordSuccess(string $host): void
    {
        $this->cache->delete($this->key($host));
    }

    /**
     * @return array{fails:int,until:int}
     */
    private function state(string $host): array
    {
        $state = $this->cache->get($this->key($host));

        return is_array($state)
            ? ['fails' => (int) ($state['fails'] ?? 0), 'until' => (int) ($state['until'] ?? 0)]
            : ['fails' => 0, 'until' => 0];
    }

    private function key(string $host): string
    {
        return 'cb_' . md5($host);
    }
}
