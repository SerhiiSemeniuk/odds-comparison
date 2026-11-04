<?php
/**
 * The block: registration, server-side rendering and the public table endpoint,
 * end to end (block markup, signed configuration, REST answer, stored snapshot).
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Integration;

use OddsComparison\Block\ConfigSigner;
use OddsComparison\Database\Schema;
use WP_Block_Type_Registry;

final class BlockTest extends IntegrationTestCase
{
    /**
     * @param array<string,mixed> $attributes
     */
    private function render(array $attributes): string
    {
        return render_block([
            'blockName'    => 'odds-comparison/odds-table',
            'attrs'        => $attributes,
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function attributes(array $override = []): array
    {
        return $override + [
            'source'      => 'fake',
            'sport'       => 'nba',
            'eventId'     => 'e1',
            'eventName'   => 'Home v Away',
            'eventPath'   => '/s/e1',
            'eventStarts' => 0,
            'market'      => 'moneyline',
            'bookmakers'  => [],
        ];
    }

    /**
     * @return array{token:string,sig:string}
     */
    private function signedFrom(string $html): array
    {
        $this->assertSame(1, preg_match('/data-token="([^"]+)"/', $html, $token), 'the block must carry a signed token');
        $this->assertSame(1, preg_match('/data-sig="([^"]+)"/', $html, $sig));

        return ['token' => $token[1], 'sig' => $sig[1]];
    }

    public function testTheBlockIsRegisteredWithItsAttributes(): void
    {
        $block = WP_Block_Type_Registry::get_instance()->get_registered('odds-comparison/odds-table');

        $this->assertNotNull($block);
        foreach (['source', 'sport', 'eventId', 'eventName', 'eventPath', 'eventStarts', 'market', 'bookmakers', 'highlightBest', 'showFormatSwitch'] as $name) {
            $this->assertArrayHasKey($name, $block->attributes);
        }
        $this->assertTrue($block->attributes['highlightBest']['default']);
        $this->assertNotNull($block->render_callback);
    }

    public function testItRendersALoaderWithASignedConfigurationAndNoOdds(): void
    {
        $html = $this->render($this->attributes());

        $this->assertStringContainsString('data-oddscmp', $html);
        $this->assertStringContainsString('data-endpoint="' . esc_url(rest_url('odds-comparison/v1/table')) . '"', str_replace('&#038;', '&amp;', $html));
        $this->assertStringContainsString('data-format="decimal"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringNotContainsString('2.00', $html, 'the page never contains odds, so cached HTML cannot go stale');
        $this->assertSame(0, $this->source->oddsCalls, 'rendering must not scrape');
    }

    public function testTheSignedConfigurationVerifiesAndMatchesTheAttributes(): void
    {
        $signed = $this->signedFrom($this->render($this->attributes(['bookmakers' => ['bk', 'bk', 'Other Book']])));

        $config = (new ConfigSigner())->verify($signed['token'], $signed['sig']);

        $this->assertSame('fake', $config['source']);
        $this->assertSame('e1', $config['event']);
        $this->assertSame('/s/e1', $config['path']);
        $this->assertSame('moneyline', $config['market']);
        $this->assertSame(['bk', 'otherbook'], $config['bookmakers'], 'ids are sanitised and de-duplicated');
    }

    public function testVisitorsSeeNothingFromAnIncompleteBlockButEditorsGetAHint(): void
    {
        $incomplete = $this->attributes(['market' => '']);

        $this->assertSame('', $this->render($incomplete));

        $this->login('editor');
        $this->assertStringContainsString('choose an event and a market', $this->render($incomplete));
    }

    public function testABlockWithAnUnsafePathOrUnknownSourceRendersNothing(): void
    {
        $this->assertSame('', $this->render($this->attributes(['eventPath' => '//evil.example/x'])));
        $this->assertSame('', $this->render($this->attributes(['source' => 'ghost'])));
    }

    public function testAVisitorGetsTheTableThroughThePublicEndpoint(): void
    {
        global $wpdb;

        $signed   = $this->signedFrom($this->render($this->attributes()));
        $response = $this->rest('GET', '/table', $signed);
        $table    = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('fresh', $table['status']);
        $this->assertSame('', $table['notice']);
        $this->assertSame('Home v Away', $table['event']['name']);
        $this->assertSame(['bk'], array_column($table['bookmakers'], 'id'));
        $this->assertSame(2.0, $table['outcomes'][0]['cells'][0]['decimal']);
        $this->assertTrue($table['outcomes'][0]['cells'][0]['best']);
        $this->assertSame(['decimal', 'fractional', 'american'], array_keys($table['formats']));
        $this->assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::odds())); // phpcs:ignore WordPress.DB.PreparedSQL

        $this->rest('GET', '/table', $signed);
        $this->assertSame(1, $this->source->oddsCalls, 'later visitors are served from the stored snapshot');
    }

    public function testATamperedConfigurationIsRefused(): void
    {
        $signed = $this->signedFrom($this->render($this->attributes()));
        $evil   = rtrim(strtr(base64_encode((string) wp_json_encode(['source' => 'fake', 'sport' => 'nba', 'event' => 'e9', 'name' => 'x', 'path' => '/s/e9', 'starts' => 0, 'market' => 'moneyline', 'bookmakers' => []])), '+/', '-_'), '=');

        $this->assertSame(403, $this->rest('GET', '/table', ['token' => $evil, 'sig' => $signed['sig']])->get_status());
        $this->assertSame(0, $this->source->oddsCalls);
    }

    public function testWhenTheSourceFailsVisitorsGetTheLastKnownOddsWithAGenericNotice(): void
    {
        global $wpdb;

        $signed = $this->signedFrom($this->render($this->attributes()));
        $this->rest('GET', '/table', $signed);

        $wpdb->query('UPDATE ' . Schema::odds() . ' SET fresh_until = ' . (time() - 5)); // phpcs:ignore WordPress.DB.PreparedSQL
        $this->source->fail = true;

        $response = $this->rest('GET', '/table', $signed);
        $table    = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('stale', $table['status']);
        $this->assertStringNotContainsString('boom', (string) wp_json_encode($table), 'internal error text is not shown to visitors');
        $this->assertNotSame('', $table['notice']);
    }

    public function testNothingStoredAndAFailingSourceIsAGenericServiceError(): void
    {
        $this->source->fail = true;
        $signed             = $this->signedFrom($this->render($this->attributes()));

        $response = $this->rest('GET', '/table', $signed);

        $this->assertSame(503, $response->get_status());
        $this->assertStringNotContainsString('boom', (string) wp_json_encode($response->get_data()));
    }

    public function testAFinishedEventKeepsItsFinalOddsAndIsNotRefreshed(): void
    {
        global $wpdb;

        $signed = $this->signedFrom($this->render($this->attributes(['eventStarts' => time() - 5 * 3600])));
        $first  = $this->rest('GET', '/table', $signed)->get_data();
        $this->assertSame('finished', $first['status']);

        $wpdb->query('UPDATE ' . Schema::odds() . ' SET fresh_until = ' . (time() - 5)); // phpcs:ignore WordPress.DB.PreparedSQL
        $this->rest('GET', '/table', $signed);

        $this->assertSame(1, $this->source->oddsCalls);
    }

    public function testTheLockMakesConcurrentVisitorsWaitInsteadOfScraping(): void
    {
        $signed = $this->signedFrom($this->render($this->attributes()));
        set_transient('oddscmp_lock_d_0_odds_fake_e1_moneyline', 1, 30);

        $response = $this->rest('GET', '/table', $signed);

        $this->assertSame(202, $response->get_status());
        $this->assertSame('pending', $response->get_data()['status']);
    }

    public function testAdminsLinkWinsAndTemplatesAreFilledInForVisitors(): void
    {
        // An editor previews the odds once, which records the bookmaker; then a visitor loads the page.
        $this->login('editor');
        $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);
        wp_set_current_user(0);
        \OddsComparison\Plugin::instance()->bookmakers()->save(['fake' => ['bk' => ['enabled' => 1, 'url' => 'https://aff.example/{sport}/{event}', 'name' => '', 'order' => 0]]]);

        $signed = $this->signedFrom($this->render($this->attributes()));
        $table  = $this->rest('GET', '/table', $signed)->get_data();

        $this->assertSame('https://aff.example/nba/e1', $table['bookmakers'][0]['url']);
    }
}
