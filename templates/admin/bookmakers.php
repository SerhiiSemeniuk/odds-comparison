<?php
/**
 * Bookmakers tab.
 *
 * @var list<array{id:string,label:string,bookmakers:list<\OddsComparison\Bookmakers\Bookmaker>}> $groups
 *
 * @package OddsComparison
 */

defined('ABSPATH') || exit;
?>
<p class="description">
    <?php esc_html_e('Each source offers its own bookmakers. Only the bookmakers a source actually provides prices for are listed; choose which to show and where their "Bet" buttons lead.', 'odds-comparison'); ?>
</p>
<p class="description">
    <?php
    printf(
        /* translators: %s: list of placeholders such as {sport} {event} */
        esc_html__('A link may contain placeholders that are filled in for the event shown: %s. Example: https://sportsbook.example.com/{sport}', 'odds-comparison'),
        implode(' ', array_map(
            static fn (string $p): string => '<code>' . esc_html('{' . $p . '}') . '</code>',
            \OddsComparison\Bookmakers\LinkTemplate::PLACEHOLDERS
        ))
    );
    ?>
</p>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="odds_comparison_save">
    <input type="hidden" name="tab" value="bookmakers">
    <?php wp_nonce_field('odds_comparison_save'); ?>

    <?php foreach ($groups as $group) : ?>
        <section class="oddscmp-group" data-source="<?php echo esc_attr($group['id']); ?>">
            <h2>
                <?php echo esc_html($group['label']); ?>
                <span class="oddscmp-count">
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of bookmakers */
                        _n('%d bookmaker', '%d bookmakers', count($group['bookmakers']), 'odds-comparison'),
                        count($group['bookmakers'])
                    )); ?>
                </span>
            </h2>

            <?php if ($group['bookmakers'] === []) : ?>
                <p class="description">
                    <?php esc_html_e('No bookmakers detected yet. They are recorded as soon as this source returns odds, or you can detect them now.', 'odds-comparison'); ?>
                </p>
            <?php else : ?>
                <table class="widefat striped oddscmp-bookmakers">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Show', 'odds-comparison'); ?></th>
                            <th scope="col"><?php esc_html_e('Name', 'odds-comparison'); ?></th>
                            <th scope="col"><?php esc_html_e('Link (affiliate URL)', 'odds-comparison'); ?></th>
                            <th scope="col"><?php esc_html_e('Order', 'odds-comparison'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['bookmakers'] as $b) : ?>
                        <?php $field = 'bookmakers[' . $group['id'] . '][' . $b->id . ']'; ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="<?php echo esc_attr($field); ?>[enabled]" value="1" <?php checked($b->enabled); ?>
                                    aria-label="<?php echo esc_attr(sprintf(
                                        /* translators: %s: bookmaker name */
                                        __('Show %s', 'odds-comparison'),
                                        $b->name
                                    )); ?>">
                            </td>
                            <td>
                                <input type="text" name="<?php echo esc_attr($field); ?>[name]" value="<?php echo esc_attr($b->name); ?>" class="regular-text">
                                <code><?php echo esc_html($b->id); ?></code>
                            </td>
                            <td>
                                <input type="url" name="<?php echo esc_attr($field); ?>[url]" value="<?php echo esc_attr($b->url); ?>" class="large-text" placeholder="https://">
                            </td>
                            <td>
                                <input type="number" name="<?php echo esc_attr($field); ?>[order]" value="<?php echo esc_attr((string) $b->order); ?>" class="small-text" step="1">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p>
                <button type="button" class="button" data-detect-bookmakers="<?php echo esc_attr($group['id']); ?>">
                    <?php esc_html_e('Detect bookmakers', 'odds-comparison'); ?>
                </button>
            </p>
        </section>
    <?php endforeach; ?>

    <span class="oddscmp-status" role="status" aria-live="polite"></span>
    <?php submit_button(__('Save changes', 'odds-comparison')); ?>
</form>
