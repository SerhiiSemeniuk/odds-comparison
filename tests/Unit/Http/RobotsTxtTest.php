<?php
/**
 * robots.txt parser tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Http;

use OddsComparison\Http\RobotsTxt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    private const FILE = <<<TXT
        User-agent: *
        Disallow: /clickout*
        Disallow: /search*
        Disallow: /*?year=
        Disallow: */view-all-markets/*
        Allow: /search/public
        Disallow: /private\$

        User-agent: OddsComparisonBot
        Disallow: /bot-only
        TXT;

    /**
     * @return array<string,array{string,string,bool}>
     */
    public static function cases(): array
    {
        return [
            'wildcard group blocks a prefix'        => ['SomeOtherBot', '/clickout/bet365', false],
            'an unlisted path is allowed'           => ['SomeOtherBot', '/football/england', true],
            '* in the middle of a rule'             => ['SomeOtherBot', '/football?year=2024', false],
            'leading wildcard'                      => ['SomeOtherBot', '/a/view-all-markets/x', false],
            'a longer Allow beats a Disallow'       => ['SomeOtherBot', '/search/public', true],
            'a $ anchor does not block longer paths' => ['SomeOtherBot', '/private/page', true],
            'a $ anchor blocks the exact path'      => ['SomeOtherBot', '/private', false],
            'a named group replaces the wildcard one' => ['OddsComparisonBot', '/clickout/bet365', true],
            'rules of the named group apply'        => ['OddsComparisonBot', '/bot-only', false],
            'agent matching is case-insensitive'    => ['oddscomparisonbot/1.0', '/bot-only', false],
        ];
    }

    #[DataProvider('cases')]
    public function testEvaluatesRules(string $agent, string $path, bool $allowed): void
    {
        $this->assertSame($allowed, (new RobotsTxt(self::FILE, $agent))->isAllowed($path));
    }

    public function testAnEmptyFileAllowsEverything(): void
    {
        $this->assertTrue((new RobotsTxt('', 'X'))->isAllowed('/anything'));
    }

    public function testAnEmptyDisallowAllowsEverything(): void
    {
        $this->assertTrue((new RobotsTxt("User-agent: *\nDisallow:\n", 'X'))->isAllowed('/anything'));
    }

    public function testCommentsAreIgnored(): void
    {
        $robots = new RobotsTxt("# header\nUser-agent: * # everyone\nDisallow: /x # no\n", 'X');

        $this->assertFalse($robots->isAllowed('/x'));
        $this->assertTrue($robots->isAllowed('/y'));
    }
}
