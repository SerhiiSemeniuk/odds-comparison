<?php
/**
 * Odds snapshot store.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Storage;

use OddsComparison\Contracts\OddsStoreInterface;
use OddsComparison\Database\Schema;

/**
 * Persists the latest odds snapshot per source, event and market in a custom
 * table instead of transients, so the data neither bloats wp_options nor
 * depends on the object cache, and old rows can be removed with one indexed
 * query.
 *
 * Old rows are purged opportunistically: at most once an hour a write also
 * deletes snapshots of events that ended long ago, so no cron job is needed.
 */
final class OddsStore implements OddsStoreInterface
{
    private const PURGE_TRANSIENT = 'oddscmp_purge';

    /**
     * @return array{data:array<mixed>,fresh_until:int,fetched_at:int}|null
     */
    public function find(string $sourceId, string $eventId, string $marketId): ?array
    {
        global $wpdb;

        $table = Schema::odds();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payload, fresh_until, fetched_at FROM {$table} WHERE source_id = %s AND event_id = %s AND market_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $this->cut($sourceId, 32),
                $this->cut($eventId, 120),
                $this->cut($marketId, 32)
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        $data = json_decode((string) $row['payload'], true);

        return is_array($data)
            ? ['data' => $data, 'fresh_until' => (int) $row['fresh_until'], 'fetched_at' => (int) $row['fetched_at']]
            : null;
    }

    /**
     * Insert or replace a snapshot.
     *
     * @param array<mixed> $data     Snapshot as produced by OddsSnapshot::toArray().
     * @param int|null     $startsAt Event start (unix time), used to purge finished events.
     */
    public function save(string $sourceId, string $eventId, string $marketId, array $data, int $freshUntil, ?int $startsAt): void
    {
        global $wpdb;

        $table = Schema::odds();
        $now   = time();

        // One atomic upsert on the (source, event, market) unique key. The table name comes from
        // Schema (the site's prefix plus a constant); every value goes through a placeholder.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (source_id, event_id, market_id, payload, fetched_at, fresh_until, starts_at)
                 VALUES (%s, %s, %s, %s, %d, %d, NULLIF(%d, 0))
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at),
                    fresh_until = VALUES(fresh_until), starts_at = VALUES(starts_at)",
                $this->cut($sourceId, 32),
                $this->cut($eventId, 120),
                $this->cut($marketId, 32),
                (string) wp_json_encode($data),
                $now,
                $freshUntil,
                max(0, $startsAt ?? 0) // NULLIF turns an unknown start (0) into NULL.
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $this->maybePurge();
    }

    /**
     * Delete every snapshot (the dashboard "Clear cache" button).
     */
    public function clear(): void
    {
        global $wpdb;

        $wpdb->query('DELETE FROM ' . Schema::odds()); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /**
     * Delete snapshots of events that finished, or were last fetched, more
     * than the retention period ago.
     *
     * @return int Number of rows removed.
     */
    public function purge(): int
    {
        global $wpdb;

        /**
         * Filters how many days a snapshot is kept after its event started.
         *
         * @param int $days Retention in days (default 7).
         */
        $cutoff = time() - max(1, (int) apply_filters('odds_comparison_retention_days', 7)) * DAY_IN_SECONDS;
        $table  = Schema::odds();

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE (starts_at IS NOT NULL AND starts_at < %d) OR (starts_at IS NULL AND fetched_at < %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $cutoff,
                $cutoff
            )
        );

        return is_int($deleted) ? $deleted : 0;
    }

    private function maybePurge(): void
    {
        if (get_transient(self::PURGE_TRANSIENT) !== false) {
            return;
        }

        // Set first so concurrent writers do not all purge.
        set_transient(self::PURGE_TRANSIENT, 1, HOUR_IN_SECONDS);
        $this->purge();
    }

    private function cut(string $value, int $length): string
    {
        return substr($value, 0, $length);
    }
}
