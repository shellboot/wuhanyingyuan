<?php
/**
 * About section and editable result-image carousel.
 *
 * @package Wuhanyingyuan
 */

$carousel = yy_theme_query_records('yy_carousel_image');
$carousel_slides = [];

while ($carousel->have_posts()) {
    $carousel->the_post();
    $image_id = yy_record_image_id(get_the_ID());
    if (!$image_id) {
        continue;
    }

    $carousel_slides[] = [
        'image_id' => $image_id,
        'alt' => (string) get_post_meta(get_the_ID(), '_yy_image_alt', true),
    ];
}

wp_reset_postdata();
?>
<section class="section about" id="about" aria-labelledby="aboutTitle">
    <div class="container">
        <div class="about-grid">
            <div class="about-text">
                <h2 id="aboutTitle"><?php echo esc_html((string) yy_setting('about_title')); ?></h2>
                <?php yy_render_setting_paragraphs((string) yy_setting('about_description')); ?>
                <button class="btn" type="button" data-wechat-trigger><?php echo esc_html((string) yy_setting('about_button_text')); ?></button>
            </div>
            <div class="about-visual">
                <?php if ($carousel_slides !== []) : ?>
                    <div class="carousel" id="dataCarousel" aria-roledescription="carousel" aria-label="投放数据效果">
                        <div class="carousel-track" id="carouselTrack">
                            <?php foreach ($carousel_slides as $index => $slide) : ?>
                                <div class="carousel-slide<?php echo $index === 0 ? ' is-active' : ''; ?>" role="group" aria-roledescription="slide" aria-label="<?php echo esc_attr(($index + 1) . ' / ' . count($carousel_slides)); ?>">
                                    <?php
                                    echo wp_get_attachment_image($slide['image_id'], 'full', false, [
                                        'width' => 1024,
                                        'height' => 576,
                                        'alt' => $slide['alt'],
                                        'loading' => 'lazy',
                                        'decoding' => 'async',
                                    ]);
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="carousel-button prev" id="carouselPrev" type="button" aria-label="上一张">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>
                        <button class="carousel-button next" id="carouselNext" type="button" aria-label="下一张">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="carousel-dots" id="carouselDots" aria-label="选择轮播图"></div>
                    </div>
                <?php else : ?>
                    <div class="carousel" id="dataCarousel" aria-label="投放数据效果">
                        <div class="carousel-track" id="carouselTrack">
                            <div class="carousel-slide is-active">
                                <img src="<?php echo esc_url(yy_theme_image_url('images/data-1.png')); ?>" width="1024" height="576" alt="投放数据趋势">
                            </div>
                        </div>
                        <button class="carousel-button next" id="carouselNext" type="button" aria-label="下一张">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                        <div class="carousel-dots" id="carouselDots"></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
