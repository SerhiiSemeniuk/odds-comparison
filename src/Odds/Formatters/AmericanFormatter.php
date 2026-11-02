<?php
/**
 * American formatter.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Odds\Formatters;

use OddsComparison\Contracts\FormatterInterface;
use OddsComparison\Odds\Odds;

/**
 * Renders odds as US moneyline odds, e.g. "+150" or "-200".
 */
final class AmericanFormatter implements FormatterInterface
{
    public function key(): string
    {
        return 'american';
    }

    public function label(): string
    {
        return __('American', 'odds-comparison');
    }

    public function format(Odds $odds): string
    {
        $american = $odds->toAmerican();

        return ($american > 0 ? '+' : '') . $american;
    }
}
