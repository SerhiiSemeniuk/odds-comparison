<?php
/**
 * Sources tab.
 *
 * @var list<array<string,mixed>> $rows
 * @var int                       $ttl
 * @var string                    $active
 * @var bool                      $apiKeySet  Whether an API key is saved.
 * @var list<string>              $apiRegions Selected bookmaker regions.
 * @var array<string,string>      $regions    Region code => label.
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
            <?php $health = $row['status']; ?>
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
                    <span class="oddscmp-badge oddscmp-badge--<?php echo esc_attr($health['state']); ?>" data-role="badge">
                        <?php echo esc_html($health['label']); ?>
                    </span>
                    <p class="description" data-role="message"><?php echo esc_html($health['message']); ?></p>
                </td>
                <td>
                    <span data-role="checked"><?php echo esc_html($health['checked']); ?></span>
                    <span class="description" data-role="latency">
                        <?php echo $health['latencyMs'] !== null ? esc_html($health['latencyMs'] . ' ms') : ''; ?>
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

    <h2><?php esc_html_e('The Odds API', 'odds-comparison'); ?></h2>
    <input type="hidden" name="api_regions[]" value="">
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="oddscmp-api-key"><?php esc_html_e('API key', 'odds-comparison'); ?></label></th>
            <td>
                <input type="password" id="oddscmp-api-key" name="api_key" value="" class="regular-text" autocomplete="new-password" spellcheck="false"
                    placeholder="<?php echo esc_attr($apiKeySet ? __('A key is saved. Type a new one to replace it.', 'odds-comparison') : __('Paste your key', 'odds-comparison')); ?>">
                <?php if ($apiKeySet) : ?>
                    <label><input type="checkbox" name="api_key_remove" value="1"> <?php esc_html_e('Remove the saved key', 'odds-comparison'); ?></label>
                <?php endif; ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: link to the API website */
                        esc_html__('Get a key at %s (a free plan exists). The key is stored in the database and never shown again.', 'odds-comparison'),
                        '<a href="https://the-odds-api.com" target="_blank" rel="noopener">the-odds-api.com</a>'
                    );
                    ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Bookmaker regions', 'odds-comparison'); ?></th>
            <td>
                <fieldset>
                    <legend class="screen-reader-text"><?php esc_html_e('Bookmaker regions', 'odds-comparison'); ?></legend>
                    <?php foreach ($regions as $code => $label) : ?>
                        <label style="display:inline-block;margin-right:16px">
                            <input type="checkbox" name="api_regions[]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $apiRegions, true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <p class="description">
                    <?php esc_html_e('Every odds request costs one credit per region and market, so fewer regions and a longer freshness time below save credits.', 'odds-comparison'); ?>
                </p>
            </td>
        </tr>
    </table>

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
