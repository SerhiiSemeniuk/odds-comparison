<?php
/**
 * Base source exception.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Exception;

use RuntimeException;

/**
 * Base class of every error raised while talking to an odds source.
 */
class SourceException extends RuntimeException
{
}
