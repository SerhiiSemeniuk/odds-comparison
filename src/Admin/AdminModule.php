<?php
/**
 * Admin dashboard module.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Admin;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Plugin;
use OddsComparison\Rest\AdminController;
use OddsComparison\Sources\StatusPresenter;

/**
 * The "Odds Comparison" admin screen.
 *
 * Tabs: Sources (pick the source, see availability, check it), Bookmakers
 * (visibility, order, links), Markets and Display. Forms post to admin-post.php
 * with a nonce; availability checks and cache flushes call the REST API
 * (see AdminController).
 */
final class AdminModule implements HookableInterface
{
    public const SLUG = 'odds-comparison';

    private const CAPABILITY = 'manage_options';

    private string $hook = '';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_odds_comparison_save', [$this, 'handleSave']);
    }

    /**
     * Tab slug => label.
     *
     * @return array<string,string>
     */
    private function tabs(): array
    {
        return [
            'sources'    => __('Sources', 'odds-comparison'),
            'bookmakers' => __('Bookmakers', 'odds-comparison'),
            'markets'    => __('Markets', 'odds-comparison'),
            'display'    => __('Display', 'odds-comparison'),
        ];
    }

    public function addMenu(): void
    {
        $this->hook = (string) add_menu_page(
            __('Odds Comparison', 'odds-comparison'),
            __('Odds Comparison', 'odds-comparison'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
            'dashicons-chart-bar',
            58
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }

        wp_enqueue_style('oddscmp-admin', ODDSCMP_URL . 'assets/admin.css', [], ODDSCMP_VERSION);
        wp_enqueue_script(
            'oddscmp-admin',
            ODDSCMP_URL . 'assets/admin.js',
            ['wp-api-fetch'],
            ODDSCMP_VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_localize_script('oddscmp-admin', 'oddscmpAdmin', [
            'restNamespace' => AdminController::NAMESPACE,
            'checking'      => __('Checking…', 'odds-comparison'),
            'detecting'     => __('Detecting bookmakers…', 'odds-comparison'),
            'flushed'       => __('Cache cleared.', 'odds-comparison'),
            'failed'        => __('The request failed. Try again.', 'odds-comparison'),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'odds-comparison'));
        }

        $tabs = $this->tabs();
        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'sources'; // phpcs:ignore WordPress.Security.NonceVerification
        $tab  = isset($tabs[$tab]) ? $tab : 'sources';

        $this->view('page', [
            'tabs'    => $tabs,
            'tab'     => $tab,
            'updated' => isset($_GET['updated']), // phpcs:ignore WordPress.Security.NonceVerification
            'content' => $this->tabContent($tab),
        ]);
    }

    /**
     * Save the form of a tab and redirect back.
     */
    public function handleSave(): void
    {
        check_admin_referer('odds_comparison_save');

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'odds-comparison'), '', ['response' => 403]);
        }

        $tab   = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'sources';
        $input = wp_unslash($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by the stores below.

        if ($tab === 'bookmakers') {
            $this->plugin->bookmakers()->save((array) ($input['bookmakers'] ?? []));
        } else {
            $this->plugin->settings()->save(
                (array) $input,
                $this->plugin->sources()->ids(),
                array_keys($this->plugin->converter()->formats())
            );
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'tab' => $tab, 'updated' => 1],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Render the body of a tab.
     */
    private function tabContent(string $tab): string
    {
        $settings = $this->plugin->settings();

        return match ($tab) {
            'bookmakers' => $this->capture('bookmakers', ['groups' => $this->bookmakerGroups()]),
            'markets'    => $this->capture('markets', [
                'markets' => $settings->markets(),
                'enabled' => $settings->enabledMarkets(),
            ]),
            'display'    => $this->capture('display', [
                'formats'  => $this->plugin->converter()->formats(),
                'settings' => $settings->all(),
            ]),
            default      => $this->capture('sources', [
                'rows'   => $this->sourceRows(),
                'ttl'    => $settings->ttl(),
                'active' => $settings->source(),
            ]),
        };
    }

    /**
     * One group per source with the bookmakers that source provides.
     *
     * @return list<array{id:string,label:string,bookmakers:list<\OddsComparison\Bookmakers\Bookmaker>}>
     */
    private function bookmakerGroups(): array
    {
        $groups = [];

        foreach ($this->plugin->sources()->all() as $id => $source) {
            $groups[] = [
                'id'         => $id,
                'label'      => $source->label(),
                'bookmakers' => $this->plugin->bookmakers()->all($id),
            ];
        }

        return $groups;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function sourceRows(): array
    {
        $settings = $this->plugin->settings();
        $health   = $this->plugin->health();
        $rows     = [];

        foreach ($this->plugin->sources()->all() as $id => $source) {
            $rows[] = [
                'id'          => $id,
                'label'       => $source->label(),
                'description' => $source->description(),
                'fallback'    => in_array($id, (array) $settings->get('fallback'), true),
                'status'      => StatusPresenter::present($health->status($id)),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function capture(string $name, array $vars): string
    {
        ob_start();
        $this->view($name, $vars);

        return (string) ob_get_clean();
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function view(string $name, array $vars): void
    {
        extract($vars, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract
        include ODDSCMP_DIR . 'templates/admin/' . $name . '.php';
    }
}
