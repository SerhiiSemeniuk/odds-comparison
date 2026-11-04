<?php
/**
 * BetExplorer source tests.
 *
 * The pages below keep the structure of the real site (menu links, tournament
 * links, match rows with data-odd cells, JSON-LD start times) in a minimal form.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Sources;

use OddsComparison\Http\HttpResponse;
use OddsComparison\Sources\BetExplorerSource;
use OddsComparison\Sources\Exception\BlockedException;
use OddsComparison\Sources\Exception\ParseException;
use OddsComparison\Sources\Exception\UnreachableException;
use OddsComparison\Sources\HealthState;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class BetExplorerSourceTest extends TestCase
{
    private const HOME = <<<'HTML'
        <ul class="menu">
          <li class="menu__item"><a href="/" class="menu__item__in menu__item__in--current"><i class="icon"></i>Football</a></li>
          <li class="menu__item"><a href="/basketball/" class="menu__item__in"><i class="icon"></i>Basketball</a></li>
          <li class="menu__item"><a href="/hockey/" class="menu__item__in"><i class="icon"></i>Hockey</a></li>
          <li><a href="/football/" class="submenu__item__in">Summary</a></li>
        </ul>
        HTML;

    private const SPORT = <<<'HTML'
        <table>
          <tr><td><a href="/football/england/premier-league/" class="table-main__tournament">Premier League</a></td></tr>
          <tr><td><a href="/football/spain/laliga/" class="table-main__tournament">LaLiga</a></td></tr>
          <tr><td><a href="/football/italy/serie-a/" class="table-main__tournament">Serie A</a></td></tr>
          <tr><td><a href="/football/france/ligue-1/" class="table-main__tournament">Ligue 1</a></td></tr>
        </table>
        HTML;

    private const LEAGUE = <<<'HTML'
        <h1 id="ageTitle">Age verification required</h1>
        <h1 dir="ltr">Premier League Tables, Fixtures, Stats &amp; Odds</h1>
        <div class="wrap-section__header__title">Premier League 2026/2027 Table &amp; Stats</div>
        <script type="application/ld+json">[{"@type":"SportsEvent","name":"Newcastle - Everton","url":"https://www.betexplorer.com/football/england/premier-league/newcastle-utd-everton/YceoIxQh/","startDate":"2026-11-02T21:00:00+01:00"}]</script>
        <table>
          <tr>
            <td><a href="/football/england/premier-league/newcastle-utd-everton/YceoIxQh/" class="in-match"><span>Newcastle</span> - <span>Everton</span></a></td>
            <td class="table-main__odds"><button data-odd="2.20"></button></td>
            <td class="table-main__odds"><button data-odd="3.57"></button></td>
            <td class="table-main__odds"><button data-odd="3.18"></button></td>
          </tr>
          <tr>
            <td><a href="/football/england/premier-league/arsenal-chelsea/AbCdEf12/" class="in-match"><span>Arsenal</span> - <span>Chelsea</span></a></td>
            <td class="table-main__odds"><button data-odd="1.80"></button></td>
            <td class="table-main__odds"><button data-odd="2.10"></button></td>
          </tr>
          <tr>
            <td><a href="/football/england/premier-league/no-prices/ZzZzZz99/" class="in-match"><span>No</span> - <span>Prices</span></a></td>
            <td class="table-main__odds"><button data-odd="-"></button></td>
          </tr>
        </table>
        HTML;

    private function source(?FakeHttpClient $http = null): BetExplorerSource
    {
        $http ??= $this->site();

        return new BetExplorerSource($http);
    }

    private function site(): FakeHttpClient
    {
        return (new FakeHttpClient())->on('betexplorer.com', static function (string $url): HttpResponse {
            $body = match (parse_url($url, PHP_URL_PATH)) {
                '/'                                  => self::HOME,
                '/football/'                         => self::SPORT,
                '/football/england/premier-league/'  => self::LEAGUE,
                default                              => self::LEAGUE,
            };

            return new HttpResponse(200, $body);
        });
    }

    public function testListsSportsIncludingTheDefaultOneLinkedToTheRoot(): void
    {
        $sports = $this->source()->sports();

        $this->assertSame(['football', 'basketball', 'hockey'], array_map(static fn ($s) => $s->id, $sports));
        $this->assertSame('Football', $sports[0]->name);
    }

    public function testListsMatchesWithLeagueAndStartTime(): void
    {
        $events = $this->source()->events('football');

        $this->assertSame('Newcastle v Everton', $events[0]->name);
        $this->assertSame('YceoIxQh', $events[0]->id);
        $this->assertSame('Premier League', $events[0]->league, 'the age-gate h1 must not be used');
        $this->assertSame(strtotime('2026-11-02T21:00:00+01:00'), $events[0]->startsAt);
        $this->assertNull($events[1]->startsAt, 'matches without JSON-LD have no start time');
    }

    public function testRowsWithoutPricesAreSkipped(): void
    {
        $names = array_unique(array_map(static fn (Event $e) => $e->name, $this->source()->events('football')));

        $this->assertNotContains('No v Prices', $names);
    }

    public function testReadsOnlyTheFirstFewLeagues(): void
    {
        $http = $this->site();

        $this->source($http)->events('football');

        $leagues = array_filter($http->requests, static fn (string $u) => substr_count($u, '/') > 4);
        $this->assertCount(3, $leagues, 'every league is a request, so the number is capped');
    }

    public function testThreeWayRowsAreOfferedAsOneXTwo(): void
    {
        $event = new Event('YceoIxQh', 'football', 'Newcastle v Everton', '/football/england/premier-league/newcastle-utd-everton/YceoIxQh/');

        $markets = $this->source()->markets($event);
        $this->assertSame('1x2', $markets[0]->id);

        $snapshot = $this->source()->odds($event, '1x2');
        $this->assertSame(['Newcastle', 'Draw', 'Everton'], array_map(static fn ($o) => $o->name, $snapshot->outcomes));
        $this->assertSame([2.2, 3.57, 3.18], array_map(static fn ($o) => $o->prices['average'], $snapshot->outcomes));
        $this->assertSame(['average'], $snapshot->bookmakerIds());
    }

    public function testTwoWayRowsAreOfferedAsMoneyline(): void
    {
        $event = new Event('AbCdEf12', 'football', 'Arsenal v Chelsea', '/football/england/premier-league/arsenal-chelsea/AbCdEf12/');

        $this->assertSame('moneyline', $this->source()->markets($event)[0]->id);

        $outcomes = $this->source()->odds($event, 'moneyline')->outcomes;
        $this->assertSame(['Arsenal', 'Chelsea'], array_map(static fn ($o) => $o->name, $outcomes));
    }

    public function testAnEventThatIsNoLongerListedIsAParseError(): void
    {
        $this->expectException(ParseException::class);

        $this->source()->odds(new Event('gone', 'football', 'Gone', '/football/england/premier-league/gone-team/gone/'), '1x2');
    }

    public function testAForbiddenResponseIsABlock(): void
    {
        $http = (new FakeHttpClient())->page('betexplorer.com', 'Forbidden', 403);

        $this->expectException(BlockedException::class);
        $this->source($http)->sports();
    }

    public function testAChallengePageServedWithStatus200IsABlock(): void
    {
        $http = (new FakeHttpClient())->page('betexplorer.com', '<html><script src="https://challenges.cloudflare.com/x"></script></html>');

        $this->expectException(BlockedException::class);
        $this->source($http)->sports();
    }

    public function testAServerErrorIsUnreachable(): void
    {
        $http = (new FakeHttpClient())->page('betexplorer.com', 'oops', 500);

        $this->expectException(UnreachableException::class);
        $this->source($http)->sports();
    }

    public function testAnEmptyBodyIsAParseError(): void
    {
        $http = (new FakeHttpClient())->page('betexplorer.com', '   ');

        $this->expectException(ParseException::class);
        $this->source($http)->sports();
    }

    public function testAvailabilityIsReportedForEachFailureMode(): void
    {
        $ok = $this->source()->checkAvailability();
        $this->assertSame(HealthState::Available, $ok->state);
        $this->assertStringContainsString('3', $ok->message);
        $this->assertNotNull($ok->latencyMs);

        $blocked = $this->source((new FakeHttpClient())->page('betexplorer.com', '', 403))->checkAvailability();
        $this->assertSame(HealthState::Blocked, $blocked->state);

        $down = $this->source((new FakeHttpClient())->page('betexplorer.com', '', 503))->checkAvailability();
        $this->assertSame(HealthState::Unreachable, $down->state);

        $changed = $this->source((new FakeHttpClient())->page('betexplorer.com', '<html><body>new design</body></html>'))->checkAvailability();
        $this->assertSame(HealthState::ParserMismatch, $changed->state, 'a page that loads but has no sports means the markup changed');
    }
}
