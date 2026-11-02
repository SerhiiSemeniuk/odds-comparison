<?php
/**
 * Unreachable exception.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Exception;

/**
 * The source could not be reached (DNS, timeout, 5xx, robots.txt disallow).
 */
final class UnreachableException extends SourceException
{
}
