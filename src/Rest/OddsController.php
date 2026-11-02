<?php
/**
 * Odds REST controller.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Rest;

use InvalidArgumentException;
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Plugin;
use OddsComparison\Sources\Exception\SourceException;
use OddsComparison\Sources\Model\Event;
use OddsComparison\Sources\Model\Market;
use OddsComparison\Sources\Model\Sport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Read-only endpoints used by the block editor. They can trigger scrapes, so
 * they require the `edit_posts` capability (and the cookie nonce) instead of
 * being public.
 *
 *   GET /odds-comparison/v1/sources
 *   GET /odds-comparison/v1/sports?source=
 *   GET /odds-comparison/v1/events?source=&sport=
 *   GET /odds-comparison/v1/markets?source=&sport=&event=&name=&path=&starts=
 *   GET /odds-comparison/v1/bookmakers?source=
 *   GET /odds-comparison/v1/odds?source=&sport=&event=&name=&path=&starts=&market=&bookmakers[]=
 *
 * `odds` answers 202 {status: pending} while another request is fetching.
 */
final class OddsController implements HookableInterface
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns   = AdminController::NAMESPACE;
        $read = [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => [$this, 'canRead'],
        ];

        register_rest_route($ns, '/sources', $read + ['callback' => [$this, 'sources']]);
        register_rest_route($ns, '/sports', $read + ['callback' => [$this, 'sports'], 'args' => $this->args(['source'])]);
        register_rest_route($ns, '/events', $read + ['callback' => [$this, 'events'], 'args' => $this->args(['source', 'sport'])]);
        register_rest_route($ns, '/markets', $read + ['callback' => [$this, 'markets'], 'args' => $this->eventArgs()]);
        register_rest_route($ns, '/bookmakers', $read + ['callback' => [$this, 'bookmakers'], 'args' => $this->args(['source'])]);
        register_rest_route($ns, '/odds', $read + [
            'callback' => [$this, 'odds'],
            'args'     => $this->eventArgs() + [
                'market'     => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key'],
                'bookmakers' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
            ],
        ]);
    }

    public function canRead(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Registered sources, the active one first.
     */
    public function sources(): WP_REST_Response
    {
        $active = $this->plugin->settings()->source();
        $out    = [];

        foreach ($this->plugin->sources()->all() as $id => $source) {
            $out[] = ['id' => $id, 'label' => $source->label(), 'active' => $id === $active];
        }

        usort($out, static fn (array $a, array $b): int => (int) $b['active'] <=> (int) $a['active']);

        return new WP_REST_Response($out);
    }

    public function sports(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->guard(function () use ($request): WP_REST_Response {
            $result = $this->plugin->odds()->sports($this->sourceId($request));

            return new WP_REST_Response(array_map(static fn (Sport $s): array => $s->toArray(), $result['items']));
        });
    }

    public function events(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->guard(function () use ($request): WP_REST_Response {
            $result = $this->plugin->odds()->events((string) $request['sport'], $this->sourceId($request));

            return new WP_REST_Response(array_map(static fn (Event $e): array => $e->toArray(), $result['items']));
        });
    }

    public function markets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->guard(function () use ($request): WP_REST_Response {
            $markets = $this->plugin->odds()->markets($this->event($request), $this->sourceId($request));

            return new WP_REST_Response(array_map(static fn (Market $m): array => $m->toArray(), $markets));
        });
    }

    /**
     * Enabled bookmakers of a source (for the block's add/remove list).
     */
    public function bookmakers(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->guard(function () use ($request): WP_REST_Response {
            $out = [];
            foreach ($this->plugin->bookmakers()->enabled($this->sourceId($request)) as $bookmaker) {
                $out[] = ['id' => $bookmaker->id, 'name' => $bookmaker->name];
            }

            return new WP_REST_Response($out);
        });
    }

    public function odds(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->guard(function () use ($request): WP_REST_Response {
            $service  = $this->plugin->odds();
            $sourceId = $this->sourceId($request);
            $event    = $this->event($request);
            $marketId = (string) $request['market'];

            $result = $service->odds($event, $marketId, $sourceId);

            // Another request is fetching; the client asks again shortly.
            if ($result->snapshot === null) {
                return new WP_REST_Response(['status' => 'pending', 'retryAfter' => $result->retryAfter], 202);
            }

            $selected = array_values(array_filter(array_map('sanitize_key', (array) $request['bookmakers'])));
            $data     = $this->plugin->presenter()->present(
                $result,
                $event,
                $service->marketName($event, $marketId, $sourceId),
                $selected
            );

            // Editors see how visitors will see it, including the link attributes.
            $settings     = $this->plugin->settings();
            $data['link'] = ['rel' => (string) $settings->get('link_rel'), 'target' => (string) $settings->get('link_target')];

            return new WP_REST_Response($data);
        });
    }

    /**
     * Run a handler and translate domain errors into REST errors.
     *
     * @param callable():WP_REST_Response $handler
     */
    private function guard(callable $handler): WP_REST_Response|WP_Error
    {
        try {
            return $handler();
        } catch (InvalidArgumentException $e) {
            return new WP_Error('odds_comparison_bad_request', $e->getMessage(), ['status' => 400]);
        } catch (SourceException $e) {
            return new WP_Error('odds_comparison_source_failed', $e->getMessage(), ['status' => 502]);
        }
    }

    /**
     * @throws InvalidArgumentException For an unknown source.
     */
    private function sourceId(WP_REST_Request $request): string
    {
        $id = (string) $request['source'];

        if (!$this->plugin->sources()->has($id)) {
            throw new InvalidArgumentException(__('Unknown source.', 'odds-comparison'));
        }

        return $id;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function event(WP_REST_Request $request): Event
    {
        return $this->plugin->odds()->makeEvent(
            (string) $request['sport'],
            (string) $request['event'],
            (string) $request['name'],
            (string) $request['path'],
            (int) $request['starts']
        );
    }

    /**
     * @param list<string> $names
     * @return array<string,array<string,mixed>>
     */
    private function args(array $names): array
    {
        $args = [];
        foreach ($names as $name) {
            $args[$name] = ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'];
        }

        return $args;
    }

    /**
     * Arguments that identify an event.
     *
     * @return array<string,array<string,mixed>>
     */
    private function eventArgs(): array
    {
        return $this->args(['source', 'sport', 'event', 'path']) + [
            'name'   => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            'starts' => ['type' => 'integer', 'default' => 0],
        ];
    }
}
