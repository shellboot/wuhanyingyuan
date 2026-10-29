<?php
/**
 * Editable cooperation process.
 *
 * @package Wuhanyingyuan
 */

$steps = yy_theme_query_records('yy_process');
if (!$steps->have_posts()) {
    wp_reset_postdata();
    return;
}
?>
<section class="section process-section" id="process" aria-labelledby="processTitle">
    <div class="container">
        <?php yy_render_section_heading('process_tagline', 'process_title', 'process_subtitle', 'processTitle'); ?>
        <ol class="process-grid">
            <?php $step_number = 1; ?>
            <?php while ($steps->have_posts()) : $steps->the_post(); ?>
                <li class="step">
                    <span class="num" aria-hidden="true"><?php echo esc_html((string) $step_number); ?></span>
                    <h3><?php the_title(); ?></h3>
                    <p><?php echo esc_html((string) get_post_meta(get_the_ID(), '_yy_description', true)); ?></p>
                </li>
                <?php $step_number++; ?>
            <?php endwhile; ?>
        </ol>
    </div>
</section>
<?php wp_reset_postdata(); ?>
