<?php
/**
 * Settings tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Settings;

use OddsComparison\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private const SOURCES = ['bestodds', 'betexplorer', 'odds-api'];
    private const FORMATS = ['decimal', 'fractional', 'american'];

    protected function setUp(): void
    {
        $GLOBALS['oddscmp_options'] = [];
        $GLOBALS['oddscmp_filters'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['oddscmp_options'] = [];
    }

    private function save(array $input): array
    {
        return (new Settings())->save($input, self::SOURCES, self::FORMATS);
    }

    public function testDefaultsApplyUntilSomethingIsSaved(): void
    {
        $settings = new Settings();

        $this->assertSame('bestodds', $settings->source());
        $this->assertSame(300, $settings->ttl());
        $this->assertSame('decimal', $settings->format());
        $this->assertSame(['bestodds', 'betexplorer'], $settings->sourceChain());
        $this->assertSame('', $settings->apiKey());
        $this->assertSame(['eu'], $settings->apiRegions());
    }

    public function testAnUnknownSourceOrFormatKeepsTheCurrentValue(): void
    {
        $saved = $this->save(['source' => 'nope', 'format' => 'nope']);

        $this->assertSame('bestodds', $saved['source']);
        $this->assertSame('decimal', $saved['format']);
    }

    public function testTheFallbackListDropsUnknownSourcesDuplicatesAndTheActiveOne(): void
    {
        $saved = $this->save(['source' => 'odds-api', 'fallback' => ['odds-api', 'bestodds', 'bestodds', 'ghost', '']]);

        $this->assertSame(['bestodds'], $saved['fallback']);
        $this->assertSame(['odds-api', 'bestodds'], (new Settings())->sourceChain());
    }

    public function testTheFreshnessTimeIsClamped(): void
    {
        $this->assertSame(60, $this->save(['ttl' => 5])['ttl']);
        $this->assertSame(86400, $this->save(['ttl' => 999999])['ttl']);
        $this->assertSame(900, $this->save(['ttl' => 900])['ttl']);
    }

    public function testOnlyKnownMarketsCanBeEnabled(): void
    {
        $saved = $this->save(['markets' => ['moneyline', '', 'made-up']]);

        $this->assertSame(['moneyline'], $saved['markets']);
    }

    public function testAnEmptyMarketsSubmissionDisablesAllMarkets(): void
    {
        $this->assertSame([], $this->save(['markets' => ['']])['markets']);
    }

    public function testLinkTargetIsLimitedToKnownValues(): void
    {
        $this->assertSame('_self', $this->save(['link_target' => '_self'])['link_target']);
        $this->assertSame('_blank', $this->save(['link_target' => 'javascript:x'])['link_target']);
    }

    public function testAFormThatDoesNotMentionAFieldLeavesItAlone(): void
    {
        $this->save(['ttl' => 900, 'format' => 'american']);
        $saved = $this->save(['link_rel' => 'sponsored']);

        $this->assertSame(900, $saved['ttl']);
        $this->assertSame('american', $saved['format']);
    }

    public function testTheApiKeyIsKeptWhenTheFieldIsLeftEmpty(): void
    {
        $this->save(['api_key' => 'abc123DEF']);

        $this->assertSame('abc123DEF', $this->save(['api_key' => ''])['api_key']);
        $this->assertSame('abc123DEF', $this->save(['api_key' => '   '])['api_key']);
        $this->assertSame('abc123DEF', $this->save(['ttl' => 120])['api_key']);
    }

    public function testTheApiKeyCanBeReplacedAndRemoved(): void
    {
        $this->save(['api_key' => 'old']);

        $this->assertSame('new123', $this->save(['api_key' => ' new-123 '])['api_key'], 'only letters and digits survive');
        $this->assertSame('', $this->save(['api_key_remove' => '1'])['api_key']);
    }

    public function testRegionsAreFilteredAndAtLeastOneIsAlwaysKept(): void
    {
        $this->assertSame(['uk', 'eu'], $this->save(['api_regions' => ['uk', 'moon', 'eu']])['api_regions']);
        $this->assertSame(['eu'], $this->save(['api_regions' => ['']])['api_regions']);
        $this->assertSame(['eu'], $this->save(['api_regions' => ['moon']])['api_regions']);
    }

    public function testTheKeyAndRegionsAreReadBackThroughTheGetters(): void
    {
        $this->save(['api_key' => 'KEY1', 'api_regions' => ['us', 'au']]);
        $settings = new Settings();

        $this->assertSame('KEY1', $settings->apiKey());
        $this->assertSame(['us', 'au'], $settings->apiRegions());
    }
}
