<?php
/**
 * Plugin kernel.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison;

use OddsComparison\Contracts\HookableInterface;

/**
 * Singleton that boots every module of the plugin.
 *
 * Third parties can add their own modules through the
 * `odds_comparison_modules` filter (class names implementing HookableInterface).
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private bool $booted = false;

    /** @var list<HookableInterface> */
    private array $modules = [];

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

        load_plugin_textdomain('odds-comparison', false, dirname(plugin_basename(ODDSCMP_FILE)) . '/languages');

        /**
         * Filters the list of module classes booted by the plugin.
         *
         * @param list<class-string<HookableInterface>> $modules Module class names.
         */
        $classes = apply_filters('odds_comparison_modules', $this->defaultModules());

        foreach ($classes as $class) {
            if (is_a($class, HookableInterface::class, true)) {
                $module = new $class();
                $module->register();
                $this->modules[] = $module;
            }
        }
    }

    /**
     * Modules shipped with the plugin.
     *
     * @return list<class-string<HookableInterface>>
     */
    private function defaultModules(): array
    {
        return [];
    }

    /**
     * Activation callback.
     */
    public static function activate(): void
    {
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
