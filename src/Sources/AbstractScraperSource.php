<?php
/**
 * Base class for scraped sources.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

use DOMXPath;
use DOMDocument;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Contracts\SourceInterface;
use OddsComparison\Http\HttpResponse;
use OddsComparison\Sources\Exception\BlockedException;
use OddsComparison\Sources\Exception\ParseException;
use OddsComparison\Sources\Exception\SourceException;
use OddsComparison\Sources\Exception\UnreachableException;

/**
 * Template for sources that read data from an HTML website.
 *
 * Subclasses only describe *where* the data is (baseUrl, XPath queries in
 * sports()/events()/markets()/odds()); this class provides the shared plumbing:
 * fetching, bot-challenge detection, HTML to XPath, number parsing and the
 * availability probe used by the admin dashboard.
 */
abstract class AbstractScraperSource implements SourceInterface
{
    /**
     * Body fragments that identify an anti-bot interstitial served with HTTP 200.
     */
    private const CHALLENGE_MARKERS = [
        'challenges.cloudflare.com',
        'cf-challenge',
        'Just a moment...',
        'Attention Required',
    ];

    public function __construct(protected readonly HttpClientInterface $http)
    {
    }

    /**
     * Site root without a trailing slash, e.g. "https://www.example.com".
     */
    abstract protected function baseUrl(): string;

    public function requiresApiKey(): bool
    {
        return false;
    }

    /**
     * Probe the site by loading the sports list through the real parser, so a
     * block, an outage and a markup change are told apart.
     */
    public function checkAvailability(): HealthStatus
    {
        $start = microtime(true);

        try {
            $sports = $this->sports();
        } catch (BlockedException $e) {
            return $this->status(HealthState::Blocked, $e->getMessage(), $start);
        } catch (UnreachableException $e) {
            return $this->status(HealthState::Unreachable, $e->getMessage(), $start);
        } catch (ParseException $e) {
            return $this->status(HealthState::ParserMismatch, $e->getMessage(), $start);
        } catch (SourceException $e) {
            return $this->status(HealthState::Unreachable, $e->getMessage(), $start);
        }

        if ($sports === []) {
            return $this->status(
                HealthState::ParserMismatch,
                __('The page loaded but no sports were found.', 'odds-comparison'),
                $start
            );
        }

        return $this->status(
            HealthState::Available,
            sprintf(
                /* translators: %d: number of sports found */
                _n('%d sport found.', '%d sports found.', count($sports), 'odds-comparison'),
                count($sports)
            ),
            $start
        );
    }

    /**
     * GET a page and fail early on blocks and HTTP errors.
     *
     * @throws BlockedException
     * @throws UnreachableException
     */
    protected function fetch(string $pathOrUrl): HttpResponse
    {
        $url      = $this->absoluteUrl($pathOrUrl);
        $response = $this->http->get($url);

        if ($this->isBlocked($response)) {
            throw new BlockedException(sprintf(
                /* translators: 1: HTTP status, 2: URL */
                __('Request was blocked (HTTP %1$d) at %2$s.', 'odds-comparison'),
                $response->status,
                $url
            ));
        }

        if (!$response->isSuccess()) {
            throw new UnreachableException(sprintf(
                /* translators: 1: HTTP status, 2: URL */
                __('Unexpected HTTP %1$d from %2$s.', 'odds-comparison'),
                $response->status,
                $url
            ));
        }

        return $response;
    }

    /**
     * Fetch a page and return an XPath handle over its DOM.
     *
     * @throws SourceException
     */
    protected function xpath(string $pathOrUrl): DOMXPath
    {
        return $this->parseHtml($this->fetch($pathOrUrl)->body);
    }

    /**
     * Build an XPath handle from raw HTML. Public-in-effect for tests that
     * feed saved fixtures straight to a parser.
     *
     * @throws ParseException When the HTML is empty or unparsable.
     */
    protected function parseHtml(string $html): DOMXPath
    {
        if (trim($html) === '') {
            throw new ParseException(__('Empty response body.', 'odds-comparison'));
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        // The XML prolog forces UTF-8 decoding; DOMDocument assumes ISO-8859-1 otherwise.
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new ParseException(__('The response could not be parsed as HTML.', 'odds-comparison'));
        }

        return new DOMXPath($document);
    }

    /**
     * Detect a refusal: HTTP 403/429, a Cloudflare mitigation header or a
     * challenge page served with a success status.
     */
    protected function isBlocked(HttpResponse $response): bool
    {
        if (in_array($response->status, [401, 403, 429], true)) {
            return true;
        }

        if ($response->header('cf-mitigated') !== '') {
            return true;
        }

        // Only scan the head of the body: challenge pages are small.
        $head = substr($response->body, 0, 6000);
        foreach (self::CHALLENGE_MARKERS as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a site-relative path against baseUrl().
     */
    protected function absoluteUrl(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }

        return $this->baseUrl() . '/' . ltrim($pathOrUrl, '/');
    }

    /**
     * Parse a decimal price from scraped text. Returns null for anything that
     * is not a valid decimal price (must be greater than 1.0).
     */
    protected function parseDecimal(string $text): ?float
    {
        $text = str_replace(',', '.', trim($text));

        if (!is_numeric($text)) {
            return null;
        }

        $value = (float) $text;

        return $value > 1.0 ? $value : null;
    }

    private function status(HealthState $state, string $message, float $start): HealthStatus
    {
        return new HealthStatus(
            $state,
            $message,
            (int) round((microtime(true) - $start) * 1000),
            time()
        );
    }
}
