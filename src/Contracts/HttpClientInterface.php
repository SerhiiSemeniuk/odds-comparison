<?php
/**
 * HTTP client contract.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

use OddsComparison\Http\HttpResponse;
use OddsComparison\Sources\Exception\SourceException;

/**
 * Transport used by scrapers. Keeping it behind an interface lets tests feed
 * saved HTML to a parser and lets the production client add rate limiting,
 * robots.txt checks and a circuit breaker without the parsers knowing.
 */
interface HttpClientInterface
{
    /**
     * Perform a GET request.
     *
     * @param array<string,string> $headers Extra request headers.
     * @throws SourceException On transport failure, robots.txt disallow or open circuit.
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
