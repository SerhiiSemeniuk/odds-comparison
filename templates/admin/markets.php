<?php
/**
 * Markets tab.
 *
 * @var array<string,string> $markets Market id => label.
 * @var list<string>         $enabled Enabled market ids.
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;
?>
<p class="description">
    <?php esc_html_e('Choose which betting markets can be shown. A source only offers the markets it supports.', 'odds-comparison'); ?>
</p>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="odds_comparison_save">
    <input type="hidden" name="tab" value="markets">
    <input type="hidden" name="markets[]" value="">
    <?php wp_nonce_field('odds_comparison_save'); ?>

    <fieldset>
        <legend class="screen-reader-text"><?php esc_html_e('Enabled markets', 'odds-comparison'); ?></legend>
        <?php foreach ($markets as $id => $label) : ?>
            <p>
                <label>
                    <input type="checkbox" name="markets[]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id, $enabled, true)); ?>>
                    <?php echo esc_html($label); ?>
                    <code><?php echo esc_html($id); ?></code>
                </label>
            </p>
        <?php endforeach; ?>
    </fieldset>

    <?php submit_button(__('Save changes', 'odds-comparison')); ?>
</form>
