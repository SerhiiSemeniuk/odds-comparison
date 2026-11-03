<?php
/**
 * BestOdds source.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

use DOMElement;
use OddsComparison\Sources\Exception\ParseException;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\Market;
use OddsComparison\Sources\Model\OddsSnapshot;
use OddsComparison\Sources\Model\Outcome;
use OddsComparison\Sources\Model\Sport;
use OddsComparison\Support\NuxtPayload;

/**
 * Scrapes bestodds.com (US sports: NBA, NFL, MLB, NHL).
 *
 * The pages are server-rendered with a Nuxt data payload that contains the
 * real, current prices of a dozen sportsbooks (bet365, DraftKings, FanDuel,
 * BetMGM, Caesars, Unibet…) as American odds. We read that payload instead of
 * the HTML tables because it is structured and far more stable than markup.
 *
 * Only clean event URLs are requested; the site's robots.txt disallows query
 * strings and /visit/ redirects, and the Fetcher enforces robots.txt anyway.
 */
final class BestOddsSource extends AbstractScraperSource
{
    public const ID = 'bestodds';

    /** Market id => oddType in the payload. */
    private const MARKETS = [
        'moneyline' => 'moneyline',
        'spread'    => 'spread',
        'total'     => 'overUnder',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'BestOdds';
    }

    public function description(): string
    {
        return __('Scrapes US sports (NBA, NFL, MLB, NHL) from bestodds.com with live prices from about a dozen sportsbooks.', 'odds-comparison');
    }

    protected function baseUrl(): string
    {
        return 'https://www.bestodds.com';
    }

    /**
     * @return list<Sport>
     */
    public function sports(): array
    {
        $sports = [];

        foreach ($this->xpath('/odds')->query('//a/@href') ?: [] as $href) {
            if (preg_match('#^/odds/([a-z0-9-]+)$#', (string) $href->nodeValue, $m) === 1 && !isset($sports[$m[1]])) {
                $sports[$m[1]] = new Sport($m[1], strtoupper($m[1]));
            }
        }

        return array_values($sports);
    }

    /**
     * @return list<Event>
     */
    public function events(string $sportId): array
    {
        $sportId = trim($sportId, '/');
        $page    = $this->fetch('/odds/' . $sportId);
        $games   = $this->games($page->body);
        $xpath   = $this->parseHtml($page->body);
        $events  = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $a) {
            if (!$a instanceof DOMElement) {
                continue;
            }

            $path = $a->getAttribute('href');
            // Only dated, query-less event URLs; other links on the page are tabs or truncated.
            if (preg_match('#^/odds/' . preg_quote($sportId, '#') . '/event/([a-z0-9-]+-\d{2}-\d{2}-\d{4})$#', $path, $m) !== 1
                || isset($events[$m[1]])
            ) {
                continue;
            }

            $events[$m[1]] = $this->event($m[1], $sportId, $path, $games);
        }

        return array_values($events);
    }

    /**
     * @return list<Market>
     */
    public function markets(Event $event): array
    {
        return [
            new Market('moneyline', __('Moneyline', 'odds-comparison')),
            new Market('spread', __('Spread', 'odds-comparison')),
            new Market('total', __('Total (Over/Under)', 'odds-comparison')),
        ];
    }

    public function odds(Event $event, string $marketId): OddsSnapshot
    {
        if (!isset(self::MARKETS[$marketId])) {
            throw new ParseException(sprintf('Unknown market "%s".', $marketId));
        }

        $root = NuxtPayload::fromHtml($this->fetch($event->url)->body);
        $game = $this->findGame($root);
        $rows = $this->latestPerOperator($root, self::MARKETS[$marketId]);

        if ($rows === []) {
            throw new ParseException(__('No prices are published for this market yet.', 'odds-comparison'));
        }

        $home = trim(($game['homeMarket'] ?? '') . ' ' . ($game['home'] ?? ''));
        $away = trim(($game['awayMarket'] ?? '') . ' ' . ($game['away'] ?? ''));

        if ($marketId === 'moneyline') {
            $labels = [$home, $away];
        } else {
            // Prefer the line most bookmakers currently quote so prices are comparable.
            $rows = $this->dominantLine($rows);
            $line = (float) ($rows[array_key_first($rows)]['line'] ?? 0.0);

            $labels = $marketId === 'spread'
                ? [$home . ' ' . $this->signed($line), $away . ' ' . $this->signed(-$line)]
                : [
                    /* translators: %s: total points line */
                    sprintf(__('Over %s', 'odds-comparison'), $this->plain($line)),
                    /* translators: %s: total points line */
                    sprintf(__('Under %s', 'odds-comparison'), $this->plain($line)),
                ];
        }

        $prices = [[], []];
        foreach ($rows as $operator => $row) {
            foreach (['home', 'away'] as $i => $side) {
                $decimal = $this->americanToDecimal($row[$side] ?? null);
                if ($decimal !== null) {
                    $prices[$i][$operator] = $decimal;
                }
            }
        }

        return new OddsSnapshot(
            $event->id,
            $marketId,
            self::ID,
            [new Outcome($labels[0], $prices[0]), new Outcome($labels[1], $prices[1])],
            time()
        );
    }

    /**
     * Game objects of a sport page (id, teams, start time).
     *
     * @return list<array<string,mixed>>
     */
    private function games(string $html): array
    {
        try {
            return NuxtPayload::findAll(
                NuxtPayload::fromHtml($html),
                static fn (array $n): bool => isset($n['home'], $n['away'], $n['scheduled']) && is_string($n['home'])
            );
        } catch (ParseException) {
            return [];
        }
    }

    /**
     * Build an Event, taking names and start time from the page's game data
     * when the URL slug matches, and from the slug itself otherwise.
     *
     * @param list<array<string,mixed>> $games
     */
    private function event(string $slug, string $sportId, string $path, array $games): Event
    {
        foreach ($games as $game) {
            $prefix = $this->slugify((string) $game['home']) . '-vs-' . $this->slugify((string) $game['away']);

            if (str_starts_with($slug, $prefix)) {
                return new Event(
                    $slug,
                    $sportId,
                    trim(($game['homeMarket'] ?? '') . ' ' . $game['home']) . ' v ' . trim(($game['awayMarket'] ?? '') . ' ' . $game['away']),
                    $path,
                    strtoupper($sportId),
                    ($time = strtotime((string) $game['scheduled'])) !== false ? $time : null,
                );
            }
        }

        $teams = explode('-vs-', (string) preg_replace('/-\d{2}-\d{2}-\d{4}$/', '', $slug), 2);
        $name  = implode(' v ', array_map(static fn (string $t): string => ucwords(str_replace('-', ' ', $t)), $teams));

        return new Event($slug, $sportId, $name, $path, strtoupper($sportId));
    }

    /**
     * The game object of an event page.
     *
     * @return array<string,mixed>
     * @throws ParseException
     */
    private function findGame(mixed $root): array
    {
        $games = NuxtPayload::findAll(
            $root,
            static fn (array $n): bool => isset($n['home'], $n['away'], $n['homeMarket']) && is_string($n['home'])
        );

        if ($games === []) {
            throw new ParseException(__('The event page has no game data.', 'odds-comparison'));
        }

        return $games[0];
    }

    /**
     * Newest quote of one odds type per sportsbook.
     *
     * The payload keeps a history of moving lines, so entries are ordered by
     * time and the latest wins.
     *
     * @return array<string,array<string,mixed>> Operator => quote.
     */
    private function latestPerOperator(mixed $root, string $oddType): array
    {
        $quotes = NuxtPayload::findAll(
            $root,
            static fn (array $n): bool => ($n['oddType'] ?? null) === $oddType
                && isset($n['operator'], $n['time'])
                && (!isset($n['period']) || $n['period'] === 'overall')
        );

        usort($quotes, static fn (array $a, array $b): int => strcmp((string) $a['time'], (string) $b['time']));

        $latest = [];
        foreach ($quotes as $quote) {
            $latest[(string) $quote['operator']] = $quote;
        }

        return $latest;
    }

    /**
     * Keep only the quotes at the line most sportsbooks currently offer.
     *
     * @param array<string,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function dominantLine(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key          = (string) ($row['line'] ?? '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);
        $line = (string) array_key_first($counts);

        return array_filter($rows, static fn (array $row): bool => (string) ($row['line'] ?? '') === $line);
    }

    /**
     * American price to decimal odds; null for missing or invalid values.
     */
    private function americanToDecimal(mixed $american): ?float
    {
        if (!is_int($american) && !is_float($american)) {
            return null;
        }

        if ($american >= 100) {
            return round(1 + $american / 100, 4);
        }

        if ($american <= -100) {
            return round(1 + 100 / abs($american), 4);
        }

        return null;
    }

    private function signed(float $value): string
    {
        return ($value > 0 ? '+' : '') . $this->plain($value);
    }

    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function slugify(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }
}
