import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..', '..');
const read = (file) => readFileSync(resolve(root, file), 'utf8');

const adminMenu = read('wp-content/plugins/wuhanyingyuan-core/inc/admin-menu.php');
const contentTypes = read('wp-content/plugins/wuhanyingyuan-core/inc/content-types.php');
const siteSettings = read('wp-content/plugins/wuhanyingyuan-core/inc/site-settings.php');

assert.match(adminMenu, /add_menu_page\(\s*'盈圆官网'/);
assert.match(adminMenu, /'yy-site'/);
assert.match(
  adminMenu,
  /add_submenu_page\(\s*'yy-site'\s*,\s*'首屏内容'\s*,\s*'首屏内容'\s*,\s*'yy_manage_homepage_settings'\s*,\s*'yy-home-settings'\s*,\s*'yy_render_home_settings_page'\s*\)/,
);
assert.match(
  adminMenu,
  /add_submenu_page\(\s*'yy-site'\s*,\s*'网站设置'\s*,\s*'网站设置'\s*,\s*'manage_options'\s*,\s*'yy-site-settings'\s*,\s*'yy_render_site_settings_page'\s*\)/,
);
assert.match(
  adminMenu,
  /add_submenu_page\(\s*'yy-site'\s*,\s*'发布账号'\s*,\s*'发布账号'\s*,\s*'yy_manage_publishers'\s*,\s*'yy-publishers'\s*,\s*'yy_render_publisher_accounts_page'\s*\)/,
);
assert.match(adminMenu, /function yy_restrict_admin_menu\(\): void/);
assert.match(adminMenu, /add_action\('admin_menu', 'yy_restrict_admin_menu', 9999\)/);
assert.match(adminMenu, /function yy_redirect_limited_admin_users\(\): void/);
assert.match(adminMenu, /add_action\('admin_init', 'yy_redirect_limited_admin_users'\)/);
assert.match(adminMenu, /admin_url\('admin\.php\?page=yy-site'\)/);
assert.match(adminMenu, /admin_url\('profile\.php\?yy_publisher_notice=1'\)/);

let previousOffset = -1;
for (const label of [
  '首页管理',
  '首屏内容',
  '投放平台',
  '服务内容',
  '服务优势',
  '合作流程',
  '客户案例',
  '常见问题',
  '数据效果',
  '新闻列表',
  '新增新闻',
  '新闻分类',
  '图片资料',
  '网站设置',
  '发布账号',
]) {
  const offset = adminMenu.indexOf(`'${label}'`);
  assert.ok(offset > previousOffset, `${label} must be registered in the approved order.`);
  previousOffset = offset;
}

assert.match(contentTypes, /'show_in_menu'\s*=>\s*false/);
assert.match(
  siteSettings,
  /register_setting\(\s*'yy_home_settings_group'\s*,\s*'yy_home_settings'\s*,\s*\[[\s\S]*?'capability'\s*=>\s*'yy_manage_homepage_settings'/,
);
assert.match(
  siteSettings,
  /register_setting\(\s*'yy_site_settings_group'\s*,\s*'yy_site_settings'\s*,\s*\[[\s\S]*?'capability'\s*=>\s*'manage_options'/,
);

console.log('Admin menu contract passed.');
