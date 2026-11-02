<?php
/**
 * Health status value object.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

/**
 * Result of a source availability check. Serialisable so it can be cached.
 */
final class HealthStatus
{
    /**
     * @param HealthState $state     Outcome of the check.
     * @param string      $message   Human readable detail (error text, count of sports found…).
     * @param int|null    $latencyMs Round-trip time of the probe in milliseconds.
     * @param int         $checkedAt Unix timestamp of the check.
     */
    public function __construct(
        public readonly HealthState $state,
        public readonly string $message = '',
        public readonly ?int $latencyMs = null,
        public readonly int $checkedAt = 0,
    ) {
    }

    public static function unknown(): self
    {
        return new self(HealthState::Unknown);
    }

    public function isUsable(): bool
    {
        return $this->state->isUsable();
    }

    /**
     * @return array{state:string,label:string,message:string,latencyMs:int|null,checkedAt:int}
     */
    public function toArray(): array
    {
        return [
            'state'     => $this->state->value,
            'label'     => $this->state->label(),
            'message'   => $this->message,
            'latencyMs' => $this->latencyMs,
            'checkedAt' => $this->checkedAt,
        ];
    }

    /**
     * @param array<string,mixed> $data Result of toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            HealthState::tryFrom((string) ($data['state'] ?? '')) ?? HealthState::Unknown,
            (string) ($data['message'] ?? ''),
            isset($data['latencyMs']) ? (int) $data['latencyMs'] : null,
            (int) ($data['checkedAt'] ?? 0),
        );
    }
}
