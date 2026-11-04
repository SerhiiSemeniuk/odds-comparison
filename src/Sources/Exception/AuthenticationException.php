<?php
/**
 * Authentication exception.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Exception;

/**
 * The source rejected the credentials: the API key is missing, invalid or
 * deactivated. The dashboard shows this as "Needs API key".
 */
final class AuthenticationException extends SourceException
{
}
