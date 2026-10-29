<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Store binary content in the WordPress uploads directory.
 *
 * @return array<string, mixed>|WP_Error
 */
function yy_upload_binary_asset(string $contents, string $filename): array|WP_Error
{
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $filename = sanitize_file_name(wp_basename($filename));
    if ($filename === '') {
        return new WP_Error('yy_invalid_filename', '上传文件名无效。');
    }

    $upload = wp_upload_bits($filename, null, $contents);
    if (!empty($upload['error'])) {
        return new WP_Error('yy_upload_failed', (string) $upload['error']);
    }

    $filetype = wp_check_filetype_and_ext($upload['file'], $filename);
    if (empty($filetype['type'])) {
        wp_delete_file($upload['file']);
        return new WP_Error('yy_invalid_file_type', '上传文件类型不受支持。');
    }

    $upload['type'] = $filetype['type'];
    return $upload;
}

/**
 * Import a bundled theme asset into the media library once.
 */
function yy_import_theme_asset(string $relative_path, string $title, string $alt = ''): int
{
    $existing = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_key' => '_yy_theme_asset',
        'meta_value' => $relative_path,
    ]);
    if ($existing) {
        return (int) $existing[0];
    }

    $source = get_template_directory() . '/assets/' . ltrim($relative_path, '/');
    if (!is_readable($source)) {
        return 0;
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
        'post_title' => $title,
        'post_content' => '',
        'post_status' => 'inherit',
    ], $upload['file']);
    if (is_wp_error($attachment_id) || !$attachment_id) {
        return 0;
    }

    wp_update_attachment_metadata(
        $attachment_id,
        wp_generate_attachment_metadata($attachment_id, $upload['file'])
    );
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    update_post_meta($attachment_id, '_yy_theme_asset', $relative_path);

    return (int) $attachment_id;
}

/**
 * Find a seeded post without relying on mutable titles or slugs.
 */
function yy_find_seeded_post(string $post_type, string $seed_key): int
{
    $posts = get_posts([
        'post_type' => $post_type,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_key' => '_yy_seed_key',
        'meta_value' => $seed_key,
    ]);

    return $posts ? (int) $posts[0] : 0;
}

/**
 * Create starter content once while preserving later editorial changes.
 *
 * @param array<string, mixed> $meta
 */
function yy_seed_post(
    string $post_type,
    string $seed_key,
    string $title,
    array $meta = [],
    string $content = '',
    string $status = 'publish',
    bool $overwrite = false
): int {
    $post_id = yy_find_seeded_post($post_type, $seed_key);
    if ($post_id && !$overwrite) {
        return $post_id;
    }

    $data = [
        'post_type' => $post_type,
        'post_status' => $status,
        'post_title' => $title,
        'post_content' => wp_kses_post($content),
    ];
    if ($post_id) {
        $data['ID'] = $post_id;
        $post_id = wp_update_post($data, true);
    } else {
        $post_id = wp_insert_post($data, true);
    }
    if (is_wp_error($post_id)) {
        return 0;
    }

    update_post_meta($post_id, '_yy_seed_key', $seed_key);
    foreach ($meta as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }

    return (int) $post_id;
}

/**
 * Create a page or return its existing ID.
 */
function yy_ensure_page(string $slug, string $title): int
{
    $page = get_page_by_path($slug, OBJECT, 'page');
    if ($page instanceof WP_Post) {
        return (int) $page->ID;
    }

    $page_id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
    ], true);

    return is_wp_error($page_id) ? 0 : (int) $page_id;
}

/**
 * Ensure the primary navigation exists and points to stable WordPress URLs.
 */
function yy_ensure_primary_menu(): void
{
    $locations = get_theme_mod('nav_menu_locations', []);
    $menu = !empty($locations['primary'])
        ? wp_get_nav_menu_object((int) $locations['primary'])
        : false;

    if (!$menu) {
        $seeded_menus = new WP_Term_Query([
            'taxonomy' => 'nav_menu',
            'hide_empty' => false,
            'number' => 1,
            'meta_key' => '_yy_seed_menu',
            'meta_value' => 'primary',
        ]);
        if (!empty($seeded_menus->terms[0])) {
            $menu = $seeded_menus->terms[0];
        }
    }

    if (!$menu) {
        $menu = wp_get_nav_menu_object('primary');
    }
    if (!$menu) {
        $menu = wp_get_nav_menu_object('主导航');
    }

    if (!$menu) {
        $menu_id = wp_create_nav_menu('主导航');
        if (is_wp_error($menu_id)) {
            return;
        }
    } else {
        $menu_id = (int) $menu->term_id;
    }
    update_term_meta($menu_id, '_yy_seed_menu', 'primary');

    $existing = wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);
    if ($existing) {
        $locations['primary'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);
        return;
    }

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
    foreach ($items as $position => $item) {
        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title' => $item[0],
            'menu-item-url' => $item[1],
            'menu-item-status' => 'publish',
            'menu-item-position' => $position + 1,
        ]);
    }

    $locations['primary'] = $menu_id;
    set_theme_mod('nav_menu_locations', $locations);
}

/**
 * Build a complete, editable starter site.
 *
 * @return array<string, int>
 */
function yy_bootstrap_site(bool $overwrite = false, bool $configure_wp_options = false): array
{
    yy_install_default_options();

    if ($overwrite || $configure_wp_options) {
        update_option('blogname', '盈圆');
        update_option('blogdescription', '武汉信息流广告投放与本地推线索获客服务商');
        update_option('timezone_string', 'Asia/Shanghai');
        update_option('date_format', 'Y-m-d');
        update_option('start_of_week', 1);
        update_option('show_on_front', 'page');
        update_option('permalink_structure', '/news/%postname%/');
        update_option('category_base', 'news/category');
        update_option('default_category', yy_ensure_category('行业动态', 'industry-news'));
        update_option('default_comment_status', 'closed');
        update_option('default_ping_status', 'closed');
        global $wp_rewrite;
        if ($wp_rewrite instanceof WP_Rewrite) {
            $wp_rewrite->set_permalink_structure('/news/%postname%/');
            $wp_rewrite->set_category_base('news/category');
        }
    }

    $home_page_id = yy_ensure_page('home', '首页');
    $news_page_id = yy_ensure_page('news', '新闻资讯');
    if ($overwrite || $configure_wp_options) {
        if ($home_page_id) {
            update_option('page_on_front', $home_page_id);
        }
        if ($news_page_id) {
            update_option('page_for_posts', $news_page_id);
        }
    } else {
        if ($home_page_id && !get_option('page_on_front')) {
            update_option('page_on_front', $home_page_id);
        }
        if ($news_page_id && !get_option('page_for_posts')) {
            update_option('page_for_posts', $news_page_id);
        }
    }

    $categories = [
        'industry-news' => '行业动态',
        'advertising-guides' => '投放干货',
        'platform-policies' => '平台政策',
    ];
    foreach ($categories as $slug => $name) {
        yy_ensure_category($name, $slug);
    }

    $logo_id = yy_import_theme_asset('images/logo1.jpg', '盈圆 Logo', '盈圆');
    $qr_id = yy_import_theme_asset('images/whyy.jpg', '盈圆微信咨询二维码', '盈圆微信咨询二维码');
    $site_settings = wp_parse_args(get_option('yy_site_settings', []), yy_site_setting_defaults());
    if (!$site_settings['logo_id']) {
        $site_settings['logo_id'] = $logo_id;
    }
    if (!$site_settings['wechat_qr_id']) {
        $site_settings['wechat_qr_id'] = $qr_id;
    }
    update_option('yy_site_settings', $site_settings);

    $platforms = [
        ['巨量引擎', 'OCEAN ENGINE', '巨量', 'jj', '覆盖抖音、今日头条、西瓜视频、番茄小说、穿山甲。信息流 AD 与巨量千川双引擎驱动电商与品牌增长。', '6亿+', 'DAU 日活', '¥50亿', '月广告消耗'],
        ['腾讯广点通', 'GDT / YTX', '广点通', 'gdt', '微信朋友圈、公众号、小程序、QQ 广告位、视频号。社交关系链下的高转化场景，特别适合教育、法律、百货等高信任、高决策成本的赛道。', '13亿+', '微信月活', '180+', '广告位'],
        ['快手磁力引擎', 'KWAI E-COMMERCE', '快手', 'ks', '信息流广告、磁力金牛电商投放、磁力聚星达人分销。下沉市场与白牌起量的热门平台，用户需求意图相对明确，线索质量相对突出。', '4亿+', '月活用户', '3 大', '投放产品线'],
        ['百度营销', 'BAIDU MARKETING', '百度', 'bd', '百度搜索、百度信息流、百度地图、好看视频。搜索赛道拥有庞大用户基数，用户主动搜索表达需求，意向相对明确，适合招商加盟、教育、企服等强线索行业。', '7亿+', '百度 APP 月活', '4 大', '核心流量入口'],
        ['GEO 生成式引擎优化', 'GENERATIVE ENGINE OPTIMIZATION', 'GEO', 'geo', '豆包、DeepSeek、Kimi、文心一言、腾讯元宝、夸克等 AI 搜索与问答入口。通过结构化内容与语义优化，助力品牌争取在 AI 问答场景获得更多曝光机会，抢占下一代流量入口。', '6+', '主流 AI 入口覆盖', 'AI', '品牌答案占位'],
    ];
    foreach ($platforms as $index => $item) {
        yy_seed_post('yy_platform', 'platform-' . ($index + 1), $item[0], [
            '_yy_order' => $index + 1,
            '_yy_english_name' => $item[1],
            '_yy_icon' => $item[2],
            '_yy_icon_variant' => $item[3],
            '_yy_description' => $item[4],
            '_yy_metric_1_value' => $item[5],
            '_yy_metric_1_label' => $item[6],
            '_yy_metric_2_value' => $item[7],
            '_yy_metric_2_label' => $item[8],
        ], '', 'publish', $overwrite);
    }

    $services = [
        ['免费账户诊断', '析', '结合投放现状给到优化方案，争取帮您优化投放效率。'],
        ['投放运营服务', '投', '专业团队持续优化投放，争取稳定获取优质线索。'],
        ['定向投放', '定', '结合业务目标与人群特征进行定向触达，覆盖意向相对明确的潜在客户。'],
        ['全链路配套服务', '配', '提供广告投放全链路配套服务，协助处理账户、素材、数据等投放问题。'],
    ];
    foreach ($services as $index => $item) {
        yy_seed_post('yy_service', 'service-' . ($index + 1), $item[0], [
            '_yy_order' => $index + 1,
            '_yy_icon' => $item[1],
            '_yy_description' => $item[2],
        ], '', 'publish', $overwrite);
    }

    $advantages = [
        ['精细化定向投放', '精', '精细化定向投放，尽力把控线索质量。'],
        ['经验沉淀', '验', '深耕信息流投放，拥有多年投放运营经验。'],
        ['收费透明', '明', '收费方案清晰透明，无隐藏附加收费项目。'],
        ['数据同步', '数', '提供投放数据实时同步，投放情况可随时查看。'],
    ];
    foreach ($advantages as $index => $item) {
        yy_seed_post('yy_advantage', 'advantage-' . ($index + 1), $item[0], [
            '_yy_order' => $index + 1,
            '_yy_icon' => $item[1],
            '_yy_description' => $item[2],
        ], '', 'publish', $overwrite);
    }

    $steps = [
        ['需求沟通', '了解你的行业、产品与目标，免费诊断现有账户。'],
        ['方案制定', '输出投放策略与排期计划，明确目标与预期。'],
        ['搭建上线', '资料资质齐备后完成账户搭建、素材制作与计划上线。'],
        ['持续优化', '盯盘调整、周期复盘，效果稳步向上。'],
    ];
    foreach ($steps as $index => $item) {
        yy_seed_post('yy_process', 'process-' . ($index + 1), $item[0], [
            '_yy_order' => $index + 1,
            '_yy_description' => $item[1],
        ], '', 'publish', $overwrite);
    }

    $cases = [
        ['某法律行业视频号+朋友圈投放', '法律行业', '围绕视频号内容种草与朋友圈定向触达，搭建法律咨询线索获取链路。', '法', '-28%', '获客成本', '3.8倍', 'ROI 提升'],
        ['某职教机构线索投放', '教育培训', '搜索与信息流组合投放，结合落地页测试和人群优化，持续改善线索获取效率。', '教', '+160%', '有效线索量', '', ''],
        ['某美业加盟品牌推广', '美业加盟', '围绕加盟招商目标人群定向投放，短视频与朋友圈素材持续迭代，累计积累加盟线索。', '美', '5000+', '加盟线索', '', ''],
    ];
    foreach ($cases as $index => $item) {
        yy_seed_post('yy_case', 'case-' . ($index + 1), $item[0], [
            '_yy_order' => $index + 1,
            '_yy_tag' => $item[1],
            '_yy_description' => $item[2],
            '_yy_icon' => $item[3],
            '_yy_metric_1_value' => $item[4],
            '_yy_metric_1_label' => $item[5],
            '_yy_metric_2_value' => $item[6],
            '_yy_metric_2_label' => $item[7],
        ], '', 'publish', $overwrite);
    }

    $faqs = [
        ['广告代运营服务包含哪些内容？', '服务覆盖免费账户诊断、投放运营、定向投放与全链路配套服务，协助处理账户、素材、数据等投放问题。'],
        ['支持哪些广告投放平台？', '目前覆盖巨量引擎、腾讯广告、快手磁力引擎、百度营销等主流平台，并提供 GEO 生成式引擎优化服务，助力品牌争取在豆包、DeepSeek、Kimi、文心一言、腾讯元宝、夸克等 AI 搜索与问答场景获得更多曝光机会。'],
        ['如何开始广告代运营合作？', '添加微信后沟通行业、产品和投放目标，我们会免费诊断现有账户，再制定投放策略与执行方案。'],
        ['广告投放多久可以看到效果？', '不同行业、预算和账户基础会影响起量速度。通常在账户完成搭建并进入稳定投放后，可持续观察数据并迭代优化。'],
    ];
    foreach ($faqs as $index => $item) {
        yy_seed_post('yy_faq', 'faq-' . ($index + 1), $item[0], [
            '_yy_order' => $index + 1,
        ], '<p>' . $item[1] . '</p>', 'publish', $overwrite);
    }

    $stats = [
        ['亿元级', '累计管理投放预算'],
        ['2%-5%', '部分客户 ROI 提升'],
        ['30%', '部分客户平均获客成本降幅可达'],
    ];
    foreach ($stats as $index => $item) {
        yy_seed_post('yy_stat', 'stat-' . ($index + 1), '成果数据 ' . ($index + 1), [
            '_yy_order' => $index + 1,
            '_yy_metric_1_value' => $item[0],
            '_yy_metric_1_label' => $item[1],
        ], '', 'publish', $overwrite);
    }

    for ($index = 1; $index <= 4; $index++) {
        $image_id = yy_import_theme_asset(
            'images/data-' . $index . '.png',
            '投放数据效果 ' . $index,
            '投放数据效果 ' . $index
        );
        yy_seed_post('yy_carousel_image', 'carousel-' . $index, '投放数据效果 ' . $index, [
            '_yy_order' => $index,
            '_yy_image_id' => $image_id,
            '_yy_image_alt' => '投放数据效果 ' . $index,
        ], '', 'publish', $overwrite);
    }

    $news = [
        ['信息流广告账户起量前，先检查这五个关键环节', 'industry-news', '从账户结构、定向、素材、出价与转化回传五个方面检查信息流广告账户的起量基础。', '<h2>一、账户结构</h2><p>清晰的账户结构便于控制预算和定位问题。计划层级不宜过度堆叠，应围绕业务目标与投放阶段逐步扩展。</p><h2>二、定向设置</h2><p>结合目标客户画像设置人群范围，并在稳定投放后根据数据表现持续调整，避免一次性叠加过多限制条件。</p><h2>三、素材与承接</h2><p>广告素材、落地页和转化链路需要保持一致。上线前应同时检查素材表达、页面加载与表单可用性。</p>'],
        ['本地推线索投放如何兼顾数量与质量', 'advertising-guides', '本地商家投放线索广告时，需要在覆盖范围、素材表达和有效线索筛选之间取得平衡。', '<p>本地推投放不应只关注表单数量，还要观察电话接通、到店咨询和后续成交等环节。</p><h2>持续复盘</h2><p>建议按周查看线索有效率，结合地域、时段和素材维度逐步优化，让预算更集中地流向有效线索。</p>'],
        ['广告素材迭代：从数据反馈到下一轮创意', 'advertising-guides', '用点击率、转化率和线索质量共同判断素材，避免只根据单一指标频繁调整。', '<p>素材表现需要放在完整转化链路中判断。高点击不一定带来高质量线索，低点击也可能对应高意向人群。</p><p>每轮测试保留明确变量，并为素材积累足够的数据量，再根据结果安排下一轮创意方向。</p>'],
    ];
    foreach ($news as $index => $item) {
        $existing_post_id = yy_find_seeded_post('post', 'news-' . ($index + 1));
        $post_id = yy_seed_post('post', 'news-' . ($index + 1), $item[0], [], $item[3], 'publish', $overwrite);
        if ($post_id && (!$existing_post_id || $overwrite)) {
            $term = get_category_by_slug($item[1]);
            if ($term instanceof WP_Term) {
                wp_set_post_categories($post_id, [(int) $term->term_id], false);
            }
            wp_update_post([
                'ID' => $post_id,
                'post_excerpt' => $item[2],
                'post_date' => sprintf('2026-10-%02d 09:00:00', 20 - $index),
            ]);
        }
    }

    yy_ensure_primary_menu();
    flush_rewrite_rules();

    return [
        'home_page_id' => $home_page_id,
        'news_page_id' => $news_page_id,
        'logo_attachment_id' => $logo_id,
        'wechat_qr_attachment_id' => $qr_id,
    ];
}

/**
 * Create a category if it does not exist.
 */
function yy_ensure_category(string $name, string $slug): int
{
    $term = get_term_by('slug', $slug, 'category');
    if ($term instanceof WP_Term) {
        return (int) $term->term_id;
    }

    $created = wp_insert_term($name, 'category', ['slug' => $slug]);
    return is_wp_error($created) ? 0 : (int) $created['term_id'];
}

/**
 * WP-CLI: wp yy bootstrap [--overwrite] [--configure-wp-options].
 */
function yy_cli_bootstrap(array $args, array $assoc_args): void
{
    $overwrite = isset($assoc_args['overwrite']);
    $configure_wp_options = $overwrite || isset($assoc_args['configure-wp-options']);
    $result = yy_bootstrap_site($overwrite, $configure_wp_options);
    WP_CLI::success('盈圆站点初始化完成：' . wp_json_encode($result, JSON_UNESCAPED_UNICODE));
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('yy bootstrap', 'yy_cli_bootstrap');
}
