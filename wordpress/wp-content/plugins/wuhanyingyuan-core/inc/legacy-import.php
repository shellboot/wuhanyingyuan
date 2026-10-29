<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Preserve inbound links from the retired static and Node.js site.
 */
function yy_handle_legacy_redirects(): void
{
    if (is_admin()) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI'])
        ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
        : '/';
    $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);

    if ($path === '/news.html') {
        wp_safe_redirect(home_url('/news/'), 301);
        exit;
    }

    if ($path === '/article.html') {
        $legacy_id = isset($_GET['id'])
            ? sanitize_text_field(wp_unslash($_GET['id']))
            : '';
        if ($legacy_id !== '') {
            $posts = get_posts([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_yy_legacy_id',
                'meta_value' => $legacy_id,
            ]);
            if ($posts) {
                $target = get_permalink((int) $posts[0]);
                if ($target) {
                    wp_safe_redirect($target, 301);
                    exit;
                }
            }
        }

        wp_safe_redirect(home_url('/news/'), 301);
        exit;
    }

    if ($path === '/admin.html') {
        wp_safe_redirect(admin_url(), 301);
        exit;
    }
}
add_action('template_redirect', 'yy_handle_legacy_redirects', 1);

/**
 * Find an attachment imported from one legacy source.
 */
function yy_find_legacy_attachment(string $source): int
{
    $posts = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_key' => '_yy_legacy_asset',
        'meta_value' => $source,
    ]);

    return $posts ? (int) $posts[0] : 0;
}

/**
 * Import one legacy local upload into the WordPress media library.
 */
function yy_import_legacy_upload(string $source_url, string $uploads_path): int
{
    $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
    if (!str_starts_with($path, '/uploads/')) {
        return 0;
    }

    $relative = ltrim(substr($path, strlen('/uploads/')), '/');
    $source = realpath($uploads_path . DIRECTORY_SEPARATOR . $relative);
    $root = realpath($uploads_path);
    if (!$source || !$root || !str_starts_with($source, $root . DIRECTORY_SEPARATOR) || !is_readable($source)) {
        return 0;
    }

    $existing = yy_find_legacy_attachment($source_url);
    if ($existing) {
        return $existing;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $contents = file_get_contents($source);
    if ($contents === false) {
        return 0;
    }

    $upload = yy_upload_binary_asset($contents, wp_basename($source));
    if (is_wp_error($upload)) {
        return 0;
    }

    $attachment_id = wp_insert_attachment([
        'post_mime_type' => $upload['type'],
        'post_title' => pathinfo(wp_basename($source), PATHINFO_FILENAME),
        'post_status' => 'inherit',
    ], $upload['file']);
    if (is_wp_error($attachment_id) || !$attachment_id) {
        return 0;
    }

    wp_update_attachment_metadata(
        $attachment_id,
        wp_generate_attachment_metadata($attachment_id, $upload['file'])
    );
    update_post_meta($attachment_id, '_yy_legacy_asset', $source_url);

    return (int) $attachment_id;
}

/**
 * Rewrite local legacy image URLs to imported attachment URLs.
 *
 * @param list<string> $warnings
 */
function yy_rewrite_legacy_content_images(string $content, string $uploads_path, array &$warnings): string
{
    return (string) preg_replace_callback(
        '/(<img\b[^>]*\bsrc=)(["\'])([^"\']+)\2/i',
        static function (array $matches) use ($uploads_path, &$warnings): string {
            $source = html_entity_decode($matches[3], ENT_QUOTES, get_bloginfo('charset'));
            $path = (string) wp_parse_url($source, PHP_URL_PATH);
            if (!str_starts_with($path, '/uploads/')) {
                return $matches[0];
            }

            $attachment_id = yy_import_legacy_upload($source, $uploads_path);
            $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
            if (!$url) {
                $warnings[] = '正文旧图导入失败，已保留原 URL：' . sanitize_text_field($source);
            }
            return $matches[1] . $matches[2] . ($url ?: $matches[3]) . $matches[2];
        },
        $content
    );
}

/**
 * Convert old timestamps into a WordPress date string.
 */
function yy_legacy_post_date(mixed $value): string
{
    if (is_numeric($value)) {
        $timestamp = (float) $value;
        if ($timestamp > 100000000000) {
            $timestamp /= 1000;
        }
    } else {
        $timestamp = strtotime((string) $value);
    }

    return $timestamp ? wp_date('Y-m-d H:i:s', (int) $timestamp) : current_time('mysql');
}

/**
 * Import or update one legacy article by its stable old ID.
 *
 * @param array<string, mixed> $article
 * @return array{id: int, legacy_id: string, status: string, warnings: list<string>}
 */
function yy_import_legacy_article(array $article, string $uploads_path): array
{
    $legacy_id = sanitize_text_field((string) ($article['id'] ?? ''));
    $title = sanitize_text_field((string) ($article['title'] ?? ''));
    if ($legacy_id === '' || $title === '') {
        return ['id' => 0, 'legacy_id' => $legacy_id, 'status' => 'invalid', 'warnings' => []];
    }

    $matches = get_posts([
        'post_type' => 'post',
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_key' => '_yy_legacy_id',
        'meta_value' => $legacy_id,
    ]);
    $post_id = $matches ? (int) $matches[0] : 0;

    $warnings = [];
    $content = yy_rewrite_legacy_content_images(
        (string) ($article['content'] ?? ''),
        $uploads_path,
        $warnings
    );
    $status = !empty($article['published']) ? 'publish' : 'draft';
    $post_data = [
        'post_type' => 'post',
        'post_title' => $title,
        'post_content' => wp_kses_post($content),
        'post_excerpt' => sanitize_text_field((string) ($article['summary'] ?? '')),
        'post_status' => $status,
        'post_date' => yy_legacy_post_date($article['createdAt'] ?? ''),
    ];
    if ($post_id) {
        $post_data['ID'] = $post_id;
        $result = wp_update_post($post_data, true);
    } else {
        $result = wp_insert_post($post_data, true);
    }
    if (is_wp_error($result)) {
        return ['id' => 0, 'legacy_id' => $legacy_id, 'status' => 'failed', 'warnings' => $warnings];
    }

    $post_id = (int) $result;
    update_post_meta($post_id, '_yy_legacy_id', $legacy_id);

    $category_name = sanitize_text_field((string) ($article['category'] ?? '行业动态'));
    if ($category_name === '') {
        $category_name = '行业动态';
    }
    $category_id = yy_ensure_category($category_name, sanitize_title($category_name));
    if ($category_id) {
        wp_set_post_categories($post_id, [$category_id], false);
    }

    $cover = sanitize_text_field((string) ($article['cover'] ?? ''));
    if ($cover !== '') {
        $attachment_id = yy_import_legacy_upload($cover, $uploads_path);
        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
        } else {
            $warnings[] = '封面旧图导入失败，未设置特色图片：' . sanitize_text_field($cover);
        }
    }

    return [
        'id' => $post_id,
        'legacy_id' => $legacy_id,
        'status' => $matches ? 'updated' : 'created',
        'warnings' => $warnings,
    ];
}

/**
 * WP-CLI: wp yy import-legacy --articles=<path> [--uploads=<path>].
 */
function yy_cli_import_legacy(array $args, array $assoc_args): void
{
    $articles_path = isset($assoc_args['articles'])
        ? (string) $assoc_args['articles']
        : '';
    if ($articles_path === '' || !is_readable($articles_path)) {
        WP_CLI::error('请用 --articles 指定可读取的旧文章 JSON 文件。');
    }

    $uploads_path = isset($assoc_args['uploads'])
        ? (string) $assoc_args['uploads']
        : dirname($articles_path) . '/uploads';
    $json = file_get_contents($articles_path);
    $items = json_decode((string) $json, true);
    if (!is_array($items)) {
        WP_CLI::error('旧文章 JSON 不是有效数组。');
    }

    $counts = ['created' => 0, 'updated' => 0, 'invalid' => 0, 'failed' => 0];
    $warning_count = 0;
    foreach ($items as $article) {
        if (!is_array($article)) {
            $counts['invalid']++;
            continue;
        }
        $result = yy_import_legacy_article($article, $uploads_path);
        $counts[$result['status']]++;
        foreach ($result['warnings'] as $warning) {
            $warning_count++;
            WP_CLI::warning(sprintf('legacy #%s: %s', $result['legacy_id'], $warning));
        }
        if ($result['id']) {
            WP_CLI::log(sprintf(
                '%s legacy #%s -> WordPress #%d',
                $result['status'],
                $result['legacy_id'],
                $result['id']
            ));
        }
    }

    $counts['warnings'] = $warning_count;
    WP_CLI::success('旧文章导入完成：' . wp_json_encode($counts, JSON_UNESCAPED_UNICODE));
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('yy import-legacy', 'yy_cli_import_legacy');
}
