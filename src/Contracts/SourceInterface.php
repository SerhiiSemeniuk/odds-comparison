<?php
/**
 * Odds source contract.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

use OddsComparison\Sources\Exception\SourceException;
use OddsComparison\Sources\HealthStatus;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\Market;
use OddsComparison\Sources\Model\OddsSnapshot;
use OddsComparison\Sources\Model\Sport;

/**
 * A provider of sports, events and odds: a scraped website, a JSON API or a
 * bundled fixture set. Everything above this interface (cache, REST, block)
 * is source-agnostic, so adding a provider means adding one class.
 *
 * Methods throw a SourceException subclass on failure.
 */
interface SourceInterface
{
    /**
     * Stable machine id, e.g. "betexplorer". Used in settings and cache keys.
     */
    public function id(): string;

    /**
     * Human readable name for the admin dashboard.
     */
    public function label(): string;

    /**
     * Short description shown under the name (what it is, caveats).
     */
    public function description(): string;

    /**
     * Whether the source needs an API key to work.
     */
    public function requiresApiKey(): bool;

    /**
     * Probe the source and report whether it is usable right now.
     *
     * Must not throw: failures are translated into a HealthStatus.
     */
    public function checkAvailability(): HealthStatus;

    /**
     * @return list<Sport>
     * @throws SourceException
     */
    public function sports(): array;

    /**
     * Upcoming events of a sport.
     *
     * @return list<Event>
     * @throws SourceException
     */
    public function events(string $sportId): array;

    /**
     * Markets that can be requested for an event.
     *
     * @return list<Market>
     * @throws SourceException
     */
    public function markets(Event $event): array;

    /**
     * Prices of one market across bookmakers.
     *
     * @throws SourceException
     */
    public function odds(Event $event, string $marketId): OddsSnapshot;
}
