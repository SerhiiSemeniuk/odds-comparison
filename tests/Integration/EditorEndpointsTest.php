<?php
/**
 * The REST endpoints the block editor uses.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Integration;

use OddsComparison\Database\Schema;

final class EditorEndpointsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->login('editor');
    }

    public function testListsTheRegisteredSourcesWithTheActiveOneFirst(): void
    {
        $data = $this->rest('GET', '/sources')->get_data();
        $ids  = array_column($data, 'id');

        $this->assertContains('fake', $ids);
        $this->assertContains('bestodds', $ids);
        $this->assertContains('odds-api', $ids);
        $this->assertTrue($data[0]['active']);
        $this->assertSame('bestodds', $data[0]['id'], 'the default active source comes first');
    }

    public function testSportsEventsAndMarketsAreServedFromTheSource(): void
    {
        $this->assertSame([['id' => 's', 'name' => 'Sport']], $this->rest('GET', '/sports', ['source' => 'fake'])->get_data());

        $events = $this->rest('GET', '/events', ['source' => 'fake', 'sport' => 'nba'])->get_data();
        $this->assertSame('Home v Away', $events[0]['name']);
        $this->assertSame('/s/e1', $events[0]['url']);

        $markets = $this->rest('GET', '/markets', $this->eventParams())->get_data();
        $this->assertSame(['moneyline', 'spread'], array_column($markets, 'id'));
    }

    public function testOnlyEnabledMarketsAreOffered(): void
    {
        update_option('odds_comparison_settings', ['markets' => ['spread']]);

        $markets = $this->rest('GET', '/markets', $this->eventParams())->get_data();

        $this->assertSame(['spread'], array_column($markets, 'id'));
    }

    public function testOddsReturnAFreshTableAndRecordTheBookmakers(): void
    {
        $response = $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);
        $table    = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('fresh', $table['status']);
        $this->assertSame('Moneyline', $table['market']['name']);
        $this->assertSame(['bk'], array_column($table['bookmakers'], 'id'));
        $this->assertSame('Book K', $table['bookmakers'][0]['name'], 'the source reported name is used');
        $this->assertSame(['decimal' => '2.00', 'fractional' => '1/1', 'american' => '+100'], $table['outcomes'][0]['cells'][0]['formats']);
        $this->assertSame(['rel' => 'sponsored nofollow noopener', 'target' => '_blank'], $table['link']);

        $bookmakers = $this->rest('GET', '/bookmakers', ['source' => 'fake'])->get_data();
        $this->assertSame([['id' => 'bk', 'name' => 'Book K']], $bookmakers);
    }

    public function testTheSecondRequestIsServedFromTheDatabase(): void
    {
        global $wpdb;

        $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);
        $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);

        $this->assertSame(1, $this->source->oddsCalls);
        $this->assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::odds())); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public function testASelectionOfBookmakersFiltersTheColumns(): void
    {
        $this->source->names = ['bk' => 'Book K'];
        $table               = $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline', 'bookmakers' => 'someone-else'])->get_data();

        $this->assertSame([], $table['bookmakers']);
    }

    public function testAnEventInTheLockedStateAnswers202(): void
    {
        set_transient('oddscmp_lock_d_0_odds_fake_e1_moneyline', 1, 30);

        $response = $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);

        $this->assertSame(202, $response->get_status());
        $this->assertSame('pending', $response->get_data()['status']);
        $this->assertSame(0, $this->source->oddsCalls);
    }

    public function testASourceFailureWithNothingStoredIsABadGateway(): void
    {
        $this->source->fail = true;

        $response = $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);

        $this->assertSame(502, $response->get_status());
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function badEvents(): array
    {
        return [
            'a protocol-relative path' => [['path' => '//evil.example/x']],
            'an absolute url'          => [['path' => 'http://127.0.0.1/admin']],
            'dot segments'             => [['path' => '/a/../b']],
            'a query string'           => [['path' => '/odds/nba?x=1']],
            'a slash in the event id'  => [['event' => 'a/b']],
            'an unknown source'        => [['source' => 'ghost']],
        ];
    }

    /**
     * @param array<string,mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badEvents')]
    public function testUnsafeOrUnknownInputIsRefusedBeforeAnyRequestIsMade(array $override): void
    {
        $response = $this->rest('GET', '/odds', $override + $this->eventParams() + ['market' => 'moneyline']);

        $this->assertSame(400, $response->get_status());
        $this->assertSame(0, $this->source->oddsCalls);
    }
}
