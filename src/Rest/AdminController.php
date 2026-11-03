<?php
/**
 * Admin REST controller.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Rest;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Plugin;
use OddsComparison\Sources\Exception\SourceException;
use OddsComparison\Sources\StatusPresenter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoints used by the dashboard. All require `manage_options`; the
 * cookie nonce (X-WP-Nonce, added by wp.apiFetch) protects against CSRF.
 *
 *   POST /odds-comparison/v1/sources/{id}/check   {id} is a source id or "all"
 *   POST /odds-comparison/v1/sources/{id}/bookmakers/detect
 *   POST /odds-comparison/v1/cache/flush
 */
final class AdminController implements HookableInterface
{
    public const NAMESPACE = 'odds-comparison/v1';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/sources/(?P<id>[a-z0-9_-]+)/check', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'check'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'id' => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sources/(?P<id>[a-z0-9_-]+)/bookmakers/detect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'detectBookmakers'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'id' => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/cache/flush', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'flush'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Check one source or all of them and return their statuses keyed by id.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function check(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id  = (string) $request['id'];
        $ids = $this->plugin->sources()->ids();

        if ($id !== 'all' && !in_array($id, $ids, true)) {
            return new WP_Error('odds_comparison_unknown_source', __('Unknown source.', 'odds-comparison'), ['status' => 404]);
        }

        $out = [];
        foreach ($id === 'all' ? $ids : [$id] as $sourceId) {
            $out[$sourceId] = StatusPresenter::present($this->plugin->health()->check($sourceId));
        }

        return new WP_REST_Response($out);
    }

    /**
     * Discover the bookmakers a source provides by reading real odds.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function detectBookmakers(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (string) $request['id'];

        if (!$this->plugin->sources()->has($id)) {
            return new WP_Error('odds_comparison_unknown_source', __('Unknown source.', 'odds-comparison'), ['status' => 404]);
        }

        try {
            $ids = $this->plugin->odds()->detectBookmakers($id);
        } catch (SourceException $e) {
            return new WP_Error('odds_comparison_source_failed', $e->getMessage(), ['status' => 502]);
        }

        return new WP_REST_Response(['source' => $id, 'bookmakers' => $ids]);
    }

    public function flush(): WP_REST_Response
    {
        $this->plugin->odds()->flush();

        return new WP_REST_Response(['flushed' => true]);
    }
}
