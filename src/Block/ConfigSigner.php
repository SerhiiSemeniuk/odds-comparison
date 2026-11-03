<?php
/**
 * Block config signer.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Block;

/**
 * Signs the configuration of a rendered odds table.
 *
 * The front end asks the public REST endpoint for a table by sending the
 * configuration the server rendered, together with its HMAC. The endpoint only
 * accepts configurations it signed itself, so visitors cannot make the site
 * scrape arbitrary events or sources.
 */
final class ConfigSigner
{
    /**
     * @param array<string,mixed> $config
     * @return array{token:string,sig:string}
     */
    public function sign(array $config): array
    {
        $token = rtrim(strtr(base64_encode((string) wp_json_encode($config)), '+/', '-_'), '=');

        return ['token' => $token, 'sig' => $this->signature($token)];
    }

    /**
     * @return array<string,mixed>|null The config, or null for a bad signature or payload.
     */
    public function verify(string $token, string $sig): ?array
    {
        if (!hash_equals($this->signature($token), $sig)) {
            return null;
        }

        $json   = base64_decode(strtr($token, '-_', '+/'), true);
        $config = $json !== false ? json_decode($json, true) : null;

        return is_array($config) ? $config : null;
    }

    private function signature(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }
}
