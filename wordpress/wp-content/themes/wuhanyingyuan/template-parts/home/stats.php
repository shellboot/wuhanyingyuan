<?php
/**
 * Editable result metrics.
 *
 * @package Wuhanyingyuan
 */

$stats = yy_theme_query_records('yy_stat');
if (!$stats->have_posts()) {
    wp_reset_postdata();
    return;
}
?>
<section class="section stats" id="stats" aria-label="服务成果数据">
    <div class="container">
        <div class="stats-grid">
            <?php while ($stats->have_posts()) : $stats->the_post(); ?>
                <div>
                    <div class="stat-num"><?php echo esc_html((string) get_post_meta(get_the_ID(), '_yy_metric_1_value', true)); ?></div>
                    <div class="stat-label"><?php echo esc_html((string) get_post_meta(get_the_ID(), '_yy_metric_1_label', true)); ?></div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
</section>
<?php wp_reset_postdata(); ?>
