<?php
/**
 * Plugin Name: 盈圆站点核心
 * Description: 盈圆官网的内容类型、后台设置、新闻迁移与发布权限。
 * Version: 1.0.1
 * Author: Wuhanyingyuan
 * Requires at least: 6.5
 * Requires PHP: 8.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('YY_CORE_VERSION', '1.0.1');
define('YY_CORE_PATH', plugin_dir_path(__FILE__));

require_once YY_CORE_PATH . 'inc/content-types.php';
require_once YY_CORE_PATH . 'inc/meta-fields.php';
require_once YY_CORE_PATH . 'inc/site-settings.php';
require_once YY_CORE_PATH . 'inc/admin-columns.php';
require_once YY_CORE_PATH . 'inc/publisher-role.php';
require_once YY_CORE_PATH . 'inc/bootstrap.php';
require_once YY_CORE_PATH . 'inc/legacy-import.php';

/**
 * Initialize the plugin's durable WordPress objects.
 */
function yy_core_activate(): void
{
    yy_register_content_types();
    yy_core_sync_roles(true);
    yy_install_default_options();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'yy_core_activate');

/**
 * Flush rewrite rules only when the plugin is deactivated.
 */
function yy_core_deactivate(): void
{
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'yy_core_deactivate');

/**
 * Keep role definitions current after deployments that update this plugin.
 */
function yy_core_sync_roles(bool $force = false): void
{
    if (!$force && get_option('yy_core_role_version') === YY_CORE_VERSION) {
        return;
    }

    yy_register_publisher_role();
    yy_register_content_editor_role();
    yy_register_administrator_homepage_capabilities();
    update_option('yy_core_role_version', YY_CORE_VERSION, false);
}
add_action('init', 'yy_core_sync_roles', 20);
