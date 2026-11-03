<?php
/**
 * Event model.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources\Model;

/**
 * A single event (match) a source can provide odds for.
 */
final class Event
{
    /**
     * @param string      $id       Source-specific identifier.
     * @param string      $sportId  Identifier of the parent Sport.
     * @param string      $name     Display name, e.g. "Arsenal v Chelsea".
     * @param string      $url      Source path/URL the odds are scraped from.
     * @param string      $league   Competition name, may be empty.
     * @param int|null    $startsAt Unix timestamp of the start, when known.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sportId,
        public readonly string $name,
        public readonly string $url = '',
        public readonly string $league = '',
        public readonly ?int $startsAt = null,
    ) {
    }

    /**
     * @return array{id:string,sportId:string,name:string,url:string,league:string,startsAt:int|null}
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'sportId'  => $this->sportId,
            'name'     => $this->name,
            'url'      => $this->url,
            'league'   => $this->league,
            'startsAt' => $this->startsAt,
        ];
    }

    /**
     * @param array<string,mixed> $data Result of toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['sportId'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['url'] ?? ''),
            (string) ($data['league'] ?? ''),
            isset($data['startsAt']) ? (int) $data['startsAt'] : null,
        );
    }
}
