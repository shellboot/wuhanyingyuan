<?php
/**
 * Theme bootstrap.
 *
 * @package Wuhanyingyuan
 */

if (!defined('ABSPATH')) {
    exit;
}

define('YY_THEME_VERSION', '1.0.0');
define('YY_THEME_PATH', get_template_directory());
define('YY_THEME_URL', get_template_directory_uri());

require_once YY_THEME_PATH . '/inc/assets.php';
require_once YY_THEME_PATH . '/inc/navigation.php';
require_once YY_THEME_PATH . '/inc/template-helpers.php';
require_once YY_THEME_PATH . '/inc/seo.php';

/**
 * Set up theme features used by the public site and WordPress admin previews.
 */
function yy_theme_setup(): void
{
    load_theme_textdomain('wuhanyingyuan', YY_THEME_PATH . '/languages');

    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', [
        'height' => 112,
        'width' => 336,
        'flex-height' => true,
        'flex-width' => true,
    ]);
    add_theme_support('html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ]);
    add_theme_support('responsive-embeds');

    add_image_size('yy-news-card', 720, 405, true);
    add_image_size('yy-article-cover', 1200, 675, true);

    register_nav_menus([
        'primary' => '主导航',
    ]);
}
add_action('after_setup_theme', 'yy_theme_setup');

/**
 * Use a predictable content width for embedded media.
 */
function yy_theme_content_width(): void
{
    $GLOBALS['content_width'] = 1140;
}
add_action('after_setup_theme', 'yy_theme_content_width', 0);
