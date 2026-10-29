<?php
/**
 * Primary navigation helpers.
 *
 * @package Wuhanyingyuan
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render stable navigation when the menu has not been created yet.
 */
function yy_render_primary_fallback(): void
{
    $items = [
        ['投放平台', home_url('/#platforms')],
        ['服务内容', home_url('/#services')],
        ['合作流程', home_url('/#process')],
        ['案例展示', home_url('/#cases')],
        ['新闻资讯', home_url('/news/')],
        ['关于我们', home_url('/#about')],
        ['常见问题', home_url('/#faq')],
        ['联系我们', home_url('/#contact')],
    ];

    echo '<ul id="primaryNavigation" class="nav-links">';
    foreach ($items as $item) {
        $is_current = $item[1] === home_url('/news/') && (is_home() || is_singular('post') || is_category());
        printf(
            '<li><a href="%1$s"%2$s>%3$s</a></li>',
            esc_url($item[1]),
            $is_current ? ' aria-current="page"' : '',
            esc_html($item[0])
        );
    }
    echo '</ul>';
}

/**
 * Render the menu assigned to the primary location.
 */
function yy_render_primary_navigation(): void
{
    if (has_nav_menu('primary')) {
        wp_nav_menu([
            'theme_location' => 'primary',
            'container' => false,
            'menu_id' => 'primaryNavigation',
            'menu_class' => 'nav-links',
            'fallback_cb' => 'yy_render_primary_fallback',
            'depth' => 1,
        ]);
        return;
    }

    yy_render_primary_fallback();
}
