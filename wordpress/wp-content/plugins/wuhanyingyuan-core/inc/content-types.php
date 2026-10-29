<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content types that compose the editable homepage.
 *
 * @return array<string, array<string, string>>
 */
function yy_content_type_definitions(): array
{
    return [
        'yy_platform' => [
            'singular' => '投放平台',
            'plural' => '投放平台',
            'icon' => 'dashicons-screenoptions',
        ],
        'yy_service' => [
            'singular' => '服务内容',
            'plural' => '服务内容',
            'icon' => 'dashicons-hammer',
        ],
        'yy_advantage' => [
            'singular' => '服务优势',
            'plural' => '服务优势',
            'icon' => 'dashicons-awards',
        ],
        'yy_process' => [
            'singular' => '合作流程',
            'plural' => '合作流程',
            'icon' => 'dashicons-editor-ol',
        ],
        'yy_case' => [
            'singular' => '客户案例',
            'plural' => '客户案例',
            'icon' => 'dashicons-portfolio',
        ],
        'yy_faq' => [
            'singular' => '常见问题',
            'plural' => '常见问题',
            'icon' => 'dashicons-editor-help',
        ],
        'yy_carousel_image' => [
            'singular' => '数据效果图',
            'plural' => '数据效果图',
            'icon' => 'dashicons-images-alt2',
        ],
        'yy_stat' => [
            'singular' => '成果数据',
            'plural' => '成果数据',
            'icon' => 'dashicons-chart-bar',
        ],
    ];
}

/**
 * Register the structured homepage content types.
 */
function yy_register_content_types(): void
{
    $position = 21;

    foreach (yy_content_type_definitions() as $post_type => $definition) {
        $supports = ['title'];
        if (in_array($post_type, ['yy_case', 'yy_carousel_image'], true)) {
            $supports[] = 'thumbnail';
        }
        if ($post_type === 'yy_faq') {
            $supports[] = 'editor';
        }

        register_post_type($post_type, [
            'labels' => [
                'name' => $definition['plural'],
                'singular_name' => $definition['singular'],
                'add_new_item' => '新增' . $definition['singular'],
                'edit_item' => '编辑' . $definition['singular'],
                'new_item' => '新增' . $definition['singular'],
                'view_item' => '查看' . $definition['singular'],
                'search_items' => '搜索' . $definition['plural'],
                'not_found' => '暂无' . $definition['plural'],
                'menu_name' => $definition['plural'],
            ],
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'menu_icon' => $definition['icon'],
            'menu_position' => $position++,
            'supports' => $supports,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'capability_type' => ['yy_homepage_item', 'yy_homepage_items'],
            'map_meta_cap' => true,
        ]);
    }
}
add_action('init', 'yy_register_content_types', 5);
