<?php
/**
 * Odds value object.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Odds;

use InvalidArgumentException;

/**
 * Immutable odds value, stored internally as decimal (European) odds.
 *
 * Decimal odds are the canonical representation: every other format is derived
 * from them, so conversions never drift through repeated round trips.
 */
final class Odds
{
    /** Largest denominator tried when approximating a fractional price. */
    private const MAX_DENOMINATOR = 100;

    /** Tolerance when snapping a decimal price to a "traders' fraction". */
    private const FRACTION_TOLERANCE = 0.0035;

    private function __construct(private readonly float $decimal)
    {
    }

    /**
     * @throws InvalidArgumentException When odds are not greater than 1.0.
     */
    public static function fromDecimal(float $decimal): self
    {
        if (!is_finite($decimal) || $decimal <= 1.0) {
            throw new InvalidArgumentException('Decimal odds must be greater than 1.0.');
        }

        return new self($decimal);
    }

    /**
     * @throws InvalidArgumentException When the fraction is not positive.
     */
    public static function fromFractional(int $numerator, int $denominator): self
    {
        if ($numerator <= 0 || $denominator <= 0) {
            throw new InvalidArgumentException('Fractional odds need a positive numerator and denominator.');
        }

        return new self(1.0 + $numerator / $denominator);
    }

    /**
     * @throws InvalidArgumentException When the price is between -100 and +100.
     */
    public static function fromAmerican(int $american): self
    {
        if ($american >= 100) {
            return new self(1.0 + $american / 100);
        }

        if ($american <= -100) {
            return new self(1.0 + 100 / abs($american));
        }

        throw new InvalidArgumentException('American odds must be <= -100 or >= +100.');
    }

    /**
     * Parse a string in any supported format.
     *
     * Accepts "2.50", "3/2", "EVS" / "evens", "+150" and "-200".
     *
     * @throws InvalidArgumentException When the string cannot be parsed.
     */
    public static function parse(string $value): self
    {
        $value = strtolower(trim($value));

        if (in_array($value, ['evs', 'evens', 'ev'], true)) {
            return self::fromFractional(1, 1);
        }

        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $value, $m) === 1) {
            return self::fromFractional((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^[+-]\d+$/', $value) === 1) {
            return self::fromAmerican((int) $value);
        }

        if (is_numeric($value)) {
            return self::fromDecimal((float) $value);
        }

        throw new InvalidArgumentException(sprintf('Unrecognised odds value "%s".', $value));
    }

    public function toDecimal(): float
    {
        return $this->decimal;
    }

    /**
     * Fractional price as [numerator, denominator], snapped to the smallest
     * denominator within tolerance, so 1.91 becomes 10/11 rather than 91/100.
     *
     * @return array{0:int,1:int}
     */
    public function toFractional(): array
    {
        $profit  = $this->decimal - 1.0;
        $best    = [(int) round($profit * self::MAX_DENOMINATOR), self::MAX_DENOMINATOR];
        $bestErr = INF;

        for ($den = 1; $den <= self::MAX_DENOMINATOR; $den++) {
            $num = max(1, (int) round($profit * $den));
            $err = abs($num / $den - $profit);

            if ($err <= self::FRACTION_TOLERANCE) {
                return [$num, $den];
            }

            if ($err < $bestErr) {
                $bestErr = $err;
                $best    = [$num, $den];
            }
        }

        return $best;
    }

    public function toAmerican(): int
    {
        $profit = $this->decimal - 1.0;

        if ($this->decimal >= 2.0) {
            return (int) round($profit * 100);
        }

        return -(int) round(100 / $profit);
    }
}
