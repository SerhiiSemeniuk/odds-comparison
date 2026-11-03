<?php
/**
 * Market model.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Model;

/**
 * A betting market, e.g. "Match Winner" or "Over/Under 2.5".
 */
final class Market
{
    /**
     * @param string $id   Stable market key shared across sources (e.g. "1x2").
     * @param string $name Display name.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }

    /**
     * @return array{id:string,name:string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }

    /**
     * @param array<string,mixed> $data Result of toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['id'] ?? ''), (string) ($data['name'] ?? ''));
    }
}
