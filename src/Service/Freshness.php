<?php
/**
 * Freshness enum.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Service;

/**
 * How trustworthy the odds in an OddsResult are.
 */
enum Freshness: string
{
    /** Fetched within the TTL. */
    case Fresh = 'fresh';

    /** The source failed; these are the last known odds, shown with a notice. */
    case Stale = 'stale';

    /** The event is over; the last stored odds are final and no longer refreshed. */
    case Finished = 'finished';

    /** Another request is fetching right now; try again shortly. */
    case Pending = 'pending';
}
