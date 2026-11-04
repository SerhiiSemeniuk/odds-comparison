<?php
/**
 * Odds store contract.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

/**
 * Persistence of the latest odds snapshot per source, event and market.
 *
 * The production implementation uses a custom table; tests use an in-memory one.
 */
interface OddsStoreInterface
{
    /**
     * @return array{data:array<mixed>,fresh_until:int,fetched_at:int}|null
     */
    public function find(string $sourceId, string $eventId, string $marketId): ?array;

    /**
     * Insert or replace a snapshot.
     *
     * @param array<mixed> $data     Snapshot as produced by OddsSnapshot::toArray().
     * @param int|null     $startsAt Event start (unix time), used to purge finished events.
     */
    public function save(string $sourceId, string $eventId, string $marketId, array $data, int $freshUntil, ?int $startsAt): void;

    /**
     * Delete every snapshot.
     */
    public function clear(): void;

    /**
     * Delete snapshots of events that ended long ago.
     *
     * @return int Number of rows removed.
     */
    public function purge(): int;
}
