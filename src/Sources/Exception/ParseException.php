<?php
/**
 * Parse exception.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Exception;

/**
 * The page was fetched but its structure no longer matches what the parser
 * expects (the site changed its markup).
 */
final class ParseException extends SourceException
{
}
