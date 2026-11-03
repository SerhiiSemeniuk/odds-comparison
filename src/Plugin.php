<?php
/**
 * Plugin kernel.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison;

use OddsComparison\Admin\AdminModule;
use OddsComparison\Block\BlockModule;
use OddsComparison\Block\ConfigSigner;
use OddsComparison\Bookmakers\BookmakerRepository;
use OddsComparison\Cache\TransientCache;
use OddsComparison\Contracts\CacheInterface;
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Contracts\HttpClientInterface;
use OddsComparison\Database\Schema;
use OddsComparison\Http\CircuitBreaker;
use OddsComparison\Http\Fetcher;
use OddsComparison\Odds\Converter;
use OddsComparison\Rest\AdminController;
use OddsComparison\Rest\OddsController;
use OddsComparison\Rest\TableController;
use OddsComparison\Service\OddsPresenter;
use OddsComparison\Service\OddsService;
use OddsComparison\Settings\Settings;
use OddsComparison\Sources\BestOddsSource;
use OddsComparison\Sources\BetExplorerSource;
use OddsComparison\Sources\HealthMonitor;
use OddsComparison\Sources\SourceRegistry;
use OddsComparison\Storage\OddsStore;

/**
 * Singleton kernel: boots the modules and lazily builds the shared services.
 *
 * Services are created on first use and shared, which keeps requests that do
 * not touch odds (most front-end page views) free of any setup cost.
 * Third parties can add modules through the `odds_comparison_modules` filter.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private bool $booted = false;

    /** @var list<HookableInterface> */
    private array $modules = [];

    private ?CacheInterface $cache = null;
    private ?HttpClientInterface $http = null;
    private ?Settings $settings = null;
    private ?SourceRegistry $registry = null;
    private ?HealthMonitor $health = null;
    private ?BookmakerRepository $bookmakers = null;
    private ?Converter $converter = null;
    private ?OddsService $service = null;
    private ?OddsPresenter $presenter = null;
    private ?ConfigSigner $signer = null;
    private ?OddsStore $store = null;

    private function __construct()
    {
    }

    /**
     * Get the shared plugin instance.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Instantiate and register all modules. Safe to call more than once.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        // Create or migrate the custom tables when the code ships a newer schema.
        Schema::maybeUpgrade();

        load_plugin_textdomain('odds-comparison', false, dirname(plugin_basename(ODDSCMP_FILE)) . '/languages');

        /**
         * Filters the list of module classes booted by the plugin.
         *
         * @param list<class-string<HookableInterface>> $modules Module class names.
         */
        $classes = apply_filters('odds_comparison_modules', $this->defaultModules());

        foreach ($classes as $class) {
            if (is_a($class, HookableInterface::class, true)) {
                $module = new $class($this);
                $module->register();
                $this->modules[] = $module;
            }
        }
    }

    public function cache(): CacheInterface
    {
        return $this->cache ??= new TransientCache();
    }

    public function http(): HttpClientInterface
    {
        return $this->http ??= new Fetcher($this->cache(), new CircuitBreaker($this->cache()));
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings();
    }

    /**
     * Registry with the built-in sources (extend via `odds_comparison_sources`).
     */
    public function sources(): SourceRegistry
    {
        return $this->registry ??= new SourceRegistry([
            BestOddsSource::ID    => fn () => new BestOddsSource($this->http()),
            BetExplorerSource::ID => fn () => new BetExplorerSource($this->http()),
        ]);
    }

    public function health(): HealthMonitor
    {
        return $this->health ??= new HealthMonitor($this->sources(), $this->cache());
    }

    public function bookmakers(): BookmakerRepository
    {
        return $this->bookmakers ??= new BookmakerRepository();
    }

    public function converter(): Converter
    {
        return $this->converter ??= new Converter();
    }

    public function signer(): ConfigSigner
    {
        return $this->signer ??= new ConfigSigner();
    }

    public function presenter(): OddsPresenter
    {
        return $this->presenter ??= new OddsPresenter($this->converter(), $this->bookmakers());
    }

    public function odds(): OddsService
    {
        return $this->service ??= new OddsService(
            $this->sources(),
            $this->cache(),
            $this->settings(),
            $this->health(),
            $this->bookmakers(),
            $this->store()
        );
    }

    public function store(): OddsStore
    {
        return $this->store ??= new OddsStore();
    }

    /**
     * Modules shipped with the plugin.
     *
     * @return list<class-string<HookableInterface>>
     */
    private function defaultModules(): array
    {
        // REST requests are not is_admin(), so the controllers are always registered.
        $modules = [AdminController::class, OddsController::class, TableController::class, BlockModule::class];

        if (is_admin()) {
            $modules[] = AdminModule::class;
        }

        return $modules;
    }

    /**
     * Activation callback.
     */
    public static function activate(): void
    {
        Schema::install();

        do_action('odds_comparison_activate');
    }

    /**
     * Deactivation callback.
     */
    public static function deactivate(): void
    {
        do_action('odds_comparison_deactivate');
    }
}
