<?php
/**
 * Outcome model.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Model;

/**
 * One selectable result of a market together with every bookmaker's price.
 */
final class Outcome
{
    /**
     * @param string             $name   Outcome label, e.g. "Arsenal", "Draw".
     * @param array<string,float> $prices Decimal odds keyed by bookmaker id.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $prices,
    ) {
    }

    /**
     * Highest available decimal price, or null when no bookmaker quotes it.
     */
    public function best(): ?float
    {
        return $this->prices === [] ? null : max($this->prices);
    }

    /**
     * @return array{name:string,prices:array<string,float>}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'prices' => $this->prices];
    }

    /**
     * @param array<string,mixed> $data Result of toArray().
     */
    public static function fromArray(array $data): self
    {
        $prices = [];
        foreach ((array) ($data['prices'] ?? []) as $bookmaker => $price) {
            $prices[(string) $bookmaker] = (float) $price;
        }

        return new self((string) ($data['name'] ?? ''), $prices);
    }
}
