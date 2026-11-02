<?php
/**
 * Odds snapshot model.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Model;

/**
 * Odds of one market of one event, as returned by a source at a point in time.
 *
 * This is the unit that gets cached and rendered by the block.
 */
final class OddsSnapshot
{
    /**
     * @param string        $eventId   Event identifier.
     * @param string        $marketId  Market identifier.
     * @param string        $sourceId  Id of the source that produced the data.
     * @param list<Outcome> $outcomes  Outcomes with per-bookmaker prices.
     * @param int           $fetchedAt Unix timestamp of the fetch.
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $marketId,
        public readonly string $sourceId,
        public readonly array $outcomes,
        public readonly int $fetchedAt,
    ) {
    }

    /**
     * Bookmaker ids that have at least one price in this snapshot.
     *
     * @return list<string>
     */
    public function bookmakerIds(): array
    {
        $ids = [];
        foreach ($this->outcomes as $outcome) {
            $ids = array_merge($ids, array_keys($outcome->prices));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'eventId'   => $this->eventId,
            'marketId'  => $this->marketId,
            'sourceId'  => $this->sourceId,
            'outcomes'  => array_map(static fn (Outcome $o): array => $o->toArray(), $this->outcomes),
            'fetchedAt' => $this->fetchedAt,
        ];
    }

    /**
     * @param array<string,mixed> $data Result of toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['eventId'] ?? ''),
            (string) ($data['marketId'] ?? ''),
            (string) ($data['sourceId'] ?? ''),
            array_map(
                static fn ($o): Outcome => Outcome::fromArray((array) $o),
                (array) ($data['outcomes'] ?? [])
            ),
            (int) ($data['fetchedAt'] ?? 0),
        );
    }
}
