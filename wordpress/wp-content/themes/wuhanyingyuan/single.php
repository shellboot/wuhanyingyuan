<?php
/**
 * Single post template.
 *
 * @package Wuhanyingyuan
 */

get_header();
?>
<div class="article-page">
    <div class="container">
        <?php while (have_posts()) : the_post(); ?>
            <article class="single-article">
                <nav class="article-breadcrumb" aria-label="面包屑">
                    <a href="<?php echo esc_url(home_url('/')); ?>">首页</a>
                    <span>/</span>
                    <a href="<?php echo esc_url((string) get_permalink((int) get_option('page_for_posts'))); ?>">新闻资讯</a>
                </nav>
                <header class="article-head">
                    <span class="news-tag"><?php echo esc_html(yy_primary_category_label(get_the_ID())); ?></span>
                    <h1><?php the_title(); ?></h1>
                    <div class="article-meta">
                        <time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>"><?php echo esc_html(get_the_date('Y-m-d')); ?></time>
                        <span aria-hidden="true">·</span>
                        <span><?php the_author(); ?></span>
                    </div>
                </header>
                <?php if (has_post_thumbnail()) : ?>
                    <div class="article-cover"><?php the_post_thumbnail('yy-article-cover', ['loading' => 'eager']); ?></div>
                <?php endif; ?>
                <div class="article-content"><?php the_content(); ?></div>
                <footer class="article-footer">
                    <?php
                    the_post_navigation([
                        'prev_text' => '<span>上一篇</span>%title',
                        'next_text' => '<span>下一篇</span>%title',
                    ]);
                    ?>
                    <a class="article-back" href="<?php echo esc_url((string) get_permalink((int) get_option('page_for_posts'))); ?>">← 返回新闻列表</a>
                </footer>
            </article>
        <?php endwhile; ?>
    </div>
</div>
<?php get_footer(); ?>
