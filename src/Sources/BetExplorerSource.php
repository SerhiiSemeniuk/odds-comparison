<?php
/**
 * BetExplorer source.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

use DOMElement;
use DOMXPath;
use OddsComparison\Sources\Exception\ParseException;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\Market;
use OddsComparison\Sources\Model\OddsSnapshot;
use OddsComparison\Sources\Model\Outcome;
use OddsComparison\Sources\Model\Sport;

/**
 * Scrapes betexplorer.com.
 *
 * What the site exposes in its server-rendered HTML:
 *  - the list of sports (top menu);
 *  - leagues per sport and matches per league;
 *  - ONE aggregated price per outcome (1X2 / moneyline) for each match.
 *
 * Per-bookmaker prices are loaded by JavaScript and are deliberately not
 * scraped, so this source reports the aggregate under the pseudo bookmaker
 * id "average". Use the API or fixture source for real bookmaker comparison.
 *
 * Only clean URLs are requested (the site's robots.txt disallows query
 * strings, /bookmaker/ and /redirect/); the Fetcher enforces robots.txt anyway.
 */
final class BetExplorerSource extends AbstractScraperSource
{
    public const ID = 'betexplorer';

    /** Pseudo bookmaker id for the aggregated price. */
    public const AVERAGE = 'average';

    /** Leagues read per sport when listing events (each one is an HTTP request). */
    private const MAX_LEAGUES = 3;

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'BetExplorer';
    }

    public function description(): string
    {
        return __('Scrapes sports, leagues, matches and average odds from betexplorer.com. No per-bookmaker prices.', 'odds-comparison');
    }

    protected function baseUrl(): string
    {
        return 'https://www.betexplorer.com';
    }

    /**
     * @return list<Sport>
     */
    public function sports(): array
    {
        $xpath  = $this->xpath('/');
        $nodes  = $xpath->query('//a[' . $this->hasClass('menu__item__in') . '][starts-with(@href, "/")]');
        $sports = [];

        foreach ($nodes ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $slug = trim($node->getAttribute('href'), '/');
            $name = $this->sportName($node);

            // The site's default sport (football) links to the root "/".
            if ($slug === '') {
                $slug = strtolower(str_replace(' ', '-', $name));
            }

            if ($slug === '' || str_contains($slug, '/') || isset($sports[$slug])) {
                continue;
            }

            $sports[$slug] = new Sport($slug, $name !== '' ? $name : ucwords(str_replace('-', ' ', $slug)));
        }

        return array_values($sports);
    }

    /**
     * Matches of the first few leagues listed on the sport page.
     *
     * @return list<Event>
     */
    public function events(string $sportId): array
    {
        $sportId = trim($sportId, '/');
        $xpath   = $this->xpath('/' . $sportId . '/');
        $leagues = [];

        foreach ($xpath->query('//a[' . $this->hasClass('table-main__tournament') . ']/@href') ?: [] as $href) {
            $leagues[] = (string) $href->nodeValue;
        }

        $events = [];
        foreach (array_slice(array_unique($leagues), 0, self::MAX_LEAGUES) as $league) {
            $events = array_merge($events, $this->leagueEvents($league, $sportId));
        }

        return $events;
    }

    /**
     * @return list<Market>
     */
    public function markets(Event $event): array
    {
        $row = $this->findRow($event);

        return [
            new Market(
                count($row['prices']) === 3 ? '1x2' : 'moneyline',
                count($row['prices']) === 3
                    ? __('Match Winner (1X2)', 'odds-comparison')
                    : __('Moneyline', 'odds-comparison')
            ),
        ];
    }

    public function odds(Event $event, string $marketId): OddsSnapshot
    {
        $row   = $this->findRow($event);
        $names = $row['teams'];
        $three = count($row['prices']) === 3;

        $labels = $three
            ? [$names[0], __('Draw', 'odds-comparison'), $names[1]]
            : [$names[0], $names[1]];

        $outcomes = [];
        foreach ($row['prices'] as $i => $price) {
            $outcomes[] = new Outcome($labels[$i] ?? (string) ($i + 1), [self::AVERAGE => $price]);
        }

        return new OddsSnapshot($event->id, $marketId, self::ID, $outcomes, time());
    }

    /**
     * Parse the matches of one league page.
     *
     * @return list<Event>
     * @throws ParseException When the page has no matches table at all.
     */
    private function leagueEvents(string $leaguePath, string $sportId): array
    {
        $xpath  = $this->xpath($leaguePath);
        $league = $this->leagueName($xpath);
        $dates  = $this->startDates($xpath);
        $events = [];

        foreach ($this->rows($xpath) as $row) {
            $url      = $row['url'];
            $events[] = new Event(
                $row['id'],
                $sportId,
                implode(' v ', $row['teams']),
                $url,
                $league,
                $dates[$this->absoluteUrl($url)] ?? null,
            );
        }

        return $events;
    }

    /**
     * Locate an event's row on its league page (the event URL minus the last two segments).
     *
     * @return array{id:string,url:string,teams:array{0:string,1:string},prices:list<float>}
     * @throws ParseException When the event is no longer listed.
     */
    private function findRow(Event $event): array
    {
        $segments = explode('/', trim((string) wp_parse_url($event->url, PHP_URL_PATH), '/'));
        $league   = '/' . implode('/', array_slice($segments, 0, -2)) . '/';

        foreach ($this->rows($this->xpath($league)) as $row) {
            if ($row['id'] === $event->id) {
                return $row;
            }
        }

        throw new ParseException(sprintf(
            /* translators: %s: event name */
            __('Event "%s" is no longer listed on its league page.', 'odds-comparison'),
            $event->name
        ));
    }

    /**
     * Extract match rows: link, two team names and the decimal prices.
     *
     * @return list<array{id:string,url:string,teams:array{0:string,1:string},prices:list<float>}>
     */
    private function rows(DOMXPath $xpath): array
    {
        $rows = [];

        foreach ($xpath->query('//tr[.//a[' . $this->hasClass('in-match') . ']]') ?: [] as $tr) {
            $link  = $xpath->query('.//a[' . $this->hasClass('in-match') . ']', $tr)?->item(0);
            $spans = $xpath->query('.//a[' . $this->hasClass('in-match') . ']/span', $tr);

            if (!$link instanceof DOMElement || $spans === false || $spans->length < 2) {
                continue;
            }

            $url  = $link->getAttribute('href');
            $path = array_values(array_filter(explode('/', $url)));
            $id   = (string) end($path);

            $prices = [];
            foreach ($xpath->query('.//td[' . $this->hasClass('table-main__odds') . ']//@data-odd', $tr) ?: [] as $attr) {
                $price = $this->parseDecimal((string) $attr->nodeValue);
                if ($price !== null) {
                    $prices[] = $price;
                }
            }

            if ($id === '' || !in_array(count($prices), [2, 3], true)) {
                continue;
            }

            $rows[] = [
                'id'     => $id,
                'url'    => $url,
                'teams'  => [trim((string) $spans->item(0)?->textContent), trim((string) $spans->item(1)?->textContent)],
                'prices' => $prices,
            ];
        }

        return $rows;
    }

    /**
     * Start times from the page's schema.org JSON-LD, keyed by event URL.
     *
     * @return array<string,int>
     */
    private function startDates(DOMXPath $xpath): array
    {
        $dates = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode($script->textContent, true);
            if (!is_array($data)) {
                continue;
            }

            foreach (array_is_list($data) ? $data : [$data] as $item) {
                if (is_array($item) && isset($item['url'], $item['startDate'])) {
                    $time = strtotime((string) $item['startDate']);
                    if ($time !== false) {
                        $dates[(string) $item['url']] = $time;
                    }
                }
            }
        }

        return $dates;
    }

    /**
     * Menu link label, e.g. "Basketball".
     */
    private function sportName(DOMElement $node): string
    {
        return trim($node->textContent);
    }

    /**
     * League name from the page header, without the season suffix
     * ("Premier League 2026/2027 Table & Stats" becomes "Premier League").
     * The page also contains an age-gate h1, so the plain h1 is not used.
     */
    private function leagueName(DOMXPath $xpath): string
    {
        $title = trim((string) $xpath->evaluate(
            'string(//*[' . $this->hasClass('wrap-section__header__title') . '][1])'
        ));

        return trim(preg_replace('/\s+\d{4}(\/\d{2,4})?.*$/', '', $title) ?? $title);
    }

    /**
     * XPath predicate matching a whole class token.
     */
    private function hasClass(string $class): string
    {
        return sprintf('contains(concat(" ", normalize-space(@class), " "), " %s ")', $class);
    }
}
