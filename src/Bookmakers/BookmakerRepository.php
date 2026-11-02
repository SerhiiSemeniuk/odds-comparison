<?php
/**
 * Bookmaker repository.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Bookmakers;

use OddsComparison\Database\Schema;

/**
 * Stores bookmaker settings (visibility, order, links), separately per source,
 * in the oddscmp_bookmakers table.
 *
 * A source only lists the bookmakers it really provides prices for, so the
 * list of a source is filled from that source's data: whenever odds are
 * fetched, their bookmaker ids are recorded here (see discover()). Nothing is
 * pre-seeded.
 *
 * KNOWN is only a lookup of display names and default sites for ids that have
 * been discovered; extend it with the `odds_comparison_known_bookmakers`
 * filter. Unknown ids get a name derived from the id. A row's own name and url
 * columns hold only what the admin customised; empty means "use the default".
 */
final class BookmakerRepository
{
    /**
     * Official names and sites of bookmakers seen in source data.
     * Replace the URLs with affiliate links in the dashboard.
     *
     * @var array<string,array{0:string,1:string}> id => [name, url]
     */
    private const KNOWN = [
        'average'            => ['Average', ''],
        'bet365'             => ['bet365', 'https://www.bet365.com'],
        'betmgm'             => ['BetMGM', 'https://sports.betmgm.com'],
        'betrivers'          => ['BetRivers', 'https://www.betrivers.com'],
        'caesars'            => ['Caesars', 'https://www.caesars.com/sportsbook-and-casino'],
        'draftkings'         => ['DraftKings', 'https://sportsbook.draftkings.com'],
        'espn_bet'           => ['ESPN BET', 'https://espnbet.com'],
        'fanatics'           => ['Fanatics', 'https://sportsbook.fanatics.com'],
        'fanduel'            => ['FanDuel', 'https://sportsbook.fanduel.com'],
        'sportingbet'        => ['Sportingbet', 'https://sports.sportingbet.com'],
        'sports-interaction' => ['Sports Interaction', 'https://www.sportsinteraction.com'],
        'sugarhouse'         => ['SugarHouse', 'https://www.betrivers.com'],
        'unibet'             => ['Unibet', 'https://www.unibet.com'],
    ];

    /** @var array<string,array<string,array<string,mixed>>> Rows loaded this request: source id => bookmaker id => row. */
    private array $loaded = [];

    /**
     * Bookmakers of one source, ordered by position then name.
     *
     * @return list<Bookmaker>
     */
    public function all(string $sourceId): array
    {
        $out = [];

        foreach ($this->rows($sourceId) as $id => $row) {
            $out[] = $this->hydrate((string) $id, $row);
        }

        usort($out, static fn (Bookmaker $a, Bookmaker $b): int => [$a->order, $a->name] <=> [$b->order, $b->name]);

        return $out;
    }

    /**
     * @return list<Bookmaker>
     */
    public function enabled(string $sourceId): array
    {
        return array_values(array_filter($this->all($sourceId), static fn (Bookmaker $b): bool => $b->enabled));
    }

    public function get(string $sourceId, string $id): Bookmaker
    {
        return $this->hydrate($id, $this->rows($sourceId)[$id] ?? []);
    }

    /**
     * Record bookmaker ids a source has reported prices for.
     *
     * @param list<string> $ids
     */
    public function discover(string $sourceId, array $ids): void
    {
        global $wpdb;

        $known = $this->rows($sourceId);
        $table = Schema::bookmakers();
        $added = false;

        foreach ($ids as $id) {
            if (isset($known[$id]) || $id === '' || strlen($id) > 64) {
                continue;
            }

            // INSERT IGNORE: a concurrent request may have added the same row.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (source_id, bookmaker_id, name, url, enabled, sort_order) VALUES (%s, %s, '', '', 1, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sourceId,
                    $id,
                    count($known) * 10
                )
            );
            $added      = true;
            $known[$id] = [];
        }

        if ($added) {
            unset($this->loaded[$sourceId]);
        }
    }

    /**
     * Update bookmakers from the dashboard form.
     *
     * @param array<string,array<string,array<string,mixed>>> $input source id => bookmaker id => [enabled, url, name, order]
     */
    public function save(array $input): void
    {
        global $wpdb;

        foreach ($input as $sourceId => $bookmakers) {
            $sourceId = (string) $sourceId;
            $existing = $this->rows($sourceId);

            foreach ((array) $bookmakers as $id => $submitted) {
                $id = (string) $id;
                if (!isset($existing[$id]) || !is_array($submitted)) {
                    continue;
                }

                $wpdb->update(
                    Schema::bookmakers(),
                    [
                        'enabled'    => !empty($submitted['enabled']) ? 1 : 0,
                        'url'        => LinkTemplate::sanitize((string) ($submitted['url'] ?? '')),
                        'name'       => sanitize_text_field((string) ($submitted['name'] ?? '')),
                        'sort_order' => (int) ($submitted['order'] ?? 0),
                    ],
                    ['source_id' => $sourceId, 'bookmaker_id' => $id],
                    ['%d', '%s', '%s', '%d'],
                    ['%s', '%s']
                );
            }

            unset($this->loaded[$sourceId]);
        }
    }

    /**
     * Rows of one source, read once per request.
     *
     * @return array<string,array<string,mixed>> bookmaker id => row
     */
    private function rows(string $sourceId): array
    {
        global $wpdb;

        if (!isset($this->loaded[$sourceId])) {
            $table = Schema::bookmakers();
            $found = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT bookmaker_id, name, url, enabled, sort_order FROM {$table} WHERE source_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $sourceId
                ),
                ARRAY_A
            );

            $this->loaded[$sourceId] = [];
            foreach ((array) $found as $row) {
                $this->loaded[$sourceId][(string) $row['bookmaker_id']] = $row;
            }
        }

        return $this->loaded[$sourceId];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(string $id, array $row): Bookmaker
    {
        [$name, $url] = $this->known()[$id] ?? [ucwords(str_replace(['-', '_'], ' ', $id)), ''];

        $customName = (string) ($row['name'] ?? '');
        $customUrl  = (string) ($row['url'] ?? '');

        return new Bookmaker(
            $id,
            $customName !== '' ? $customName : $name,
            $customUrl !== '' ? $customUrl : $url,
            (bool) ($row['enabled'] ?? true),
            (int) ($row['sort_order'] ?? 0),
        );
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    private function known(): array
    {
        /**
         * Filters the display names and default sites of known bookmaker ids.
         *
         * @param array<string,array{0:string,1:string}> $known id => [name, url]
         */
        return apply_filters('odds_comparison_known_bookmakers', self::KNOWN);
    }
}
