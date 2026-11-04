<?php
/**
 * Dashboard behaviour: REST actions, settings, and what the pages print.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Integration;

use OddsComparison\Admin\AdminModule;
use OddsComparison\Database\Schema;
use OddsComparison\Plugin;
use OddsComparison\Settings\Settings;
use ReflectionMethod;

final class AdminTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->login('administrator');

        require_once ABSPATH . 'wp-admin/includes/template.php';
    }

    private function tab(string $name): string
    {
        $method = new ReflectionMethod(AdminModule::class, 'tabContent');

        return (string) $method->invoke(new AdminModule(Plugin::instance()), $name);
    }

    public function testASourceNeedingAKeyReportsItWithoutMakingARequest(): void
    {
        $response = $this->rest('POST', '/sources/odds-api/check');
        $status   = $response->get_data()['odds-api'];

        $this->assertSame(200, $response->get_status());
        $this->assertSame('needs_key', $status['state']);
        $this->assertSame('Needs API key', $status['label']);
    }

    public function testCheckingASourceReturnsItsStatusAndStoresIt(): void
    {
        $status = $this->rest('POST', '/sources/fake/check')->get_data()['fake'];

        $this->assertSame('unknown', $status['state']);
        $this->assertArrayHasKey('checked', $status);
    }

    public function testCheckingAnUnknownSourceIs404(): void
    {
        $this->assertSame(404, $this->rest('POST', '/sources/ghost/check')->get_status());
    }

    public function testDetectingBookmakersReadsARealEventAndRecordsThem(): void
    {
        global $wpdb;

        $data = $this->rest('POST', '/sources/fake/bookmakers/detect')->get_data();

        $this->assertSame('fake', $data['source']);
        $this->assertSame(['bk'], $data['bookmakers']);
        $this->assertSame('Book K', $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . Schema::bookmakers() . ' WHERE source_id = %s', 'fake'))); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public function testDetectingBookmakersOfAFailingSourceIsABadGateway(): void
    {
        $this->source->fail = true;

        $this->assertSame(502, $this->rest('POST', '/sources/fake/bookmakers/detect')->get_status());
    }

    public function testClearingTheCacheEmptiesTheStoredOddsAndStartsANewNamespace(): void
    {
        global $wpdb;

        $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);
        $namespace = get_option('odds_comparison_cache_ns');

        $this->assertSame(200, $this->rest('POST', '/cache/flush')->get_status());

        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::odds())); // phpcs:ignore WordPress.DB.PreparedSQL
        $this->assertNotSame($namespace, get_option('odds_comparison_cache_ns'));

        $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);
        $this->assertSame(2, $this->source->oddsCalls, 'after a flush the next request fetches again');
    }

    public function testTheSourcesTabListsEverySourceWithItsStatusAndCheckButton(): void
    {
        $html = $this->tab('sources');

        foreach (['bestodds', 'betexplorer', 'odds-api', 'fake'] as $id) {
            $this->assertStringContainsString('data-source="' . $id . '"', $html);
            $this->assertStringContainsString('data-check-source="' . $id . '"', $html);
        }
        $this->assertStringContainsString('data-check-source="all"', $html);
        $this->assertStringContainsString('name="_wpnonce"', $html, 'the form is protected by a nonce');
        $this->assertStringContainsString('name="action" value="odds_comparison_save"', $html);
    }

    public function testTheApiKeyIsWriteOnlyInTheForm(): void
    {
        $secret = 'IntegrationTestKey0123456789';
        $plugin = Plugin::instance();

        $plugin->settings()->save(['api_key' => $secret, 'api_regions' => ['uk']], $plugin->sources()->ids(), ['decimal']);
        $html = $this->tab('sources');

        $this->assertSame($secret, $plugin->settings()->apiKey(), 'the key is stored');
        $this->assertStringNotContainsString($secret, $html, 'but never printed back');
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('A key is saved', $html);
        $this->assertStringContainsString('name="api_key_remove"', $html);
        $this->assertMatchesRegularExpression('/value="uk"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="us"\s+checked/', $html);
    }

    public function testNoKeyMeansNoRemoveCheckbox(): void
    {
        $html = $this->tab('sources');

        $this->assertStringContainsString('Paste your key', $html);
        $this->assertStringNotContainsString('name="api_key_remove"', $html);
    }

    public function testSettingsArePersistedAndValidated(): void
    {
        $plugin = Plugin::instance();

        $plugin->settings()->save(['source' => 'fake', 'fallback' => ['bestodds', 'ghost'], 'ttl' => '10', 'format' => 'american'], $plugin->sources()->ids(), ['decimal', 'american']);

        $settings = new Settings();
        $this->assertSame('fake', $settings->source());
        $this->assertSame(['fake', 'bestodds'], $settings->sourceChain());
        $this->assertSame(60, $settings->ttl(), 'clamped to the minimum');
        $this->assertSame('american', $settings->format());
        $this->assertNotFalse(get_option(Settings::OPTION));
    }

    public function testTheBookmakersTabGroupsBookmakersPerSource(): void
    {
        $this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline']);

        $html = $this->tab('bookmakers');

        $this->assertStringContainsString('data-source="fake"', $html);
        $this->assertStringContainsString('name="bookmakers[fake][bk][name]"', $html);
        $this->assertStringContainsString('value="Book K"', $html);
        $this->assertStringContainsString('data-detect-bookmakers="bestodds"', $html, 'sources with no bookmakers yet offer detection');
        $this->assertStringContainsString('<code>{sport}</code>', $html, 'the placeholders are explained');
    }

    public function testTheMarketsAndDisplayTabsRender(): void
    {
        $this->assertStringContainsString('name="markets[]" value="moneyline"', $this->tab('markets'));
        $this->assertStringContainsString('name="link_rel"', $this->tab('display'));
    }
}
