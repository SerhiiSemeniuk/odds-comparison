<?php
/**
 * Nuxt payload builder for tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Support;

/**
 * Turns a PHP structure into the page markup Nuxt 3 produces: a
 * `__NUXT_DATA__` script holding one flat "devalue" array in which every value
 * lives in its own slot and objects refer to their children by index.
 * It is the inverse of NuxtPayload::fromHtml().
 */
final class NuxtFixture
{
    /**
     * @param mixed $root Arrays (lists or maps) and scalars.
     */
    public static function page(mixed $root, string $extraHtml = ''): string
    {
        $flat = [];
        self::add($root, $flat);

        return '<!DOCTYPE html><html><head><title>Fixture</title></head><body>' . $extraHtml
            . '<script type="application/json" data-nuxt-data="nuxt-app" data-ssr="true" id="__NUXT_DATA__">'
            . json_encode($flat, JSON_UNESCAPED_SLASHES)
            . '</script></body></html>';
    }

    /**
     * @param list<mixed> $flat
     */
    private static function add(mixed $value, array &$flat): int
    {
        $index  = count($flat);
        $flat[] = null;

        if (!is_array($value)) {
            $flat[$index] = $value;

            return $index;
        }

        $out = [];
        foreach ($value as $key => $child) {
            $out[$key] = self::add($child, $flat);
        }
        $flat[$index] = $out;

        return $index;
    }
}
