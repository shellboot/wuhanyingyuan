<?php
/**
 * Editable customer case studies.
 *
 * @package Wuhanyingyuan
 */

$cases = yy_theme_query_records('yy_case');
if (!$cases->have_posts()) {
    wp_reset_postdata();
    return;
}
?>
<section class="section cases" id="cases" aria-labelledby="casesTitle">
    <div class="container">
        <?php yy_render_section_heading('cases_tagline', 'cases_title', 'cases_subtitle', 'casesTitle'); ?>
        <div class="cases-grid">
            <?php $case_number = 1; ?>
            <?php while ($cases->have_posts()) : $cases->the_post(); ?>
                <?php
                $post_id = get_the_ID();
                $image_id = yy_record_image_id($post_id);
                $metric_1_value = (string) get_post_meta($post_id, '_yy_metric_1_value', true);
                $metric_1_label = (string) get_post_meta($post_id, '_yy_metric_1_label', true);
                $metric_2_value = (string) get_post_meta($post_id, '_yy_metric_2_value', true);
                $metric_2_label = (string) get_post_meta($post_id, '_yy_metric_2_label', true);
                ?>
                <article class="case-card">
                    <?php if ($image_id) : ?>
                        <div class="case-cover case-cover--image"><?php echo wp_get_attachment_image($image_id, 'large', false, ['loading' => 'lazy', 'alt' => get_the_title()]); ?></div>
                    <?php else : ?>
                        <div class="case-cover c<?php echo esc_attr((string) (($case_number - 1) % 3 + 1)); ?>" aria-hidden="true"><?php echo esc_html((string) get_post_meta($post_id, '_yy_icon', true)); ?></div>
                    <?php endif; ?>
                    <div class="case-body">
                        <?php if ((string) get_post_meta($post_id, '_yy_tag', true) !== '') : ?><span class="case-tag"><?php echo esc_html((string) get_post_meta($post_id, '_yy_tag', true)); ?></span><?php endif; ?>
                        <h3><?php the_title(); ?></h3>
                        <p><?php echo esc_html((string) get_post_meta($post_id, '_yy_description', true)); ?></p>
                        <?php if ($metric_1_value !== '' || $metric_2_value !== '') : ?>
                            <dl class="case-metrics<?php echo $metric_2_value === '' ? ' case-metrics--single' : ''; ?>">
                                <?php if ($metric_1_value !== '') : ?><div><dt class="m-num"><?php echo esc_html($metric_1_value); ?></dt><dd class="m-label"><?php echo esc_html($metric_1_label); ?></dd></div><?php endif; ?>
                                <?php if ($metric_2_value !== '') : ?><div><dt class="m-num"><?php echo esc_html($metric_2_value); ?></dt><dd class="m-label"><?php echo esc_html($metric_2_label); ?></dd></div><?php endif; ?>
                            </dl>
                        <?php endif; ?>
                    </div>
                </article>
                <?php $case_number++; ?>
            <?php endwhile; ?>
        </div>
        <?php if ((string) yy_setting('cases_note') !== '') : ?><p class="data-note"><?php echo esc_html((string) yy_setting('cases_note')); ?></p><?php endif; ?>
    </div>
</section>
<?php wp_reset_postdata(); ?>
