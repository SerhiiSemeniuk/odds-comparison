<?php
/**
 * Odds store tests against the real table.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Integration;

use OddsComparison\Database\Schema;
use OddsComparison\Storage\OddsStore;

final class OddsStoreTest extends IntegrationTestCase
{
    /**
     * @return array<string,mixed>
     */
    private function snapshot(float $price = 2.5): array
    {
        return ['eventId' => 'e1', 'marketId' => 'moneyline', 'sourceId' => 'srcA', 'outcomes' => [['name' => 'A', 'prices' => ['fanduel' => $price]]], 'fetchedAt' => time()];
    }

    private function rowCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::odds()); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    protected function setUp(): void
    {
        parent::setUp();
        delete_transient('oddscmp_purge'); // the opportunistic purge runs at most hourly
    }

    public function testAMissIsNull(): void
    {
        $this->assertNull((new OddsStore())->find('srcA', 'e1', 'moneyline'));
    }

    public function testASnapshotRoundTrips(): void
    {
        $store = new OddsStore();
        $store->save('srcA', 'e1', 'moneyline', $this->snapshot(), time() + 300, time() + 3600);

        $found = $store->find('srcA', 'e1', 'moneyline');

        $this->assertNotNull($found);
        $this->assertSame(2.5, $found['data']['outcomes'][0]['prices']['fanduel']);
        $this->assertGreaterThan(time(), $found['fresh_until']);
    }

    public function testSavingTheSameKeyUpdatesInPlace(): void
    {
        $store = new OddsStore();
        $store->save('srcA', 'e1', 'moneyline', $this->snapshot(2.5), time() + 300, null);
        $store->save('srcA', 'e1', 'moneyline', $this->snapshot(3.0), time() + 600, null);

        $this->assertSame(1, $this->rowCount(), 'the unique key turns the second save into an update');
        $this->assertEquals(3.0, $store->find('srcA', 'e1', 'moneyline')['data']['outcomes'][0]['prices']['fanduel']);
    }

    public function testMarketsAndEventsAreSeparateRows(): void
    {
        $store = new OddsStore();
        $store->save('srcA', 'e1', 'moneyline', $this->snapshot(), time() + 300, null);
        $store->save('srcA', 'e1', 'spread', $this->snapshot(), time() + 300, null);
        $store->save('srcA', 'e2', 'moneyline', $this->snapshot(), time() + 300, null);
        $store->save('srcB', 'e1', 'moneyline', $this->snapshot(), time() + 300, null);

        $this->assertSame(4, $this->rowCount());
    }

    public function testAnUnknownStartIsStoredAsNull(): void
    {
        global $wpdb;

        (new OddsStore())->save('srcA', 'e1', 'moneyline', $this->snapshot(), time() + 300, null);

        $this->assertNull($wpdb->get_var('SELECT starts_at FROM ' . Schema::odds())); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public function testPurgeRemovesOnlyEventsThatEndedLongAgo(): void
    {
        $store = new OddsStore();
        $store->save('srcA', 'old', 'moneyline', $this->snapshot(), time() - 10, time() - 10 * DAY_IN_SECONDS);
        delete_transient('oddscmp_purge');
        $store->save('srcA', 'recent', 'moneyline', $this->snapshot(), time() + 60, time() - DAY_IN_SECONDS);
        $store->save('srcA', 'future', 'moneyline', $this->snapshot(), time() + 60, time() + DAY_IN_SECONDS);
        $store->save('srcA', 'nostart', 'moneyline', $this->snapshot(), time() + 60, null);

        $store->purge();

        $this->assertNull($store->find('srcA', 'old', 'moneyline'));
        $this->assertNotNull($store->find('srcA', 'recent', 'moneyline'));
        $this->assertNotNull($store->find('srcA', 'future', 'moneyline'));
        $this->assertNotNull($store->find('srcA', 'nostart', 'moneyline'));
    }

    public function testWritesPurgeAtMostOncePerHour(): void
    {
        $store = new OddsStore();
        $store->save('srcA', 'old1', 'moneyline', $this->snapshot(), time() - 10, time() - 10 * DAY_IN_SECONDS);

        $this->assertNotFalse(get_transient('oddscmp_purge'), 'the write marked the purge as done for the hour');

        $store->save('srcA', 'old2', 'moneyline', $this->snapshot(), time() - 10, time() - 10 * DAY_IN_SECONDS);
        $this->assertNotNull($store->find('srcA', 'old2', 'moneyline'), 'no second purge inside the hour');
    }

    public function testTheRetentionPeriodIsFilterable(): void
    {
        $store = new OddsStore();
        $store->save('srcA', 'e1', 'moneyline', $this->snapshot(), time() - 10, time() - 2 * DAY_IN_SECONDS);

        add_filter('odds_comparison_retention_days', static fn (): int => 1);
        try {
            $store->purge();
        } finally {
            remove_all_filters('odds_comparison_retention_days');
        }

        $this->assertNull($store->find('srcA', 'e1', 'moneyline'));
    }

    public function testClearEmptiesTheTable(): void
    {
        $store = new OddsStore();
        $store->save('srcA', 'e1', 'moneyline', $this->snapshot(), time() + 300, null);

        $store->clear();

        $this->assertSame(0, $this->rowCount());
    }

    public function testVeryLongKeysAreTruncatedInsteadOfFailing(): void
    {
        $store = new OddsStore();
        $store->save('srcA', str_repeat('e', 300), 'moneyline', $this->snapshot(), time() + 300, null);

        $this->assertNotNull($store->find('srcA', str_repeat('e', 300), 'moneyline'));
    }
}
