<?php
/**
 * Link template tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Bookmakers;

use OddsComparison\Bookmakers\LinkTemplate;
use OddsComparison\Sources\Model\Event;
use PHPUnit\Framework\TestCase;

final class LinkTemplateTest extends TestCase
{
    private function event(string $name = 'Charlotte Hornets v Detroit Pistons'): Event
    {
        return new Event('hornets-vs-pistons-02-11-2026', 'nba', $name, '/odds/nba/event/x');
    }

    public function testSanitisingKeepsThePlaceholders(): void
    {
        // The stand-in for esc_url_raw() strips curly braces like the real one does.
        $this->assertStringNotContainsString('{', esc_url_raw('https://a.com/{sport}'), 'control: braces are stripped');

        $saved = LinkTemplate::sanitize('https://sportsbook.example.com/{sport}/{event}?q={name}&m={market}');

        $this->assertStringContainsString('{sport}', $saved);
        $this->assertStringContainsString('{event}', $saved);
        $this->assertStringContainsString('{name}', $saved);
        $this->assertStringContainsString('{market}', $saved);
    }

    public function testSanitisingRejectsDangerousSchemes(): void
    {
        $this->assertSame('', LinkTemplate::sanitize('javascript:alert(1)'));
    }

    public function testExpandFillsAndEncodesTheValues(): void
    {
        $url = LinkTemplate::expand('https://x.example/{sport}?q={name}&m={market}&e={event}', $this->event(), 'moneyline');

        $this->assertSame(
            'https://x.example/nba?q=Charlotte%20Hornets%20v%20Detroit%20Pistons&m=moneyline&e=hornets-vs-pistons-02-11-2026',
            $url
        );
    }

    public function testValuesCannotBreakOutOfTheUrl(): void
    {
        $url = LinkTemplate::expand('https://x.example/?q={name}', $this->event('a&b=c#d'), 'm');

        $this->assertSame('https://x.example/?q=a%26b%3Dc%23d', $url);
    }

    public function testPlainUrlsAreUntouched(): void
    {
        $this->assertSame('https://plain.example.com/a', LinkTemplate::expand('https://plain.example.com/a', $this->event(), 'm'));
    }

    public function testAnEmptyLinkStaysEmpty(): void
    {
        $this->assertSame('', LinkTemplate::expand('', $this->event(), 'm'));
    }
}
