<?php
/**
 * Admin page shell.
 *
 * @var array<string,string> $tabs
 * @var string               $tab
 * @var bool                 $updated
 * @var string               $content Pre-escaped tab markup.
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;
?>
<div class="wrap oddscmp-admin">
    <h1><?php esc_html_e('Odds Comparison', 'odds-comparison'); ?></h1>

    <?php if ($updated) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'odds-comparison'); ?></p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Settings sections', 'odds-comparison'); ?>">
        <?php foreach ($tabs as $slug => $label) : ?>
            <a href="<?php echo esc_url(add_query_arg(['page' => 'odds-comparison', 'tab' => $slug], admin_url('admin.php'))); ?>"
               class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"
               <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>>
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="oddscmp-panel">
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered by an escaping template. ?>
    </div>
</div>
