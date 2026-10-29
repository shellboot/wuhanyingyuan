<?php
/**
 * Not-found template.
 *
 * @package Wuhanyingyuan
 */

get_header();
?>
<section class="not-found">
    <div class="container">
        <span class="error-code" aria-hidden="true">404</span>
        <h1>页面没有找到</h1>
        <p>链接可能已经失效，或者内容已经移动。</p>
        <div class="hero-actions">
            <a class="btn" href="<?php echo esc_url(home_url('/')); ?>">返回首页</a>
            <a class="btn btn-outline" href="<?php echo esc_url((string) get_permalink((int) get_option('page_for_posts'))); ?>">浏览新闻</a>
        </div>
    </div>
</section>
<?php get_footer(); ?>
