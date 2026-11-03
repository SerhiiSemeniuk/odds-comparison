<?php
/**
 * Public table REST controller.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Rest;

use InvalidArgumentException;
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Plugin;
use OddsComparison\Sources\Exception\SourceException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public endpoint the front-end block script calls.
 *
 *   GET /odds-comparison/v1/table?token=&sig=
 *
 * `token` is the configuration the server rendered into the page and `sig` its
 * HMAC (see ConfigSigner); unsigned or altered requests are refused, so the
 * endpoint can only ever scrape events that editors put on the site.
 *
 * Answers 200 with the table, 202 {status: pending} while another request is
 * fetching (the client asks again), or an error.
 */
final class TableController implements HookableInterface
{
    public const NAMESPACE = AdminController::NAMESPACE;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/table', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'table'],
            'permission_callback' => '__return_true', // Protected by the signature below.
            'args'                => [
                'token' => ['type' => 'string', 'required' => true],
                'sig'   => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function table(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $config = $this->plugin->signer()->verify((string) $request['token'], (string) $request['sig']);

        if ($config === null) {
            return new WP_Error('odds_comparison_forbidden', __('Invalid request.', 'odds-comparison'), ['status' => 403]);
        }

        try {
            $service = $this->plugin->odds();
            $source  = (string) ($config['source'] ?? '');

            if (!$this->plugin->sources()->has($source)) {
                throw new InvalidArgumentException('Unknown source.');
            }

            $event    = $service->makeEvent(
                (string) ($config['sport'] ?? ''),
                (string) ($config['event'] ?? ''),
                (string) ($config['name'] ?? ''),
                (string) ($config['path'] ?? ''),
                (int) ($config['starts'] ?? 0)
            );
            $marketId = sanitize_key((string) ($config['market'] ?? ''));
            $result   = $service->odds($event, $marketId, $source);

            if ($result->snapshot === null) {
                return new WP_REST_Response(['status' => 'pending', 'retryAfter' => $result->retryAfter], 202);
            }

            $selected = array_values(array_filter(array_map('sanitize_key', array_map('strval', (array) ($config['bookmakers'] ?? [])))));
            $data     = $this->plugin->presenter()->present($result, $event, $service->marketName($event, $marketId, $source), $selected);
        } catch (InvalidArgumentException) {
            return new WP_Error('odds_comparison_bad_request', __('Invalid request.', 'odds-comparison'), ['status' => 400]);
        } catch (SourceException) {
            // Internal error details are not for visitors.
            return new WP_Error('odds_comparison_unavailable', __('Odds are temporarily unavailable.', 'odds-comparison'), ['status' => 503]);
        }

        $settings       = $this->plugin->settings();
        $data['notice'] = $data['status'] === 'stale'
            ? __('The odds source is unavailable right now. Showing the last known odds.', 'odds-comparison')
            : '';
        $data['link']   = ['rel' => (string) $settings->get('link_rel'), 'target' => (string) $settings->get('link_target')];

        return new WP_REST_Response($data);
    }
}
