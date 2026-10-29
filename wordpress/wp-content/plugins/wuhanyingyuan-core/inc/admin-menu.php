<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the single branded administration entry for the site team.
 */
function yy_register_yy_admin_menu(): void
{
    add_menu_page(
        '盈圆官网',
        '盈圆官网',
        'yy_manage_homepage_settings',
        'yy-site',
        'yy_render_site_manager_page',
        'dashicons-admin-site-alt3',
        3
    );

    add_submenu_page('yy-site', '首页管理', '首页管理', 'yy_manage_homepage_settings', 'yy-site', 'yy_render_site_manager_page');
    add_submenu_page('yy-site', '首屏内容', '首屏内容', 'yy_manage_homepage_settings', 'yy-home-settings', 'yy_render_home_settings_page');
    add_submenu_page('yy-site', '投放平台', '投放平台', 'edit_yy_homepage_items', 'edit.php?post_type=yy_platform');
    add_submenu_page('yy-site', '服务内容', '服务内容', 'edit_yy_homepage_items', 'edit.php?post_type=yy_service');
    add_submenu_page('yy-site', '服务优势', '服务优势', 'edit_yy_homepage_items', 'edit.php?post_type=yy_advantage');
    add_submenu_page('yy-site', '合作流程', '合作流程', 'edit_yy_homepage_items', 'edit.php?post_type=yy_process');
    add_submenu_page('yy-site', '客户案例', '客户案例', 'edit_yy_homepage_items', 'edit.php?post_type=yy_case');
    add_submenu_page('yy-site', '常见问题', '常见问题', 'edit_yy_homepage_items', 'edit.php?post_type=yy_faq');
    add_submenu_page('yy-site', '数据效果', '数据效果', 'edit_yy_homepage_items', 'yy-data-effects', 'yy_render_data_effects_page');
    add_submenu_page('yy-site', '新闻列表', '新闻列表', 'edit_posts', 'edit.php');
    add_submenu_page('yy-site', '新增新闻', '新增新闻', 'edit_posts', 'post-new.php');
    add_submenu_page('yy-site', '新闻分类', '新闻分类', 'manage_categories', 'edit-tags.php?taxonomy=category');
    add_submenu_page('yy-site', '图片资料', '图片资料', 'upload_files', 'upload.php');
    add_submenu_page('yy-site', '网站设置', '网站设置', 'manage_options', 'yy-site-settings', 'yy_render_site_settings_page');
    add_submenu_page('yy-site', '发布账号', '发布账号', 'yy_manage_publishers', 'yy-publishers', 'yy_render_publisher_accounts_page');
}
add_action('admin_menu', 'yy_register_yy_admin_menu', 9);

/**
 * Whether the current account is the limited site-content editor.
 */
function yy_current_user_is_content_editor(): bool
{
    if (!is_user_logged_in() || current_user_can('manage_options')) {
        return false;
    }

    return in_array('yy_content_editor', (array) wp_get_current_user()->roles, true);
}

/**
 * Whether the current account has only the API publishing role.
 */
function yy_current_user_is_api_publisher(): bool
{
    if (!is_user_logged_in()) {
        return false;
    }

    $roles = array_values((array) wp_get_current_user()->roles);
    return $roles === ['yy_publisher'];
}

/**
 * Keep only the branded menu for content editors.
 */
function yy_restrict_admin_menu(): void
{
    if (!yy_current_user_is_content_editor()) {
        return;
    }

    global $menu, $submenu;

    foreach ($menu as $index => $item) {
        if (($item[2] ?? '') !== 'yy-site') {
            unset($menu[$index]);
        }
    }

    foreach (array_keys($submenu) as $parent_slug) {
        if ($parent_slug !== 'yy-site') {
            unset($submenu[$parent_slug]);
        }
    }
}
add_action('admin_menu', 'yy_restrict_admin_menu', 9999);

/**
 * Send limited accounts away from WordPress maintenance screens.
 */
function yy_redirect_limited_admin_users(): void
{
    if (
        !is_user_logged_in()
        || wp_doing_ajax()
        || wp_doing_cron()
        || (defined('REST_REQUEST') && REST_REQUEST)
    ) {
        return;
    }

    $pagenow = $GLOBALS['pagenow'] ?? '';

    if (yy_current_user_is_api_publisher()) {
        if ($pagenow === 'profile.php') {
            return;
        }

        wp_safe_redirect(admin_url('profile.php?yy_publisher_notice=1'));
        exit;
    }

    if (yy_current_user_is_content_editor() && $pagenow === 'index.php') {
        wp_safe_redirect(admin_url('admin.php?page=yy-site'));
        exit;
    }
}
add_action('admin_init', 'yy_redirect_limited_admin_users');

/**
 * Explain why an API publishing account was redirected to its profile.
 */
function yy_render_publisher_admin_notice(): void
{
    if (!yy_current_user_is_api_publisher() || ($_GET['yy_publisher_notice'] ?? '') !== '1') {
        return;
    }

    echo '<div class="notice notice-info"><p>发布账号仅用于新闻发布接口。</p></div>';
}
add_action('admin_notices', 'yy_render_publisher_admin_notice');

/**
 * Render a compact overview of the editable website areas.
 */
function yy_render_site_manager_page(): void
{
    if (!current_user_can('yy_manage_homepage_settings')) {
        wp_die('您没有权限访问此页面。', '', ['response' => 403]);
    }

    $content_types = [
        'yy_platform' => '投放平台',
        'yy_service' => '服务内容',
        'yy_advantage' => '服务优势',
        'yy_process' => '合作流程',
        'yy_case' => '客户案例',
        'yy_faq' => '常见问题',
    ];
    ?>
    <div class="wrap">
        <h1>盈圆官网</h1>
        <p>从下方入口维护官网内容、新闻和图片。</p>
        <div class="card" style="max-width: 760px;">
            <h2>首页内容</h2>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=yy-home-settings')); ?>">编辑首屏内容</a></p>
            <ul>
                <?php foreach ($content_types as $post_type => $label): ?>
                    <li>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . $post_type)); ?>"><?php echo esc_html($label); ?></a>
                    </li>
                <?php endforeach; ?>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=yy-data-effects')); ?>">数据效果</a></li>
            </ul>
        </div>
        <div class="card" style="max-width: 760px;">
            <h2>新闻与图片</h2>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php')); ?>">新闻列表</a>
                <a class="button" href="<?php echo esc_url(admin_url('post-new.php')); ?>">新增新闻</a>
                <a class="button" href="<?php echo esc_url(admin_url('upload.php')); ?>">图片资料</a>
            </p>
        </div>
    </div>
    <?php
}

/**
 * Link to the two data-oriented homepage content types.
 */
function yy_render_data_effects_page(): void
{
    if (!current_user_can('edit_yy_homepage_items')) {
        wp_die('您没有权限访问此页面。', '', ['response' => 403]);
    }

    $content_types = [
        'yy_carousel_image' => '数据效果图',
        'yy_stat' => '成果数据',
    ];
    ?>
    <div class="wrap">
        <h1>数据效果</h1>
        <table class="widefat striped" style="max-width: 760px;">
            <thead>
                <tr>
                    <th scope="col">内容类型</th>
                    <th scope="col">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($content_types as $post_type => $label): ?>
                    <tr>
                        <td><?php echo esc_html($label); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . $post_type)); ?>">查看列表</a>
                            |
                            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . $post_type)); ?>">新增</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
