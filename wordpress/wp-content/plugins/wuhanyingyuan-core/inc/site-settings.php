<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global values that can be edited from the WordPress administration area.
 *
 * @return array<string, mixed>
 */
function yy_site_setting_defaults(): array
{
    return [
        'brand_name' => '盈圆',
        'phone' => '18107552669',
        'work_hours' => '周一至周五 9:00 - 18:00',
        'wechat_qr_id' => 0,
        'logo_id' => 0,
        'footer_text' => '© 2026 盈圆 · 武汉信息流广告线索服务商',
        'company_description' => '盈圆专注武汉信息流广告投放与本地推线索获客，提供账户诊断、投放运营、定向投放与全链路配套服务。',
        'seo_title' => '武汉信息流广告投放｜本地推线索获客服务商',
        'seo_description' => '武汉专业信息流广告代运营，抖音、快手、视频号本地推线索获客，账户诊断、投放优化全链路配套服务。',
        'seo_image_id' => 0,
        'beian_text' => '',
    ];
}

/**
 * Editable homepage copy shared by the page templates.
 *
 * @return array<string, mixed>
 */
function yy_home_setting_defaults(): array
{
    return [
        'hero_tagline' => '专业广告代运营 · 让效果说话',
        'hero_title' => '把预算交给专业的人',
        'hero_highlight' => '让每一分广告费都花在刀刃上',
        'hero_description' => '盈圆专注广告代运营，从账户搭建、素材创意到投放优化全程托管，帮你降低获客成本、放大投放效果。',
        'hero_primary_text' => '免费诊断账户',
        'hero_secondary_text' => '微信咨询',
        'platforms_tagline' => 'ADVERTISING PLATFORMS',
        'platforms_title' => '主流投放平台',
        'platforms_subtitle' => '覆盖主流信息流与搜索平台，连接本地推、下沉市场及 AI 问答场景',
        'services_tagline' => 'OUR SERVICES',
        'services_title' => '服务内容',
        'services_subtitle' => '以客户投放效果为核心，提供从诊断到运营的全链路配套服务',
        'advantages_tagline' => 'OUR ADVANTAGES',
        'advantages_title' => '服务优势',
        'advantages_subtitle' => '以客户投放效果为核心考核标准，持续打磨每个投放环节',
        'process_tagline' => 'HOW WE WORK',
        'process_title' => '合作流程',
        'process_subtitle' => '资料、资质条件齐备的情况下可较快启动账户上线',
        'cases_tagline' => 'SUCCESS STORIES',
        'cases_title' => '案例展示',
        'cases_subtitle' => '过往客户案例节选，持续复盘并争取改善投放效果',
        'news_tagline' => 'NEWS & INSIGHTS',
        'news_title' => '新闻资讯',
        'news_subtitle' => '行业动态、投放干货与平台政策更新',
        'news_archive_title' => '新闻资讯',
        'news_archive_description' => '行业动态 · 平台政策 · 投放干货，持续更新',
        'about_title' => '关于盈圆',
        'about_description' => "盈圆团队具备成熟的投放优化能力，服务过多家不同行业客户。我们关注账户结构、线索质量与预算效率，以客户投放效果为核心考核标准。\n\n围绕信息流、本地推与搜索场景，盈圆协助商家优化投放，争取提升广告投放价值，并通过持续复盘争取改善投放效果。",
        'about_button_text' => '开始合作',
        'faq_tagline' => 'FREQUENTLY ASKED QUESTIONS',
        'faq_title' => '常见问题',
        'faq_subtitle' => '了解广告代运营服务与合作方式',
        'contact_title' => '让专业的人，帮你把广告投好',
        'contact_description' => '现在咨询，即可获得免费账户诊断与投放建议',
        'contact_button_text' => '微信咨询',
        'contact_secondary' => '点击上方按钮，扫码添加微信咨询',
        'platforms_note' => '平台用户规模数据来源于对应平台公开披露信息，仅供参考。',
        'cases_note' => '以下为部分合作客户过往案例效果，受行业、预算、素材、市场环境等因素影响，不保证所有客户均可实现相同结果。',
    ];
}

/**
 * Install explicit defaults once so administrators can edit every setting.
 */
function yy_install_default_options(): void
{
    add_option('yy_site_settings', yy_site_setting_defaults());
    add_option('yy_home_settings', yy_home_setting_defaults());
}

function yy_register_site_settings(): void
{
    register_setting('yy_site_settings_group', 'yy_site_settings', [
        'type' => 'array',
        'sanitize_callback' => 'yy_sanitize_site_settings',
        'default' => yy_site_setting_defaults(),
        'capability' => 'manage_options',
    ]);
    register_setting('yy_home_settings_group', 'yy_home_settings', [
        'type' => 'array',
        'sanitize_callback' => 'yy_sanitize_home_settings',
        'default' => yy_home_setting_defaults(),
        'capability' => 'yy_manage_homepage_settings',
    ]);
}
add_action('admin_init', 'yy_register_site_settings');

function yy_sanitize_site_settings(mixed $input): array
{
    return yy_sanitize_option_group($input, yy_site_setting_defaults());
}

function yy_sanitize_home_settings(mixed $input): array
{
    return yy_sanitize_option_group($input, yy_home_setting_defaults());
}

/**
 * @param array<string, mixed> $defaults
 * @return array<string, mixed>
 */
function yy_sanitize_option_group(mixed $input, array $defaults): array
{
    $input = is_array($input) ? $input : [];
    $output = [];

    foreach ($defaults as $key => $default) {
        $raw = $input[$key] ?? $default;
        if (is_int($default) || str_ends_with($key, '_id')) {
            $output[$key] = absint($raw);
        } elseif (in_array($key, ['company_description', 'hero_description', 'about_description', 'contact_description'], true)) {
            $output[$key] = sanitize_textarea_field((string) $raw);
        } else {
            $output[$key] = sanitize_text_field((string) $raw);
        }
    }

    return $output;
}

function yy_render_site_settings_page(): void
{
    yy_render_settings_form(
        'yy_site_settings',
        'yy_site_settings_group',
        yy_site_setting_defaults(),
        '全站设置',
        'manage_options'
    );
}

function yy_render_home_settings_page(): void
{
    yy_render_settings_form(
        'yy_home_settings',
        'yy_home_settings_group',
        yy_home_setting_defaults(),
        '首页设置',
        'yy_manage_homepage_settings'
    );
}

/**
 * @param array<string, mixed> $defaults
 */
function yy_render_settings_form(
    string $option_name,
    string $group,
    array $defaults,
    string $title,
    string $capability
): void
{
    if (!current_user_can($capability)) {
        wp_die('您没有权限访问此页面。', '', ['response' => 403]);
    }

    $saved = get_option($option_name, []);
    $values = wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    wp_enqueue_media();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html($title); ?></h1>
        <p>
            <?php if (current_user_can('manage_options')): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=yy-site-settings')); ?>">全站设置</a>
                |
            <?php endif; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=yy-home-settings')); ?>">首页设置</a>
        </p>
        <form action="<?php echo esc_url(admin_url('options.php')); ?>" method="post">
            <?php settings_fields($group); ?>
            <table class="form-table" role="presentation"><tbody>
            <?php foreach ($defaults as $key => $default): ?>
                <?php
                $id = 'yy-setting-' . sanitize_html_class($key);
                $name = $option_name . '[' . $key . ']';
                $value = $values[$key] ?? $default;
                $is_media = str_ends_with($key, '_id');
                $is_long = in_array($key, ['company_description', 'hero_description', 'about_description', 'contact_description'], true);
                ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html(yy_setting_label($key)); ?></label></th>
                    <td>
                        <?php if ($is_media): ?>
                            <?php $image_url = $value ? wp_get_attachment_image_url((int) $value, 'medium') : ''; ?>
                            <input type="hidden" class="yy-setting-media-id" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>">
                            <div class="yy-setting-media-preview" style="margin:10px 0">
                                <?php if ($image_url): ?>
                                    <img src="<?php echo esc_url($image_url); ?>" alt="" style="max-width:240px;height:auto">
                                <?php endif; ?>
                            </div>
                            <button type="button" class="button yy-setting-media-select">选择图片</button>
                            <button type="button" class="button-link-delete yy-setting-media-clear">清除</button>
                        <?php elseif ($is_long): ?>
                            <textarea class="large-text" rows="6" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"><?php echo esc_textarea((string) $value); ?></textarea>
                        <?php else: ?>
                            <input class="regular-text" type="text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>">
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php submit_button('保存设置'); ?>
        </form>
    </div>
    <script>
    jQuery(function ($) {
      $(document).on('click', '.yy-setting-media-select', function (event) {
        event.preventDefault();
        var field = $(this).closest('td');
        var frame = wp.media({ title: '选择图片', button: { text: '使用此图片' }, multiple: false });
        frame.on('select', function () {
          var attachment = frame.state().get('selection').first().toJSON();
          var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
          field.find('.yy-setting-media-id').val(attachment.id);
          field.find('.yy-setting-media-preview').html($('<img>', { src: url, alt: '', css: { maxWidth: '240px', height: 'auto' } }));
        });
        frame.open();
      }).on('click', '.yy-setting-media-clear', function (event) {
        event.preventDefault();
        var field = $(this).closest('td');
        field.find('.yy-setting-media-id').val('');
        field.find('.yy-setting-media-preview').empty();
      });
    });
    </script>
    <?php
}

function yy_setting_label(string $key): string
{
    $labels = [
        'brand_name' => '品牌名称',
        'phone' => '联系电话',
        'work_hours' => '工作时间',
        'wechat_qr_id' => '微信二维码',
        'logo_id' => '站点 Logo',
        'footer_text' => '页脚版权',
        'company_description' => '品牌简介',
        'seo_title' => '默认 SEO 标题',
        'seo_description' => '默认 SEO 描述',
        'seo_image_id' => '默认分享图片',
        'beian_text' => '备案信息',
        'hero_tagline' => '主视觉标签',
        'hero_title' => '主视觉标题',
        'hero_highlight' => '主视觉强调句',
        'hero_description' => '主视觉说明',
        'hero_primary_text' => '主按钮文案',
        'hero_secondary_text' => '次按钮文案',
        'platforms_tagline' => '投放平台英文标签',
        'platforms_title' => '投放平台标题',
        'platforms_subtitle' => '投放平台副标题',
        'services_tagline' => '服务内容英文标签',
        'services_title' => '服务内容标题',
        'services_subtitle' => '服务内容副标题',
        'advantages_tagline' => '服务优势英文标签',
        'advantages_title' => '服务优势标题',
        'advantages_subtitle' => '服务优势副标题',
        'process_tagline' => '合作流程英文标签',
        'process_title' => '合作流程标题',
        'process_subtitle' => '合作流程副标题',
        'cases_tagline' => '案例英文标签',
        'cases_title' => '案例标题',
        'cases_subtitle' => '案例副标题',
        'news_tagline' => '新闻英文标签',
        'news_title' => '新闻标题',
        'news_subtitle' => '新闻副标题',
        'news_archive_title' => '新闻列表页标题',
        'news_archive_description' => '新闻列表页说明',
        'about_title' => '关于我们标题',
        'about_description' => '关于我们正文',
        'about_button_text' => '关于我们按钮',
        'faq_tagline' => '常见问题英文标签',
        'faq_title' => '常见问题标题',
        'faq_subtitle' => '常见问题副标题',
        'contact_title' => '联系区标题',
        'contact_description' => '联系区说明',
        'contact_button_text' => '联系区按钮',
        'contact_secondary' => '联系区辅助文案',
        'platforms_note' => '投放平台数据说明',
        'cases_note' => '案例数据说明',
    ];

    return $labels[$key] ?? $key;
}

/**
 * Read one global setting with a deterministic default.
 */
function yy_get_site_setting(string $key, mixed $default = ''): mixed
{
    $defaults = yy_site_setting_defaults();
    $settings = get_option('yy_site_settings', []);
    $settings = is_array($settings) ? $settings : [];
    return $settings[$key] ?? $defaults[$key] ?? $default;
}

/**
 * Read one homepage setting with a deterministic default.
 */
function yy_get_home_setting(string $key, mixed $default = ''): mixed
{
    $defaults = yy_home_setting_defaults();
    $settings = get_option('yy_home_settings', []);
    $settings = is_array($settings) ? $settings : [];
    return $settings[$key] ?? $defaults[$key] ?? $default;
}
