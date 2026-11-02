<?php
/**
 * Health state enum.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

/**
 * Availability states a source can report to the admin dashboard.
 */
enum HealthState: string
{
    case Available      = 'available';
    case Blocked        = 'blocked';
    case Unreachable    = 'unreachable';
    case ParserMismatch = 'parser_mismatch';
    case NeedsKey       = 'needs_key';
    case Unknown        = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Available      => __('Available', 'odds-comparison'),
            self::Blocked        => __('Blocked', 'odds-comparison'),
            self::Unreachable    => __('Unreachable', 'odds-comparison'),
            self::ParserMismatch => __('Parser mismatch', 'odds-comparison'),
            self::NeedsKey       => __('Needs API key', 'odds-comparison'),
            self::Unknown        => __('Not checked', 'odds-comparison'),
        };
    }

    /**
     * Whether data can be requested from a source in this state.
     */
    public function isUsable(): bool
    {
        return $this === self::Available;
    }
}
