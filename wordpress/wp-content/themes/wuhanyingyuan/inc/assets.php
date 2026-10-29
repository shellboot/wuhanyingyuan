<?php
/**
 * Front-end assets.
 *
 * @package Wuhanyingyuan
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version an asset using its modification time so deploys invalidate caches.
 */
function yy_theme_asset_version(string $relative_path): string
{
    $file = YY_THEME_PATH . '/assets/' . ltrim($relative_path, '/');
    return file_exists($file) ? (string) filemtime($file) : YY_THEME_VERSION;
}

/**
 * Enqueue the visual shell and the interaction script.
 */
function yy_enqueue_theme_assets(): void
{
    wp_enqueue_style(
        'yy-base',
        YY_THEME_URL . '/assets/css/styles.css',
        [],
        yy_theme_asset_version('css/styles.css')
    );
    wp_enqueue_style(
        'yy-news',
        YY_THEME_URL . '/assets/css/news.css',
        ['yy-base'],
        yy_theme_asset_version('css/news.css')
    );
    wp_enqueue_style(
        'yy-wordpress',
        YY_THEME_URL . '/assets/css/wordpress.css',
        ['yy-news'],
        yy_theme_asset_version('css/wordpress.css')
    );
    wp_enqueue_script(
        'yy-main',
        YY_THEME_URL . '/assets/js/main.js',
        [],
        yy_theme_asset_version('js/main.js'),
        true
    );
}
add_action('wp_enqueue_scripts', 'yy_enqueue_theme_assets');

/**
 * Keep the public site free of WordPress's default emoji script.
 */
function yy_disable_emoji_assets(): void
{
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
}
add_action('init', 'yy_disable_emoji_assets');
