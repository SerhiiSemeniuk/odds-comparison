<?php
/**
 * Circuit breaker tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Http;

use OddsComparison\Cache\ArrayCache;
use OddsComparison\Http\CircuitBreaker;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    public function testIsClosedByDefault(): void
    {
        $breaker = new CircuitBreaker(new ArrayCache());

        $this->assertFalse($breaker->isOpen('a.com'));
        $this->assertSame(0, $breaker->remaining('a.com'));
    }

    public function testOpensAfterTheThresholdOfFailures(): void
    {
        $breaker = new CircuitBreaker(new ArrayCache(), 3, 600);

        $breaker->recordFailure('a.com');
        $breaker->recordFailure('a.com');
        $this->assertFalse($breaker->isOpen('a.com'), 'two failures are tolerated');

        $breaker->recordFailure('a.com');
        $this->assertTrue($breaker->isOpen('a.com'));
        $this->assertGreaterThan(500, $breaker->remaining('a.com'));
    }

    public function testRetryAfterOpensTheCircuitAtOnce(): void
    {
        $breaker = new CircuitBreaker(new ArrayCache(), 3, 600);

        $breaker->recordFailure('a.com', 120);

        $this->assertTrue($breaker->isOpen('a.com'));
        $this->assertGreaterThan(100, $breaker->remaining('a.com'));
        $this->assertLessThanOrEqual(120, $breaker->remaining('a.com'));
    }

    public function testASuccessResetsTheCounter(): void
    {
        $breaker = new CircuitBreaker(new ArrayCache(), 2, 600);

        $breaker->recordFailure('a.com');
        $breaker->recordSuccess('a.com');
        $breaker->recordFailure('a.com');

        $this->assertFalse($breaker->isOpen('a.com'));
    }

    public function testHostsAreIndependent(): void
    {
        $breaker = new CircuitBreaker(new ArrayCache(), 1, 600);

        $breaker->recordFailure('a.com');

        $this->assertTrue($breaker->isOpen('a.com'));
        $this->assertFalse($breaker->isOpen('b.com'));
    }
}
