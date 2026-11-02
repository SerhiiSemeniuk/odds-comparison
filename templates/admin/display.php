<?php
/**
 * Display tab.
 *
 * @var array<string,string> $formats  Format key => label.
 * @var array<string,mixed>  $settings
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="odds_comparison_save">
    <input type="hidden" name="tab" value="display">
    <?php wp_nonce_field('odds_comparison_save'); ?>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="oddscmp-format"><?php esc_html_e('Default odds format', 'odds-comparison'); ?></label></th>
            <td>
                <select id="oddscmp-format" name="format">
                    <?php foreach ($formats as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['format'], $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Visitors can switch the format in the table; this is what they see first.', 'odds-comparison'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="oddscmp-rel"><?php esc_html_e('Link rel attribute', 'odds-comparison'); ?></label></th>
            <td>
                <input type="text" id="oddscmp-rel" name="link_rel" value="<?php echo esc_attr((string) $settings['link_rel']); ?>" class="regular-text">
                <p class="description"><?php esc_html_e('Use "sponsored" for affiliate links.', 'odds-comparison'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="oddscmp-target"><?php esc_html_e('Open links in', 'odds-comparison'); ?></label></th>
            <td>
                <select id="oddscmp-target" name="link_target">
                    <option value="_blank" <?php selected($settings['link_target'], '_blank'); ?>><?php esc_html_e('A new tab', 'odds-comparison'); ?></option>
                    <option value="_self" <?php selected($settings['link_target'], '_self'); ?>><?php esc_html_e('The same tab', 'odds-comparison'); ?></option>
                </select>
            </td>
        </tr>
    </table>

    <?php submit_button(__('Save changes', 'odds-comparison')); ?>
</form>
