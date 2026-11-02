<?php
/**
 * Fractional formatter.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Odds\Formatters;

use OddsComparison\Contracts\FormatterInterface;
use OddsComparison\Odds\Odds;

/**
 * Renders odds as UK fractional odds, e.g. "3/2".
 */
final class FractionalFormatter implements FormatterInterface
{
    public function key(): string
    {
        return 'fractional';
    }

    public function label(): string
    {
        return __('Fractional', 'odds-comparison');
    }

    public function format(Odds $odds): string
    {
        [$numerator, $denominator] = $odds->toFractional();

        return $numerator . '/' . $denominator;
    }
}
