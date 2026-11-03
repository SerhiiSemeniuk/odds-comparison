<?php
/**
 * Odds result.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Service;

use OddsComparison\Sources\Model\OddsSnapshot;

/**
 * Outcome of asking OddsService for the odds of one market of one event.
 */
final class OddsResult
{
    /**
     * @param OddsSnapshot|null $snapshot   The odds; null only while Pending.
     * @param Freshness         $freshness  Whether the odds are fresh, stale, final or still loading.
     * @param string            $notice     Why the data is stale (source error), for display.
     * @param int               $retryAfter Seconds to wait before asking again when Pending.
     */
    public function __construct(
        public readonly ?OddsSnapshot $snapshot,
        public readonly Freshness $freshness,
        public readonly string $notice = '',
        public readonly int $retryAfter = 0,
    ) {
    }

    public static function pending(int $retryAfter = 2): self
    {
        return new self(null, Freshness::Pending, '', $retryAfter);
    }
}
