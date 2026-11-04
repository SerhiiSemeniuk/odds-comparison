<?php
/**
 * Keyed source contract.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

/**
 * A source that needs credentials (an API key) before it can be used.
 */
interface KeyedSourceInterface extends SourceInterface
{
    /**
     * Whether a key is configured. It says nothing about the key being valid:
     * checkAvailability() finds that out.
     */
    public function hasKey(): bool;
}
