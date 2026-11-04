<?php
/**
 * Controllable source.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Support;

use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Sources\Exception\SourceException;
use OddsComparison\Sources\HealthStatus;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\Market;
use OddsComparison\Sources\Model\OddsSnapshot;
use OddsComparison\Sources\Model\Outcome;
use OddsComparison\Sources\Model\Sport;

/**
 * A source whose behaviour a test controls: it can fail, change its price and
 * counts how often it was asked.
 */
final class FakeSource implements SourceInterface
{
    public int $oddsCalls = 0;

    public int $sportsCalls = 0;

    public bool $fail = false;

    public float $price = 2.0;

    /** @var array<string,string> Bookmaker names reported with the odds. */
    public array $names = ['bk' => 'Book K'];

    public function __construct(private readonly string $id = 'fake')
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return 'Fake ' . $this->id;
    }

    public function description(): string
    {
        return '';
    }

    public function requiresApiKey(): bool
    {
        return false;
    }

    public function checkAvailability(): HealthStatus
    {
        return HealthStatus::unknown();
    }

    public function sports(): array
    {
        $this->sportsCalls++;
        if ($this->fail) {
            throw new SourceException('sports failed');
        }

        return [new Sport('s', 'Sport')];
    }

    public function events(string $sportId): array
    {
        if ($this->fail) {
            throw new SourceException('events failed');
        }

        return [new Event('e1', $sportId, 'Home v Away', '/s/e1')];
    }

    public function markets(Event $event): array
    {
        return [new Market('moneyline', 'Moneyline'), new Market('spread', 'Spread')];
    }

    public function odds(Event $event, string $marketId): OddsSnapshot
    {
        $this->oddsCalls++;
        if ($this->fail) {
            throw new SourceException('boom');
        }

        return new OddsSnapshot($event->id, $marketId, $this->id, [new Outcome('A', ['bk' => $this->price])], time(), $this->names);
    }
}
