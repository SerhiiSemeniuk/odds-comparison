<?php
/**
 * Config signer tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Block;

use OddsComparison\Block\ConfigSigner;
use PHPUnit\Framework\TestCase;

final class ConfigSignerTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config = [
        'source'     => 'bestodds',
        'sport'      => 'nba',
        'event'      => 'e1',
        'path'       => '/odds/nba/event/e1',
        'market'     => 'moneyline',
        'bookmakers' => ['fanduel'],
    ];

    public function testAValidTokenVerifiesAndRoundTrips(): void
    {
        $signer = new ConfigSigner();
        ['token' => $token, 'sig' => $sig] = $signer->sign($this->config);

        $this->assertSame($this->config, $signer->verify($token, $sig));
    }

    public function testAWrongSignatureIsRejected(): void
    {
        $signer = new ConfigSigner();
        ['token' => $token, 'sig' => $sig] = $signer->sign($this->config);

        $this->assertNull($signer->verify($token, strrev($sig)));
        $this->assertNull($signer->verify($token, ''));
    }

    public function testATamperedPayloadIsRejected(): void
    {
        $signer = new ConfigSigner();
        ['sig' => $sig] = $signer->sign($this->config);

        $evil = rtrim(strtr(base64_encode((string) json_encode(['path' => '//evil.example/x'] + $this->config)), '+/', '-_'), '=');

        $this->assertNull($signer->verify($evil, $sig));
    }

    public function testGarbageIsRejected(): void
    {
        $this->assertNull((new ConfigSigner())->verify('!!!', 'x'));
    }

    public function testTheTokenIsUrlSafe(): void
    {
        ['token' => $token] = (new ConfigSigner())->sign($this->config + ['name' => 'Ünïcode ??>> /+']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }
}
