<?php
/**
 * Odds converter service.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Odds;

use InvalidArgumentException;
use OddsComparison\Contracts\FormatterInterface;
use OddsComparison\Odds\Formatters\AmericanFormatter;
use OddsComparison\Odds\Formatters\DecimalFormatter;
use OddsComparison\Odds\Formatters\FractionalFormatter;

/**
 * Registry of odds formatters and a facade for converting between formats.
 *
 * New formats can be added through the `odds_comparison_formatters` filter.
 */
final class Converter
{
    /** @var array<string,FormatterInterface> */
    private array $formatters = [];

    /**
     * @param list<FormatterInterface>|null $formatters Defaults to the built-in set.
     */
    public function __construct(?array $formatters = null)
    {
        $formatters ??= [
            new DecimalFormatter(),
            new FractionalFormatter(),
            new AmericanFormatter(),
        ];

        if (function_exists('apply_filters')) {
            /**
             * Filters the registered odds formatters.
             *
             * @param list<FormatterInterface> $formatters Formatter instances.
             */
            $formatters = apply_filters('odds_comparison_formatters', $formatters);
        }

        foreach ($formatters as $formatter) {
            if ($formatter instanceof FormatterInterface) {
                $this->formatters[$formatter->key()] = $formatter;
            }
        }
    }

    /**
     * Render odds in the given format.
     *
     * @throws InvalidArgumentException For an unknown format key.
     */
    public function format(Odds $odds, string $format): string
    {
        if (!isset($this->formatters[$format])) {
            throw new InvalidArgumentException(sprintf('Unknown odds format "%s".', $format));
        }

        return $this->formatters[$format]->format($odds);
    }

    /**
     * Render odds in every registered format, keyed by format key.
     *
     * @return array<string,string>
     */
    public function formatAll(Odds $odds): array
    {
        $out = [];
        foreach ($this->formatters as $key => $formatter) {
            $out[$key] = $formatter->format($odds);
        }

        return $out;
    }

    /**
     * Convert a raw string in any supported format to another format.
     */
    public function convert(string $value, string $to): string
    {
        return $this->format(Odds::parse($value), $to);
    }

    /**
     * Available formats as key => label.
     *
     * @return array<string,string>
     */
    public function formats(): array
    {
        $out = [];
        foreach ($this->formatters as $key => $formatter) {
            $out[$key] = $formatter->label();
        }

        return $out;
    }

    public function has(string $format): bool
    {
        return isset($this->formatters[$format]);
    }
}
