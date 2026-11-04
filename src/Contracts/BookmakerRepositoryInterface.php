<?php
/**
 * Bookmaker repository contract.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

use OddsComparison\Bookmakers\Bookmaker;

/**
 * Storage of the bookmakers each source provides and the admin's choices
 * (visibility, order, name, link) for them.
 */
interface BookmakerRepositoryInterface
{
    /**
     * Bookmakers of one source, ordered by position then name.
     *
     * @return list<Bookmaker>
     */
    public function all(string $sourceId): array;

    /**
     * @return list<Bookmaker>
     */
    public function enabled(string $sourceId): array;

    public function get(string $sourceId, string $id): Bookmaker;

    /**
     * Record bookmaker ids a source has reported prices for.
     *
     * @param list<string>          $ids
     * @param array<string,string>  $names Display names the source reported, by bookmaker id.
     */
    public function discover(string $sourceId, array $ids, array $names = []): void;

    /**
     * Update bookmakers from the dashboard form.
     *
     * @param array<string,array<string,array<string,mixed>>> $input source id => bookmaker id => [enabled, url, name, order]
     */
    public function save(array $input): void;
}
