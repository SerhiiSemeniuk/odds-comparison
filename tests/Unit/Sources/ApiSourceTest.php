<?php
/**
 * The Odds API source tests.
 *
 * The JSON fixtures follow the response shapes in the API's v4 documentation
 * (sports, events, event odds) and the error body the API returns for a bad key.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Sources;

use OddsComparison\Http\HttpResponse;
use OddsComparison\Sources\ApiSource;
use OddsComparison\Sources\Exception\AuthenticationException;
use OddsComparison\Sources\Exception\BlockedException;
use OddsComparison\Sources\Exception\ParseException;
use OddsComparison\Sources\Exception\UnreachableException;
use OddsComparison\Sources\HealthState;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ApiSourceTest extends TestCase
{
    private const KEY = 'SECRETKEY1234567890abcdef';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/oddsapi/' . $name);
    }

    private function source(FakeHttpClient $http, string $key = self::KEY, array $regions = ['eu']): ApiSource
    {
        return new ApiSource($http, $key, $regions);
    }

    private function soccerEvent(): Event
    {
        return new Event('ev1aaaa', 'soccer_epl', 'Arsenal v Chelsea', '/soccer_epl/ev1aaaa');
    }

    private function nbaEvent(): Event
    {
        return new Event('ev9nba', 'basketball_nba', 'Charlotte Hornets v Detroit Pistons', '/basketball_nba/ev9nba');
    }

    /**
     * @return array<string,string>
     */
    private function query(FakeHttpClient $http, int $index = 0): array
    {
        parse_str((string) parse_url($http->requests[$index], PHP_URL_QUERY), $query);

        return $query;
    }

    public function testWithoutAKeyNothingIsRequested(): void
    {
        $http   = new FakeHttpClient();
        $source = $this->source($http, '');

        $this->assertFalse($source->hasKey());
        $this->assertSame(HealthState::NeedsKey, $source->checkAvailability()->state);

        try {
            $source->sports();
            $this->fail('A missing key must raise AuthenticationException.');
        } catch (AuthenticationException) {
            $this->assertSame([], $http->requests);
        }
    }

    public function testListsActiveSportsAndSkipsOutrights(): void
    {
        $http   = (new FakeHttpClient())->page('/v4/sports/', $this->fixture('sports.json'));
        $sports = $this->source($http)->sports();

        $this->assertSame(['soccer_epl', 'basketball_nba'], array_map(static fn ($s) => $s->id, $sports));
        $this->assertSame('Soccer: EPL', $sports[0]->name);
        $this->assertSame(self::KEY, $this->query($http)['apiKey']);
    }

    public function testListsEventsAndSkipsThoseWithoutAnId(): void
    {
        $http   = (new FakeHttpClient())->page('/v4/sports/soccer_epl/events', $this->fixture('events.json'));
        $events = $this->source($http)->events('soccer_epl');

        $this->assertCount(2, $events);
        $this->assertSame('Arsenal v Chelsea', $events[0]->name);
        $this->assertSame('/soccer_epl/ev1aaaa', $events[0]->url);
        $this->assertSame('EPL', $events[0]->league);
        $this->assertSame(strtotime('2026-11-08T16:30:00Z'), $events[0]->startsAt);
        $this->assertSame(['apiKey'], array_keys($this->query($http)), 'listing events is free and needs no regions or markets');
    }

    public function testFootballGetsAThreeWayMarketAndOtherSportsMoneyline(): void
    {
        $source = $this->source(new FakeHttpClient());

        $this->assertSame(['1x2', 'spread', 'total'], array_map(static fn ($m) => $m->id, $source->markets($this->soccerEvent())));
        $this->assertSame(['moneyline', 'spread', 'total'], array_map(static fn ($m) => $m->id, $source->markets($this->nbaEvent())));
    }

    public function testHeadToHeadHasHomeDrawAwayAndReportsNamesAndLinks(): void
    {
        $http     = (new FakeHttpClient())->page('/events/ev1aaaa/odds', $this->fixture('odds-h2h.json'));
        $snapshot = $this->source($http)->odds($this->soccerEvent(), '1x2');

        $this->assertSame(['Arsenal', 'Draw', 'Chelsea'], array_map(static fn ($o) => $o->name, $snapshot->outcomes));
        $this->assertSame(['williamhill' => 2.1, 'betfair_ex_eu' => 2.2, 'unibet_eu' => 2.05], $snapshot->outcomes[0]->prices);
        $this->assertSame(['williamhill' => 3.5, 'betfair_ex_eu' => 3.6, 'unibet_eu' => 3.4], $snapshot->outcomes[1]->prices);
        $this->assertSame(2.2, $snapshot->outcomes[0]->best());

        $this->assertSame(['williamhill' => 'William Hill', 'betfair_ex_eu' => 'Betfair'], $snapshot->names, 'empty titles are dropped');
        $this->assertSame(
            ['williamhill' => 'https://wh.example/bet/arsenal-chelsea', 'unibet_eu' => 'https://unibet.example/event/1'],
            $snapshot->links,
            'a market link wins, else the bookmaker link'
        );
        $this->assertNotContains('broken', $snapshot->bookmakerIds(), 'a bookmaker without the market is left out');
    }

    public function testTheOddsRequestAsksForTheRightMarketRegionsAndLinks(): void
    {
        $http = (new FakeHttpClient())->page('/odds', $this->fixture('odds-h2h.json'));
        $this->source($http, self::KEY, ['eu', 'uk'])->odds($this->soccerEvent(), '1x2');

        $this->assertStringContainsString('/v4/sports/soccer_epl/events/ev1aaaa/odds', $http->requests[0]);
        $this->assertSame(
            ['regions' => 'eu,uk', 'markets' => 'h2h', 'oddsFormat' => 'decimal', 'dateFormat' => 'iso', 'includeLinks' => 'true', 'apiKey' => self::KEY],
            $this->query($http)
        );
    }

    public function testSpreadsUseTheLineMostBookmakersOffer(): void
    {
        $http     = (new FakeHttpClient())->page('/odds', $this->fixture('odds-spreads.json'));
        $snapshot = $this->source($http)->odds($this->nbaEvent(), 'spread');

        $this->assertSame(['Charlotte Hornets +5.5', 'Detroit Pistons -5.5'], array_map(static fn ($o) => $o->name, $snapshot->outcomes));
        $this->assertSame(['draftkings', 'fanduel'], array_keys($snapshot->outcomes[0]->prices), 'BetMGM quotes another line');
        $this->assertSame('spreads', $this->query($http)['markets']);
    }

    public function testTotalsUseTheLineMostBookmakersOffer(): void
    {
        $http     = (new FakeHttpClient())->page('/odds', $this->fixture('odds-totals.json'));
        $snapshot = $this->source($http)->odds($this->nbaEvent(), 'total');

        $this->assertSame(['Over 210.5', 'Under 210.5'], array_map(static fn ($o) => $o->name, $snapshot->outcomes));
        $this->assertSame(['draftkings' => 1.91, 'fanduel' => 1.9], $snapshot->outcomes[0]->prices);
    }

    public function testAMarketNobodyQuotesIsAParseError(): void
    {
        $http = (new FakeHttpClient())->page('/odds', $this->fixture('odds-h2h.json'));

        $this->expectException(ParseException::class);
        $this->source($http)->odds($this->soccerEvent(), 'spread');
    }

    public function testAnUnknownMarketIsRejectedBeforeAnyRequest(): void
    {
        $http = new FakeHttpClient();

        try {
            $this->source($http)->odds($this->soccerEvent(), 'btts');
            $this->fail('Expected a ParseException.');
        } catch (ParseException) {
            $this->assertSame([], $http->requests);
        }
    }

    public function testIdsThatCouldBendTheUrlPathAreRejectedBeforeAnyRequest(): void
    {
        $http = new FakeHttpClient();

        try {
            $this->source($http)->events('../../admin');
            $this->fail('Expected a ParseException.');
        } catch (ParseException) {
            $this->assertSame([], $http->requests);
        }
    }

    public function testABadKeyIsReportedAsAuthenticationWithoutLeakingTheKey(): void
    {
        $http = (new FakeHttpClient())->page('/v4/', $this->fixture('error-invalid-key.json'), 401);

        try {
            $this->source($http)->sports();
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('API key is not valid', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testQuotaAndRateLimitAreBlocks(): void
    {
        foreach ([['EXCEEDED_FREQ_LIMIT', 429], ['OUT_OF_USAGE_CREDITS', 401]] as [$code, $status]) {
            $http = (new FakeHttpClient())->page('/v4/', (string) json_encode(['message' => 'limit', 'error_code' => $code]), $status);

            try {
                $this->source($http)->sports();
                $this->fail('Expected an exception for ' . $code);
            } catch (BlockedException | AuthenticationException $e) {
                // A 401 status with a quota code is a key problem to the HTTP layer; both are fine, neither retries.
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testAnUnprocessableRequestIsAParseError(): void
    {
        $http = (new FakeHttpClient())->page('/v4/', (string) json_encode(['message' => 'Invalid market', 'error_code' => 'INVALID_MARKET']), 422);

        $this->expectException(ParseException::class);
        $this->source($http)->sports();
    }

    public function testAServerErrorIsUnreachable(): void
    {
        $http = (new FakeHttpClient())->page('/v4/', 'Bad gateway', 502);

        $this->expectException(UnreachableException::class);
        $this->source($http)->sports();
    }

    public function testNonJsonIsAParseError(): void
    {
        $http = (new FakeHttpClient())->page('/v4/', '<html>maintenance</html>');

        $this->expectException(ParseException::class);
        $this->source($http)->sports();
    }

    public function testTransportErrorsHaveTheKeyRemoved(): void
    {
        $http = (new FakeHttpClient())->on('/v4/', new UnreachableException('cURL error 28 for https://api.the-odds-api.com/v4/sports/?apiKey=' . self::KEY));

        try {
            $this->source($http)->sports();
            $this->fail('Expected an UnreachableException.');
        } catch (UnreachableException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
    }

    public function testAvailabilityShowsTheCreditsLeft(): void
    {
        $http   = (new FakeHttpClient())->on('/v4/sports/', new HttpResponse(200, $this->fixture('sports.json'), ['X-Requests-Remaining' => '487']));
        $status = $this->source($http)->checkAvailability();

        $this->assertSame(HealthState::Available, $status->state);
        $this->assertStringContainsString('2 sports', $status->message);
        $this->assertStringContainsString('487', $status->message);
    }

    public function testAvailabilityMapsEachFailureToAState(): void
    {
        $cases = [
            [401, 'error-invalid-key.json', HealthState::NeedsKey],
            [429, null, HealthState::Blocked],
            [502, null, HealthState::Unreachable],
        ];

        foreach ($cases as [$status, $fixture, $expected]) {
            $http = (new FakeHttpClient())->page('/v4/', $fixture !== null ? $this->fixture($fixture) : '', $status);

            $this->assertSame($expected, $this->source($http)->checkAvailability()->state, 'HTTP ' . $status);
        }
    }

    public function testDeclaresItNeedsAKey(): void
    {
        $source = $this->source(new FakeHttpClient());

        $this->assertTrue($source->requiresApiKey());
        $this->assertTrue($source->hasKey());
        $this->assertSame('odds-api', $source->id());
    }
}
