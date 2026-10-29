<?php
/**
 * Editable service cards.
 *
 * @package Wuhanyingyuan
 */

$services = yy_theme_query_records('yy_service');
if (!$services->have_posts()) {
    wp_reset_postdata();
    return;
}
?>
<section class="section services" id="services" aria-labelledby="servicesTitle">
    <div class="container">
        <?php yy_render_section_heading('services_tagline', 'services_title', 'services_subtitle', 'servicesTitle'); ?>
        <div class="services-grid">
            <?php while ($services->have_posts()) : $services->the_post(); ?>
                <article class="card">
                    <div class="icon" aria-hidden="true"><?php yy_render_record_icon(get_the_ID(), '服'); ?></div>
                    <h3><?php the_title(); ?></h3>
                    <p><?php echo esc_html((string) get_post_meta(get_the_ID(), '_yy_description', true)); ?></p>
                </article>
            <?php endwhile; ?>
        </div>
    </div>
</section>
<?php wp_reset_postdata(); ?>
