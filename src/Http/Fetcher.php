<?php
/**
 * Polite HTTP client.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

use OddsComparison\Contracts\CacheInterface;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Sources\Exception\BlockedException;
use OddsComparison\Sources\Exception\UnreachableException;

/**
 * HttpClientInterface implementation for scraping on top of wp_remote_get().
 *
 * Being a good citizen is built in:
 *  - identifies itself honestly (no browser spoofing);
 *  - obeys the host's robots.txt (cached for 12 hours);
 *  - spaces requests to the same host by a minimum interval;
 *  - honours Retry-After and stops after repeated failures (circuit breaker);
 *  - retries transient 5xx / network errors once.
 *
 * It never tries to defeat bot protection: a challenge page is reported as a
 * block and the source is left alone until the cooldown ends.
 */
final class Fetcher implements HttpClientInterface
{
    private const ROBOTS_TTL      = 12 * HOUR_IN_SECONDS;
    private const ROBOTS_FAIL_TTL = 15 * MINUTE_IN_SECONDS;
    private const MAX_THROTTLE_US = 2_000_000;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly CircuitBreaker $breaker,
        private readonly int $minIntervalMs = 1000,
        private readonly int $timeout = 10,
    ) {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $parts = wp_parse_url($url);
        $host  = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new UnreachableException(sprintf('Invalid URL "%s".', $url));
        }

        $this->guardCircuit($host);
        $this->guardRobots($parts, $host);
        $this->throttle($host);

        $response = $this->request($url, $headers);
        $this->record($host, $response);

        return $response;
    }

    /**
     * @throws BlockedException When the circuit for the host is open.
     */
    private function guardCircuit(string $host): void
    {
        $remaining = $this->breaker->remaining($host);

        if ($remaining > 0) {
            throw new BlockedException(sprintf(
                /* translators: 1: host name, 2: seconds */
                __('Requests to %1$s are paused for another %2$d s after repeated failures.', 'odds-comparison'),
                $host,
                $remaining
            ));
        }
    }

    /**
     * @param array<string,mixed> $parts Result of wp_parse_url().
     * @throws UnreachableException When robots.txt disallows the path.
     */
    private function guardRobots(array $parts, string $host): void
    {
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        if (!$this->robots($parts['scheme'] . '://' . $host)->isAllowed($path)) {
            throw new UnreachableException(sprintf(
                /* translators: %s: URL path */
                __('The path %s is disallowed by robots.txt.', 'odds-comparison'),
                $path
            ));
        }
    }

    private function robots(string $origin): RobotsTxt
    {
        $key  = 'robots_' . md5($origin);
        $body = $this->cache->get($key);

        if (!is_string($body)) {
            $reply = wp_remote_get($origin . '/robots.txt', $this->args([]));
            $code  = is_wp_error($reply) ? 0 : (int) wp_remote_retrieve_response_code($reply);

            if ($code >= 200 && $code < 300) {
                $body = (string) wp_remote_retrieve_body($reply);
                $this->cache->set($key, $body, self::ROBOTS_TTL);
            } else {
                // No robots.txt (4xx) means no restrictions; an error is retried soon.
                $body = '';
                $this->cache->set($key, $body, $code >= 400 && $code < 500 ? self::ROBOTS_TTL : self::ROBOTS_FAIL_TTL);
            }
        }

        return new RobotsTxt($body, $this->agentToken());
    }

    /**
     * Sleep just long enough to respect the per-host minimum interval.
     */
    private function throttle(string $host): void
    {
        $key  = 'last_' . md5($host);
        $last = $this->cache->get($key);

        if (is_float($last)) {
            $wait = (int) (($last + $this->minIntervalMs / 1000 - microtime(true)) * 1_000_000);
            if ($wait > 0) {
                usleep(min($wait, self::MAX_THROTTLE_US));
            }
        }

        $this->cache->set($key, microtime(true), 60);
    }

    /**
     * @param array<string,string> $headers
     */
    private function request(string $url, array $headers): HttpResponse
    {
        $args = $this->args($headers);
        $last = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = wp_remote_get($url, $args);

            if (is_wp_error($reply)) {
                $last = new UnreachableException($reply->get_error_message());
            } else {
                $status = (int) wp_remote_retrieve_response_code($reply);
                if ($status < 500) {
                    return new HttpResponse(
                        $status,
                        (string) wp_remote_retrieve_body($reply),
                        $this->flattenHeaders(wp_remote_retrieve_headers($reply))
                    );
                }
                $last = new UnreachableException(sprintf('HTTP %d', $status));
            }

            if ($attempt === 0) {
                usleep(500_000);
            }
        }

        $this->breaker->recordFailure((string) wp_parse_url($url, PHP_URL_HOST));
        throw $last;
    }

    private function record(string $host, HttpResponse $response): void
    {
        $blocked = in_array($response->status, [403, 429], true) || $response->header('cf-mitigated') !== '';

        if (!$blocked) {
            $this->breaker->recordSuccess($host);

            return;
        }

        $retry = $response->header('retry-after');
        $this->breaker->recordFailure($host, ctype_digit($retry) ? (int) $retry : null);
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function args(array $headers): array
    {
        $args = [
            'timeout'     => $this->timeout,
            'redirection' => 3,
            'user-agent'  => $this->userAgent(),
            'headers'     => $headers + ['Accept' => 'text/html,application/json;q=0.9,*/*;q=0.5'],
        ];

        /**
         * Filters the wp_remote_get() arguments used for scraping.
         *
         * Use it to route requests through your own proxy or rendering service.
         *
         * @param array<string,mixed> $args Request arguments.
         */
        return apply_filters('odds_comparison_http_args', $args);
    }

    private function agentToken(): string
    {
        return 'OddsComparisonBot';
    }

    private function userAgent(): string
    {
        return sprintf('%s/%s (+%s)', $this->agentToken(), ODDSCMP_VERSION, home_url('/'));
    }

    /**
     * @param \WpOrg\Requests\Utility\CaseInsensitiveDictionary|array<string,mixed> $headers
     * @return array<string,string>
     */
    private function flattenHeaders(mixed $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $out;
    }
}
