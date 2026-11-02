<?php
/**
 * Decimal formatter.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Odds\Formatters;

use OddsComparison\Contracts\FormatterInterface;
use OddsComparison\Odds\Odds;

/**
 * Renders odds as European decimal odds, e.g. "2.50".
 */
final class DecimalFormatter implements FormatterInterface
{
    public function key(): string
    {
        return 'decimal';
    }

    public function label(): string
    {
        return __('Decimal', 'odds-comparison');
    }

    public function format(Odds $odds): string
    {
        return number_format($odds->toDecimal(), 2, '.', '');
    }
}
