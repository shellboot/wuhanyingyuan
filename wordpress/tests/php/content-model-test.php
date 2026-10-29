<?php
/**
 * Run with:
 * docker compose run --rm wpcli wp eval-file tests/php/content-model-test.php
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This test must run through WP-CLI.\n");
    exit(1);
}

$failures = [];
$created_posts = [];
$created_attachments = [];
$created_terms = [];
$legacy_test_dir = '';
$site_settings_backup = get_option('yy_site_settings', null);
$home_settings_backup = get_option('yy_home_settings', null);

/**
 * Record an assertion without aborting the remaining checks.
 */
function yy_content_model_assert(bool $condition, string $message): void
{
    global $failures;

    if (!$condition) {
        $failures[] = $message;
    }
}

/**
 * Restore one option to its exact pre-test state.
 */
function yy_content_model_restore_option(string $option_name, mixed $backup): void
{
    if ($backup === null) {
        delete_option($option_name);
        return;
    }

    update_option($option_name, $backup);
}

try {
    $expected_post_types = [
        'yy_platform',
        'yy_service',
        'yy_advantage',
        'yy_process',
        'yy_case',
        'yy_faq',
        'yy_carousel_image',
        'yy_stat',
    ];

    foreach ($expected_post_types as $post_type) {
        $object = get_post_type_object($post_type);
        yy_content_model_assert(
            $object instanceof WP_Post_Type,
            sprintf('Expected custom post type %s to be registered.', $post_type)
        );
        yy_content_model_assert(
            !$object || $object->show_ui,
            sprintf('Expected custom post type %s to be editable in wp-admin.', $post_type)
        );
        yy_content_model_assert(
            !$object || $object->show_in_rest,
            sprintf('Expected custom post type %s to be available through REST.', $post_type)
        );
    }

    $news_type = get_post_type_object('post');
    yy_content_model_assert(
        $news_type instanceof WP_Post_Type,
        'Native WordPress post type must remain available for news.'
    );
    yy_content_model_assert(
        !in_array('post', $expected_post_types, true),
        'News must not be replaced by a custom post type.'
    );

    foreach ($expected_post_types as $post_type) {
        $registered_meta = get_registered_meta_keys('post', $post_type);
        $order_schema = $registered_meta['_yy_order'] ?? null;
        yy_content_model_assert(
            is_array($order_schema),
            sprintf('Expected %s to register the shared _yy_order field.', $post_type)
        );
        yy_content_model_assert(
            ($order_schema['type'] ?? null) === 'integer',
            sprintf('Expected %s _yy_order to use the integer schema.', $post_type)
        );
        yy_content_model_assert(
            ($order_schema['single'] ?? null) === true,
            sprintf('Expected %s _yy_order to be a single value.', $post_type)
        );
    }
    $carousel_meta = get_registered_meta_keys('post', 'yy_carousel_image');
    yy_content_model_assert(
        ($carousel_meta['_yy_image_id']['type'] ?? null) === 'integer',
        'Expected the carousel attachment ID meta to use the integer schema.'
    );

    $ordered_values = [
        'later' => 30,
        'first' => 5,
        'middle' => 15,
    ];
    foreach ($ordered_values as $seed => $order) {
        $post_id = wp_insert_post([
            'post_type' => 'yy_platform',
            'post_status' => 'publish',
            'post_title' => 'YY content model test ' . $seed,
            'meta_input' => [
                '_yy_order' => $order,
                '_yy_description' => 'temporary test record',
            ],
        ], true);
        yy_content_model_assert(
            !is_wp_error($post_id) && $post_id > 0,
            sprintf('Could not create the temporary %s ordering record.', $seed)
        );
        if (!is_wp_error($post_id) && $post_id > 0) {
            $created_posts[] = (int) $post_id;
            update_post_meta((int) $post_id, '_yy_order', $order);
        }
    }

    $sorted_ids = get_posts([
        'post_type' => 'yy_platform',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => '_yy_order',
        'orderby' => [
            'meta_value_num' => 'ASC',
            'ID' => 'ASC',
        ],
        'post__in' => $created_posts,
        'ignore_sticky_posts' => true,
    ]);
    $sorted_orders = array_map(
        static fn (int $post_id): int => (int) get_post_meta($post_id, '_yy_order', true),
        $sorted_ids
    );
    yy_content_model_assert(
        $sorted_orders === [5, 15, 30],
        'Expected _yy_order records to sort numerically in ascending order.'
    );

    $legacy_token = strtolower(wp_generate_password(8, false));
    $legacy_test_dir = trailingslashit(get_temp_dir()) . 'yy-legacy-' . $legacy_token;
    $legacy_uploads = $legacy_test_dir . '/uploads';
    $legacy_category_name = '迁移测试分类 ' . $legacy_token;
    $legacy_category_slug = 'legacy-test-' . $legacy_token;
    wp_mkdir_p($legacy_uploads);
    $legacy_image = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl9sAAAAASUVORK5CYII=',
        true
    );
    yy_content_model_assert(
        is_string($legacy_image) && $legacy_image !== '',
        'Could not decode the legacy PNG fixture.'
    );
    yy_content_model_assert(
        $legacy_image !== false
            && file_put_contents($legacy_uploads . '/sample.png', $legacy_image) !== false,
        'Could not create the legacy upload fixture.'
    );

    $legacy_published_id = 'legacy-published-' . $legacy_token;
    $legacy_published = yy_import_legacy_article([
        'id' => $legacy_published_id,
        'title' => '旧站已发布文章',
        'content' => '<p>安全正文</p><script>alert("xss")</script><img src="/uploads/sample.png">',
        'summary' => '旧站摘要',
        'published' => true,
        'category' => $legacy_category_name,
        'cover' => '/uploads/sample.png',
        'createdAt' => '2025-01-02T03:04:05+08:00',
    ], $legacy_uploads);
    if ($legacy_published['id']) {
        $created_posts[] = $legacy_published['id'];
    }
    yy_content_model_assert(
        $legacy_published['status'] === 'created',
        'Expected the first legacy import to create one article.'
    );
    yy_content_model_assert(
        $legacy_published['id'] > 0
            && get_post_status($legacy_published['id']) === 'publish',
        'Expected a published legacy article to import as published.'
    );
    $legacy_content = (string) get_post_field('post_content', $legacy_published['id']);
    yy_content_model_assert(
        !str_contains($legacy_content, '<script'),
        'Expected legacy script markup to be removed by wp_kses_post().'
    );
    yy_content_model_assert(
        get_post_meta($legacy_published['id'], '_yy_legacy_id', true) === $legacy_published_id,
        'Expected the legacy article ID to be stored for redirects and idempotent imports.'
    );
    $legacy_thumbnail_id = absint(get_post_thumbnail_id($legacy_published['id']));
    if ($legacy_thumbnail_id) {
        $created_attachments[] = $legacy_thumbnail_id;
    }
    yy_content_model_assert(
        $legacy_thumbnail_id > 0 && wp_attachment_is_image($legacy_thumbnail_id),
        'Expected the legacy cover image to be imported into the media library.'
    );
    yy_content_model_assert(
        str_contains($legacy_content, '/wp-content/uploads/'),
        'Expected a local legacy image URL to be rewritten to the media library URL.'
    );
    $legacy_term = get_term_by('slug', $legacy_category_slug, 'category');
    if ($legacy_term instanceof WP_Term) {
        $created_terms[] = (int) $legacy_term->term_id;
    }
    yy_content_model_assert(
        $legacy_term instanceof WP_Term
            && has_category((int) $legacy_term->term_id, $legacy_published['id']),
        'Expected the legacy category to be created and assigned.'
    );

    $legacy_updated = yy_import_legacy_article([
        'id' => $legacy_published_id,
        'title' => '旧站已发布文章（更新）',
        'content' => '<p>更新后的正文</p>',
        'summary' => '更新后的摘要',
        'published' => true,
        'category' => $legacy_category_name,
        'createdAt' => '2025-01-02T03:04:05+08:00',
    ], $legacy_uploads);
    yy_content_model_assert(
        $legacy_updated['status'] === 'updated'
            && $legacy_updated['id'] === $legacy_published['id'],
        'Expected a repeated legacy import to update the existing article.'
    );
    yy_content_model_assert(
        get_the_title($legacy_updated['id']) === '旧站已发布文章（更新）',
        'Expected the repeated import to update article fields.'
    );
    $legacy_matches = get_posts([
        'post_type' => 'post',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => '_yy_legacy_id',
        'meta_value' => $legacy_published_id,
    ]);
    yy_content_model_assert(
        count($legacy_matches) === 1,
        'Expected repeated imports to create exactly one article per legacy ID.'
    );

    $missing_legacy_image = '/uploads/missing-legacy-' . $legacy_token . '.png';
    $legacy_missing_image = yy_import_legacy_article([
        'id' => 'legacy-missing-image-' . $legacy_token,
        'title' => '旧站缺图文章',
        'content' => '<p>正文保留</p><img src="' . $missing_legacy_image . '">',
        'published' => true,
        'category' => $legacy_category_name,
        'cover' => $missing_legacy_image,
        'createdAt' => '2025-01-04T03:04:05+08:00',
    ], $legacy_uploads);
    if ($legacy_missing_image['id']) {
        $created_posts[] = $legacy_missing_image['id'];
    }
    yy_content_model_assert(
        $legacy_missing_image['status'] === 'created',
        'Expected an article with missing legacy images to still import.'
    );
    $missing_image_content = (string) get_post_field('post_content', $legacy_missing_image['id']);
    yy_content_model_assert(
        str_contains($missing_image_content, $missing_legacy_image),
        'Expected an unavailable legacy image URL to remain in the article body.'
    );
    yy_content_model_assert(
        absint(get_post_thumbnail_id($legacy_missing_image['id'])) === 0,
        'Expected an unavailable legacy cover image not to create a thumbnail.'
    );
    yy_content_model_assert(
        count($legacy_missing_image['warnings'] ?? []) === 2,
        'Expected missing body and cover images to return two import warnings.'
    );

    $legacy_draft = yy_import_legacy_article([
        'id' => 'legacy-draft-' . $legacy_token,
        'title' => '旧站草稿',
        'content' => '<p>草稿正文</p>',
        'published' => false,
        'category' => $legacy_category_name,
        'createdAt' => '2025-01-03T03:04:05+08:00',
    ], $legacy_uploads);
    if ($legacy_draft['id']) {
        $created_posts[] = $legacy_draft['id'];
    }
    yy_content_model_assert(
        $legacy_draft['id'] > 0
            && get_post_status($legacy_draft['id']) === 'draft',
        'Expected an unpublished legacy article to import as a draft.'
    );

    $site_defaults = yy_site_setting_defaults();
    foreach (['brand_name', 'phone', 'work_hours', 'wechat_qr_id'] as $key) {
        yy_content_model_assert(
            array_key_exists($key, $site_defaults),
            sprintf('Expected site setting default %s.', $key)
        );
    }

    $home_defaults = yy_home_setting_defaults();
    foreach ([
        'hero_title',
        'hero_description',
        'hero_primary_text',
        'hero_secondary_text',
        'platforms_title',
        'services_title',
        'advantages_title',
        'process_title',
        'cases_title',
        'news_title',
        'faq_title',
        'contact_title',
    ] as $key) {
        yy_content_model_assert(
            array_key_exists($key, $home_defaults),
            sprintf('Expected homepage setting default %s.', $key)
        );
    }

    update_option('yy_site_settings', array_merge($site_defaults, [
        'brand_name' => '盈圆内容模型测试',
        'phone' => '18107552669',
        'wechat_qr_id' => 42,
    ]));
    update_option('yy_home_settings', array_merge($home_defaults, [
        'hero_title' => '内容模型测试标题',
        'hero_primary_text' => '测试按钮',
    ]));
    yy_content_model_assert(
        yy_get_site_setting('brand_name') === '盈圆内容模型测试',
        'yy_get_site_setting() did not return the saved value.'
    );
    yy_content_model_assert(
        yy_get_site_setting('wechat_qr_id') === 42,
        'yy_get_site_setting() did not preserve the integer attachment ID.'
    );
    yy_content_model_assert(
        yy_get_home_setting('hero_title') === '内容模型测试标题',
        'yy_get_home_setting() did not return the saved value.'
    );
    yy_content_model_assert(
        yy_get_site_setting('not_a_setting', 'fallback') === 'fallback',
        'Setting readers must return the supplied fallback for unknown keys.'
    );
} finally {
    foreach ($created_posts as $post_id) {
        wp_delete_post($post_id, true);
    }
    foreach ($created_attachments as $attachment_id) {
        wp_delete_attachment($attachment_id, true);
    }
    foreach ($created_terms as $term_id) {
        wp_delete_term($term_id, 'category');
    }
    if ($legacy_test_dir !== '' && is_dir($legacy_test_dir)) {
        $legacy_files = glob($legacy_test_dir . '/uploads/*') ?: [];
        foreach ($legacy_files as $legacy_file) {
            if (is_file($legacy_file)) {
                wp_delete_file($legacy_file);
            }
        }
        @rmdir($legacy_test_dir . '/uploads');
        @rmdir($legacy_test_dir);
    }
    yy_content_model_restore_option('yy_site_settings', $site_settings_backup);
    yy_content_model_restore_option('yy_home_settings', $home_settings_backup);
}

if ($failures) {
    WP_CLI::error("Content model test failed:\n- " . implode("\n- ", $failures));
}

WP_CLI::success('Content model test passed.');
