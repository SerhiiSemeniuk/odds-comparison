<?php
/**
 * Plugin settings.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Settings;

/**
 * Typed access to the plugin's single settings option.
 *
 * Values are normalised on save, so readers can trust their types.
 */
final class Settings
{
    public const OPTION = 'odds_comparison_settings';

    /**
     * @return array<string,mixed>
     */
    public function defaults(): array
    {
        return [
            // Source used first; the others are tried in order when it is unavailable.
            'source'      => 'bestodds',
            'fallback'    => ['betexplorer'],
            // Seconds a cached odds snapshot is considered fresh.
            'ttl'         => 300,
            // Default display format (decimal | fractional | american).
            'format'      => 'decimal',
            // Enabled market ids.
            'markets'     => array_keys($this->markets()),
            'link_rel'    => 'sponsored nofollow noopener',
            'link_target' => '_blank',
        ];
    }

    /**
     * Market ids the plugin knows about, with labels.
     *
     * @return array<string,string>
     */
    public function markets(): array
    {
        $markets = [
            '1x2'       => __('Match Winner (1X2)', 'odds-comparison'),
            'moneyline' => __('Moneyline', 'odds-comparison'),
            'spread'    => __('Spread', 'odds-comparison'),
            'total'     => __('Total (Over/Under)', 'odds-comparison'),
        ];

        /**
         * Filters the markets that can be enabled in the settings.
         *
         * @param array<string,string> $markets Market id => label.
         */
        return apply_filters('odds_comparison_markets', $markets);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge($this->defaults(), is_array($stored) ? $stored : []);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function source(): string
    {
        return (string) $this->get('source');
    }

    /**
     * Source ids in the order they are tried: the active one, then fallbacks.
     *
     * @return list<string>
     */
    public function sourceChain(): array
    {
        return array_values(array_unique(array_merge([$this->source()], (array) $this->get('fallback'))));
    }

    public function ttl(): int
    {
        return (int) $this->get('ttl');
    }

    public function format(): string
    {
        return (string) $this->get('format');
    }

    /**
     * @return list<string>
     */
    public function enabledMarkets(): array
    {
        return array_values(array_map('strval', (array) $this->get('markets')));
    }

    /**
     * Validate raw input and store it. Unknown keys are dropped.
     *
     * @param array<string,mixed> $input        Raw values (e.g. from a form).
     * @param list<string>        $validSources Registered source ids.
     * @param list<string>        $validFormats Registered format keys.
     * @return array<string,mixed> The stored settings.
     */
    public function save(array $input, array $validSources, array $validFormats): array
    {
        $current = $this->all();

        $source = (string) ($input['source'] ?? $current['source']);
        $source = in_array($source, $validSources, true) ? $source : $current['source'];

        $fallback = array_values(array_filter(
            array_map('strval', (array) ($input['fallback'] ?? $current['fallback'])),
            static fn (string $id): bool => in_array($id, $validSources, true) && $id !== $source
        ));

        $format = (string) ($input['format'] ?? $current['format']);

        $markets = array_values(array_intersect(
            array_map('strval', (array) ($input['markets'] ?? $current['markets'])),
            array_keys($this->markets())
        ));

        $target = (string) ($input['link_target'] ?? $current['link_target']);

        $clean = [
            'source'      => $source,
            'fallback'    => array_values(array_unique($fallback)),
            'ttl'         => max(60, min(DAY_IN_SECONDS, (int) ($input['ttl'] ?? $current['ttl']))),
            'format'      => in_array($format, $validFormats, true) ? $format : $current['format'],
            'markets'     => $markets,
            'link_rel'    => sanitize_text_field((string) ($input['link_rel'] ?? $current['link_rel'])),
            'link_target' => in_array($target, ['_blank', '_self'], true) ? $target : '_blank',
        ];

        update_option(self::OPTION, $clean, false);

        return $clean;
    }
}
