<?php
/**
 * Blocked exception.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Exception;

/**
 * The source refused the request (HTTP 403/429, bot challenge, rate limit).
 *
 * The HTTP layer uses this to trip its circuit breaker instead of retrying.
 */
final class BlockedException extends SourceException
{
}
