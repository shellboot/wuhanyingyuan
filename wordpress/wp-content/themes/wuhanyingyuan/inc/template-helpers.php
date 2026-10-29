<?php
/**
 * Shared template helpers.
 *
 * @package Wuhanyingyuan
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Query structured records by their explicit order field.
 */
function yy_theme_query_records(string $post_type, int $limit = -1): WP_Query
{
    return new WP_Query([
        'post_type' => $post_type,
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        'ignore_sticky_posts' => true,
        'no_found_rows' => true,
        'meta_query' => [
            'relation' => 'OR',
            'yy_order' => [
                'key' => '_yy_order',
                'compare' => 'EXISTS',
                'type' => 'NUMERIC',
            ],
            'yy_order_missing' => [
                'key' => '_yy_order',
                'compare' => 'NOT EXISTS',
            ],
        ],
        'orderby' => [
            'yy_order' => 'ASC',
            'date' => 'DESC',
        ],
    ]);
}

/**
 * Get a site or homepage setting through the core plugin when available.
 */
function yy_setting(string $key, mixed $default = ''): mixed
{
    return function_exists('yy_get_home_setting')
        ? yy_get_home_setting($key, $default)
        : $default;
}

/**
 * Get a global site setting through the core plugin when available.
 */
function yy_global_setting(string $key, mixed $default = ''): mixed
{
    return function_exists('yy_get_site_setting')
        ? yy_get_site_setting($key, $default)
        : $default;
}

/**
 * Render a plain-text setting as paragraph text without losing line breaks.
 */
function yy_render_setting_paragraphs(string $value): void
{
    $paragraphs = preg_split('/\R{2,}/u', trim($value)) ?: [];
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }
        echo '<p>' . nl2br(esc_html($paragraph)) . '</p>';
    }
}

/**
 * Return a local theme image URL, used for starter artwork.
 */
function yy_theme_image_url(string $relative_path): string
{
    return YY_THEME_URL . '/assets/' . ltrim($relative_path, '/');
}

/**
 * Render a configured image or a bundled fallback.
 */
function yy_render_setting_image(string $setting_key, string $fallback_path, array $attributes = []): void
{
    $image_id = absint(yy_global_setting($setting_key, 0));
    if ($image_id) {
        echo wp_get_attachment_image($image_id, 'full', false, $attributes);
        return;
    }

    $attributes['src'] = yy_theme_image_url($fallback_path);
    $attributes['alt'] = $attributes['alt'] ?? '';
    echo '<img';
    foreach ($attributes as $name => $value) {
        printf(' %1$s="%2$s"', esc_attr($name), esc_attr((string) $value));
    }
    echo '>';
}

/**
 * Return the first category label for a news post.
 */
function yy_primary_category_label(int $post_id): string
{
    $categories = get_the_category($post_id);
    return $categories ? (string) $categories[0]->name : '新闻资讯';
}

/**
 * Trim text for cards while respecting the site charset.
 */
function yy_excerpt_text(int $post_id, int $length = 90): string
{
    $excerpt = (string) get_the_excerpt($post_id);
    if ($excerpt === '') {
        $excerpt = (string) get_post_field('post_content', $post_id);
    }

    return wp_html_excerpt(wp_strip_all_tags($excerpt), $length, '…');
}

/**
 * Render one news card for the homepage and archive.
 */
function yy_render_news_card(int $post_id, bool $show_excerpt = true): void
{
    $permalink = get_permalink($post_id);
    $title = get_the_title($post_id);
    $fallback = function_exists('mb_substr')
        ? mb_substr($title, 0, 2, get_bloginfo('charset'))
        : substr($title, 0, 2);
    $thumbnail = get_the_post_thumbnail($post_id, 'yy-news-card', [
        'loading' => 'lazy',
        'decoding' => 'async',
        'alt' => $title,
    ]);
    ?>
    <article class="news-card">
        <a href="<?php echo esc_url($permalink); ?>">
            <div class="news-cover">
                <?php if ($thumbnail) : ?>
                    <?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php else : ?>
                    <span class="news-cover-fallback" aria-hidden="true"><?php echo esc_html($fallback); ?></span>
                <?php endif; ?>
            </div>
            <div class="news-body">
                <span class="news-tag"><?php echo esc_html(yy_primary_category_label($post_id)); ?></span>
                <h3><?php echo esc_html($title); ?></h3>
                <?php if ($show_excerpt) : ?>
                    <p><?php echo esc_html(yy_excerpt_text($post_id)); ?></p>
                <?php endif; ?>
                <div class="news-meta">
                    <time datetime="<?php echo esc_attr(get_the_date(DATE_W3C, $post_id)); ?>">
                        <?php echo esc_html(get_the_date('Y-m-d', $post_id)); ?>
                    </time>
                </div>
            </div>
        </a>
    </article>
    <?php
}

/**
 * Render a section heading from editable homepage copy.
 */
function yy_render_section_heading(string $tagline_key, string $title_key, string $subtitle_key, string $title_id): void
{
    ?>
    <header class="section-title">
        <?php if ((string) yy_setting($tagline_key) !== '') : ?>
            <span class="tag"><?php echo esc_html((string) yy_setting($tagline_key)); ?></span>
        <?php endif; ?>
        <h2 id="<?php echo esc_attr($title_id); ?>"><?php echo esc_html((string) yy_setting($title_key)); ?></h2>
        <?php if ((string) yy_setting($subtitle_key) !== '') : ?>
            <p><?php echo esc_html((string) yy_setting($subtitle_key)); ?></p>
        <?php endif; ?>
    </header>
    <?php
}

/**
 * Render one structured icon as a compact visual mark.
 */
function yy_render_record_icon(int $post_id, string $fallback = '◆'): void
{
    $icon = (string) get_post_meta($post_id, '_yy_icon', true);
    echo '<span class="icon-text" aria-hidden="true">' . esc_html($icon !== '' ? $icon : $fallback) . '</span>';
}

/**
 * Return the featured image or configured carousel image for a record.
 */
function yy_record_image_id(int $post_id): int
{
    $candidates = [
        absint(get_post_meta($post_id, '_yy_image_id', true)),
        absint(get_post_thumbnail_id($post_id)),
    ];

    foreach ($candidates as $image_id) {
        if ($image_id && wp_attachment_is_image($image_id)) {
            return $image_id;
        }
    }

    return 0;
}
