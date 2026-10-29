<?php
/**
 * Editable frequently asked questions.
 *
 * @package Wuhanyingyuan
 */

$faqs = yy_theme_query_records('yy_faq');
if (!$faqs->have_posts()) {
    wp_reset_postdata();
    return;
}
?>
<section class="section faq-section" id="faq" aria-labelledby="faqTitle">
    <div class="container">
        <?php yy_render_section_heading('faq_tagline', 'faq_title', 'faq_subtitle', 'faqTitle'); ?>
        <div class="faq-list">
            <?php while ($faqs->have_posts()) : $faqs->the_post(); ?>
                <details class="faq-item">
                    <summary><?php the_title(); ?></summary>
                    <?php echo wp_kses_post(apply_filters('the_content', get_the_content())); ?>
                </details>
            <?php endwhile; ?>
        </div>
    </div>
</section>
<?php wp_reset_postdata(); ?>
