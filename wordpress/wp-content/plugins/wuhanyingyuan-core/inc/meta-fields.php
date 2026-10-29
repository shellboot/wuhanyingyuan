<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared and type-specific fields for structured homepage records.
 *
 * @return array<string, array<string, mixed>>
 */
function yy_meta_field_definitions(string $post_type = ''): array
{
    $order = [
        '_yy_order' => [
            'label' => '排序值',
            'type' => 'integer',
            'default' => 10,
            'help' => '数值越小越靠前。',
        ],
    ];

    $definitions = [
        'yy_platform' => [
            '_yy_english_name' => ['label' => '英文名称', 'type' => 'text'],
            '_yy_icon' => ['label' => '图标文字', 'type' => 'text', 'default' => '平台'],
            '_yy_icon_variant' => ['label' => '图标样式', 'type' => 'text', 'default' => 'jj', 'help' => '例如 jj、gdt、ks、bd、geo。'],
            '_yy_description' => ['label' => '平台说明', 'type' => 'textarea'],
            '_yy_metric_1_value' => ['label' => '指标 1 数值', 'type' => 'text'],
            '_yy_metric_1_label' => ['label' => '指标 1 名称', 'type' => 'text'],
            '_yy_metric_2_value' => ['label' => '指标 2 数值', 'type' => 'text'],
            '_yy_metric_2_label' => ['label' => '指标 2 名称', 'type' => 'text'],
        ],
        'yy_service' => [
            '_yy_icon' => ['label' => '图标', 'type' => 'text', 'default' => '◆'],
            '_yy_description' => ['label' => '服务说明', 'type' => 'textarea'],
        ],
        'yy_advantage' => [
            '_yy_icon' => ['label' => '图标', 'type' => 'text', 'default' => '◆'],
            '_yy_description' => ['label' => '优势说明', 'type' => 'textarea'],
        ],
        'yy_process' => [
            '_yy_description' => ['label' => '流程说明', 'type' => 'textarea'],
        ],
        'yy_case' => [
            '_yy_tag' => ['label' => '行业标签', 'type' => 'text'],
            '_yy_description' => ['label' => '案例说明', 'type' => 'textarea'],
            '_yy_icon' => ['label' => '无封面时的图标', 'type' => 'text', 'default' => '案'],
            '_yy_metric_1_value' => ['label' => '指标 1 数值', 'type' => 'text'],
            '_yy_metric_1_label' => ['label' => '指标 1 名称', 'type' => 'text'],
            '_yy_metric_2_value' => ['label' => '指标 2 数值', 'type' => 'text'],
            '_yy_metric_2_label' => ['label' => '指标 2 名称', 'type' => 'text'],
        ],
        'yy_faq' => [],
        'yy_carousel_image' => [
            '_yy_image_id' => ['label' => '轮播图片', 'type' => 'media'],
            '_yy_image_alt' => ['label' => '图片替代文本', 'type' => 'text'],
        ],
        'yy_stat' => [
            '_yy_metric_1_value' => ['label' => '展示数值', 'type' => 'text'],
            '_yy_metric_1_label' => ['label' => '数值说明', 'type' => 'text'],
        ],
    ];

    if ($post_type !== '') {
        return array_merge($order, $definitions[$post_type] ?? []);
    }

    return array_merge($order, ...array_values($definitions));
}

/**
 * Register post meta so the WordPress REST API and CLI can use the same schema.
 */
function yy_register_meta_fields(): void
{
    foreach (array_keys(yy_content_type_definitions()) as $post_type) {
        foreach (yy_meta_field_definitions($post_type) as $meta_key => $field) {
            $is_integer = in_array($field['type'], ['integer', 'media'], true);
            register_post_meta($post_type, $meta_key, [
                'type' => $is_integer ? 'integer' : 'string',
                'single' => true,
                'default' => $field['default'] ?? ($is_integer ? 0 : ''),
                'show_in_rest' => true,
                'sanitize_callback' => static function ($value) use ($field) {
                    return yy_sanitize_meta_value($value, $field['type']);
                },
                'auth_callback' => static function ($allowed, $meta_key, $object_id, $user_id, $cap, $caps) {
                    return current_user_can('edit_post', (int) $object_id);
                },
            ]);
        }
    }
}
add_action('init', 'yy_register_meta_fields', 10);

/**
 * Sanitize one meta value according to its declared field type.
 */
function yy_sanitize_meta_value(mixed $value, string $type): mixed
{
    return match ($type) {
        'integer', 'media' => absint($value),
        'textarea' => sanitize_textarea_field((string) $value),
        default => sanitize_text_field((string) $value),
    };
}

/**
 * Register one focused editor box for each structured content type.
 */
function yy_register_content_meta_boxes(): void
{
    foreach (yy_content_type_definitions() as $post_type => $definition) {
        add_meta_box(
            'yy-content-fields',
            $definition['singular'] . '设置',
            'yy_render_content_meta_box',
            $post_type,
            'normal',
            'high'
        );
    }
}
add_action('add_meta_boxes', 'yy_register_content_meta_boxes');

/**
 * Render structured fields with a nonce and field-level descriptions.
 */
function yy_render_content_meta_box(WP_Post $post): void
{
    $fields = yy_meta_field_definitions($post->post_type);
    wp_nonce_field('yy_save_content_meta', 'yy_content_meta_nonce');
    wp_enqueue_media();

    echo '<table class="form-table" role="presentation"><tbody>';
    foreach ($fields as $key => $field) {
        $value = get_post_meta($post->ID, $key, true);
        if ($value === '' && isset($field['default'])) {
            $value = $field['default'];
        }
        $id = 'yy-meta-' . sanitize_html_class($key);
        $name = 'yy_meta[' . esc_attr($key) . ']';

        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($field['label']) . '</label></th><td>';

        if ($field['type'] === 'textarea') {
            printf(
                '<textarea class="large-text" rows="4" id="%1$s" name="%2$s">%3$s</textarea>',
                esc_attr($id),
                esc_attr($name),
                esc_textarea((string) $value)
            );
        } elseif ($field['type'] === 'integer') {
            printf(
                '<input class="small-text" type="number" min="0" id="%1$s" name="%2$s" value="%3$s">',
                esc_attr($id),
                esc_attr($name),
                esc_attr((string) $value)
            );
        } elseif ($field['type'] === 'media') {
            $image_url = $value ? wp_get_attachment_image_url((int) $value, 'medium') : '';
            printf(
                '<input type="hidden" class="yy-media-id" id="%1$s" name="%2$s" value="%3$s">',
                esc_attr($id),
                esc_attr($name),
                esc_attr((string) $value)
            );
            echo '<div class="yy-media-preview" style="margin:8px 0">';
            if ($image_url) {
                printf('<img src="%s" alt="" style="max-width:240px;height:auto">', esc_url($image_url));
            }
            echo '</div><button type="button" class="button yy-media-select">选择图片</button> ';
            echo '<button type="button" class="button-link-delete yy-media-clear">清除</button>';
        } else {
            printf(
                '<input class="regular-text" type="text" id="%1$s" name="%2$s" value="%3$s">',
                esc_attr($id),
                esc_attr($name),
                esc_attr((string) $value)
            );
        }

        if (!empty($field['help'])) {
            echo '<p class="description">' . esc_html($field['help']) . '</p>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

/**
 * Persist structured fields after nonce, post type and capability checks.
 */
function yy_save_content_meta(int $post_id): void
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!isset($_POST['yy_content_meta_nonce']) || !wp_verify_nonce(
        sanitize_text_field(wp_unslash($_POST['yy_content_meta_nonce'])),
        'yy_save_content_meta'
    )) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $post_type = get_post_type($post_id);
    $fields = yy_meta_field_definitions($post_type);
    $submitted = isset($_POST['yy_meta']) && is_array($_POST['yy_meta'])
        ? wp_unslash($_POST['yy_meta'])
        : [];

    foreach ($fields as $key => $field) {
        $raw_value = $submitted[$key] ?? '';
        $value = yy_sanitize_meta_value($raw_value, $field['type']);
        update_post_meta($post_id, $key, $value);
    }
}
add_action('save_post', 'yy_save_content_meta');

/**
 * Enable the media picker for structured image fields.
 */
function yy_content_admin_assets(string $hook): void
{
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, array_keys(yy_content_type_definitions()), true)) {
        return;
    }

    wp_enqueue_media();
    wp_add_inline_script('media-editor', <<<'JS'
jQuery(function ($) {
  $(document).on('click', '.yy-media-select', function (event) {
    event.preventDefault();
    var wrap = $(this).closest('td');
    var frame = wp.media({ title: '选择图片', button: { text: '使用此图片' }, multiple: false });
    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      wrap.find('.yy-media-id').val(attachment.id);
      wrap.find('.yy-media-preview').html($('<img>', {
        src: (attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url),
        alt: '',
        css: { maxWidth: '240px', height: 'auto' }
      }));
    });
    frame.open();
  }).on('click', '.yy-media-clear', function (event) {
    event.preventDefault();
    var wrap = $(this).closest('td');
    wrap.find('.yy-media-id').val('');
    wrap.find('.yy-media-preview').empty();
  });
});
JS);
}
add_action('admin_enqueue_scripts', 'yy_content_admin_assets');
