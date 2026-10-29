<?php

if (!defined('ABSPATH')) {
    fwrite(STDERR, "admin-experience-test.php must run inside WordPress.\n");
    exit(1);
}

$yy_admin_failures = [];

function yy_admin_assert(bool $condition, string $message): void
{
    global $yy_admin_failures;

    if (!$condition) {
        $yy_admin_failures[] = $message;
    }
}

$administrator = get_role('administrator');
$editor = get_role('yy_content_editor');
$publisher = get_role('yy_publisher');

yy_admin_assert($administrator instanceof WP_Role, 'Administrator role must exist.');
yy_admin_assert($editor instanceof WP_Role, 'Content editor role must exist.');
yy_admin_assert($publisher instanceof WP_Role, 'Publisher role must exist.');

if ($administrator instanceof WP_Role) {
    yy_admin_assert($administrator->has_cap('yy_manage_homepage_settings'), 'Administrator must edit homepage settings.');
    yy_admin_assert($administrator->has_cap('yy_manage_publishers'), 'Administrator must manage publishers.');
}

if ($editor instanceof WP_Role) {
    yy_admin_assert($editor->has_cap('yy_manage_homepage_settings'), 'Content editor must edit homepage settings.');
    yy_admin_assert($editor->has_cap('yy_manage_publishers'), 'Content editor must manage publishers.');
    yy_admin_assert($editor->has_cap('manage_categories'), 'Content editor must manage news categories.');
    yy_admin_assert(!$editor->has_cap('manage_options'), 'Content editor must not manage site options.');
    yy_admin_assert(!$editor->has_cap('edit_pages'), 'Content editor must not edit pages.');
    yy_admin_assert(!$editor->has_cap('publish_pages'), 'Content editor must not publish pages.');
    yy_admin_assert(!$editor->has_cap('moderate_comments'), 'Content editor must not moderate comments.');
    yy_admin_assert(!$editor->has_cap('switch_themes'), 'Content editor must not switch themes.');
    yy_admin_assert(!$editor->has_cap('edit_theme_options'), 'Content editor must not edit theme options.');
    yy_admin_assert(!$editor->has_cap('activate_plugins'), 'Content editor must not activate plugins.');
    yy_admin_assert(!$editor->has_cap('install_plugins'), 'Content editor must not install plugins.');
    yy_admin_assert(!$editor->has_cap('edit_users'), 'Content editor must not edit users.');
    yy_admin_assert(!$editor->has_cap('list_users'), 'Content editor must not list users.');
    yy_admin_assert(!$editor->has_cap('create_users'), 'Content editor must not create users.');
    yy_admin_assert(!$editor->has_cap('delete_users'), 'Content editor must not delete users.');
    yy_admin_assert(!$editor->has_cap('promote_users'), 'Content editor must not promote users.');
}

if ($publisher instanceof WP_Role) {
    yy_admin_assert(!$publisher->has_cap('yy_manage_homepage_settings'), 'Publisher must not access site administration.');
    yy_admin_assert(!$publisher->has_cap('yy_manage_publishers'), 'Publisher must not manage accounts.');
}

if ($yy_admin_failures !== []) {
    foreach ($yy_admin_failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    WP_CLI::error(sprintf('Admin experience tests failed with %d error(s).', count($yy_admin_failures)));
}

WP_CLI::success('Admin experience tests passed.');
