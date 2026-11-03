<?php
/**
 * Health status presenter.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Sources;

/**
 * Turns a HealthStatus into display data shared by the admin templates and the
 * REST API, so both show exactly the same text.
 */
final class StatusPresenter
{
    /**
     * @return array{state:string,label:string,message:string,latencyMs:int|null,checkedAt:int,checked:string}
     */
    public static function present(HealthStatus $status): array
    {
        $data            = $status->toArray();
        $data['checked'] = $status->checkedAt > 0
            /* translators: %s: human readable time difference, e.g. "5 minutes" */
            ? sprintf(__('%s ago', 'odds-comparison'), human_time_diff($status->checkedAt))
            : __('never', 'odds-comparison');

        return $data;
    }
}
