<?php
/**
 * Latest published news.
 *
 * @package Wuhanyingyuan
 */

$latest_news = new WP_Query([
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => 3,
    'ignore_sticky_posts' => true,
    'no_found_rows' => true,
]);
if (!$latest_news->have_posts()) {
    wp_reset_postdata();
    return;
}
$news_page_url = get_option('page_for_posts') ? get_permalink((int) get_option('page_for_posts')) : home_url('/news/');
?>
<section class="section news-section" id="news" aria-labelledby="newsTitle">
    <div class="container">
        <?php yy_render_section_heading('news_tagline', 'news_title', 'news_subtitle', 'newsTitle'); ?>
        <div class="news-grid">
            <?php while ($latest_news->have_posts()) : $latest_news->the_post(); ?>
                <?php yy_render_news_card(get_the_ID()); ?>
            <?php endwhile; ?>
        </div>
        <div class="news-more">
            <a class="btn btn-outline" href="<?php echo esc_url((string) $news_page_url); ?>">查看全部资讯</a>
        </div>
    </div>
</section>
<?php wp_reset_postdata(); ?>
