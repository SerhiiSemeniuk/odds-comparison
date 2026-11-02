<?php
/**
 * Plugin Name:       Odds Comparison
 * Description:       Compare live odds from multiple bookmakers with an admin dashboard, a Gutenberg block and fractional / decimal / American odds conversion.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            SeSeSe
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       odds-comparison
 * Domain Path:       /languages
 *
 * @package OddsComparison
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('ODDSCMP_VERSION', '1.0.0');
define('ODDSCMP_FILE', __FILE__);
define('ODDSCMP_DIR', plugin_dir_path(__FILE__));
define('ODDSCMP_URL', plugin_dir_url(__FILE__));

require_once ODDSCMP_DIR . 'src/autoload.php';

register_activation_hook(__FILE__, [\OddsComparison\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\OddsComparison\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \OddsComparison\Plugin::instance()->boot();
});
