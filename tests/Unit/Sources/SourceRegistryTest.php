<?php
/**
 * SourceRegistry tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Sources;

use InvalidArgumentException;
use OddsComparison\Sources\SourceRegistry;
use OddsComparison\Tests\Support\FakeSource;
use PHPUnit\Framework\TestCase;

final class SourceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['oddscmp_filters'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['oddscmp_filters'] = [];
    }

    public function testSourcesAreBuiltLazilyAndOnlyOnce(): void
    {
        $built    = 0;
        $registry = new SourceRegistry(['a' => function () use (&$built) {
            $built++;

            return new FakeSource('a');
        }]);

        $this->assertSame(0, $built, 'registering builds nothing');

        $first = $registry->get('a');
        $this->assertSame($first, $registry->get('a'));
        $this->assertSame(1, $built);
    }

    public function testAnUnknownSourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SourceRegistry())->get('ghost');
    }

    public function testIdsAndLookupDoNotBuildSources(): void
    {
        $registry = new SourceRegistry(['a' => static fn () => throw new \LogicException('must not be built'), 'b' => static fn () => new FakeSource('b')]);

        $this->assertSame(['a', 'b'], $registry->ids());
        $this->assertTrue($registry->has('a'));
        $this->assertFalse($registry->has('c'));
    }

    public function testAllReturnsEverySourceById(): void
    {
        $registry = new SourceRegistry(['a' => fn () => new FakeSource('a'), 'b' => fn () => new FakeSource('b')]);

        $this->assertSame(['a', 'b'], array_keys($registry->all()));
    }

    public function testRegisteringAgainReplacesTheSource(): void
    {
        $registry = new SourceRegistry(['a' => fn () => new FakeSource('old')]);
        $registry->get('a');

        $registry->register('a', fn () => new FakeSource('new'));

        $this->assertSame('new', $registry->get('a')->id());
    }

    public function testThirdPartiesAddSourcesThroughTheFilter(): void
    {
        $GLOBALS['oddscmp_filters']['odds_comparison_sources'] = static function (array $factories): array {
            $factories['extra'] = static fn () => new FakeSource('extra');

            return $factories;
        };

        $registry = new SourceRegistry(['a' => fn () => new FakeSource('a')]);

        $this->assertSame(['a', 'extra'], $registry->ids());
    }
}
