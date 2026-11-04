<?php
/**
 * In-memory bookmaker repository.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Support;

use OddsComparison\Bookmakers\Bookmaker;
use OddsComparison\Contracts\BookmakerRepositoryInterface;

/**
 * BookmakerRepositoryInterface kept in an array, for tests of the service layer.
 */
final class ArrayBookmakerRepository implements BookmakerRepositoryInterface
{
    /** @var array<string,array<string,array{name:string,url:string,custom:bool,enabled:bool,order:int}>> */
    public array $rows = [];

    public function all(string $sourceId): array
    {
        $out = [];
        foreach ($this->rows[$sourceId] ?? [] as $id => $row) {
            $out[] = new Bookmaker((string) $id, $row['name'] !== '' ? $row['name'] : (string) $id, $row['url'], $row['enabled'], $row['order'], $row['custom']);
        }

        usort($out, static fn (Bookmaker $a, Bookmaker $b): int => $a->order <=> $b->order);

        return $out;
    }

    public function enabled(string $sourceId): array
    {
        return array_values(array_filter($this->all($sourceId), static fn (Bookmaker $b): bool => $b->enabled));
    }

    public function get(string $sourceId, string $id): Bookmaker
    {
        foreach ($this->all($sourceId) as $bookmaker) {
            if ($bookmaker->id === $id) {
                return $bookmaker;
            }
        }

        return new Bookmaker($id, $id);
    }

    public function discover(string $sourceId, array $ids, array $names = []): void
    {
        foreach ($ids as $id) {
            $this->rows[$sourceId][$id] ??= [
                'name'    => $names[$id] ?? '',
                'url'     => '',
                'custom'  => false,
                'enabled' => true,
                'order'   => count($this->rows[$sourceId] ?? []) * 10,
            ];
        }
    }

    public function save(array $input): void
    {
    }

    /**
     * Test helper: add a bookmaker. A link counts as set by the admin ($custom) unless
     * it is only the built-in default site.
     */
    public function add(string $sourceId, string $id, string $name = '', string $url = '', bool $enabled = true, bool $custom = true): self
    {
        $this->rows[$sourceId][$id] = ['name' => $name, 'url' => $url, 'custom' => $custom && $url !== '', 'enabled' => $enabled, 'order' => $this->rows[$sourceId][$id]['order'] ?? count($this->rows[$sourceId] ?? []) * 10];

        return $this;
    }
}
