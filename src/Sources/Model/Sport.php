<?php
/**
 * Sport model.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Model;

/**
 * A sport offered by a source, e.g. "Football".
 */
final class Sport
{
    /**
     * @param string $id   Source-specific identifier (usually a URL slug).
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
