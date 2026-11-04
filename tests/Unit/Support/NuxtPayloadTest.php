<?php
/**
 * Nuxt payload decoder tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Support;

use OddsComparison\Sources\Exception\ParseException;
use OddsComparison\Support\NuxtPayload;
use OddsComparison\Tests\Support\NuxtFixture;
use PHPUnit\Framework\TestCase;

final class NuxtPayloadTest extends TestCase
{
    public function testDecodesAStructureBuiltByTheFixtureEncoder(): void
    {
        $data = ['game' => ['home' => 'Hornets', 'score' => 101, 'live' => false, 'none' => null], 'odds' => [['operator' => 'x', 'home' => 150]]];

        $this->assertSame($data, NuxtPayload::fromHtml(NuxtFixture::page($data)));
    }

    public function testDecodesTheRealPayloadShape(): void
    {
        // A hand-written flat array: the root wraps an object whose values are slots.
        $html = '<script type="application/json" id="__NUXT_DATA__">'
            . '[["ShallowReactive",1],{"data":2},{"games":3,"count":5},[4],{"home":6},2,"Hornets"]'
            . '</script>';

        $this->assertSame(['data' => ['games' => [['home' => 'Hornets']], 'count' => 2]], NuxtPayload::fromHtml($html));
    }

    public function testNegativeIndexesAreNull(): void
    {
        $html = '<script type="application/json" id="__NUXT_DATA__">[{"a":-1,"b":1},"x"]</script>';

        $this->assertSame(['a' => null, 'b' => 'x'], NuxtPayload::fromHtml($html));
    }

    public function testAPageWithoutAPayloadIsAParseError(): void
    {
        $this->expectException(ParseException::class);

        NuxtPayload::fromHtml('<html><body>nothing here</body></html>');
    }

    public function testBrokenJsonIsAParseError(): void
    {
        $this->expectException(ParseException::class);

        NuxtPayload::fromHtml('<script type="application/json" id="__NUXT_DATA__">{not json</script>');
    }

    public function testFindAllCollectsMatchingNodesAtAnyDepth(): void
    {
        $tree = ['a' => ['oddType' => 'moneyline', 'v' => 1], 'b' => ['c' => [['oddType' => 'spread'], ['x' => 1]]]];

        $found = NuxtPayload::findAll($tree, static fn (array $n): bool => isset($n['oddType']));

        $this->assertCount(2, $found);
        $this->assertSame(['moneyline', 'spread'], array_column($found, 'oddType'));
    }
}
