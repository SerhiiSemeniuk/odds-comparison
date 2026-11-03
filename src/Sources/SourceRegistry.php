<?php
/**
 * Source registry.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

use InvalidArgumentException;
use OddsComparison\Contracts\SourceInterface;

/**
 * Factory and registry of odds sources.
 *
 * Sources are registered as lazy factories (id => callable returning a
 * SourceInterface), so a source is only instantiated when it is used.
 * Third parties add sources through the `odds_comparison_sources` filter.
 */
final class SourceRegistry
{
    /** @var array<string,callable():SourceInterface> */
    private array $factories = [];

    /** @var array<string,SourceInterface> */
    private array $instances = [];

    /**
     * @param array<string,callable():SourceInterface> $factories Built-in sources.
     */
    public function __construct(array $factories = [])
    {
        if (function_exists('apply_filters')) {
            /**
             * Filters the source factories.
             *
             * @param array<string,callable():SourceInterface> $factories id => factory.
             */
            $factories = apply_filters('odds_comparison_sources', $factories);
        }

        foreach ($factories as $id => $factory) {
            $this->register((string) $id, $factory);
        }
    }

    /**
     * @param callable():SourceInterface $factory
     */
    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /**
     * @throws InvalidArgumentException For an unknown id.
     */
    public function get(string $id): SourceInterface
    {
        if (!isset($this->factories[$id])) {
            throw new InvalidArgumentException(sprintf('Unknown odds source "%s".', $id));
        }

        return $this->instances[$id] ??= ($this->factories[$id])();
    }

    /**
     * Ids of all registered sources, without instantiating them.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Every registered source, keyed by id.
     *
     * @return array<string,SourceInterface>
     */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->factories) as $id) {
            $out[$id] = $this->get($id);
        }

        return $out;
    }
}
