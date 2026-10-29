<?php
/**
 * WordPress posts page.
 *
 * @package Wuhanyingyuan
 */

get_header();
$page_id = absint(get_option('page_for_posts'));
$title = (string) yy_setting('news_archive_title', $page_id ? get_the_title($page_id) : '新闻资讯');
$description = (string) yy_setting('news_archive_description', '');
?>
<section class="page-hero">
    <div class="container">
        <span class="tag">NEWS &amp; INSIGHTS</span>
        <h1><?php echo esc_html($title); ?></h1>
        <?php if ($description !== '') : ?><p><?php echo esc_html($description); ?></p><?php endif; ?>
    </div>
</section>

<div class="news-page">
    <div class="container">
        <?php if (have_posts()) : ?>
            <div class="news-list">
                <?php while (have_posts()) : the_post(); ?>
                    <?php yy_render_news_card(get_the_ID()); ?>
                <?php endwhile; ?>
            </div>
            <?php
            the_posts_pagination([
                'mid_size' => 2,
                'prev_text' => '上一页',
                'next_text' => '下一页',
                'screen_reader_text' => '新闻分页',
                'class' => 'pagination',
            ]);
            ?>
        <?php else : ?>
            <p class="news-empty">暂无新闻内容。</p>
        <?php endif; ?>
    </div>
</div>
<?php get_footer(); ?>
