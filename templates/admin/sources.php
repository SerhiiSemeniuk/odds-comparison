<?php
/**
 * Sources tab.
 *
 * @var list<array<string,mixed>> $rows
 * @var int                       $ttl
 * @var string                    $active
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;
?>
<p class="description">
    <?php esc_html_e('Choose where odds come from. If the active source is unavailable, the ticked fallback sources are tried in order.', 'odds-comparison'); ?>
</p>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="odds_comparison_save">
    <input type="hidden" name="tab" value="sources">
    <input type="hidden" name="fallback[]" value="">
    <?php wp_nonce_field('odds_comparison_save'); ?>

    <table class="widefat striped oddscmp-sources">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Active', 'odds-comparison'); ?></th>
                <th scope="col"><?php esc_html_e('Fallback', 'odds-comparison'); ?></th>
                <th scope="col"><?php esc_html_e('Source', 'odds-comparison'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'odds-comparison'); ?></th>
                <th scope="col"><?php esc_html_e('Last check', 'odds-comparison'); ?></th>
                <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'odds-comparison'); ?></span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) : ?>
            <?php $status = $row['status']; ?>
            <tr data-source="<?php echo esc_attr($row['id']); ?>">
                <td>
                    <input type="radio" name="source" value="<?php echo esc_attr($row['id']); ?>"
                        <?php checked($active, $row['id']); ?>
                        aria-label="<?php echo esc_attr(sprintf(
                            /* translators: %s: source name */
                            __('Use %s as the active source', 'odds-comparison'),
                            $row['label']
                        )); ?>">
                </td>
                <td>
                    <input type="checkbox" name="fallback[]" value="<?php echo esc_attr($row['id']); ?>"
                        <?php checked($row['fallback']); ?>
                        aria-label="<?php echo esc_attr(sprintf(
                            /* translators: %s: source name */
                            __('Use %s as a fallback', 'odds-comparison'),
                            $row['label']
                        )); ?>">
                </td>
                <td>
                    <strong><?php echo esc_html($row['label']); ?></strong>
                    <p class="description"><?php echo esc_html($row['description']); ?></p>
                </td>
                <td>
                    <span class="oddscmp-badge oddscmp-badge--<?php echo esc_attr($status['state']); ?>" data-role="badge">
                        <?php echo esc_html($status['label']); ?>
                    </span>
                    <p class="description" data-role="message"><?php echo esc_html($status['message']); ?></p>
                </td>
                <td>
                    <span data-role="checked"><?php echo esc_html($status['checked']); ?></span>
                    <span class="description" data-role="latency">
                        <?php echo $status['latencyMs'] !== null ? esc_html($status['latencyMs'] . ' ms') : ''; ?>
                    </span>
                </td>
                <td>
                    <button type="button" class="button" data-check-source="<?php echo esc_attr($row['id']); ?>">
                        <?php esc_html_e('Check availability', 'odds-comparison'); ?>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p>
        <button type="button" class="button" data-check-source="all"><?php esc_html_e('Check all sources', 'odds-comparison'); ?></button>
        <span class="oddscmp-status" role="status" aria-live="polite"></span>
    </p>

    <h2><?php esc_html_e('Cache', 'odds-comparison'); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="oddscmp-ttl"><?php esc_html_e('Odds stay fresh for', 'odds-comparison'); ?></label></th>
            <td>
                <input type="number" id="oddscmp-ttl" name="ttl" min="60" max="86400" step="30" value="<?php echo esc_attr((string) $ttl); ?>" class="small-text">
                <?php esc_html_e('seconds', 'odds-comparison'); ?>
                <p class="description"><?php esc_html_e('After that, visitors still get the last stored odds instantly while they are refreshed in the background.', 'odds-comparison'); ?></p>
                <button type="button" class="button" id="oddscmp-flush"><?php esc_html_e('Clear cache', 'odds-comparison'); ?></button>
            </td>
        </tr>
    </table>

    <?php submit_button(__('Save changes', 'odds-comparison')); ?>
</form>
