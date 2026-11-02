<?php
/**
 * Odds formatter contract.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

use OddsComparison\Odds\Odds;

/**
 * Strategy that renders an Odds value in one display format.
 */
interface FormatterInterface
{
    /**
     * Machine key, e.g. "decimal". Used in settings, REST and block attributes.
     */
    public function key(): string;

    /**
     * Human readable label shown in the UI.
     */
    public function label(): string;

    /**
     * Render the odds as a string.
     */
    public function format(Odds $odds): string;
}
