<?php
/**
 * HTTP response value object.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

/**
 * Minimal, immutable HTTP response.
 */
final class HttpResponse
{
    /** @var array<string,string> Lower-cased header names. */
    public readonly array $headers;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = [],
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
