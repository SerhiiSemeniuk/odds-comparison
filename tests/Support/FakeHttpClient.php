<?php
/**
 * Fake HTTP client.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Support;

use Closure;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Http\HttpResponse;
use OddsComparison\Sources\Exception\UnreachableException;
use Throwable;

/**
 * Answers requests from a route table, so parsers can be tested on saved pages
 * without any network access. A route matches when its needle appears in the URL.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{0:string,1:HttpResponse|Closure|Throwable}> */
    private array $routes = [];

    /** @var list<string> URLs requested, in order. */
    public array $requests = [];

    public function on(string $needle, HttpResponse|Closure|Throwable $answer): self
    {
        $this->routes[] = [$needle, $answer];

        return $this;
    }

    /**
     * Shortcut: answer 200 with a body.
     */
    public function page(string $needle, string $body, int $status = 200, array $headers = []): self
    {
        return $this->on($needle, new HttpResponse($status, $body, $headers));
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = $url;

        foreach ($this->routes as [$needle, $answer]) {
            if (!str_contains($url, $needle)) {
                continue;
            }
            if ($answer instanceof Throwable) {
                throw $answer;
            }

            return $answer instanceof Closure ? $answer($url) : $answer;
        }

        throw new UnreachableException('No fake route for ' . $url);
    }
}
