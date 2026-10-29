<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add useful structured-content columns without taking over standard post lists.
 */
function yy_register_admin_columns(): void
{
    foreach (array_keys(yy_content_type_definitions()) as $post_type) {
        add_filter("manage_{$post_type}_posts_columns", 'yy_content_admin_columns');
        add_action("manage_{$post_type}_posts_custom_column", 'yy_render_content_admin_column', 10, 2);
        add_filter("manage_edit-{$post_type}_sortable_columns", 'yy_content_sortable_columns');
    }
}
add_action('admin_init', 'yy_register_admin_columns');

/**
 * @param array<string, string> $columns
 * @return array<string, string>
 */
function yy_content_admin_columns(array $columns): array
{
    $updated = [];
    foreach ($columns as $key => $label) {
        $updated[$key] = $label;
        if ($key === 'title') {
            $updated['yy_thumbnail'] = '图片';
            $updated['yy_order'] = '排序';
            $updated['yy_summary'] = '内容摘要';
        }
    }
    return $updated;
}

function yy_render_content_admin_column(string $column, int $post_id): void
{
    if ($column === 'yy_order') {
        echo esc_html((string) get_post_meta($post_id, '_yy_order', true));
        return;
    }

    if ($column === 'yy_thumbnail') {
        $image_id = (int) get_post_meta($post_id, '_yy_image_id', true);
        if (!$image_id) {
            $image_id = (int) get_post_thumbnail_id($post_id);
        }
        if ($image_id) {
            echo wp_get_attachment_image($image_id, [64, 64], false, ['style' => 'width:64px;height:64px;object-fit:cover;border-radius:4px']);
        } else {
            echo '—';
        }
        return;
    }

    if ($column === 'yy_summary') {
        $summary = (string) get_post_meta($post_id, '_yy_description', true);
        if ($summary === '') {
            $summary = wp_strip_all_tags((string) get_post_field('post_content', $post_id));
        }
        echo esc_html(wp_html_excerpt($summary, 70, '…'));
    }
}

/**
 * @param array<string, string> $columns
 * @return array<string, string>
 */
function yy_content_sortable_columns(array $columns): array
{
    $columns['yy_order'] = 'yy_order';
    return $columns;
}

/**
 * Sort structured records by the explicit numeric order field.
 */
function yy_content_admin_order(WP_Query $query): void
{
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    if ($query->get('orderby') !== 'yy_order') {
        return;
    }

    $query->set('meta_key', '_yy_order');
    $query->set('orderby', 'meta_value_num');
    $query->set('order', strtoupper((string) $query->get('order')) === 'DESC' ? 'DESC' : 'ASC');
}
add_action('pre_get_posts', 'yy_content_admin_order');
