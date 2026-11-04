<?php
/**
 * Source health monitor.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

use OddsComparison\Contracts\CacheInterface;
use OddsComparison\Contracts\KeyedSourceInterface;

/**
 * Runs availability checks and remembers the latest result of every source,
 * so the dashboard can show a status without hitting the sites on each load.
 */
final class HealthMonitor
{
    private const TTL = WEEK_IN_SECONDS;

    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Last known status of a source (Unknown when it was never checked).
     */
    public function status(string $sourceId): HealthStatus
    {
        $data = $this->cache->get($this->key($sourceId));

        return is_array($data) ? HealthStatus::fromArray($data) : HealthStatus::unknown();
    }

    /**
     * Probe a source now and store the result.
     */
    public function check(string $sourceId): HealthStatus
    {
        $source = $this->registry->get($sourceId);

        $keyed  = $source instanceof KeyedSourceInterface ? $source->hasKey() : $this->hasKey($sourceId);
        $status = $source->requiresApiKey() && !$keyed
            ? new HealthStatus(HealthState::NeedsKey, __('Add an API key in the settings.', 'odds-comparison'), null, time())
            : $source->checkAvailability();

        $this->cache->set($this->key($sourceId), $status->toArray(), self::TTL);

        return $status;
    }

    /**
     * Probe every registered source.
     *
     * @return array<string,HealthStatus>
     */
    public function checkAll(): array
    {
        $out = [];
        foreach (array_keys($this->registry->all()) as $id) {
            $out[$id] = $this->check($id);
        }

        return $out;
    }

    /**
     * A source is worth trying unless a check already found it unusable.
     * Never-checked sources get the benefit of the doubt.
     */
    public function isWorthTrying(string $sourceId): bool
    {
        $state = $this->status($sourceId)->state;

        return $state === HealthState::Available || $state === HealthState::Unknown;
    }

    private function hasKey(string $sourceId): bool
    {
        /**
         * Filters whether an API key is configured for a source that needs one.
         *
         * @param bool   $hasKey   Whether a key is set.
         * @param string $sourceId Source id.
         */
        return (bool) apply_filters('odds_comparison_source_has_key', false, $sourceId);
    }

    private function key(string $sourceId): string
    {
        return 'health_' . $sourceId;
    }
}
