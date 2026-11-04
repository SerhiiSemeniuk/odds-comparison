<?php
/**
 * HealthMonitor tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Sources;

use OddsComparison\Cache\ArrayCache;
use OddsComparison\Sources\HealthMonitor;
use OddsComparison\Sources\HealthState;
use OddsComparison\Sources\SourceRegistry;
use OddsComparison\Tests\Support\FakeKeyedSource;
use OddsComparison\Tests\Support\FakeSource;
use PHPUnit\Framework\TestCase;

final class HealthMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['oddscmp_filters'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['oddscmp_filters'] = [];
    }

    public function testAnUncheckedSourceIsUnknownButWorthTrying(): void
    {
        $monitor = new HealthMonitor(new SourceRegistry(['fake' => fn () => new FakeSource()]), new ArrayCache());

        $this->assertSame(HealthState::Unknown, $monitor->status('fake')->state);
        $this->assertTrue($monitor->isWorthTrying('fake'));
    }

    public function testCheckStoresTheResultForLaterReads(): void
    {
        $keyed   = new FakeKeyedSource(true, HealthState::Blocked);
        $monitor = new HealthMonitor(new SourceRegistry(['keyed' => fn () => $keyed]), new ArrayCache());

        $status = $monitor->check('keyed');

        $this->assertSame(HealthState::Blocked, $status->state);
        $this->assertSame(HealthState::Blocked, $monitor->status('keyed')->state);
        $this->assertFalse($monitor->isWorthTrying('keyed'), 'a source known to be blocked is skipped by the fallback chain');
    }

    public function testASourceNeedingAKeyIsNotProbedWithoutOne(): void
    {
        $keyed   = new FakeKeyedSource(false);
        $monitor = new HealthMonitor(new SourceRegistry(['keyed' => fn () => $keyed]), new ArrayCache());

        $this->assertSame(HealthState::NeedsKey, $monitor->check('keyed')->state);
        $this->assertSame(0, $keyed->probes, 'no request is made with a missing key');
    }

    public function testASourceWithAKeyIsProbed(): void
    {
        $keyed   = new FakeKeyedSource(true);
        $monitor = new HealthMonitor(new SourceRegistry(['keyed' => fn () => $keyed]), new ArrayCache());

        $this->assertSame(HealthState::Available, $monitor->check('keyed')->state);
        $this->assertSame(1, $keyed->probes);
    }

    public function testCheckAllCoversEverySource(): void
    {
        $monitor = new HealthMonitor(
            new SourceRegistry(['a' => fn () => new FakeSource('a'), 'keyed' => fn () => new FakeKeyedSource(true)]),
            new ArrayCache()
        );

        $this->assertSame(['a', 'keyed'], array_keys($monitor->checkAll()));
    }
}
