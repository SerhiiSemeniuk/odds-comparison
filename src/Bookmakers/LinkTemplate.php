<?php
/**
 * Bookmaker link template.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Bookmakers;

use OddsComparison\Sources\Model\Event;

/**
 * Link templates for the "Bet" buttons.
 *
 * A bookmaker's link may contain placeholders that are filled in for the event
 * shown in the table, so one setting can send visitors to a sport page, a
 * search or an affiliate network's deep-link URL:
 *
 *   {sport}   sport id as used by the source, e.g. "nba"
 *   {event}   event id, e.g. "hornets-vs-pistons-02-11-2026"
 *   {name}    event name, e.g. "Charlotte Hornets v Detroit Pistons"
 *   {market}  market id, e.g. "moneyline"
 *
 * Values are URL-encoded when inserted. The sources do not know a bookmaker's
 * own event ids, so templates cannot point at one specific bet slip.
 */
final class LinkTemplate
{
    public const PLACEHOLDERS = ['sport', 'event', 'name', 'market'];

    /**
     * Sanitise a URL entered in the dashboard while keeping its placeholders.
     *
     * esc_url_raw() strips curly braces, so placeholders are swapped for safe
     * markers around it.
     */
    public static function sanitize(string $template): string
    {
        $safe = $template;
        foreach (self::PLACEHOLDERS as $name) {
            $safe = str_replace('{' . $name . '}', self::marker($name), $safe);
        }

        $clean = esc_url_raw($safe);

        foreach (self::PLACEHOLDERS as $name) {
            $clean = str_replace(self::marker($name), '{' . $name . '}', $clean);
        }

        return $clean;
    }

    /**
     * Fill the placeholders for an event and market.
     */
    public static function expand(string $template, Event $event, string $marketId): string
    {
        if (!str_contains($template, '{')) {
            return $template;
        }

        $values = [
            '{sport}'  => $event->sportId,
            '{event}'  => $event->id,
            '{name}'   => $event->name,
            '{market}' => $marketId,
        ];

        return strtr($template, array_map('rawurlencode', $values));
    }

    private static function marker(string $name): string
    {
        return '__oddscmp_' . $name . '__';
    }
}
