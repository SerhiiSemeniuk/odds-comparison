<?php
/**
 * The Odds API source.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Contracts\KeyedSourceInterface;
use OddsComparison\Http\HttpResponse;
use OddsComparison\Sources\Exception\AuthenticationException;
use OddsComparison\Sources\Exception\BlockedException;
use OddsComparison\Sources\Exception\ParseException;
use OddsComparison\Sources\Exception\SourceException;
use OddsComparison\Sources\Exception\UnreachableException;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\Market;
use OddsComparison\Sources\Model\OddsSnapshot;
use OddsComparison\Sources\Model\Outcome;
use OddsComparison\Sources\Model\Sport;

/**
 * The Odds API (https://the-odds-api.com), a licensed odds feed.
 *
 * Unlike the scraped sources it needs an API key and covers many sports and
 * leagues (football included) with prices per bookmaker, optionally with deep
 * links to the bet. Listing sports and events is free; asking for odds costs
 * one credit per region and market, so keep the odds freshness TTL generous.
 *
 * The key travels in the query string, therefore every message that leaves
 * this class has it removed.
 */
final class ApiSource implements KeyedSourceInterface
{
    public const ID = 'odds-api';

    /** Bookmaker regions the API knows, with labels. */
    public const REGIONS = ['us' => 'United States', 'us2' => 'United States (more)', 'uk' => 'United Kingdom', 'au' => 'Australia', 'eu' => 'Europe'];

    private const BASE = 'https://api.the-odds-api.com/v4';

    /** Our market id => the API's market key. */
    private const MARKET_KEYS = ['1x2' => 'h2h', 'moneyline' => 'h2h', 'spread' => 'spreads', 'total' => 'totals'];

    /**
     * @param list<string> $regions Bookmaker regions to request, e.g. ["eu"].
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly array $regions = ['eu'],
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'The Odds API';
    }

    public function description(): string
    {
        return __('Licensed odds feed with many sports (football included) and prices per bookmaker. Needs an API key; odds requests use credits.', 'odds-comparison');
    }

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Probe the free /sports endpoint, which also tells whether the key works
     * and how many credits are left.
     */
    public function checkAvailability(): HealthStatus
    {
        if (!$this->hasKey()) {
            return new HealthStatus(HealthState::NeedsKey, __('Add an API key in the settings.', 'odds-comparison'), null, time());
        }

        $start = microtime(true);

        try {
            $response = $this->call('/sports/');
            $sports   = $this->sportsFrom($this->decode($response));
        } catch (AuthenticationException $e) {
            return $this->status(HealthState::NeedsKey, $e->getMessage(), $start);
        } catch (BlockedException $e) {
            return $this->status(HealthState::Blocked, $e->getMessage(), $start);
        } catch (ParseException $e) {
            return $this->status(HealthState::ParserMismatch, $e->getMessage(), $start);
        } catch (SourceException $e) {
            return $this->status(HealthState::Unreachable, $e->getMessage(), $start);
        }

        $message = sprintf(
            /* translators: %d: number of sports */
            _n('%d sport available.', '%d sports available.', count($sports), 'odds-comparison'),
            count($sports)
        );

        $remaining = $response->header('x-requests-remaining');
        if ($remaining !== '') {
            $message .= ' ' . sprintf(
                /* translators: %s: number of credits left */
                __('Credits left: %s.', 'odds-comparison'),
                $remaining
            );
        }

        return $this->status($sports === [] ? HealthState::ParserMismatch : HealthState::Available, $message, $start);
    }

    /**
     * Active sports and leagues that have head-to-head style markets
     * (outright / futures lists are skipped).
     *
     * @return list<Sport>
     */
    public function sports(): array
    {
        return $this->sportsFrom($this->decode($this->call('/sports/')));
    }

    /**
     * @return list<Event>
     */
    public function events(string $sportId): array
    {
        $sportId = $this->token($sportId);
        $events  = [];

        foreach ($this->decode($this->call('/sports/' . $sportId . '/events')) as $row) {
            if (!is_array($row) || !isset($row['id'], $row['home_team'], $row['away_team'])) {
                continue;
            }

            $starts = isset($row['commence_time']) ? strtotime((string) $row['commence_time']) : false;

            $events[] = new Event(
                (string) $row['id'],
                $sportId,
                $row['home_team'] . ' v ' . $row['away_team'],
                '/' . $sportId . '/' . $row['id'],
                (string) ($row['sport_title'] ?? ''),
                $starts !== false ? $starts : null,
            );
        }

        return $events;
    }

    /**
     * @return list<Market>
     */
    public function markets(Event $event): array
    {
        return [
            str_starts_with($event->sportId, 'soccer_')
                ? new Market('1x2', __('Match Winner (1X2)', 'odds-comparison'))
                : new Market('moneyline', __('Moneyline', 'odds-comparison')),
            new Market('spread', __('Spread', 'odds-comparison')),
            new Market('total', __('Total (Over/Under)', 'odds-comparison')),
        ];
    }

    public function odds(Event $event, string $marketId): OddsSnapshot
    {
        if (!isset(self::MARKET_KEYS[$marketId])) {
            throw new ParseException(sprintf('Unknown market "%s".', $marketId));
        }

        $key  = self::MARKET_KEYS[$marketId];
        $data = $this->decode($this->call(
            '/sports/' . $this->token($event->sportId) . '/events/' . $this->token($event->id) . '/odds',
            [
                'regions'      => implode(',', $this->regions),
                'markets'      => $key,
                'oddsFormat'   => 'decimal',
                'dateFormat'   => 'iso',
                'includeLinks' => 'true',
            ]
        ));

        $home = (string) ($data['home_team'] ?? '');
        $away = (string) ($data['away_team'] ?? '');

        // Only the bookmakers that quote the requested market.
        $quotes = [];
        $names  = [];
        $links  = [];
        foreach ((array) ($data['bookmakers'] ?? []) as $bookmaker) {
            if (!is_array($bookmaker) || !isset($bookmaker['key'])) {
                continue;
            }
            foreach ((array) ($bookmaker['markets'] ?? []) as $market) {
                if (is_array($market) && ($market['key'] ?? '') === $key && is_array($market['outcomes'] ?? null)) {
                    $id          = (string) $bookmaker['key'];
                    $quotes[$id] = $market['outcomes'];
                    $names[$id]  = (string) ($bookmaker['title'] ?? '');
                    // Deep link to the market if the API gave one, else to the bookmaker's event.
                    $links[$id] = (string) ($market['link'] ?? $bookmaker['link'] ?? '');
                }
            }
        }

        $outcomes = match ($key) {
            'h2h'     => $this->headToHead($quotes, $home, $away),
            'spreads' => $this->spreads($quotes, $home, $away),
            default   => $this->totals($quotes),
        };

        $used = [];
        foreach ($outcomes as $outcome) {
            $used += $outcome->prices;
        }
        if ($used === []) {
            throw new ParseException(__('No bookmaker offers prices for this market.', 'odds-comparison'));
        }

        return new OddsSnapshot(
            $event->id,
            $marketId,
            self::ID,
            $outcomes,
            time(),
            array_filter(array_intersect_key($names, $used), static fn (string $n): bool => $n !== ''),
            array_filter(array_intersect_key($links, $used), static fn (string $l): bool => $l !== ''),
        );
    }

    /**
     * Home, draw (when offered) and away prices.
     *
     * @param array<string,list<array<string,mixed>>> $quotes Bookmaker id => outcomes.
     * @return list<Outcome>
     */
    private function headToHead(array $quotes, string $home, string $away): array
    {
        $prices = ['home' => [], 'draw' => [], 'away' => []];

        foreach ($quotes as $id => $outcomes) {
            foreach ($outcomes as $outcome) {
                $slot  = match ($outcome['name'] ?? null) {
                    $home   => 'home',
                    $away   => 'away',
                    'Draw'  => 'draw',
                    default => null,
                };
                $price = $this->price($outcome);

                if ($slot !== null && $price !== null) {
                    $prices[$slot][$id] = $price;
                }
            }
        }

        $out = [new Outcome($home, $prices['home'])];
        if ($prices['draw'] !== []) {
            $out[] = new Outcome(__('Draw', 'odds-comparison'), $prices['draw']);
        }
        $out[] = new Outcome($away, $prices['away']);

        return $out;
    }

    /**
     * Handicap prices on the line most bookmakers offer.
     *
     * @param array<string,list<array<string,mixed>>> $quotes
     * @return list<Outcome>
     */
    private function spreads(array $quotes, string $home, string $away): array
    {
        $line = $this->dominantLine($quotes, static fn (array $o): bool => ($o['name'] ?? null) === $home);
        if ($line === null) {
            return [];
        }

        $homePrices = [];
        $awayPrices = [];
        foreach ($quotes as $id => $outcomes) {
            foreach ($outcomes as $outcome) {
                $price = $this->price($outcome);
                if ($price === null) {
                    continue;
                }
                if (($outcome['name'] ?? null) === $home && $this->point($outcome) === $line) {
                    $homePrices[$id] = $price;
                } elseif (($outcome['name'] ?? null) === $away && $this->point($outcome) === -$line) {
                    $awayPrices[$id] = $price;
                }
            }
        }

        return [
            new Outcome($home . ' ' . $this->signed($line), $homePrices),
            new Outcome($away . ' ' . $this->signed(-$line), $awayPrices),
        ];
    }

    /**
     * Over / under prices on the line most bookmakers offer.
     *
     * @param array<string,list<array<string,mixed>>> $quotes
     * @return list<Outcome>
     */
    private function totals(array $quotes): array
    {
        $line = $this->dominantLine($quotes, static fn (array $o): bool => ($o['name'] ?? null) === 'Over');
        if ($line === null) {
            return [];
        }

        $over  = [];
        $under = [];
        foreach ($quotes as $id => $outcomes) {
            foreach ($outcomes as $outcome) {
                $price = $this->price($outcome);
                if ($price === null || $this->point($outcome) !== $line) {
                    continue;
                }
                if (($outcome['name'] ?? null) === 'Over') {
                    $over[$id] = $price;
                } elseif (($outcome['name'] ?? null) === 'Under') {
                    $under[$id] = $price;
                }
            }
        }

        $label = $this->plain($line);

        return [
            /* translators: %s: total points line */
            new Outcome(sprintf(__('Over %s', 'odds-comparison'), $label), $over),
            /* translators: %s: total points line */
            new Outcome(sprintf(__('Under %s', 'odds-comparison'), $label), $under),
        ];
    }

    /**
     * The line quoted by the most bookmakers, so prices are comparable.
     *
     * @param array<string,list<array<string,mixed>>> $quotes
     * @param callable(array<string,mixed>):bool      $isAnchor Selects the outcome that carries the line.
     */
    private function dominantLine(array $quotes, callable $isAnchor): ?float
    {
        $counts = [];
        foreach ($quotes as $outcomes) {
            foreach ($outcomes as $outcome) {
                if (is_array($outcome) && $isAnchor($outcome) && ($point = $this->point($outcome)) !== null) {
                    $counts[(string) $point] = ($counts[(string) $point] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (float) array_key_first($counts);
    }

    /**
     * @param array<string,mixed> $outcome
     */
    private function price(array $outcome): ?float
    {
        $price = $outcome['price'] ?? null;

        return is_numeric($price) && (float) $price > 1.0 ? (float) $price : null;
    }

    /**
     * @param array<string,mixed> $outcome
     */
    private function point(array $outcome): ?float
    {
        return isset($outcome['point']) && is_numeric($outcome['point']) ? (float) $outcome['point'] : null;
    }

    /**
     * @param array<mixed> $rows Decoded /sports response.
     * @return list<Sport>
     */
    private function sportsFrom(array $rows): array
    {
        $sports = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['key']) || empty($row['active']) || !empty($row['has_outrights'])) {
                continue;
            }

            $title    = (string) ($row['title'] ?? $row['key']);
            $group    = (string) ($row['group'] ?? '');
            $sports[] = new Sport((string) $row['key'], $group !== '' ? $group . ': ' . $title : $title);
        }

        return $sports;
    }

    /**
     * GET an endpoint, turning API errors into typed exceptions.
     *
     * @param array<string,string> $query
     * @throws SourceException
     */
    private function call(string $path, array $query = []): HttpResponse
    {
        if (!$this->hasKey()) {
            throw new AuthenticationException(__('Add an API key in the settings.', 'odds-comparison'));
        }

        $url = self::BASE . $path . '?' . http_build_query($query + ['apiKey' => $this->apiKey]);

        try {
            $response = $this->http->get($url);
        } catch (SourceException $e) {
            $class = $e::class;
            throw new $class($this->redact($e->getMessage()));
        }

        if ($response->isSuccess()) {
            return $response;
        }

        $error   = json_decode($response->body, true);
        $code    = is_array($error) ? (string) ($error['error_code'] ?? '') : '';
        $message = $this->redact(is_array($error) ? (string) ($error['message'] ?? '') : '');
        $message = $message !== '' ? $message : sprintf('HTTP %d', $response->status);

        if ($response->status === 401 || in_array($code, ['INVALID_KEY', 'MISSING_KEY', 'DEACTIVATED_KEY'], true)) {
            throw new AuthenticationException($message);
        }
        if ($response->status === 429 || in_array($code, ['EXCEEDED_FREQ_LIMIT', 'OUT_OF_USAGE_CREDITS'], true)) {
            throw new BlockedException($message);
        }
        if ($response->status === 422 || $response->status === 404) {
            // The request was understood but not satisfiable, e.g. a market this sport does not have.
            throw new ParseException($message);
        }

        throw new UnreachableException($message);
    }

    /**
     * @return array<mixed>
     * @throws ParseException
     */
    private function decode(HttpResponse $response): array
    {
        $data = json_decode($response->body, true);

        if (!is_array($data)) {
            throw new ParseException(__('The API did not return JSON.', 'odds-comparison'));
        }

        return $data;
    }

    /**
     * Sport keys and event ids go into the URL path, so allow only their alphabet.
     *
     * @throws ParseException
     */
    private function token(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,120}$/', $value) !== 1) {
            throw new ParseException(__('Invalid sport or event id.', 'odds-comparison'));
        }

        return $value;
    }

    /**
     * Remove the API key from text that may be shown or logged.
     */
    private function redact(string $text): string
    {
        return $this->apiKey === '' ? $text : str_replace($this->apiKey, '***', $text);
    }

    private function status(HealthState $state, string $message, float $start): HealthStatus
    {
        return new HealthStatus($state, $message, (int) round((microtime(true) - $start) * 1000), time());
    }

    private function signed(float $value): string
    {
        return ($value > 0 ? '+' : '') . $this->plain($value);
    }

    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
