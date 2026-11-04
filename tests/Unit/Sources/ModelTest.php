<?php
/**
 * Source model tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Sources;

use OddsComparison\Sources\HealthState;
use OddsComparison\Sources\HealthStatus;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\OddsSnapshot;
use OddsComparison\Sources\Model\Outcome;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    public function testOutcomeReportsItsBestPrice(): void
    {
        $this->assertSame(2.5, (new Outcome('A', ['x' => 2.0, 'y' => 2.5, 'z' => 1.9]))->best());
        $this->assertNull((new Outcome('A', []))->best());
    }

    public function testSnapshotSurvivesAnArrayRoundTrip(): void
    {
        $snapshot = new OddsSnapshot(
            'e1',
            'moneyline',
            'src',
            [new Outcome('A', ['x' => 2.0]), new Outcome('B', ['x' => 1.8, 'y' => 1.9])],
            1700000000,
            ['x' => 'Book X'],
            ['x' => 'https://x.example/bet']
        );

        $copy = OddsSnapshot::fromArray($snapshot->toArray());

        $this->assertEquals($snapshot, $copy);
        $this->assertSame(['x', 'y'], $copy->bookmakerIds());
    }

    public function testSnapshotsWithoutNamesOrLinksStillLoad(): void
    {
        // Rows stored before names and links existed have no such keys.
        $copy = OddsSnapshot::fromArray(['eventId' => 'e', 'marketId' => 'm', 'sourceId' => 's', 'outcomes' => [], 'fetchedAt' => 5]);

        $this->assertSame([], $copy->names);
        $this->assertSame([], $copy->links);
    }

    public function testEventSurvivesAnArrayRoundTrip(): void
    {
        $event = new Event('id', 'nba', 'A v B', '/x/id', 'NBA', 1700000000);

        $this->assertEquals($event, Event::fromArray($event->toArray()));
    }

    public function testHealthStatusSurvivesAnArrayRoundTrip(): void
    {
        $status = new HealthStatus(HealthState::Blocked, 'nope', 120, 1700000000);

        $this->assertEquals($status, HealthStatus::fromArray($status->toArray()));
        $this->assertFalse($status->isUsable());
        $this->assertTrue((new HealthStatus(HealthState::Available))->isUsable());
    }

    public function testUnknownHealthStateFallsBack(): void
    {
        $this->assertSame(HealthState::Unknown, HealthStatus::fromArray(['state' => 'garbage'])->state);
    }
}
