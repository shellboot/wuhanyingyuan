<?php
/**
 * Required fallback archive template.
 *
 * @package Wuhanyingyuan
 */

get_header();
?>
<section class="page-hero">
    <div class="container">
        <h1><?php echo esc_html(is_home() ? '新闻资讯' : wp_get_document_title()); ?></h1>
    </div>
</section>
<div class="news-page">
    <div class="container">
        <?php if (have_posts()) : ?>
            <div class="news-list">
                <?php while (have_posts()) : the_post(); ?>
                    <?php if (get_post_type() === 'post') : ?>
                        <?php yy_render_news_card(get_the_ID()); ?>
                    <?php else : ?>
                        <article class="generic-card"><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php the_excerpt(); ?></article>
                    <?php endif; ?>
                <?php endwhile; ?>
            </div>
            <?php the_posts_pagination(['prev_text' => '上一页', 'next_text' => '下一页']); ?>
        <?php else : ?>
            <p class="news-empty">暂无内容。</p>
        <?php endif; ?>
    </div>
</div>
<?php get_footer(); ?>
