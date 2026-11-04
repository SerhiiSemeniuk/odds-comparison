<?php
/**
 * In-memory odds store.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Support;

use OddsComparison\Contracts\OddsStoreInterface;

/**
 * OddsStoreInterface kept in an array, for tests of the service layer.
 */
final class ArrayOddsStore implements OddsStoreInterface
{
    /** @var array<string,array{data:array<mixed>,fresh_until:int,fetched_at:int,starts_at:int|null}> */
    public array $rows = [];

    public int $saves = 0;

    public function find(string $sourceId, string $eventId, string $marketId): ?array
    {
        $row = $this->rows[$this->key($sourceId, $eventId, $marketId)] ?? null;

        return $row === null ? null : ['data' => $row['data'], 'fresh_until' => $row['fresh_until'], 'fetched_at' => $row['fetched_at']];
    }

    public function save(string $sourceId, string $eventId, string $marketId, array $data, int $freshUntil, ?int $startsAt): void
    {
        $this->saves++;
        $this->rows[$this->key($sourceId, $eventId, $marketId)] = [
            'data'        => $data,
            'fresh_until' => $freshUntil,
            'fetched_at'  => time(),
            'starts_at'   => $startsAt,
        ];
    }

    public function clear(): void
    {
        $this->rows = [];
    }

    public function purge(): int
    {
        return 0;
    }

    /**
     * Make a stored snapshot stale, as if its TTL had run out.
     */
    public function expire(string $sourceId, string $eventId, string $marketId): void
    {
        $this->rows[$this->key($sourceId, $eventId, $marketId)]['fresh_until'] = time() - 1;
    }

    private function key(string $sourceId, string $eventId, string $marketId): string
    {
        return $sourceId . '|' . $eventId . '|' . $marketId;
    }
}
