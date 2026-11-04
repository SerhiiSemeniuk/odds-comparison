<?php
/**
 * Odds service.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Service;

use InvalidArgumentException;
use OddsComparison\Contracts\BookmakerRepositoryInterface;
use OddsComparison\Contracts\CacheInterface;
use OddsComparison\Contracts\OddsStoreInterface;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Settings\Settings;
use OddsComparison\Sources\Exception\SourceException;
use OddsComparison\Sources\HealthMonitor;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\Market;
use OddsComparison\Sources\Model\OddsSnapshot;
use OddsComparison\Sources\Model\Sport;
use OddsComparison\Sources\SourceRegistry;

/**
 * The single entry point the REST API and the block use to read data.
 *
 * Odds (what visitors see) follow strict rules:
 *  - one stored snapshot per source + event + market (custom table, see
 *    OddsStore), shared by every block that shows it; a fresh one is returned
 *    immediately;
 *  - a non-fresh entry is never shown as current: the odds are fetched anew,
 *    under a short lock so concurrent visitors do not stampede the source
 *    (they get a Pending result and ask again);
 *  - if the source fails, the last known odds are returned flagged Stale with
 *    the error as a notice, and the source is left alone for a minute;
 *  - once an event is over its cached odds are final and never refreshed.
 *
 * Lists used by the editor (sports, events, markets) are cached with
 * stale-while-revalidate and fall back through the configured source chain.
 */
final class OddsService
{
    private const STALE_TTL       = DAY_IN_SECONDS;
    private const LOCK_TTL        = 30;
    private const SPORTS_TTL      = 6 * HOUR_IN_SECONDS;
    private const EVENTS_TTL      = 10 * MINUTE_IN_SECONDS;
    private const MARKETS_TTL     = HOUR_IN_SECONDS;
    private const FAILURE_BACKOFF = 60;

    /** @var list<callable():void> */
    private array $deferred = [];

    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly CacheInterface $cache,
        private readonly Settings $settings,
        private readonly HealthMonitor $health,
        private readonly BookmakerRepositoryInterface $bookmakers,
        private readonly OddsStoreInterface $store,
    ) {
    }

    /**
     * Sports from the first source that answers.
     *
     * @return array{source:string,items:list<Sport>}
     * @throws SourceException When every candidate source fails.
     */
    public function sports(?string $sourceId = null): array
    {
        $result = $this->discover($sourceId, 'sports', self::SPORTS_TTL, static fn (SourceInterface $s): array => array_map(
            static fn (Sport $x): array => $x->toArray(),
            $s->sports()
        ));

        return ['source' => $result['source'], 'items' => array_map([Sport::class, 'fromArray'], $result['data'])];
    }

    /**
     * Upcoming events of a sport.
     *
     * @return array{source:string,items:list<Event>}
     * @throws SourceException
     */
    public function events(string $sportId, ?string $sourceId = null): array
    {
        $result = $this->discover($sourceId, 'events_' . $sportId, self::EVENTS_TTL, static fn (SourceInterface $s): array => array_map(
            static fn (Event $x): array => $x->toArray(),
            $s->events($sportId)
        ));

        return ['source' => $result['source'], 'items' => array_map([Event::class, 'fromArray'], $result['data'])];
    }

    /**
     * Markets of an event that are also enabled in the settings.
     *
     * @return list<Market>
     * @throws SourceException
     */
    public function markets(Event $event, string $sourceId): array
    {
        $source = $this->source($sourceId);
        $data   = $this->remember(
            'markets_' . $sourceId . '_' . $event->id,
            self::MARKETS_TTL,
            static fn (): array => array_map(static fn (Market $m): array => $m->toArray(), $source->markets($event))
        );

        $enabled = $this->settings->enabledMarkets();

        return array_values(array_filter(
            array_map([Market::class, 'fromArray'], $data),
            static fn (Market $m): bool => in_array($m->id, $enabled, true)
        ));
    }

    /**
     * Prices of one market of one event from a specific source.
     *
     * @throws SourceException When there is neither fresh nor last-known data.
     */
    public function odds(Event $event, string $marketId, string $sourceId): OddsResult
    {
        // Snapshots live in a custom table; the lock and failure markers are short-lived transients.
        $key      = $this->namespaced($this->oddsKey($sourceId, $event->id, $marketId));
        $entry    = $this->store->find($sourceId, $event->id, $marketId);
        $finished = $this->isFinished($event);

        // Fresh data, or the final odds of a finished event: serve as is.
        if ($entry !== null && ($finished || $entry['fresh_until'] > time())) {
            return new OddsResult(
                OddsSnapshot::fromArray($entry['data']),
                $finished ? Freshness::Finished : Freshness::Fresh
            );
        }

        // The source failed a moment ago: do not hammer it.
        $failure = $this->cache->get('fail_' . $key);
        if (is_string($failure)) {
            return $this->lastKnown($entry, $failure);
        }

        // Someone else is fetching right now.
        if ($this->cache->get('lock_' . $key) !== null) {
            return OddsResult::pending();
        }

        $this->cache->set('lock_' . $key, 1, self::LOCK_TTL);
        try {
            $data = $this->fetchOdds($event, $marketId, $sourceId);
            $this->store->save($sourceId, $event->id, $marketId, $data, time() + $this->settings->ttl(), $event->startsAt);

            return new OddsResult(OddsSnapshot::fromArray($data), $finished ? Freshness::Finished : Freshness::Fresh);
        } catch (SourceException $e) {
            $this->cache->set('fail_' . $key, $e->getMessage(), self::FAILURE_BACKOFF);

            return $this->lastKnown($entry, $e->getMessage());
        } finally {
            $this->cache->delete('lock_' . $key);
        }
    }

    /**
     * Display name of a market, falling back to its id.
     */
    public function marketName(Event $event, string $marketId, string $sourceId): string
    {
        try {
            foreach ($this->markets($event, $sourceId) as $market) {
                if ($market->id === $marketId) {
                    return $market->name;
                }
            }
        } catch (SourceException) {
            // The name is cosmetic; do not fail the table because of it.
        }

        return $marketId;
    }

    /**
     * Whether an event is over. Needs a known start time; without one the
     * event is treated as still running.
     */
    public function isFinished(Event $event): bool
    {
        if ($event->startsAt === null) {
            return false;
        }

        /**
         * Filters how long after its start an event is considered over.
         *
         * @param int   $seconds Duration in seconds (default 4 hours).
         * @param Event $event   The event.
         */
        $duration = (int) apply_filters('odds_comparison_event_duration', 4 * HOUR_IN_SECONDS, $event);

        return time() > $event->startsAt + $duration;
    }

    /**
     * Rebuild an Event from request or block data, accepting only a safe
     * site-relative path so user input can never make the server fetch an
     * arbitrary URL (SSRF).
     *
     * @throws InvalidArgumentException When an identifier or the path is invalid.
     */
    public function makeEvent(string $sportId, string $id, string $name, string $path, ?int $startsAt = null): Event
    {
        $token = '/^[A-Za-z0-9_-]{1,120}$/';

        if (preg_match($token, $sportId) !== 1 || preg_match($token, $id) !== 1) {
            throw new InvalidArgumentException(__('Invalid sport or event.', 'odds-comparison'));
        }

        // Single leading slash, no scheme, no query string, no dot segments.
        if (preg_match('#^/(?!/)[A-Za-z0-9/_\-]{1,200}$#', $path) !== 1) {
            throw new InvalidArgumentException(__('Invalid event path.', 'odds-comparison'));
        }

        return new Event($id, $sportId, $name, $path, '', $startsAt !== null && $startsAt > 0 ? $startsAt : null);
    }

    /**
     * Drop all cached data (the dashboard "Flush cache" button).
     */
    public function flush(): void
    {
        $this->store->clear();

        // Transient keys (lists, locks) are many and unlisted; bump the namespace so old
        // entries are never read again. They expire on their own.
        update_option('odds_comparison_cache_ns', (string) microtime(true), false);
    }

    /**
     * Sources in the order they are tried.
     *
     * @return list<SourceInterface>
     */
    public function chain(): array
    {
        $out = [];
        foreach ($this->settings->sourceChain() as $id) {
            if ($this->registry->has($id)) {
                $out[] = $this->registry->get($id);
            }
        }

        return $out;
    }

    /**
     * @throws InvalidArgumentException For an unknown source id.
     */
    public function source(string $id): SourceInterface
    {
        return $this->registry->get($id);
    }

    /**
     * Find out which bookmakers a source provides by reading the odds of a
     * real upcoming event (the dashboard "Detect bookmakers" button).
     *
     * Walks sports, events and markets until one snapshot has prices, which
     * records its bookmakers through fetchOdds(). Gives up after a few
     * attempts to keep the number of requests small.
     *
     * @return list<string> Bookmaker ids now known for the source.
     * @throws SourceException When the source cannot be reached at all.
     */
    public function detectBookmakers(string $sourceId): array
    {
        $attempts = 0;

        foreach ($this->sports($sourceId)['items'] as $sport) {
            foreach ($this->events($sport->id, $sourceId)['items'] as $event) {
                foreach ($this->markets($event, $sourceId) as $market) {
                    if ($attempts++ >= 5) {
                        break 3;
                    }

                    try {
                        $result = $this->odds($event, $market->id, $sourceId);
                    } catch (SourceException) {
                        continue;
                    }

                    if ($result->snapshot !== null) {
                        break 3;
                    }
                }
            }
        }

        return array_map(static fn ($b): string => $b->id, $this->bookmakers->all($sourceId));
    }

    /**
     * Run a discovery call against the requested source, or walk the fallback
     * chain (skipping sources known to be down) when none is requested.
     *
     * @param callable(SourceInterface):array<mixed> $fetch
     * @return array{source:string,data:array<mixed>}
     * @throws SourceException
     */
    private function discover(?string $sourceId, string $key, int $ttl, callable $fetch): array
    {
        $candidates = $sourceId !== null
            ? [$this->source($sourceId)]
            : array_values(array_filter($this->chain(), fn (SourceInterface $s): bool => $this->health->isWorthTrying($s->id())));

        // If every source is marked down, still try them all rather than show nothing.
        if ($candidates === []) {
            $candidates = $this->chain();
        }

        $last = null;
        foreach ($candidates as $source) {
            try {
                return [
                    'source' => $source->id(),
                    'data'   => $this->remember($key . '_' . $source->id(), $ttl, static fn (): array => $fetch($source)),
                ];
            } catch (SourceException $e) {
                $last = $e;
            }
        }

        throw $last ?? new SourceException(__('No odds source is configured.', 'odds-comparison'));
    }

    /**
     * @return array<mixed>
     * @throws SourceException
     */
    private function fetchOdds(Event $event, string $marketId, string $sourceId): array
    {
        $snapshot = $this->source($sourceId)->odds($event, $marketId);
        $this->bookmakers->discover($sourceId, $snapshot->bookmakerIds(), $snapshot->names);

        return $snapshot->toArray();
    }

    /**
     * The last stored odds flagged Stale, or the error when there are none.
     *
     * @param array{data:array<mixed>,fresh_until:int}|null $entry
     * @throws SourceException When nothing was stored before.
     */
    private function lastKnown(?array $entry, string $error): OddsResult
    {
        if ($entry === null) {
            throw new SourceException($error);
        }

        return new OddsResult(OddsSnapshot::fromArray($entry['data']), Freshness::Stale, $error);
    }

    /**
     * A well-formed cache entry, or null.
     *
     * @return array{data:array<mixed>,fresh_until:int}|null
     */
    private function entry(string $key): ?array
    {
        $entry = $this->cache->get($key);

        if (is_array($entry) && isset($entry['data'], $entry['fresh_until']) && is_array($entry['data'])) {
            return ['data' => $entry['data'], 'fresh_until' => (int) $entry['fresh_until']];
        }

        return null;
    }

    /**
     * Stale-while-revalidate read-through cache for editor lists.
     *
     * @param callable():array<mixed> $fetch
     * @return array<mixed>
     * @throws SourceException Only when there is nothing cached to fall back on.
     */
    private function remember(string $key, int $ttl, callable $fetch): array
    {
        $key   = $this->namespaced($key);
        $entry = $this->entry($key);

        if ($entry !== null) {
            if ($entry['fresh_until'] <= time() && $this->cache->get('lock_' . $key) === null) {
                $this->defer(function () use ($key, $ttl, $fetch, $entry): void {
                    $this->refreshInBackground($key, $ttl, $fetch, $entry);
                });
            }

            return $entry['data'];
        }

        // Nothing cached: the first request has to wait for one fetch.
        $this->cache->set('lock_' . $key, 1, self::LOCK_TTL);
        try {
            $data = $fetch();
        } finally {
            $this->cache->delete('lock_' . $key);
        }

        $this->cache->set($key, ['data' => $data, 'fresh_until' => time() + $ttl], self::STALE_TTL);

        return $data;
    }

    /**
     * @param callable():array<mixed>                      $fetch
     * @param array{data:array<mixed>,fresh_until:int} $entry Previous entry, kept on failure.
     */
    private function refreshInBackground(string $key, int $ttl, callable $fetch, array $entry): void
    {
        $this->cache->set('lock_' . $key, 1, self::LOCK_TTL);

        try {
            $this->cache->set($key, ['data' => $fetch(), 'fresh_until' => time() + $ttl], self::STALE_TTL);
        } catch (SourceException) {
            // Keep serving the previous list, but wait a bit before trying again.
            $entry['fresh_until'] = time() + self::FAILURE_BACKOFF;
            $this->cache->set($key, $entry, self::STALE_TTL);
        } finally {
            $this->cache->delete('lock_' . $key);
        }
    }

    /**
     * Run work after the response has reached the visitor.
     *
     * @param callable():void $job
     */
    private function defer(callable $job): void
    {
        if ($this->deferred === []) {
            add_action('shutdown', [$this, 'runDeferred']);
        }

        $this->deferred[] = $job;
    }

    /**
     * Shutdown callback: flush the response, then refresh stale entries.
     *
     * @internal Public only so it can be used as a WordPress callback.
     */
    public function runDeferred(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        foreach ($this->deferred as $job) {
            $job();
        }

        $this->deferred = [];
    }

    private function namespaced(string $key): string
    {
        return 'd_' . (string) get_option('odds_comparison_cache_ns', '0') . '_' . $key;
    }

    private function oddsKey(string $sourceId, string $eventId, string $marketId): string
    {
        return 'odds_' . $sourceId . '_' . $eventId . '_' . $marketId;
    }
}
