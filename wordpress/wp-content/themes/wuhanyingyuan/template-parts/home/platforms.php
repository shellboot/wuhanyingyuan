<?php
/**
 * Editable advertising platform grid.
 *
 * @package Wuhanyingyuan
 */

$platforms = yy_theme_query_records('yy_platform');
if (!$platforms->have_posts()) {
    wp_reset_postdata();
    return;
}
?>
<section class="section platforms" id="platforms" aria-labelledby="platformsTitle">
    <div class="container">
        <?php yy_render_section_heading('platforms_tagline', 'platforms_title', 'platforms_subtitle', 'platformsTitle'); ?>
        <div class="platforms-grid">
            <?php while ($platforms->have_posts()) : $platforms->the_post(); ?>
                <?php
                $post_id = get_the_ID();
                $variant = sanitize_html_class((string) get_post_meta($post_id, '_yy_icon_variant', true));
                $variant = $variant !== '' ? $variant : 'jj';
                $english_name = (string) get_post_meta($post_id, '_yy_english_name', true);
                $description = (string) get_post_meta($post_id, '_yy_description', true);
                $metric_1_value = (string) get_post_meta($post_id, '_yy_metric_1_value', true);
                $metric_1_label = (string) get_post_meta($post_id, '_yy_metric_1_label', true);
                $metric_2_value = (string) get_post_meta($post_id, '_yy_metric_2_value', true);
                $metric_2_label = (string) get_post_meta($post_id, '_yy_metric_2_label', true);
                $is_geo = $variant === 'geo';
                ?>
                <article class="platform-card<?php echo $is_geo ? ' platform-card--geo' : ''; ?>">
                    <div class="<?php echo $is_geo ? 'platform-geo-copy' : ''; ?>">
                        <div class="platform-header">
                            <div class="platform-icon <?php echo esc_attr($variant); ?>" aria-hidden="true"><?php echo esc_html((string) get_post_meta($post_id, '_yy_icon', true)); ?></div>
                            <div>
                                <div class="platform-title-row">
                                    <h3><?php the_title(); ?></h3>
                                    <?php if ($is_geo) : ?><span class="platform-new">NEW</span><?php endif; ?>
                                </div>
                                <?php if ($english_name !== '') : ?><p class="platform-en"><?php echo esc_html($english_name); ?></p><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($description !== '') : ?><p class="platform-desc"><?php echo esc_html($description); ?></p><?php endif; ?>
                    </div>
                    <?php if ($metric_1_value !== '' || $metric_2_value !== '') : ?>
                        <dl class="platform-stats<?php echo $is_geo ? ' platform-stats--geo' : ''; ?>">
                            <?php if ($metric_1_value !== '') : ?>
                                <div><dt class="p-num"><?php echo esc_html($metric_1_value); ?></dt><dd class="p-label"><?php echo esc_html($metric_1_label); ?></dd></div>
                            <?php endif; ?>
                            <?php if ($metric_2_value !== '') : ?>
                                <div><dt class="p-num"><?php echo esc_html($metric_2_value); ?></dt><dd class="p-label"><?php echo esc_html($metric_2_label); ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    <?php endif; ?>
                </article>
            <?php endwhile; ?>
        </div>
        <?php if ((string) yy_setting('platforms_note') !== '') : ?>
            <p class="data-note"><?php echo esc_html((string) yy_setting('platforms_note')); ?></p>
        <?php endif; ?>
    </div>
</section>
<?php wp_reset_postdata(); ?>
