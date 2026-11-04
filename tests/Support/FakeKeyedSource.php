<?php
/**
 * Source that needs a key.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Support;

use OddsComparison\Contracts\KeyedSourceInterface;
use OddsComparison\Sources\HealthState;
use OddsComparison\Sources\HealthStatus;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\OddsSnapshot;

/**
 * A KeyedSourceInterface whose key presence and probe result a test controls.
 */
final class FakeKeyedSource implements KeyedSourceInterface
{
    public int $probes = 0;

    public function __construct(private readonly bool $hasKey, private readonly HealthState $result = HealthState::Available)
    {
    }

    public function id(): string
    {
        return 'keyed';
    }

    public function label(): string
    {
        return 'Keyed';
    }

    public function description(): string
    {
        return '';
    }

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function hasKey(): bool
    {
        return $this->hasKey;
    }

    public function checkAvailability(): HealthStatus
    {
        $this->probes++;

        return new HealthStatus($this->result, 'probed', 5, time());
    }

    public function sports(): array
    {
        return [];
    }

    public function events(string $sportId): array
    {
        return [];
    }

    public function markets(Event $event): array
    {
        return [];
    }

    public function odds(Event $event, string $marketId): OddsSnapshot
    {
        throw new \LogicException('not used');
    }
}
