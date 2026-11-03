<?php
/**
 * Odds table block.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Block;

use InvalidArgumentException;
use OddsComparison\Plugin;
use OddsComparison\Rest\TableController;

/**
 * Server-side rendering of the `odds-comparison/odds-table` block.
 *
 * The block is dynamic and deliberately renders no odds in its HTML: it prints
 * a loading placeholder carrying a signed configuration, and view.js fetches
 * the odds from the REST API. That keeps pages fully cacheable and guarantees
 * visitors never see odds that were frozen into cached HTML.
 */
final class OddsTableBlock
{
    public function __construct(private readonly Plugin $plugin, private readonly ConfigSigner $signer)
    {
    }

    /**
     * Validate block attributes into the configuration sent to the REST API.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>|null Null when the block is not fully set up or invalid.
     */
    public function config(array $attributes): ?array
    {
        $source = (string) ($attributes['source'] ?? '');
        $market = sanitize_key((string) ($attributes['market'] ?? ''));

        if ($market === '' || !$this->plugin->sources()->has($source)) {
            return null;
        }

        try {
            $event = $this->plugin->odds()->makeEvent(
                (string) ($attributes['sport'] ?? ''),
                (string) ($attributes['eventId'] ?? ''),
                sanitize_text_field((string) ($attributes['eventName'] ?? '')),
                (string) ($attributes['eventPath'] ?? ''),
                (int) ($attributes['eventStarts'] ?? 0)
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        $bookmakers = array_values(array_unique(array_filter(
            array_map('sanitize_key', array_map('strval', (array) ($attributes['bookmakers'] ?? [])))
        )));

        return [
            'source'     => $source,
            'sport'      => $event->sportId,
            'event'      => $event->id,
            'name'       => $event->name,
            'path'       => $event->url,
            'starts'     => (int) $event->startsAt,
            'market'     => $market,
            'bookmakers' => $bookmakers,
        ];
    }

    /**
     * Render callback.
     *
     * @param array<string,mixed> $attributes
     */
    public function render(array $attributes): string
    {
        $config = $this->config($attributes);

        if ($config === null) {
            return current_user_can('edit_posts')
                ? '<p class="oddscmp-notice">' . esc_html__('Odds Comparison: choose an event and a market in the block settings.', 'odds-comparison') . '</p>'
                : '';
        }

        $signed   = $this->signer->sign($config);
        $settings = $this->plugin->settings();

        $wrapper = get_block_wrapper_attributes([
            'class'            => 'oddscmp',
            'data-oddscmp'     => '',
            'data-endpoint'    => esc_url_raw(rest_url(TableController::NAMESPACE . '/table')),
            'data-token'       => $signed['token'],
            'data-sig'         => $signed['sig'],
            'data-format'      => $settings->format(),
            'data-highlight'   => !empty($attributes['highlightBest'] ?? true) ? '1' : '0',
            'data-switch'      => !empty($attributes['showFormatSwitch'] ?? true) ? '1' : '0',
            'data-i18n'        => (string) wp_json_encode($this->strings()),
        ]);

        return sprintf(
            '<div %1$s><div class="oddscmp__status" role="status" aria-live="polite"><span class="oddscmp__spinner" aria-hidden="true"></span>%2$s</div><noscript>%3$s</noscript></div>',
            $wrapper,
            esc_html__('Loading odds…', 'odds-comparison'),
            esc_html__('Odds need JavaScript to load.', 'odds-comparison')
        );
    }

    /**
     * Strings used by view.js (kept here so they are translated with the plugin).
     *
     * @return array<string,string>
     */
    private function strings(): array
    {
        return [
            'loading'  => __('Loading odds…', 'odds-comparison'),
            'error'    => __('Odds are temporarily unavailable.', 'odds-comparison'),
            'updated'  => __('Updated', 'odds-comparison'),
            'finished' => __('This event has finished. Odds are no longer updated.', 'odds-comparison'),
            'bet'      => __('Bet', 'odds-comparison'),
            'format'   => __('Odds format', 'odds-comparison'),
            'noOdds'   => __('No odds available.', 'odds-comparison'),
            'ago'      => __('ago', 'odds-comparison'),
            'justNow'  => __('just now', 'odds-comparison'),
            'sec'      => __('s', 'odds-comparison'),
            'min'      => __('min', 'odds-comparison'),
            'hour'     => __('h', 'odds-comparison'),
            'best'     => __('Best odds', 'odds-comparison'),
        ];
    }
}
