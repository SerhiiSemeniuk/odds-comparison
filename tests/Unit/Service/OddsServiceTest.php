<?php
/**
 * OddsService tests: caching, freshness and failure rules.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Service;

use InvalidArgumentException;
use OddsComparison\Cache\ArrayCache;
use OddsComparison\Service\Freshness;
use OddsComparison\Service\OddsService;
use OddsComparison\Settings\Settings;
use OddsComparison\Sources\Exception\SourceException;
use OddsComparison\Sources\HealthMonitor;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\SourceRegistry;
use OddsComparison\Tests\Support\ArrayBookmakerRepository;
use OddsComparison\Tests\Support\ArrayOddsStore;
use OddsComparison\Tests\Support\FakeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OddsServiceTest extends TestCase
{
    private FakeSource $source;
    private ArrayOddsStore $store;
    private ArrayBookmakerRepository $bookmakers;
    private ArrayCache $cache;
    private OddsService $service;

    protected function setUp(): void
    {
        $GLOBALS['oddscmp_options'] = [];
        $GLOBALS['oddscmp_filters'] = [];
        update_option(Settings::OPTION, ['source' => 'fake', 'fallback' => ['second']]);

        $this->source     = new FakeSource('fake');
        $this->store      = new ArrayOddsStore();
        $this->bookmakers = new ArrayBookmakerRepository();
        $this->cache      = new ArrayCache();
        $this->service    = $this->makeService([$this->source, new FakeSource('second')]);
    }

    protected function tearDown(): void
    {
        $GLOBALS['oddscmp_options'] = [];
        $GLOBALS['oddscmp_filters'] = [];
    }

    /**
     * @param list<FakeSource> $sources
     */
    private function makeService(array $sources): OddsService
    {
        $factories = [];
        foreach ($sources as $source) {
            $factories[$source->id()] = static fn () => $source;
        }
        $registry = new SourceRegistry($factories);

        return new OddsService($registry, $this->cache, new Settings(), new HealthMonitor($registry, $this->cache), $this->bookmakers, $this->store);
    }

    private function event(?int $startsAt = null): Event
    {
        return new Event('e1', 'sport', 'Home v Away', '/s/e1', '', $startsAt);
    }

    public function testTheFirstRequestFetchesOnceAndStoresTheSnapshot(): void
    {
        $result = $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->assertSame(Freshness::Fresh, $result->freshness);
        $this->assertSame(2.0, $result->snapshot?->outcomes[0]->prices['bk']);
        $this->assertSame(1, $this->source->oddsCalls);
        $this->assertSame(1, $this->store->saves);
    }

    public function testAFreshSnapshotIsServedWithoutFetching(): void
    {
        $this->service->odds($this->event(), 'moneyline', 'fake');
        $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->assertSame(1, $this->source->oddsCalls);
    }

    public function testMarketsAreStoredSeparately(): void
    {
        $this->service->odds($this->event(), 'moneyline', 'fake');
        $this->service->odds($this->event(), 'spread', 'fake');

        $this->assertSame(2, $this->source->oddsCalls);
    }

    public function testAnExpiredSnapshotIsFetchedAgainAndNeverShownAsCurrent(): void
    {
        $this->service->odds($this->event(), 'moneyline', 'fake');
        $this->store->expire('fake', 'e1', 'moneyline');
        $this->source->price = 3.0;

        $result = $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->assertSame(Freshness::Fresh, $result->freshness);
        $this->assertSame(3.0, $result->snapshot?->outcomes[0]->prices['bk'], 'the new price, not the expired one');
        $this->assertSame(2, $this->source->oddsCalls);
    }

    public function testWhenTheSourceFailsTheLastKnownOddsAreReturnedAsStaleWithTheError(): void
    {
        $this->service->odds($this->event(), 'moneyline', 'fake');
        $this->store->expire('fake', 'e1', 'moneyline');
        $this->source->fail = true;

        $result = $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->assertSame(Freshness::Stale, $result->freshness);
        $this->assertSame('boom', $result->notice);
        $this->assertSame(2.0, $result->snapshot?->outcomes[0]->prices['bk']);
    }

    public function testAFailingSourceIsLeftAloneForAWhile(): void
    {
        $this->service->odds($this->event(), 'moneyline', 'fake');
        $this->store->expire('fake', 'e1', 'moneyline');
        $this->source->fail = true;

        $this->service->odds($this->event(), 'moneyline', 'fake');
        $calls = $this->source->oddsCalls;
        $again = $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->assertSame($calls, $this->source->oddsCalls, 'no second attempt inside the backoff window');
        $this->assertSame(Freshness::Stale, $again->freshness);
    }

    public function testWithNothingStoredAFailureIsAnError(): void
    {
        $this->source->fail = true;

        $this->expectException(SourceException::class);
        $this->service->odds($this->event(), 'moneyline', 'fake');
    }

    public function testConcurrentRequestsAreToldToWaitInsteadOfStampedingTheSource(): void
    {
        $this->cache->set('lock_d_0_odds_fake_e1_moneyline', 1, 30);

        $result = $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->assertSame(Freshness::Pending, $result->freshness);
        $this->assertNull($result->snapshot);
        $this->assertGreaterThan(0, $result->retryAfter);
        $this->assertSame(0, $this->source->oddsCalls);
    }

    public function testTheLockIsReleasedAfterAFetch(): void
    {
        $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->assertNull($this->cache->get('lock_d_0_odds_fake_e1_moneyline'));
    }

    public function testAFinishedEventKeepsItsFinalOddsAndIsNeverRefreshed(): void
    {
        $finished = $this->event(time() - 5 * 3600);

        $first = $this->service->odds($finished, 'moneyline', 'fake');
        $this->assertSame(Freshness::Finished, $first->freshness);

        $this->store->expire('fake', 'e1', 'moneyline');
        $second = $this->service->odds($finished, 'moneyline', 'fake');

        $this->assertSame(Freshness::Finished, $second->freshness);
        $this->assertSame(1, $this->source->oddsCalls, 'an expired snapshot of a finished event is not refetched');
    }

    public function testAnEventWithoutAStartTimeIsNeverConsideredFinished(): void
    {
        $this->assertFalse($this->service->isFinished($this->event(null)));
    }

    public function testTheFinishedThresholdIsFilterable(): void
    {
        $started = $this->event(time() - 3 * 3600);

        $this->assertFalse($this->service->isFinished($started), '3 hours in, the default allowance is 4');

        $GLOBALS['oddscmp_filters']['odds_comparison_event_duration'] = static fn (): int => 2 * 3600;
        $this->assertTrue($this->service->isFinished($started));
    }

    public function testBookmakersAndTheirNamesAreRecordedFromFetchedOdds(): void
    {
        $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->assertSame(['bk'], array_keys($this->bookmakers->rows['fake']));
        $this->assertSame('Book K', $this->bookmakers->rows['fake']['bk']['name']);
    }

    public function testFlushDropsTheStoredSnapshots(): void
    {
        $this->service->odds($this->event(), 'moneyline', 'fake');

        $this->service->flush();

        $this->assertSame([], $this->store->rows);
    }

    public function testFlushAlsoInvalidatesCachedLists(): void
    {
        $this->service->sports('fake');
        $this->service->flush();
        $this->service->sports('fake');

        $this->assertSame(2, $this->source->sportsCalls);
    }

    public function testListsAreCached(): void
    {
        $this->service->sports('fake');
        $this->service->sports('fake');

        $this->assertSame(1, $this->source->sportsCalls);
    }

    public function testDiscoveryFallsBackToTheNextSourceWhenTheFirstFails(): void
    {
        $this->source->fail = true;

        $result = $this->service->sports();

        $this->assertSame('second', $result['source']);
        $this->assertSame('Sport', $result['items'][0]->name);
    }

    public function testDiscoveryFailsWhenEverySourceFails(): void
    {
        $first  = new FakeSource('fake');
        $second = new FakeSource('second');
        $first->fail = $second->fail = true;
        $service     = $this->makeService([$first, $second]);

        $this->expectException(SourceException::class);
        $service->sports();
    }

    public function testOnlyEnabledMarketsAreOffered(): void
    {
        update_option(Settings::OPTION, ['source' => 'fake', 'markets' => ['moneyline']]);

        $markets = $this->service->markets($this->event(), 'fake');

        $this->assertSame(['moneyline'], array_map(static fn ($m) => $m->id, $markets));
    }

    public function testMarketNameFallsBackToTheId(): void
    {
        $this->assertSame('Moneyline', $this->service->marketName($this->event(), 'moneyline', 'fake'));
        $this->assertSame('exotic', $this->service->marketName($this->event(), 'exotic', 'fake'));
    }

    public function testDetectingBookmakersReadsARealEventAndReturnsTheirIds(): void
    {
        $this->assertSame(['bk'], $this->service->detectBookmakers('fake'));
    }

    /**
     * @return array<string,array{string,string,string,string,bool}>
     */
    public static function eventInputs(): array
    {
        return [
            'a normal event'                 => ['nba', 'hornets-vs-pistons-02-11-2026', 'x', '/odds/nba/event/hornets-vs-pistons-02-11-2026', true],
            'a nested path'                  => ['football', 'AbC123', 'x', '/football/england/premier-league/a-b/AbC123/', true],
            'an underscore sport key'        => ['soccer_epl', 'ev1', 'x', '/soccer_epl/ev1', true],
            'a protocol-relative host'       => ['nba', 'e', 'x', '//evil.example/x', false],
            'an absolute url'                => ['nba', 'e', 'x', 'http://127.0.0.1/admin', false],
            'dot segments'                   => ['nba', 'e', 'x', '/a/../b', false],
            'a query string'                 => ['nba', 'e', 'x', '/odds/nba?x=1', false],
            'a relative path'                => ['nba', 'e', 'x', 'odds/nba', false],
            'an empty path'                  => ['nba', 'e', 'x', '', false],
            'a slash in the event id'        => ['nba', 'a/b', 'x', '/ok', false],
            'a space in the sport id'        => ['n ba', 'e', 'x', '/ok', false],
            'an over-long event id'          => ['nba', str_repeat('a', 121), 'x', '/ok', false],
        ];
    }

    #[DataProvider('eventInputs')]
    public function testMakeEventAcceptsOnlySafeSiteRelativePaths(string $sport, string $id, string $name, string $path, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $event = $this->service->makeEvent($sport, $id, $name, $path);

        $this->assertSame($path, $event->url);
    }

    public function testMakeEventTreatsAZeroStartAsUnknown(): void
    {
        $this->assertNull($this->service->makeEvent('nba', 'e', 'x', '/ok', 0)->startsAt);
        $this->assertSame(1700000000, $this->service->makeEvent('nba', 'e', 'x', '/ok', 1700000000)->startsAt);
    }
}
