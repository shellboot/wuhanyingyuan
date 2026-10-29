<?php
/**
 * Public SEO metadata and structured data.
 *
 * @package Wuhanyingyuan
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build the canonical URL for the current public request.
 */
function yy_seo_canonical_url(): string
{
    if (is_front_page()) {
        $url = home_url('/');
    } elseif (is_home()) {
        $posts_page_id = absint(get_option('page_for_posts'));
        $url = $posts_page_id ? (string) get_permalink($posts_page_id) : home_url('/news/');
    } elseif (is_singular()) {
        $url = (string) get_permalink(get_queried_object_id());
    } elseif (is_category() || is_tag() || is_tax()) {
        $url = (string) get_term_link(get_queried_object());
    } elseif (is_post_type_archive()) {
        $url = (string) get_post_type_archive_link((string) get_query_var('post_type'));
    } elseif (is_author()) {
        $url = (string) get_author_posts_url((int) get_query_var('author'));
    } elseif (is_search()) {
        $url = (string) get_search_link();
    } else {
        $url = home_url('/');
    }

    $paged = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
    if ($paged > 1) {
        $url = (string) get_pagenum_link($paged);
    }

    return $url;
}

/**
 * Return a compact plain-text description for the current request.
 */
function yy_seo_description(): string
{
    $fallback = (string) yy_global_setting(
        'seo_description',
        '武汉专业信息流广告代运营，抖音、快手、视频号本地推线索获客，账户诊断、投放优化全链路配套服务。'
    );

    if (is_front_page()) {
        return $fallback;
    }

    if (is_home()) {
        return (string) yy_setting('news_archive_description', $fallback);
    }

    if (is_singular()) {
        $post_id = get_queried_object_id();
        $excerpt = (string) get_post_field('post_excerpt', $post_id);
        if ($excerpt === '') {
            $excerpt = wp_trim_words(
                wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $post_id))),
                42,
                '…'
            );
        }
        return $excerpt !== '' ? $excerpt : $fallback;
    }

    if (is_category() || is_tag() || is_tax()) {
        $description = term_description();
        return $description !== '' ? wp_strip_all_tags($description) : $fallback;
    }

    return $fallback;
}

/**
 * Return the best available social sharing image URL.
 */
function yy_seo_image_url(): string
{
    if (is_singular() && has_post_thumbnail(get_queried_object_id())) {
        $image = wp_get_attachment_image_url((int) get_post_thumbnail_id(get_queried_object_id()), 'yy-article-cover');
        if ($image) {
            return (string) $image;
        }
    }

    $image_id = absint(yy_global_setting('seo_image_id', 0));
    if (!$image_id) {
        $image_id = absint(yy_global_setting('logo_id', 0));
    }
    if ($image_id) {
        $image = wp_get_attachment_image_url($image_id, 'full');
        if ($image) {
            return (string) $image;
        }
    }

    return yy_theme_image_url('images/logo1.jpg');
}

/**
 * Query the editable FAQ records for FAQPage structured data.
 *
 * @return array<int, array<string, mixed>>
 */
function yy_seo_faq_entities(): array
{
    if (!is_front_page()) {
        return [];
    }

    $query = yy_theme_query_records('yy_faq');
    $entities = [];
    foreach ($query->posts as $faq) {
        $answer = trim(wp_strip_all_tags(strip_shortcodes((string) $faq->post_content)));
        if ($answer === '') {
            continue;
        }
        $entities[] = [
            '@type' => 'Question',
            'name' => get_the_title($faq),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $answer,
            ],
        ];
    }
    wp_reset_postdata();

    return $entities;
}

/**
 * Build the JSON-LD graph without concatenating unescaped values.
 *
 * @return array<int, array<string, mixed>>
 */
function yy_seo_schema_graph(): array
{
    $home = home_url('/');
    $brand = (string) yy_global_setting('brand_name', get_bloginfo('name'));
    $phone = preg_replace('/[^\d+]/', '', (string) yy_global_setting('phone', ''));
    $organization_id = $home . '#organization';
    $website_id = $home . '#website';

    $organization = [
        '@type' => 'Organization',
        '@id' => $organization_id,
        'name' => $brand,
        'url' => $home,
        'logo' => yy_seo_image_url(),
        'description' => (string) yy_global_setting('company_description', ''),
        'areaServed' => [
            '@type' => 'Country',
            'name' => '中国',
        ],
    ];
    if ($phone !== '') {
        $organization['telephone'] = '+' . ltrim($phone, '+');
        $organization['contactPoint'] = [
            '@type' => 'ContactPoint',
            'telephone' => '+' . ltrim($phone, '+'),
            'contactType' => 'customer service',
            'areaServed' => 'CN',
            'availableLanguage' => 'zh-CN',
        ];
    }

    $graph = [
        $organization,
        [
            '@type' => 'WebSite',
            '@id' => $website_id,
            'url' => $home,
            'name' => $brand,
            'inLanguage' => 'zh-CN',
            'publisher' => ['@id' => $organization_id],
        ],
    ];

    if (is_singular('post')) {
        $post_id = get_queried_object_id();
        $article = [
            '@type' => 'Article',
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => get_permalink($post_id),
            ],
            'headline' => get_the_title($post_id),
            'description' => yy_seo_description(),
            'datePublished' => get_the_date(DATE_W3C, $post_id),
            'dateModified' => get_the_modified_date(DATE_W3C, $post_id),
            'author' => [
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', (int) get_post_field('post_author', $post_id)),
            ],
            'publisher' => ['@id' => $organization_id],
            'image' => yy_seo_image_url(),
            'inLanguage' => 'zh-CN',
        ];
        $categories = get_the_category($post_id);
        if ($categories) {
            $article['articleSection'] = wp_list_pluck($categories, 'name');
        }
        $graph[] = $article;
    }

    $faq_entities = yy_seo_faq_entities();
    if ($faq_entities) {
        $graph[] = [
            '@type' => 'FAQPage',
            '@id' => $home . '#faq',
            'mainEntity' => $faq_entities,
        ];
    }

    return $graph;
}

/**
 * Print canonical, social and structured-data tags.
 */
function yy_render_seo_metadata(): void
{
    if (is_admin() || is_feed() || is_robots() || is_404()) {
        return;
    }

    $canonical = yy_seo_canonical_url();
    $description = yy_seo_description();
    $title = wp_get_document_title();
    $image = yy_seo_image_url();
    $type = is_singular('post') ? 'article' : 'website';
    $brand = (string) yy_global_setting('brand_name', get_bloginfo('name'));
    $robots = is_search() ? 'noindex,follow' : 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
    ?>
    <meta name="description" content="<?php echo esc_attr($description); ?>">
    <meta name="robots" content="<?php echo esc_attr($robots); ?>">
    <link rel="canonical" href="<?php echo esc_url($canonical); ?>">
    <meta property="og:locale" content="zh_CN">
    <meta property="og:type" content="<?php echo esc_attr($type); ?>">
    <meta property="og:site_name" content="<?php echo esc_attr($brand); ?>">
    <meta property="og:title" content="<?php echo esc_attr($title); ?>">
    <meta property="og:description" content="<?php echo esc_attr($description); ?>">
    <meta property="og:url" content="<?php echo esc_url($canonical); ?>">
    <meta property="og:image" content="<?php echo esc_url($image); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo esc_attr($title); ?>">
    <meta name="twitter:description" content="<?php echo esc_attr($description); ?>">
    <meta name="twitter:image" content="<?php echo esc_url($image); ?>">
    <?php if (is_singular('post')) : ?>
        <meta property="article:published_time" content="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>">
        <meta property="article:modified_time" content="<?php echo esc_attr(get_the_modified_date(DATE_W3C)); ?>">
    <?php endif; ?>
    <script type="application/ld+json"><?php
        echo wp_json_encode(
            [
                '@context' => 'https://schema.org',
                '@graph' => yy_seo_schema_graph(),
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
    ?></script>
    <?php
}

remove_action('wp_head', 'rel_canonical');
add_action('wp_head', 'yy_render_seo_metadata', 2);
