<?php
/**
 * Editable service advantages.
 *
 * @package Wuhanyingyuan
 */

$advantages = yy_theme_query_records('yy_advantage');
if (!$advantages->have_posts()) {
    wp_reset_postdata();
    return;
}
?>
<section class="section advantages" id="advantages" aria-labelledby="advantagesTitle">
    <div class="container">
        <?php yy_render_section_heading('advantages_tagline', 'advantages_title', 'advantages_subtitle', 'advantagesTitle'); ?>
        <div class="advantages-grid">
            <?php while ($advantages->have_posts()) : $advantages->the_post(); ?>
                <article class="card advantage-card">
                    <div class="icon" aria-hidden="true"><?php yy_render_record_icon(get_the_ID(), '优'); ?></div>
                    <h3><?php the_title(); ?></h3>
                    <p><?php echo esc_html((string) get_post_meta(get_the_ID(), '_yy_description', true)); ?></p>
                </article>
            <?php endwhile; ?>
        </div>
    </div>
</section>
<?php wp_reset_postdata(); ?>
