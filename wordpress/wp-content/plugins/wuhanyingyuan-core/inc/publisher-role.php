<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capabilities shared by the editable homepage content types.
 *
 * @return list<string>
 */
function yy_homepage_item_capabilities(): array
{
    return [
        'edit_yy_homepage_items',
        'edit_others_yy_homepage_items',
        'publish_yy_homepage_items',
        'read_private_yy_homepage_items',
        'delete_yy_homepage_items',
        'delete_private_yy_homepage_items',
        'delete_published_yy_homepage_items',
        'delete_others_yy_homepage_items',
        'edit_private_yy_homepage_items',
        'edit_published_yy_homepage_items',
    ];
}

/**
 * Create a role and remove capabilities that are no longer allowed.
 *
 * @param list<string> $allowed
 */
function yy_sync_role_capabilities(string $role_key, string $display_name, array $allowed): void
{
    add_role($role_key, $display_name, array_fill_keys($allowed, true));

    $role = get_role($role_key);
    if (!$role) {
        return;
    }

    $allCapabilities = wp_roles()->roles[$role_key]['capabilities'] ?? [];
    foreach (array_keys($allCapabilities) as $capability) {
        if (!in_array($capability, $allowed, true)) {
            $role->remove_cap($capability);
        }
    }
    foreach ($allowed as $capability) {
        $role->add_cap($capability);
    }
}

/**
 * Create or refresh the least-privilege role used by the publishing tool.
 */
function yy_register_publisher_role(): void
{
    yy_sync_role_capabilities('yy_publisher', '新闻发布接口', [
        'read',
        'edit_posts',
        'publish_posts',
        'upload_files',
        'edit_published_posts',
    ]);
}

/**
 * Human editor role for WordPress admin content work.
 */
function yy_register_content_editor_role(): void
{
    yy_sync_role_capabilities('yy_content_editor', '内容编辑', [
        'read',
        'edit_posts',
        'edit_others_posts',
        'publish_posts',
        'edit_published_posts',
        'edit_others_published_posts',
        'delete_posts',
        'delete_published_posts',
        'upload_files',
        ...yy_homepage_item_capabilities(),
    ]);
}

/**
 * Administrators need the custom capabilities used by private homepage types.
 */
function yy_register_administrator_homepage_capabilities(): void
{
    $role = get_role('administrator');
    if (!$role) {
        return;
    }

    foreach (yy_homepage_item_capabilities() as $capability) {
        $role->add_cap($capability);
    }
}

/**
 * Application Passwords are required for the external publishing tool.
 */
add_filter('wp_is_application_passwords_available', '__return_true');
