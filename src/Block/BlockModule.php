<?php
/**
 * Block module.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Block;

use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Plugin;

/**
 * Registers the odds table block from its built block.json.
 */
final class BlockModule implements HookableInterface
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock(): void
    {
        $dir = ODDSCMP_DIR . 'build/odds-table';

        if (!is_readable($dir . '/block.json')) {
            return; // Not built yet: run `npm run build`.
        }

        $block = new OddsTableBlock($this->plugin, $this->plugin->signer());

        register_block_type($dir, [
            'render_callback' => static fn (array $attributes): string => $block->render($attributes),
        ]);
    }
}
