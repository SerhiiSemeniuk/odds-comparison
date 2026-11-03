<?php
/**
 * robots.txt parser.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Http;

/**
 * Minimal robots.txt evaluator (RFC 9309): picks the most specific user-agent
 * group, supports `*` and `$` wildcards and resolves conflicts by longest
 * match, with Allow winning ties.
 */
final class RobotsTxt
{
    /** @var list<array{allow:bool,regex:string,length:int}> */
    private array $rules = [];

    /**
     * @param string $body  Raw robots.txt content.
     * @param string $agent Product token of our crawler, e.g. "OddsComparisonBot".
     */
    public function __construct(string $body, string $agent)
    {
        $this->rules = $this->rulesFor($body, strtolower($agent));
    }

    /**
     * Whether a URL path (with optional query) may be fetched.
     */
    public function isAllowed(string $path): bool
    {
        $path = $path === '' ? '/' : $path;
        $best = null;

        foreach ($this->rules as $rule) {
            if (preg_match($rule['regex'], $path) !== 1) {
                continue;
            }
            if ($best === null
                || $rule['length'] > $best['length']
                || ($rule['length'] === $best['length'] && $rule['allow'])
            ) {
                $best = $rule;
            }
        }

        return $best === null || $best['allow'];
    }

    /**
     * @return list<array{allow:bool,regex:string,length:int}>
     */
    private function rulesFor(string $body, string $agent): array
    {
        $groups  = [];
        $current = null;
        $inRules = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field           = strtolower($field);

            if ($field === 'user-agent') {
                if ($inRules || $current === null) {
                    $current = count($groups);
                    $groups[$current] = ['agents' => [], 'rules' => []];
                    $inRules = false;
                }
                $groups[$current]['agents'][] = strtolower($value);
            } elseif (($field === 'allow' || $field === 'disallow') && $current !== null) {
                $inRules = true;
                if ($value !== '') {
                    $groups[$current]['rules'][] = [
                        'allow'  => $field === 'allow',
                        'regex'  => $this->toRegex($value),
                        'length' => strlen($value),
                    ];
                }
            }
        }

        $specific = null;
        $wildcard = null;
        foreach ($groups as $group) {
            foreach ($group['agents'] as $name) {
                if ($name === '*') {
                    $wildcard ??= $group['rules'];
                } elseif ($name !== '' && str_contains($agent, $name)) {
                    $specific = array_merge($specific ?? [], $group['rules']);
                }
            }
        }

        return $specific ?? $wildcard ?? [];
    }

    private function toRegex(string $pattern): string
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern  = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex    = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return '#^' . $regex . ($anchored ? '$' : '') . '#';
    }
}
