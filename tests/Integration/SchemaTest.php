<?php
/**
 * Database schema tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Integration;

use OddsComparison\Database\Schema;

final class SchemaTest extends IntegrationTestCase
{
    public function testTheTablesExistAfterTheBoot(): void
    {
        global $wpdb;

        $this->assertSame(Schema::bookmakers(), $wpdb->get_var("SHOW TABLES LIKE '" . Schema::bookmakers() . "'"));
        $this->assertSame(Schema::odds(), $wpdb->get_var("SHOW TABLES LIKE '" . Schema::odds() . "'"));
        $this->assertSame(Schema::VERSION, get_option('odds_comparison_db_version'));
    }

    public function testTablesUseTheSitePrefix(): void
    {
        global $wpdb;

        $this->assertStringStartsWith($wpdb->prefix, Schema::odds());
        $this->assertStringStartsWith($wpdb->prefix, Schema::bookmakers());
    }

    public function testTheOddsTableHasTheExpectedColumnsAndKeys(): void
    {
        global $wpdb;

        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . Schema::odds()); // phpcs:ignore WordPress.DB.PreparedSQL
        $this->assertSame(['id', 'source_id', 'event_id', 'market_id', 'payload', 'fetched_at', 'fresh_until', 'starts_at'], $columns);

        $keys = [];
        foreach ((array) $wpdb->get_results('SHOW INDEX FROM ' . Schema::odds(), ARRAY_A) as $row) { // phpcs:ignore WordPress.DB.PreparedSQL
            $keys[$row['Key_name']][] = $row['Column_name'];
            $keys[$row['Key_name'] . '#unique'] = (int) $row['Non_unique'] === 0;
        }

        $this->assertSame(['source_id', 'event_id', 'market_id'], $keys['entry']);
        $this->assertTrue($keys['entry#unique'], 'one snapshot per source, event and market');
        $this->assertArrayHasKey('starts_at', $keys, 'the cleanup query is indexed');
    }

    public function testTheBookmakersTableIsUniquePerSourceAndBookmaker(): void
    {
        global $wpdb;

        $unique = [];
        foreach ((array) $wpdb->get_results('SHOW INDEX FROM ' . Schema::bookmakers(), ARRAY_A) as $row) { // phpcs:ignore WordPress.DB.PreparedSQL
            if ((int) $row['Non_unique'] === 0 && $row['Key_name'] !== 'PRIMARY') {
                $unique[$row['Key_name']][] = $row['Column_name'];
            }
        }

        $this->assertSame(['source_bookmaker' => ['source_id', 'bookmaker_id']], $unique);
    }

    public function testUpgradeIsANoOpWhenTheVersionIsCurrent(): void
    {
        global $wpdb;

        $queries = $wpdb->num_queries;
        Schema::maybeUpgrade();

        $this->assertLessThanOrEqual(1, $wpdb->num_queries - $queries, 'at most the version option is read');
    }
}
