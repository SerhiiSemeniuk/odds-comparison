<?php
/**
 * Hookable module contract.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Contracts;

/**
 * A module that attaches itself to WordPress actions and filters.
 *
 * Every feature (admin page, REST controller, block, cron job) implements this
 * interface so the Plugin can boot them uniformly.
 */
interface HookableInterface
{
    /**
     * Register all WordPress hooks of this module.
     */
    public function register(): void;
}
