<?php
/**
 * Base class of the integration tests.
 *
 * Runs against a real WordPress and database. Every test happens inside a
 * transaction that is rolled back afterwards, so tests cannot affect each other.
 * (The WordPress core test case is not used: it needs PHPUnit 9, while this
 * project's unit tests use PHPUnit 10/11 attributes.)
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Integration;

use OddsComparison\Plugin;
use OddsComparison\Tests\Support\FakeSource;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_REST_Request;
use WP_REST_Response;

abstract class IntegrationTestCase extends TestCase
{
    protected FakeSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        wp_cache_flush();
        wp_set_current_user(0);
        $GLOBALS['wp_rest_server'] = null;

        $this->resetPluginServices();

        // A controllable source in place of the scraped ones, so no test touches the network.
        $this->source = new FakeSource('fake');
        $source       = $this->source;
        Plugin::instance()->sources()->register('fake', static fn () => $source);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        wp_cache_flush();
        wp_set_current_user(0);
        $GLOBALS['wp_rest_server'] = null;

        parent::tearDown();
    }

    /**
     * The Plugin singleton lives for the whole test run, and some of its services keep
     * per-request memory (for example the bookmaker rows they have read). A rolled-back
     * transaction empties the database but not that memory, so drop those services before
     * every test; they are rebuilt on first use, as at the start of a real request.
     */
    private function resetPluginServices(): void
    {
        $plugin = Plugin::instance();

        foreach (['bookmakers', 'service', 'presenter'] as $property) {
            $reflection = new ReflectionProperty($plugin, $property);
            $reflection->setValue($plugin, null);
        }
    }

    /**
     * Create a user with a role and make them the current user.
     */
    protected function login(string $role): int
    {
        $id = wp_insert_user([
            'user_login' => $role . '_' . wp_generate_password(8, false),
            'user_pass'  => wp_generate_password(),
            'role'       => $role,
        ]);
        $this->assertIsInt($id);
        wp_set_current_user($id);

        return $id;
    }

    /**
     * Call a route of the plugin's REST API in-process.
     *
     * @param array<string,mixed> $params
     */
    protected function rest(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/odds-comparison/v1' . $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * Parameters that identify the fake source's event.
     *
     * @return array<string,mixed>
     */
    protected function eventParams(?int $starts = null): array
    {
        return [
            'source' => 'fake',
            'sport'  => 'nba',
            'event'  => 'e1',
            'name'   => 'Home v Away',
            'path'   => '/s/e1',
            'starts' => $starts ?? 0,
        ];
    }
}
