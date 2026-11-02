<?php
/**
 * Nuxt payload decoder.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Support;

use OddsComparison\Sources\Exception\ParseException;

/**
 * Decodes the `<script id="__NUXT_DATA__">` payload of a server-rendered Nuxt 3
 * page into plain PHP arrays.
 *
 * Nuxt serialises state with "devalue": one flat JSON array where every
 * integer inside an object or list is an index into that array (so shared
 * values are stored once). Reactive wrappers appear as ["Reactive", index].
 */
final class NuxtPayload
{
    private const WRAPPERS = ['ShallowReactive', 'Reactive', 'Ref', 'ShallowRef', 'EmptyRef', 'EmptyShallowRef', 'Readonly', 'ShallowReadonly'];

    /** @var array<int,mixed> */
    private array $flat;

    /** @var array<int,mixed> */
    private array $memo = [];

    /**
     * @param array<int,mixed> $flat
     */
    private function __construct(array $flat)
    {
        $this->flat = $flat;
    }

    /**
     * Extract and decode the payload from a page.
     *
     * @return mixed The decoded root value.
     * @throws ParseException When the page carries no readable payload.
     */
    public static function fromHtml(string $html): mixed
    {
        if (preg_match('#<script[^>]+id="__NUXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m) !== 1) {
            throw new ParseException(__('The page has no embedded data payload.', 'odds-comparison'));
        }

        $flat = json_decode($m[1], true);
        if (!is_array($flat) || $flat === []) {
            throw new ParseException(__('The embedded data payload is not valid JSON.', 'odds-comparison'));
        }

        return (new self($flat))->resolve(0);
    }

    /**
     * Depth-first search for every array accepted by $predicate.
     *
     * @param callable(array<mixed>):bool $predicate
     * @return list<array<mixed>>
     */
    public static function findAll(mixed $node, callable $predicate): array
    {
        $found = [];
        self::collect($node, $predicate, $found);

        return $found;
    }

    /**
     * @param callable(array<mixed>):bool $predicate
     * @param list<array<mixed>>          $found
     */
    private static function collect(mixed $node, callable $predicate, array &$found): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($predicate($node)) {
            $found[] = $node;
        }

        foreach ($node as $child) {
            self::collect($child, $predicate, $found);
        }
    }

    private function resolve(int $index): mixed
    {
        // Negative indexes encode undefined / NaN / Infinity.
        if ($index < 0 || !array_key_exists($index, $this->flat)) {
            return null;
        }

        if (array_key_exists($index, $this->memo)) {
            return $this->memo[$index];
        }

        $value = $this->flat[$index];
        if (!is_array($value)) {
            return $this->memo[$index] = $value;
        }

        if (array_is_list($value)) {
            if (isset($value[0], $value[1]) && is_string($value[0]) && in_array($value[0], self::WRAPPERS, true) && is_int($value[1])) {
                return $this->memo[$index] = $this->resolve($value[1]);
            }

            $this->memo[$index] = [];
            $out                = [];
            foreach ($value as $item) {
                $out[] = is_int($item) ? $this->resolve($item) : $item;
            }

            return $this->memo[$index] = $out;
        }

        $this->memo[$index] = [];
        $out                = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_int($item) ? $this->resolve($item) : $item;
        }

        return $this->memo[$index] = $out;
    }
}
