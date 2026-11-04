<?php
/**
 * BestOdds source tests.
 *
 * The event pages are built with NuxtFixture in the same flat payload format the
 * real site embeds, so the tests cover the whole decode-and-interpret path.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Sources;

use OddsComparison\Http\HttpResponse;
use OddsComparison\Sources\BestOddsSource;
use OddsComparison\Sources\Exception\BlockedException;
use OddsComparison\Sources\Exception\ParseException;
use OddsComparison\Sources\HealthState;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Tests\Support\FakeHttpClient;
use OddsComparison\Tests\Support\NuxtFixture;
use PHPUnit\Framework\TestCase;

final class BestOddsSourceTest extends TestCase
{
    private const GAME = ['id' => 'g1', 'home' => 'Hornets', 'away' => 'Pistons', 'homeMarket' => 'Charlotte', 'awayMarket' => 'Detroit', 'scheduled' => '2026-11-03T00:30:00.000Z'];

    private function event(): Event
    {
        return new Event('hornets-vs-pistons-02-11-2026', 'nba', 'Charlotte Hornets v Detroit Pistons', '/odds/nba/event/hornets-vs-pistons-02-11-2026');
    }

    /**
     * @param array<string,mixed> $quote
     * @return array<string,mixed>
     */
    private static function quote(string $operator, string $type, ?float $line, int $home, int $away, string $time): array
    {
        return ['operator' => $operator, 'oddType' => $type, 'line' => $line, 'home' => $home, 'away' => $away, 'time' => $time];
    }

    /**
     * @param list<array<string,mixed>> $quotes
     */
    private function sourceWithEventPage(array $quotes, bool $withGame = true): BestOddsSource
    {
        $payload = ($withGame ? ['game' => self::GAME] : []) + ['odds' => $quotes];
        $http    = (new FakeHttpClient())->page('/odds/nba/event/', NuxtFixture::page($payload));

        return new BestOddsSource($http);
    }

    public function testListsTheSportsLinkedFromTheOddsPage(): void
    {
        $html = '<a href="/odds/nba">NBA</a><a href="/odds/nfl">NFL</a><a href="/odds/nba">dup</a>'
            . '<a href="/odds/nba/event/x-vs-y-02-11-2026">event</a><a href="/news">news</a><a href="/odds/nba?tab=1">tab</a>';
        $source = new BestOddsSource((new FakeHttpClient())->page('bestodds.com/odds', $html));

        $sports = $source->sports();

        $this->assertSame(['nba', 'nfl'], array_map(static fn ($s) => $s->id, $sports));
        $this->assertSame('NBA', $sports[0]->name);
    }

    public function testListsDatedEventsWithNamesAndStartTimesFromTheGameData(): void
    {
        $html = '<a href="/odds/nba/event/hornets-vs-pistons-02-11-2026">a</a>'
            . '<a href="/odds/nba/event/hornets-vs-pistons-02-11-2026?tab=gamelines">tab</a>'
            . '<a href="/odds/nba/event/nuggets-vs-trail">truncated, no date</a>'
            . '<a href="/odds/nba/event/warriors-vs-suns-02-11-2026">b</a>';
        $page   = NuxtFixture::page(['games' => [self::GAME]], $html);
        $source = new BestOddsSource((new FakeHttpClient())->page('/odds/nba', $page));

        $events = $source->events('nba');

        $this->assertCount(2, $events, 'query variants and undated links are not events');
        $this->assertSame('Charlotte Hornets v Detroit Pistons', $events[0]->name);
        $this->assertSame(strtotime('2026-11-03T00:30:00.000Z'), $events[0]->startsAt);
        $this->assertSame('/odds/nba/event/hornets-vs-pistons-02-11-2026', $events[0]->url);
        $this->assertSame('Warriors v Suns', $events[1]->name, 'without game data the name comes from the slug');
        $this->assertNull($events[1]->startsAt);
    }

    public function testMoneylineConvertsAmericanPricesAndKeepsTheLatestQuotePerSportsbook(): void
    {
        $source = $this->sourceWithEventPage([
            self::quote('draftkings', 'moneyline', null, 100, -120, '2026-11-02T10:00:00.000Z'),
            self::quote('draftkings', 'moneyline', null, 150, -200, '2026-11-02T18:00:00.000Z'),
            self::quote('fanduel', 'moneyline', null, 140, -180, '2026-11-02T17:00:00.000Z'),
            self::quote('bet365', 'moneyline', null, 50, -50, '2026-11-02T17:00:00.000Z'),
        ]);

        $snapshot = $source->odds($this->event(), 'moneyline');

        $this->assertSame(['Charlotte Hornets', 'Detroit Pistons'], array_map(static fn ($o) => $o->name, $snapshot->outcomes));
        $this->assertSame(2.5, $snapshot->outcomes[0]->prices['draftkings'], 'the newer quote wins: +150 is 2.50');
        $this->assertSame(1.5, $snapshot->outcomes[1]->prices['draftkings'], '-200 is 1.50');
        $this->assertSame(2.4, $snapshot->outcomes[0]->prices['fanduel']);
        $this->assertArrayNotHasKey('bet365', $snapshot->outcomes[0]->prices, 'prices between -100 and +100 are invalid');
    }

    public function testSpreadUsesTheLineMostSportsbooksOfferAndLabelsBothSides(): void
    {
        $source = $this->sourceWithEventPage([
            self::quote('draftkings', 'spread', 5.5, -110, -110, '2026-11-02T18:00:00.000Z'),
            self::quote('fanduel', 'spread', 5.5, -105, -115, '2026-11-02T18:00:00.000Z'),
            self::quote('caesars', 'spread', 4.5, -110, -110, '2026-11-02T18:00:00.000Z'),
        ]);

        $snapshot = $source->odds($this->event(), 'spread');

        $this->assertSame('Charlotte Hornets +5.5', $snapshot->outcomes[0]->name, 'the line is the home handicap');
        $this->assertSame('Detroit Pistons -5.5', $snapshot->outcomes[1]->name);
        $this->assertSame(['draftkings', 'fanduel'], array_keys($snapshot->outcomes[0]->prices), 'the sportsbook on another line is left out');
    }

    public function testTotalLabelsTheLine(): void
    {
        $source = $this->sourceWithEventPage([
            self::quote('draftkings', 'overUnder', 210.5, -110, -110, '2026-11-02T18:00:00.000Z'),
            self::quote('fanduel', 'overUnder', 210.5, -105, -115, '2026-11-02T18:00:00.000Z'),
        ]);

        $snapshot = $source->odds($this->event(), 'total');

        $this->assertSame(['Over 210.5', 'Under 210.5'], array_map(static fn ($o) => $o->name, $snapshot->outcomes));
    }

    public function testAnUnknownMarketIsAParseError(): void
    {
        $this->expectException(ParseException::class);

        $this->sourceWithEventPage([])->odds($this->event(), 'btts');
    }

    public function testAMarketWithoutPricesIsAParseError(): void
    {
        $this->expectException(ParseException::class);

        $this->sourceWithEventPage([self::quote('x', 'moneyline', null, 100, -100, '2026-11-02T18:00:00.000Z')])->odds($this->event(), 'spread');
    }

    public function testAPageWithoutGameDataIsAParseError(): void
    {
        $this->expectException(ParseException::class);

        $this->sourceWithEventPage([self::quote('draftkings', 'moneyline', null, 150, -200, '2026-11-02T18:00:00.000Z')], false)
            ->odds($this->event(), 'moneyline');
    }

    public function testMarketsAreMoneylineSpreadAndTotal(): void
    {
        $markets = $this->sourceWithEventPage([])->markets($this->event());

        $this->assertSame(['moneyline', 'spread', 'total'], array_map(static fn ($m) => $m->id, $markets));
    }

    public function testBlocksAndOutagesAreTyped(): void
    {
        $blocked = new BestOddsSource((new FakeHttpClient())->on('bestodds.com', new HttpResponse(403, '', ['cf-mitigated' => 'challenge'])));

        $this->assertSame(HealthState::Blocked, $blocked->checkAvailability()->state);

        $this->expectException(BlockedException::class);
        $blocked->sports();
    }
}
