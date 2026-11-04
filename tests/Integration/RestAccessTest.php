<?php
/**
 * Who may call which REST route.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

final class RestAccessTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const EDITOR_ROUTES = ['/sources', '/sports', '/events', '/bookmakers'];

    public function testEveryRouteIsRegistered(): void
    {
        $routes = array_keys(rest_get_server()->get_routes('odds-comparison/v1'));

        foreach (['/sources', '/sports', '/events', '/markets', '/bookmakers', '/odds', '/table', '/cache/flush'] as $route) {
            $this->assertContains('/odds-comparison/v1' . $route, $routes);
        }
        $this->assertContains('/odds-comparison/v1/sources/(?P<id>[a-z0-9_-]+)/check', $routes);
        $this->assertContains('/odds-comparison/v1/sources/(?P<id>[a-z0-9_-]+)/bookmakers/detect', $routes);
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'list sources'       => ['GET', '/sources'],
            'list sports'        => ['GET', '/sports'],
            'list events'        => ['GET', '/events'],
            'read bookmakers'    => ['GET', '/bookmakers'],
            'check a source'     => ['POST', '/sources/fake/check'],
            'detect bookmakers'  => ['POST', '/sources/fake/bookmakers/detect'],
            'flush the cache'    => ['POST', '/cache/flush'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function testAnonymousVisitorsAreTurnedAway(string $method, string $route): void
    {
        $this->assertSame(401, $this->rest($method, $route, ['source' => 'fake', 'sport' => 'nba'])->get_status());
    }

    #[DataProvider('protectedRoutes')]
    public function testSubscribersAreForbidden(string $method, string $route): void
    {
        $this->login('subscriber');

        $this->assertSame(403, $this->rest($method, $route, ['source' => 'fake', 'sport' => 'nba'])->get_status());
    }

    public function testContributorsMayReadEditorRoutesButNotAdminOnes(): void
    {
        $this->login('contributor');

        $this->assertSame(200, $this->rest('GET', '/sources')->get_status());
        $this->assertSame(403, $this->rest('POST', '/cache/flush')->get_status());
        $this->assertSame(403, $this->rest('POST', '/sources/fake/check')->get_status());
        $this->assertSame(403, $this->rest('POST', '/sources/fake/bookmakers/detect')->get_status());
    }

    public function testEditorsMayNotUseAdminRoutes(): void
    {
        $this->login('editor');

        $this->assertSame(200, $this->rest('GET', '/sports', ['source' => 'fake'])->get_status());
        $this->assertSame(403, $this->rest('POST', '/cache/flush')->get_status());
    }

    public function testAdministratorsMayUseEverything(): void
    {
        $this->login('administrator');

        $this->assertSame(200, $this->rest('GET', '/sources')->get_status());
        $this->assertSame(200, $this->rest('POST', '/cache/flush')->get_status());
        $this->assertSame(200, $this->rest('POST', '/sources/fake/check')->get_status());
    }

    public function testThePublicTableRouteNeedsItsParameters(): void
    {
        $this->assertSame(400, $this->rest('GET', '/table')->get_status(), 'token and signature are required');
    }

    public function testThePublicTableRouteRefusesAnUnsignedRequest(): void
    {
        $token = rtrim(strtr(base64_encode((string) wp_json_encode(['source' => 'fake'])), '+/', '-_'), '=');

        $this->assertSame(403, $this->rest('GET', '/table', ['token' => $token, 'sig' => 'deadbeef'])->get_status());
    }

    public function testEditorRoutesAreNotPubliclyReadable(): void
    {
        foreach (self::EDITOR_ROUTES as $route) {
            $this->assertContains($this->rest('GET', $route, ['source' => 'fake', 'sport' => 'nba'])->get_status(), [401, 403], $route);
        }
        $this->assertContains($this->rest('GET', '/odds', $this->eventParams() + ['market' => 'moneyline'])->get_status(), [401, 403]);
    }
}
