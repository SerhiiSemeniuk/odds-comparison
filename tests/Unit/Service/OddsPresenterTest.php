<?php
/**
 * OddsPresenter tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Service;

use InvalidArgumentException;
use OddsComparison\Odds\Converter;
use OddsComparison\Service\Freshness;
use OddsComparison\Service\OddsPresenter;
use OddsComparison\Service\OddsResult;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\OddsSnapshot;
use OddsComparison\Sources\Model\Outcome;
use OddsComparison\Tests\Support\ArrayBookmakerRepository;
use PHPUnit\Framework\TestCase;

final class OddsPresenterTest extends TestCase
{
    private ArrayBookmakerRepository $repo;

    protected function setUp(): void
    {
        $this->repo = (new ArrayBookmakerRepository())
            ->add('src', 'a', 'Alpha')
            ->add('src', 'b', 'Beta')
            ->add('src', 'c', 'Gamma')
            ->add('src', 'off', 'Hidden', '', false)
            ->add('src', 'nopriced', 'Nobody quotes me');
    }

    private function presenter(): OddsPresenter
    {
        return new OddsPresenter(new Converter(), $this->repo);
    }

    private function event(): Event
    {
        return new Event('e1', 'nba', 'Home v Away', '/s/e1');
    }

    /**
     * @param array<string,string> $links
     */
    private function oddsResult(array $links = [], Freshness $freshness = Freshness::Fresh, string $notice = ''): OddsResult
    {
        $snapshot = new OddsSnapshot('e1', 'moneyline', 'src', [
            new Outcome('Home', ['a' => 2.0, 'b' => 2.5, 'c' => 2.5, 'off' => 9.0]),
            new Outcome('Away', ['a' => 1.8, 'b' => 1.5]),
        ], 1700000000, [], $links);

        return new OddsResult($snapshot, $freshness, $notice);
    }

    public function testShowsOnlyEnabledBookmakersThatHavePrices(): void
    {
        $table = $this->presenter()->present($this->oddsResult(), $this->event(), 'Moneyline');

        $this->assertSame(['a', 'b', 'c'], array_column($table['bookmakers'], 'id'));
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($table['bookmakers'], 'name'));
    }

    public function testASelectionLimitsTheColumns(): void
    {
        $table = $this->presenter()->present($this->oddsResult(), $this->event(), 'Moneyline', ['c', 'a']);

        $this->assertSame(['a', 'c'], array_column($table['bookmakers'], 'id'), 'the bookmaker order stays the configured one');
    }

    public function testBestPricesAreFlaggedAndTiesShareTheFlag(): void
    {
        $table = $this->presenter()->present($this->oddsResult(), $this->event(), 'Moneyline');

        $home = array_column($table['outcomes'][0]['cells'], 'best', 'bookmaker');
        $this->assertSame(['a' => false, 'b' => true, 'c' => true], $home);
    }

    public function testBestIsComputedAmongTheShownColumnsOnly(): void
    {
        $table = $this->presenter()->present($this->oddsResult(), $this->event(), 'Moneyline', ['a']);

        $this->assertTrue($table['outcomes'][0]['cells'][0]['best'], 'the hidden 2.5 must not make Alpha look worse');
    }

    public function testMissingPricesBecomeEmptyCells(): void
    {
        $table = $this->presenter()->present($this->oddsResult(), $this->event(), 'Moneyline');

        $away = $table['outcomes'][1]['cells'];
        $this->assertNull($away[2]['decimal']);
        $this->assertNull($away[2]['formats']);
        $this->assertFalse($away[2]['best']);
    }

    public function testEveryPriceCarriesAllFormats(): void
    {
        $table = $this->presenter()->present($this->oddsResult(), $this->event(), 'Moneyline');

        $this->assertSame(['decimal' => '2.50', 'fractional' => '3/2', 'american' => '+150'], $table['outcomes'][0]['cells'][1]['formats']);
        $this->assertSame(['decimal', 'fractional', 'american'], array_keys($table['formats']));
    }

    public function testStatusNoticeAndContextArePassedThrough(): void
    {
        $table = $this->presenter()->present($this->oddsResult([], Freshness::Stale, 'source down'), $this->event(), 'Moneyline');

        $this->assertSame('stale', $table['status']);
        $this->assertSame('source down', $table['notice']);
        $this->assertSame('src', $table['source']);
        $this->assertSame(['id' => 'e1', 'name' => 'Home v Away'], $table['event']);
        $this->assertSame(['id' => 'moneyline', 'name' => 'Moneyline'], $table['market']);
        $this->assertSame(1700000000, $table['updated']);
    }

    public function testThePlaceholdersOfTheAdminsLinkAreFilledIn(): void
    {
        $this->repo->add('src', 'a', 'Alpha', 'https://alpha.example/{sport}/{event}');

        $table = $this->presenter()->present($this->oddsResult(), $this->event(), 'Moneyline');

        $this->assertSame('https://alpha.example/nba/e1', $table['bookmakers'][0]['url']);
    }

    public function testASourcesDeepLinkIsUsedWhenTheAdminSetNoLink(): void
    {
        $this->repo->add('src', 'a', 'Alpha', 'https://alpha.example', true, false);

        $table = $this->presenter()->present($this->oddsResult(['a' => 'https://alpha.example/bet/123']), $this->event(), 'Moneyline');

        $this->assertSame('https://alpha.example/bet/123', $table['bookmakers'][0]['url']);
    }

    public function testTheAdminsOwnLinkBeatsADeepLink(): void
    {
        $this->repo->add('src', 'a', 'Alpha', 'https://my-affiliate.example/alpha');

        $table = $this->presenter()->present($this->oddsResult(['a' => 'https://alpha.example/bet/123']), $this->event(), 'Moneyline');

        $this->assertSame('https://my-affiliate.example/alpha', $table['bookmakers'][0]['url']);
    }

    public function testAnUnsafeDeepLinkIsIgnored(): void
    {
        $this->repo->add('src', 'a', 'Alpha', 'https://alpha.example', true, false);

        $table = $this->presenter()->present($this->oddsResult(['a' => 'javascript:alert(1)']), $this->event(), 'Moneyline');

        $this->assertSame('https://alpha.example', $table['bookmakers'][0]['url']);
    }

    public function testAPendingResultCannotBePresented(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->presenter()->present(OddsResult::pending(), $this->event(), 'Moneyline');
    }
}
