# 盈圆官网 WordPress 管理后台实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把盈圆官网运营功能统一到“盈圆官网”后台入口，并限制内容编辑和发布接口账号的权限边界。

**Architecture:** 在现有盈圆核心插件中新增独立的后台菜单模块和发布账号模块。内容类型不再各自注册顶级菜单，统一由菜单模块链接到 WordPress 原生列表、编辑、媒体和分类页面；普通内容编辑只保留盈圆官网菜单，管理员继续保留完整 WordPress 维护菜单。

**Tech Stack:** WordPress、PHP 8.3、WP-CLI、Playwright、Node.js 静态契约测试、Docker。

**Spec:** `docs/superpowers/specs/2026-10-29-wuhanyingyuan-wordpress-admin-design.md`

## Global Constraints

- `administrator` 保留完整 WordPress 菜单，并增加“盈圆官网”入口。
- `yy_content_editor` 只显示盈圆官网相关菜单，可以管理首页、新闻、图片和发布账号。
- `yy_content_editor` 不得获得 `manage_options`、`edit_users`、`promote_users`，不能访问网站设置。
- `yy_publisher` 仅用于 REST API，不显示盈圆官网或 WordPress 管理菜单。
- 发布账号页面只能查询和修改角色严格等于 `yy_publisher` 的用户。
- Application Password 明文只在生成响应中显示一次，不写数据库、日志、URL 或页面源码以外的持久位置。
- 所有管理操作必须同时执行能力检查、nonce 校验、输入清洗和服务端对象权限检查。
- 菜单隐藏不能替代页面权限；直接访问受限 URL 仍必须被 WordPress 能力系统拒绝。
- 不修改数据库表结构，不使用 SQL 脚本管理菜单。
- 不改变前台页面、现有内容记录、REST API 路径和现有 Application Password 鉴权协议。
- WordPress 菜单只使用两级结构；三层语义由首页管理入口页组织。
- 用户明确要求不执行 `git commit`、`git push`、`git reset` 或 `git clean`。
- 现有 `wordpress/.env.example` 改动属于用户内容，不还原、不覆盖。

## Review Focus

- 内容编辑通过伪造 `user_id` 修改管理员/其他编辑账号，或直接访问设置页时，必须被服务端权限检查拒绝；由 PHP 和后台 E2E 覆盖。
- 内容编辑能打开首页设置但保存时被 `options.php` 按 `manage_options` 拒绝时，首页文案将无法维护；由设置注册能力和后台保存测试覆盖。
- 发布账号停用后旧 Application Password 仍能发布时，会造成未授权内容写入；由 Application Password 认证钩子测试和 REST E2E 覆盖。
- 旧发布账号尚未保存启用状态，或账号同时拥有多个角色时，不能被误停用或纳入受限管理页面；由 PHP 兼容性与严格角色测试覆盖。
- 重组菜单后管理员丢失原生菜单、内容编辑看到重复菜单或内容类型入口消失，会阻断日常运营；由管理员和编辑角色的 E2E 菜单断言覆盖。

---

## 目标文件结构

```text
wordpress/
├── wp-content/plugins/wuhanyingyuan-core/
│   ├── wuhanyingyuan-core.php
│   └── inc/
│       ├── admin-menu.php
│       ├── content-types.php
│       ├── publisher-accounts.php
│       ├── publisher-role.php
│       └── site-settings.php
├── docs/admin-guide.md
└── tests/
    ├── package.json
    ├── e2e/admin.spec.ts
    ├── php/admin-experience-test.php
    └── static/
        ├── admin-menu-contract.mjs
        └── deployment-contract.mjs
```

### Task 1: 建立角色能力和运行态权限测试

**Files:**
- Create: `wordpress/tests/php/admin-experience-test.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/publisher-role.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php`
- Modify: `wordpress/tests/static/deployment-contract.mjs`

**Interfaces:**
- Consumes: 现有 `yy_sync_role_capabilities(string $role_key, string $display_name, array $allowed): void`。
- Produces: 新能力 `yy_manage_homepage_settings` 和 `yy_manage_publishers`；管理员与 `yy_content_editor` 拥有这两项能力，`yy_publisher` 不拥有。

- [ ] **Step 1: 写角色能力失败测试**

在 `admin-experience-test.php` 中定义记录失败项并继续执行的断言函数，然后断言：

```php
$administrator = get_role('administrator');
$editor = get_role('yy_content_editor');
$publisher = get_role('yy_publisher');

yy_admin_assert($administrator->has_cap('yy_manage_homepage_settings'), 'Administrator must edit homepage settings.');
yy_admin_assert($administrator->has_cap('yy_manage_publishers'), 'Administrator must manage publishers.');
yy_admin_assert($editor->has_cap('yy_manage_homepage_settings'), 'Content editor must edit homepage settings.');
yy_admin_assert($editor->has_cap('yy_manage_publishers'), 'Content editor must manage publishers.');
yy_admin_assert(!$editor->has_cap('manage_options'), 'Content editor must not manage site options.');
yy_admin_assert(!$editor->has_cap('edit_users'), 'Content editor must not edit users.');
yy_admin_assert(!$editor->has_cap('promote_users'), 'Content editor must not promote users.');
yy_admin_assert(!$publisher->has_cap('yy_manage_homepage_settings'), 'Publisher must not access site administration.');
yy_admin_assert(!$publisher->has_cap('yy_manage_publishers'), 'Publisher must not manage accounts.');
```

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/admin-experience-test.php`

Expected: FAIL，提示管理员或内容编辑缺少新能力。

- [ ] **Step 3: 增加两项最小能力并同步角色**

在 `publisher-role.php` 中让管理员和 `yy_content_editor` 获得 `yy_manage_homepage_settings`、`yy_manage_publishers`。不得向任一角色增加 `manage_options`、`edit_users` 或 `promote_users`。

把插件版本提升到 `1.1.0`，使 `yy_core_sync_roles()` 在部署后刷新现有角色；同步把 `deployment-contract.mjs` 中的版本断言更新为 `1.1.0`。

- [ ] **Step 4: 运行测试确认通过**

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/admin-experience-test.php`

Expected: PASS，输出角色和管理后台权限测试通过。

### Task 2: 建立盈圆官网菜单和固定链接顺序

**Files:**
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/admin-menu.php`
- Create: `wordpress/tests/static/admin-menu-contract.mjs`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/content-types.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/site-settings.php`
- Modify: `wordpress/tests/package.json`
- Modify: `wordpress/tests/static/deployment-contract.mjs`

**Interfaces:**
- Consumes: Task 1 的 `yy_manage_homepage_settings`、`yy_manage_publishers`，以及现有 `edit_posts`、`edit_yy_homepage_items`、`upload_files` 能力。
- Produces: 菜单 slug `yy-site`；函数 `yy_register_yy_admin_menu(): void`、`yy_render_site_manager_page(): void`、`yy_render_data_effects_page(): void`。

- [ ] **Step 1: 写菜单静态契约失败测试**

新建 `admin-menu-contract.mjs`，读取盈圆核心插件源码并断言：

```js
assert.match(adminMenu, /add_menu_page\(\s*'盈圆官网'/);
assert.match(adminMenu, /'yy-site'/);
for (const label of [
  '首页管理', '首屏内容', '投放平台', '服务内容', '服务优势',
  '合作流程', '客户案例', '常见问题', '数据效果',
  '新闻列表', '新增新闻', '新闻分类', '图片资料', '网站设置', '发布账号',
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
```

在循环前初始化 `let previousOffset = -1;`。

把脚本加入 `tests/package.json` 的 `test:static`，并把 `admin-menu.php`、`admin-experience-test.php`、`admin-menu-contract.mjs` 加入 `deployment-contract.mjs` 的必需文件列表。

- [ ] **Step 2: 运行测试确认失败**

Run: `npm --prefix wordpress/tests run test:static`

Expected: FAIL，因为 `admin-menu.php` 尚不存在，内容类型仍注册独立菜单。

- [ ] **Step 3: 实现顶级菜单和固定顺序子菜单**

在 `admin-menu.php` 中按以下顺序注册：

```text
首页管理
首屏内容
投放平台
服务内容
服务优势
合作流程
客户案例
常见问题
数据效果
新闻列表
新增新闻
新闻分类
图片资料
网站设置
发布账号
```

实现要求：

- 顶级菜单标题为“盈圆官网”，slug 为 `yy-site`，能力为 `yy_manage_homepage_settings`。
- “首屏内容”链接到 `admin.php?page=yy-home-settings`。
- 六个普通首页内容类型链接到各自的 `edit.php?post_type=<type>`。
- “数据效果”进入 `yy_render_data_effects_page()`，只提供 `yy_carousel_image` 和 `yy_stat` 的列表及新增链接。
- 新闻链接复用 `edit.php`、`post-new.php`、`edit-tags.php?taxonomy=category`。
- 图片资料复用 `upload.php`。
- 网站设置链接到 `admin.php?page=yy-site-settings`，仅管理员可见。
- 发布账号链接到 `admin.php?page=yy-publishers`，能力为 `yy_manage_publishers`。
- 内容类型注册统一改为 `show_in_menu => false`，保留 `show_ui`、REST 和能力配置。
- 设置页不再创建“盈圆设置”顶级菜单；全站设置使用 `manage_options`，首页文案使用 `yy_manage_homepage_settings`。
- `register_setting()` 注册 `yy_site_settings` 时显式使用 `manage_options`，注册 `yy_home_settings` 时显式使用 `yy_manage_homepage_settings`；设置表单渲染和 `options.php` 保存必须使用同一能力。
- `yy_render_site_settings_page()` 只允许 `manage_options`，`yy_render_home_settings_page()` 只允许 `yy_manage_homepage_settings`；共享表单渲染函数必须接收并校验调用方指定的能力。

- [ ] **Step 4: 运行静态契约**

Run: `npm --prefix wordpress/tests run test:static`

Expected: PASS，菜单、设置能力和必需文件契约均通过。

### Task 3: 精简内容编辑后台并保护管理员体验

**Files:**
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/admin-menu.php`
- Modify: `wordpress/tests/e2e/admin.spec.ts`
- Modify: `wordpress/tests/php/admin-experience-test.php`

**Interfaces:**
- Consumes: Task 2 的 `yy-site` 菜单、登录会话和 WordPress `admin_menu` / `admin_init` 钩子。
- Produces: `yy_restrict_admin_menu(): void`、`yy_redirect_limited_admin_users(): void`；内容编辑默认进入 `admin.php?page=yy-site`。

- [ ] **Step 1: 写内容编辑后台失败测试**

在 `admin.spec.ts` 登录内容编辑后增加断言：

```ts
await expect(page.locator('#toplevel_page_yy-site')).toBeVisible();
await expect(page.locator('#menu-dashboard')).toHaveCount(0);
await expect(page.locator('#menu-posts')).toHaveCount(0);
await expect(page.locator('#menu-media')).toHaveCount(0);
await expect(page.locator('#menu-comments')).toHaveCount(0);
await expect(page.locator('#menu-appearance')).toHaveCount(0);
await expect(page.locator('#menu-plugins')).toHaveCount(0);
await expect(page.locator('#menu-users')).toHaveCount(0);
await expect(page.locator('#menu-tools')).toHaveCount(0);
await expect(page.locator('#menu-settings')).toHaveCount(0);
```

访问 `/wp-admin/` 后断言 URL 包含 `admin.php?page=yy-site`。访问 `/wp-admin/admin.php?page=yy-home-settings` 应成功；修改一个字段并提交后，重新打开页面应看到新值，再把原值保存回去。访问 `/wp-admin/admin.php?page=yy-site-settings`、`users.php` 和 `options-general.php` 应被拒绝。

PHP 测试补充：`yy_content_editor` 不具备页面、评论、主题、插件和用户管理能力。

- [ ] **Step 2: 运行后台测试确认失败**

Run: `cd wordpress && npm --prefix tests run test:admin`

Expected: FAIL，内容编辑仍能看到原生菜单，或访问后台首页时没有进入盈圆官网。

- [ ] **Step 3: 实现菜单白名单和默认跳转**

对 `yy_content_editor` 在菜单生成完成后只保留顶级 `yy-site`，其他顶级菜单和分隔项全部移除。不要修改管理员菜单。

在 `admin_init` 中：

- 内容编辑访问 `index.php` 时重定向到 `admin.php?page=yy-site`。
- `yy_publisher` 访问除 `profile.php` 以外的任何 `wp-admin` 页面时，重定向到个人资料页并显示“发布账号仅用于新闻发布接口”的提示。
- 该重定向不作用于 REST、登录流程或前台请求；发布账号仍可通过 REST 和 Application Password 使用现有发布接口。

直接 URL 的最终授权继续依赖 WordPress 页面自身的 `current_user_can()` 检查。

发布账号的后台重定向由 Task 5 在创建出已知登录密码的测试账号后覆盖；Task 5 必须直接访问 `/wp-admin/upload.php` 和 `/wp-admin/post-new.php`，断言均被重定向到 `profile.php`。

- [ ] **Step 4: 运行后台和角色测试**

Run: `cd wordpress && npm --prefix tests run test:admin`

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/admin-experience-test.php`

Expected: PASS，普通编辑只看到盈圆官网菜单，管理员菜单不受影响。

### Task 4: 实现受限发布账号管理

**Files:**
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/publisher-accounts.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/admin-menu.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php`
- Modify: `wordpress/tests/php/admin-experience-test.php`
- Modify: `wordpress/tests/static/admin-menu-contract.mjs`

**Interfaces:**
- Consumes: `yy_manage_publishers`、`yy_publisher` 角色、WordPress `admin_post_*`、nonce 和 `WP_User_Query`。
- Produces:
- `yy_get_publisher_accounts(): array<int, WP_User>`
- `yy_get_publisher_account(int $user_id): WP_User|WP_Error`
- `yy_is_pure_publisher(WP_User $user): bool`
- `yy_create_publisher_account(string $username, string $email, string $display_name): int|WP_Error`
- `yy_render_publisher_accounts_page(): void`
- `yy_handle_publisher_account_action(): void`

- [ ] **Step 1: 写发布账号边界失败测试**

PHP 测试创建临时 `yy_publisher` 用户和临时 `yy_content_editor` 用户，断言：

```php
$publishedIds = array_map(static fn (WP_User $user): int => $user->ID, yy_get_publisher_accounts());
yy_admin_assert(in_array($publisherUser->ID, $publishedIds, true), 'Publisher list must contain publisher accounts.');
yy_admin_assert(!in_array($editorUser->ID, $publishedIds, true), 'Publisher list must exclude content editors.');
yy_admin_assert(is_wp_error(yy_get_publisher_account($editorUser->ID)), 'Editor account must be rejected as a publisher.');
yy_admin_assert(is_wp_error(yy_get_publisher_account(1)), 'Administrator must be rejected as a publisher.');
```

再创建一个同时拥有 `yy_publisher` 和 `yy_content_editor` 角色的临时用户，断言 `yy_is_pure_publisher()` 返回 `false`，该用户不出现在 `yy_get_publisher_accounts()`，`yy_get_publisher_account()` 返回 `WP_Error`。

在临时用户清理阶段使用 `require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($id);`。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/admin-experience-test.php`

Expected: FAIL，发布账号查询和严格角色校验函数尚不存在。

- [ ] **Step 3: 实现发布账号页面和创建操作**

先通过 `WP_User_Query` 查询 `role=yy_publisher` 的候选账号，再用 `yy_is_pure_publisher()` 要求用户的 roles 严格等于 `['yy_publisher']`；多角色候选不得出现在页面，也不得成为任何账号操作的目标。创建表单只接受登录名、邮箱和显示名称，不接受角色字段；服务端固定使用 `yy_publisher`，并在新账号上写入 `_yy_publisher_enabled = 1`。

页面提供：

- 发布账号表格：显示名称、登录名、创建时间、启用状态
- “新建发布账号”表单
- 每个账号的生成 Application Password、重置登录密码、停用或启用按钮

所有 `admin-post.php` 操作使用包含动作和目标用户 ID 的 nonce，例如 `yy_publisher_{$operation}_{$user_id}`，并执行 `current_user_can('yy_manage_publishers')` 和目标角色校验。创建账号前校验登录名、邮箱、显示名称和唯一性；创建后的启用状态写入失败时返回错误，不留下半创建账号。失败时返回中文错误提示。

- [ ] **Step 4: 运行 PHP 和静态测试**

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/admin-experience-test.php`

Run: `npm --prefix wordpress/tests run test:static`

Expected: PASS；发布账号页面无法枚举或修改管理员、内容编辑。

### Task 5: 管理 Application Password 与停用状态

**Files:**
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/publisher-accounts.php`
- Modify: `wordpress/tests/e2e/admin.spec.ts`
- Modify: `wordpress/tests/php/admin-experience-test.php`

**Interfaces:**
- Consumes: Task 4 的发布账号页面、`WP_Application_Passwords` 和 WordPress `wp_authenticate_application_password_errors` action。
- Produces:
  - `yy_is_publisher_enabled(int $user_id): bool`
  - `yy_set_publisher_enabled(int $user_id, bool $enabled): true|WP_Error`
  - `yy_create_publisher_application_password(int $user_id, string $name): array|WP_Error`
  - `yy_reject_disabled_application_password(WP_Error $error, WP_User $user, array $item): void`

- [ ] **Step 1: 写账号生命周期失败测试**

在后台 E2E 中创建唯一发布账号，生成 Application Password，并断言响应中显示一次密码。使用该密码创建草稿应成功。

停用账号后再次使用同一 Application Password 创建草稿，应返回 `401` 或 `403`。重新启用后生成新的 Application Password，并确认新凭据可以创建草稿。

同一 E2E 通过发布账号页面设置已知登录密码，退出当前会话后登录该账号，并直接访问 `/wp-admin/`、`/wp-admin/upload.php` 和 `/wp-admin/post-new.php`，断言均重定向到 `/wp-admin/profile.php`。随后重新登录内容编辑账号继续完成停用、启用和新凭据验证。

PHP 测试断言：

```php
yy_admin_assert(yy_is_publisher_enabled($publisherUser->ID), 'New publisher accounts must be enabled by default.');
$disabled = yy_set_publisher_enabled($publisherUser->ID, false);
yy_admin_assert($disabled === true, 'A publisher account must be disableable.');
yy_admin_assert(!yy_is_publisher_enabled($publisherUser->ID), 'Disabled publisher state must persist.');
yy_admin_assert(is_wp_error(yy_set_publisher_enabled($editorUser->ID, false)), 'Editor accounts must not be disabled through publisher management.');

$applicationPasswordError = new WP_Error();
yy_reject_disabled_application_password($applicationPasswordError, $publisherUser, []);
yy_admin_assert($applicationPasswordError->has_errors(), 'Disabled publishers must fail Application Password authentication.');
```

再创建一个没有 `_yy_publisher_enabled` 元数据的临时纯发布账号，断言其仍被视为启用；为它写入 `0` 后才视为停用。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && npm --prefix tests run test:admin`

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/admin-experience-test.php`

Expected: FAIL，账号页面尚不能签发凭据或停用状态不生效。

- [ ] **Step 3: 实现凭据签发和认证拒绝**

生成 Application Password 时调用 `WP_Application_Passwords::create_new_application_password()`，直接把返回的明文写入当前 POST 响应，并通过 `nocache_headers()` 禁止缓存。不得重定向、写入查询参数、用户元数据、transient 或日志，也不得在之后重新读取或回显密码。响应中只显示本次生成的密码和返回发布账号页面的链接。

停用账号时：

- 把 `_yy_publisher_enabled` 用户元数据更新为 `0`
- 调用 `WP_Application_Passwords::delete_all_application_passwords($user_id)` 撤销现有凭据
- 使用 `add_action('wp_authenticate_application_password_errors', 'yy_reject_disabled_application_password', 10, 3)` 在 Application Password 已匹配后检查纯 `yy_publisher` 的启用状态；已停用账号向传入的 `WP_Error` 添加错误，不能记录或回显原始密码

不要使用 `authenticate` 过滤器实现这一步：WordPress REST Application Password 由 `wp_validate_application_password()` 调用 `wp_authenticate_application_password()` 完成，不经过普通登录表单的 `authenticate` 链。`wp_authenticate_application_password_errors` 会在密码哈希匹配后、使用记录写入前执行，是停用账号的正确第二道服务端边界。

兼容旧账号：没有 `_yy_publisher_enabled` 元数据的纯 `yy_publisher` 视为启用。只有显式保存为 `0` 的账号才拒绝认证，避免升级后现有发布工具立即中断。新建账号必须在创建时成功写入 `_yy_publisher_enabled = 1`，否则创建操作失败。

重新启用只把状态改为 `1`，不恢复旧 Application Password。

- [ ] **Step 4: 运行生命周期测试**

Run: `cd wordpress && npm --prefix tests run test:admin`

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/admin-experience-test.php`

Expected: PASS；停用账号的旧凭据失效，重新启用后必须签发新凭据。

### Task 6: 完成版本、文档和整站回归

**Files:**
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php`
- Modify: `wordpress/tests/static/deployment-contract.mjs`
- Create: `wordpress/docs/admin-guide.md`
- Modify: `wordpress/tests/e2e/publisher.spec.ts` only if existing publisher contract regressions require adaptation.

**Interfaces:**
- Consumes: Task 1 至 5 的菜单、权限和发布账号行为。
- Produces: 插件版本 `1.1.0`、管理员操作说明和完整验证结果。

- [ ] **Step 1: 更新版本和静态契约**

确认插件头、`YY_CORE_VERSION` 和部署静态契约都要求 `1.1.0`。删除静态测试中任何对旧顶级“盈圆设置”菜单结构的假设。

- [ ] **Step 2: 编写后台操作说明**

在 `admin-guide.md` 中说明：

- 盈圆官网菜单结构
- 管理员、内容编辑和发布接口账号的权限差异
- 新建发布账号和生成 Application Password 的步骤
- 停用账号后旧凭据立即失效
- 内容编辑不能访问网站设置
- 不通过数据库或 SQL 修改后台菜单

- [ ] **Step 3: 运行静态和 PHP 验证**

Run: `npm --prefix wordpress/tests run test:static`

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/admin-experience-test.php`

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/content-model-test.php`

Expected: 全部 PASS。

- [ ] **Step 4: 运行后台和发布接口 E2E**

Run: `cd wordpress && npm --prefix tests run test:admin`

Run: `cd wordpress && npm --prefix tests run test:publisher`

Expected: 全部 PASS；内容编辑菜单、设置隔离、发布账号生命周期和现有发布工具兼容性均通过。

- [ ] **Step 5: 运行差异检查**

Run: `git -c core.autocrlf=false diff --check`

Run: `git status --short`

Expected: 无空白错误；只包含本次后台功能文件、测试、设计和计划，以及用户原有 `wordpress/.env.example` 改动。

## 实施完成后的人工验收

1. 用管理员登录 `/wp-admin/`，确认完整 WordPress 菜单和“盈圆官网”同时存在。
2. 用 `yy_content_editor` 登录，确认默认进入盈圆官网，左侧没有 WordPress 原生内容、外观、插件、用户、工具和设置菜单。
3. 从盈圆官网依次进入首页文案、投放平台、服务内容、服务优势、合作流程、客户案例、常见问题和数据效果，确认列表和新增入口可用。
4. 从新闻列表新增一篇草稿，检查草稿不出现在前台，发布后出现在新闻页和首页最新新闻。
5. 在图片资料上传一张图片，并用于新闻特色图片。
6. 新建发布账号，生成 Application Password，用发布工具创建草稿。
7. 停用该发布账号，确认旧凭据不能再调用 REST API；重新启用后生成新凭据并验证。
8. 用内容编辑直接访问 `/wp-admin/options-general.php` 和 `/wp-admin/admin.php?page=yy-site-settings`，确认被拒绝。
9. 用管理员验证外观、插件、用户、工具和 WordPress 设置仍可正常访问。

## 计划自检

- 设计中的盈圆官网菜单、首页管理、新闻、图片、设置、发布账号和角色边界均有对应任务。
- 内容类型独立顶级菜单会移除，首页和新闻仍复用 WordPress 原生数据与编辑页面。
- 内容编辑能管理发布账号，但不能访问网站设置。
- 发布账号页面严格限制目标用户角色，避免通过账号管理获得管理员权限。
- 停用发布账号会撤销 Application Password，并通过认证过滤器阻止旧凭据。
- 管理员完整菜单、直接 URL 权限和现有 REST 发布接口均包含回归测试。
- 设计中的 Application Password 一次性展示、nonce、输入清洗和中文错误提示均落到具体任务。
- 首页设置页面能力和 `register_setting` 保存能力使用同一能力，避免“能打开但不能保存”。
- 发布账号查询和修改同时执行严格单角色判定，并兼容升级前没有启用状态元数据的账号。
- 发布账号 E2E 使用已知登录密码覆盖后台所有主要入口的重定向，避免 `upload_files` 或 `edit_posts` 绕过 API-only 限制。
- 未安排提交、推送、重置或清理命令，符合用户当前的 Git 操作要求。
