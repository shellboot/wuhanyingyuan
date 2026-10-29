# 盈圆整站 WordPress 化实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将参考静态站迁为一套由 WordPress 管理、可在 ECS Docker 中部署的完整品牌官网。

**Architecture:** 使用定制经典主题还原前台，独立站点功能插件注册内容类型、字段、迁移命令和后台配置，Docker Compose 运行 WordPress、MariaDB、Nginx 和 TLS 服务。

**Tech Stack:** WordPress、PHP 8.3、MariaDB 11、Nginx、Docker Compose、WP-CLI、Playwright。

**Spec:** `docs/superpowers/specs/2026-10-29-wuhanyingyuan-wordpress-design.md`

> **当前状态（2026-10-29）：** 源码、文档、静态契约和 WordPress Playground 运行态 E2E 已完成；公开站、后台编辑、第三方 REST 发布、草稿隔离、旧 URL 跳转、固定链接和 375/768/1440 响应式页面均已实际验证。Docker/Compose 启动、WP-CLI MariaDB 运行态、生产 Nginx、备份恢复演练、TLS 申请与 ECS 上线验收尚未在本机执行，对应步骤保持未勾选。

## Global Constraints

- 生产环境使用正式域名 `https://www.wuhanyingyuan.com/`，全站强制 HTTPS。
- 页面中文文案、联系电话 `18107552669`、时区 `Asia/Shanghai` 和中文语言环境保持一致。
- 新闻使用 WordPress 原生文章、分类、媒体库、草稿和发布状态。
- 首页重复区块和全站联系信息可在 WordPress 后台维护，不要求编辑代码。
- C 端主题不接管 `/wp-admin/` 和 `/wp-login.php`；管理后台使用 WordPress 标准页面和独立权限，默认与 C 端同域但不同路由。
- 生产环境不得依赖当前 Node.js `server.js`、`data/articles.json` 或 `/admin.html`。
- 主题、站点插件、Docker 配置和运维脚本进入版本库；数据库、上传文件、证书和真实密钥不进入版本库。
- 第三方新闻发布优先使用 WordPress 原生 REST API 和专用账号的 Application Password；`API_KEY` / `API_PASS` 的最终含义以工具官方 WordPress 适配文档为准。
- Nginx 必须把 `Authorization` 请求头传给 PHP-FPM；第三方凭据不得复用超级管理员密码。
- 所有容器镜像使用明确的稳定版本标签，禁止使用 `latest`。
- 公共页面必须在 375px、768px、1440px 宽度下完成视觉和交互回归。
- 新闻正文和自定义字段输出必须经过 WordPress 清洗与转义。
- 每次改动先写失败测试或可复现检查，再实现最小修复并验证。

## Review Focus

- 第三方工具把管理员密码当 `API_PASS`，或 Nginx 丢失 `Authorization` 头时，会造成凭据过度授权或发布验证失败；由发布接口鉴权和 Application Password 测试覆盖。
- 旧文章 ID 或旧详情页 URL 未正确跳转时，外部搜索流量会落到 404；由迁移任务验证 ID 映射和 301 行为。
- 草稿、未来文章或私密文章被公开查询或 REST 接口泄露时，运营内容会提前曝光；由文章可见性测试覆盖。
- 发布工具重复推送、分类 ID 填错或封面媒体上传失败时，会产生重复新闻或内容进入错误栏目；由幂等、分类和特色图片接口测试覆盖。
- 旧正文含 HTML、相对图片地址或脚本片段时，迁移后可能丢图或引入 XSS；由导入清洗测试覆盖。
- ECS 容器重建后数据库或上传卷接错时，内容会“消失”；由持久化重启和备份恢复演练覆盖。
- 移动端导航、微信弹窗、轮播和长标题在 375px 下出现遮挡时，官网会影响咨询转化；由移动端 Playwright 用例覆盖。

---

## 目标文件结构

```text
wordpress/
├── .env.example
├── .gitignore
├── docker-compose.yml
├── docker-compose.prod.yml
├── docker/
│   ├── wordpress/Dockerfile
│   ├── wordpress/php.ini
│   ├── nginx/default.conf
│   └── nginx/security-headers.conf
├── wp-content/
│   ├── themes/wuhanyingyuan/
│   │   ├── style.css
│   │   ├── functions.php
│   │   ├── header.php
│   │   ├── footer.php
│   │   ├── front-page.php
│   │   ├── home.php
│   │   ├── single.php
│   │   ├── page.php
│   │   ├── 404.php
│   │   ├── inc/
│   │   │   ├── assets.php
│   │   │   ├── navigation.php
│   │   │   ├── template-helpers.php
│   │   │   └── seo.php
│   │   ├── template-parts/home/
│   │   │   ├── hero.php
│   │   │   ├── platforms.php
│   │   │   ├── services.php
│   │   │   ├── advantages.php
│   │   │   ├── process.php
│   │   │   ├── cases.php
│   │   │   ├── latest-news.php
│   │   │   ├── about.php
│   │   │   ├── stats.php
│   │   │   ├── faq.php
│   │   │   └── contact.php
│   │   └── assets/
│   │       ├── css/styles.css
│   │       ├── css/news.css
│   │       ├── js/main.js
│   │       └── images/
│   └── plugins/wuhanyingyuan-core/
│       ├── wuhanyingyuan-core.php
│       ├── inc/content-types.php
│       ├── inc/meta-fields.php
│       ├── inc/site-settings.php
│       ├── inc/admin-columns.php
│       ├── inc/legacy-import.php
│       └── inc/publisher-role.php
├── scripts/
│   ├── init-site.sh
│   ├── import-legacy.sh
│   ├── setup-publisher-user.sh
│   ├── backup.sh
│   └── restore.sh
├── docs/
│   └── publisher-integration.md
└── tests/
    ├── e2e/public.spec.ts
    ├── e2e/admin.spec.ts
    ├── e2e/publisher.spec.ts
    ├── playwright.config.ts
    ├── package.json
    └── php/content-model-test.php
```

### Task 1: 建立可启动的 WordPress 容器基线

**Files:**
- Create: `wordpress/docker-compose.yml`
- Create: `wordpress/docker-compose.prod.yml`
- Create: `wordpress/.env.example`
- Create: `wordpress/.gitignore`
- Create: `wordpress/docker/wordpress/Dockerfile`
- Create: `wordpress/docker/wordpress/php.ini`
- Create: `wordpress/docker/nginx/default.conf`
- Create: `wordpress/tests/package.json`
- Create: `wordpress/tests/playwright.config.ts`
- Test: `wordpress/tests/e2e/public.spec.ts`

**Interfaces:**
- Consumes: 无。
- Produces: `docker compose up -d` 启动 `db`、`wordpress`、`nginx`，`wpcli` 通过 Compose profile 按需运行；Nginx 将 `/` 转给 WordPress，PHP-FPM 只接受容器网络访问。

- [x] **Step 1: 写一个首页冒烟测试**

在 `public.spec.ts` 中增加测试 `homepage responds with WordPress`：请求本地站点 `/`，断言状态码为 200，响应正文包含 `wp-content` 或 WordPress 生成的页面标记。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "homepage responds"`

Expected: FAIL，因为容器和站点尚未创建。

- [x] **Step 3: 创建 Compose、Nginx 和 WordPress 镜像骨架**

Compose 使用 `db`、`wordpress`、`nginx` 和按需运行的 `wpcli`；数据库和上传目录使用命名卷；WordPress 镜像基于官方 PHP-FPM 稳定标签；Nginx 配置文件设置 `client_max_body_size 64m`、FastCGI 转发和静态资源缓存；`.gitignore` 排除真实环境变量、证书、上传文件、数据库备份和测试产物。

- [ ] **Step 4: 启动并验证**

Run: `cd wordpress && docker compose up -d && npx playwright test tests/e2e/public.spec.ts --grep "homepage responds"`

Expected: PASS，且 `docker compose ps` 中三个服务为 healthy 或 running。

- [ ] **Step 5: 提交**

```bash
git add wordpress/docker-compose.yml wordpress/docker-compose.prod.yml wordpress/.env.example wordpress/.gitignore wordpress/docker wordpress/tests
git commit -m "chore: add dockerized wordpress baseline"
```

### Task 2: 注册内容类型、分类和后台字段

**Files:**
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php`
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/content-types.php`
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/meta-fields.php`
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/admin-columns.php`
- Create: `wordpress/tests/php/content-model-test.php`
- Modify: `wordpress/docker/wordpress/Dockerfile`

**Interfaces:**
- Consumes: WordPress 插件加载机制。
- Produces: 内容类型键 `yy_platform`、`yy_service`、`yy_advantage`、`yy_process`、`yy_case`、`yy_faq`、`yy_carousel_image`、`yy_stat`；每类内容提供 `_yy_order` 排序字段及设计稿列出的专属字段。

- [x] **Step 1: 写内容模型失败测试**

在 `content-model-test.php` 中断言上述八个内容类型均已注册，且 `_yy_order` 字段支持整数、按升序查询；断言新闻仍使用 WordPress 原生 `post`。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/content-model-test.php`

Expected: FAIL，因为插件未加载。

- [x] **Step 3: 实现站点插件和内容字段**

用 `register_post_type` 注册内容类型，用 `register_post_meta` 和后台 metabox 提供字段；字段保存前执行 nonce、权限和 sanitize 回调检查。

- [ ] **Step 4: 在后台验证字段**

登录 `/wp-admin/`，为每个内容类型新建一条记录，填写字段并保存；刷新后字段值仍在，列表可按排序值显示。

- [ ] **Step 5: 运行测试并提交**

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/content-model-test.php`

Expected: PASS。

```bash
git add wordpress/wp-content/plugins/wuhanyingyuan-core wordpress/tests/php/content-model-test.php wordpress/docker/wordpress/Dockerfile
git commit -m "feat: register site content model"
```

### Task 3: 实现全站设置和首页文案配置

**Files:**
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/site-settings.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/meta-fields.php`
- Test: `wordpress/tests/php/content-model-test.php`

**Interfaces:**
- Consumes: WordPress Settings API、媒体库。
- Produces: 函数 `yy_get_site_setting(string $key, mixed $default = ''): mixed` 和 `yy_get_home_setting(string $key, mixed $default = ''): mixed`，供主题读取联系方式、微信二维码、首页主视觉和各区块标题。

- [x] **Step 1: 扩展失败测试**

增加断言：站点设置支持品牌名、电话、二维码附件 ID、工作时间；首页设置支持 Hero 标题、副标题、两个按钮文案、每个首页区块标题和副标题。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/content-model-test.php`

Expected: FAIL，函数和设置项尚不存在。

- [x] **Step 3: 实现设置页和读取函数**

在 WordPress 后台增加“盈圆设置”和“首页设置”页面；二维码使用媒体库附件选择器；所有输出由主题负责转义，设置层只做保存清洗。

- [ ] **Step 4: 验证后台保存与读取**

Run: `cd wordpress && docker compose run --rm wpcli wp eval 'var_dump(yy_get_site_setting("phone"));'`

Expected: 输出当前后台设置的电话号码。

- [ ] **Step 5: 提交**

```bash
git add wordpress/wp-content/plugins/wuhanyingyuan-core/inc/site-settings.php wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php wordpress/wp-content/plugins/wuhanyingyuan-core/inc/meta-fields.php wordpress/tests/php/content-model-test.php
git commit -m "feat: add editable global and homepage settings"
```

### Task 4: 建立主题基础与全局导航页脚

**Files:**
- Create: `wordpress/wp-content/themes/wuhanyingyuan/style.css`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/functions.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/header.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/footer.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/index.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/page.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/404.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/inc/assets.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/inc/navigation.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/inc/template-helpers.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/assets/css/styles.css`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/assets/js/main.js`
- Test: `wordpress/tests/e2e/public.spec.ts`

**Interfaces:**
- Consumes: `yy_get_site_setting()`、WordPress 菜单和媒体库。
- Produces: 全站页头、主导航、页脚、移动导航、电话浮层、微信弹窗及静态资源加载；主题通过 `wp_enqueue_style` 和 `wp_enqueue_script` 加载资源。

- [x] **Step 1: 写导航和弹窗失败测试**

增加测试：首页存在主导航和页脚；点击“微信咨询”后弹窗可见；按 `Escape` 后弹窗关闭；375px 下点击菜单按钮后导航可见。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "navigation|wechat"`

Expected: FAIL，因为主题尚未启用。

- [x] **Step 3: 迁移基础样式和交互**

从参考站迁移字体、颜色、容器、按钮、导航、页脚和弹窗样式；JavaScript 改为模板安全版本，微信触发按钮和电话链接从后台设置读取。

- [ ] **Step 4: 运行导航测试**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "navigation|wechat"`

Expected: PASS，桌面和 375px 移动视口均通过。

- [ ] **Step 5: 提交**

```bash
git add wordpress/wp-content/themes/wuhanyingyuan
git commit -m "feat: add wordpress theme shell"
```

### Task 5: 还原首页各业务区块

**Files:**
- Create: `wordpress/wp-content/themes/wuhanyingyuan/front-page.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/hero.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/platforms.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/services.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/advantages.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/process.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/cases.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/about.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/stats.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/faq.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/contact.php`
- Modify: `wordpress/wp-content/themes/wuhanyingyuan/assets/css/styles.css`
- Modify: `wordpress/wp-content/themes/wuhanyingyuan/assets/js/main.js`
- Test: `wordpress/tests/e2e/public.spec.ts`

**Interfaces:**
- Consumes: 自定义内容类型查询和 `yy_get_home_setting()`。
- Produces: 首页所有锚点区块、数据效果轮播、成果数据、案例列表、FAQ 折叠和联系 CTA；区块标题与重复项来自后台数据。

- [x] **Step 1: 写首页结构失败测试**

增加测试：页面包含 `#platforms`、`#services`、`#process`、`#cases`、`#about`、`#stats`、`#faq`、`#contact`；后台有内容时对应卡片和轮播图数量与排序正确；轮播按钮能切换当前 slide。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "homepage sections|carousel"`

Expected: FAIL，首页模板尚未创建。

- [x] **Step 3: 实现首页模板**

每个区块使用独立 template part；查询自定义内容类型时设置 `post_status=publish`、`orderby=meta_value_num`、`meta_key=_yy_order`；轮播图从 `yy_carousel_image` 读取，成果数据从 `yy_stat` 读取；空数据时显示可编辑提示而不是报错。

- [ ] **Step 4: 回归视觉和交互**

在 375px、768px、1440px 下运行测试并截图对比参考站；确认卡片文字不溢出、导航不遮挡、轮播按钮可键盘操作。

- [ ] **Step 5: 提交**

```bash
git add wordpress/wp-content/themes/wuhanyingyuan/front-page.php wordpress/wp-content/themes/wuhanyingyuan/template-parts/home wordpress/wp-content/themes/wuhanyingyuan/assets
git commit -m "feat: rebuild homepage sections from wordpress content"
```

### Task 6: 实现新闻列表、详情和首页最新资讯

**Files:**
- Create: `wordpress/wp-content/themes/wuhanyingyuan/home.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/single.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/latest-news.php`
- Modify: `wordpress/wp-content/themes/wuhanyingyuan/front-page.php`
- Create: `wordpress/wp-content/themes/wuhanyingyuan/assets/css/news.css`
- Modify: `wordpress/wp-content/themes/wuhanyingyuan/assets/js/main.js`
- Test: `wordpress/tests/e2e/public.spec.ts`

**Interfaces:**
- Consumes: WordPress 原生文章、分类、特色图片和分页。
- Produces: `/news/` 分页归档、`/news/<slug>/` 详情页、首页最新 3 篇已发布文章；草稿不出现在任何公共查询中。

- [x] **Step 1: 写新闻失败测试**

增加测试：发布 3 篇文章和 1 篇草稿；首页只出现 3 篇；`/news/` 可按时间倒序分页；详情页显示标题、日期、分类、封面和正文；草稿详情返回 404。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "news"`

Expected: FAIL，新闻模板和固定链接尚未配置。

- [x] **Step 3: 实现新闻模板**

新闻归档使用 `WP_Query` 默认主查询和 `the_posts_pagination()`；详情页使用 `the_content()`、`get_the_post_thumbnail()` 和分类链接；无封面时显示参考站的标题首字回退样式。

- [ ] **Step 4: 验证固定链接和草稿隔离**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "news"`

Expected: PASS；匿名访问草稿地址为 404。

- [ ] **Step 5: 提交**

```bash
git add wordpress/wp-content/themes/wuhanyingyuan/home.php wordpress/wp-content/themes/wuhanyingyuan/single.php wordpress/wp-content/themes/wuhanyingyuan/template-parts/home/latest-news.php wordpress/wp-content/themes/wuhanyingyuan/front-page.php wordpress/wp-content/themes/wuhanyingyuan/assets
git commit -m "feat: add wordpress news archive and article pages"
```

### Task 7: 导入旧文章、媒体和 URL 跳转

**Files:**
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/legacy-import.php`
- Create: `wordpress/scripts/import-legacy.sh`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php`
- Test: `wordpress/tests/php/content-model-test.php`
- Test: `wordpress/tests/e2e/public.spec.ts`

**Interfaces:**
- Consumes: 旧 `data/articles.json`、旧 `uploads/` 目录和 WordPress Media API。
- Produces: WP-CLI 命令 `wp yy import-legacy --articles=<path> --uploads=<path>`；文章元数据 `_yy_legacy_id`；旧详情页和新详情页之间的 301 映射。

- [x] **Step 1: 写迁移失败测试**

准备包含已发布文章、草稿、HTML 正文、相对图片和旧 ID 的夹具；断言已发布/草稿状态正确、图片进入媒体库、危险脚本被移除、`_yy_legacy_id` 保存成功。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && docker compose run --rm wpcli wp eval-file tests/php/content-model-test.php`

Expected: FAIL，迁移命令不存在。

- [x] **Step 3: 实现幂等导入命令**

按旧 ID 检查是否已导入；已存在时更新而不是重复创建；正文使用 `wp_kses_post`；图片下载失败时保留原 URL 并记录警告；命令输出导入、跳过和失败数量。

- [ ] **Step 4: 验证旧地址跳转**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "legacy redirect"`

Expected: `/news.html` 和 `/article.html?id=<fixture-id>` 返回 301，并落到对应新页面。

- [ ] **Step 5: 提交**

```bash
git add wordpress/wp-content/plugins/wuhanyingyuan-core/inc/legacy-import.php wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php wordpress/scripts/import-legacy.sh wordpress/tests
git commit -m "feat: import legacy news and preserve urls"
```

### Task 8: SEO、结构化数据和安全输出

**Files:**
- Create: `wordpress/wp-content/themes/wuhanyingyuan/inc/seo.php`
- Modify: `wordpress/wp-content/themes/wuhanyingyuan/functions.php`
- Modify: `wordpress/wp-content/themes/wuhanyingyuan/single.php`
- Modify: `wordpress/docker/nginx/security-headers.conf`
- Test: `wordpress/tests/e2e/public.spec.ts`

**Interfaces:**
- Consumes: WordPress `wp_head`、文章元数据、站点设置。
- Produces: canonical、Open Graph、新闻 Article JSON-LD、Organization JSON-LD、sitemap 链接和基础安全响应头。

- [x] **Step 1: 写 SEO 失败测试**

增加测试：首页和文章页只有一个 canonical；文章页包含 `Article` JSON-LD、发布时间和封面；敏感后台 URL 不出现在 public sitemap；响应包含 `X-Content-Type-Options` 和 `Referrer-Policy`。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "seo|headers"`

Expected: FAIL，标签和响应头缺失。

- [x] **Step 3: 实现 SEO 输出和响应头**

所有动态文本使用 `esc_html`、`esc_attr`、`esc_url` 或 `wp_json_encode`；JSON-LD 不手拼未转义字符串；Nginx 配置只对安全相关头生效，不影响 WordPress 管理页登录。

- [ ] **Step 4: 验证 SEO 与安全头**

Run: `cd wordpress && npx playwright test tests/e2e/public.spec.ts --grep "seo|headers"`

Expected: PASS。

- [ ] **Step 5: 提交**

```bash
git add wordpress/wp-content/themes/wuhanyingyuan/inc/seo.php wordpress/wp-content/themes/wuhanyingyuan/functions.php wordpress/wp-content/themes/wuhanyingyuan/single.php wordpress/docker/nginx/security-headers.conf wordpress/tests/e2e/public.spec.ts
git commit -m "feat: add seo metadata and security headers"
```

### Task 9: 完成后台初始化、权限和编辑流程

**Files:**
- Create: `wordpress/scripts/init-site.sh`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/admin-columns.php`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/content-types.php`
- Test: `wordpress/tests/e2e/admin.spec.ts`

**Interfaces:**
- Consumes: WP-CLI、WordPress 角色系统。
- Produces: 初始化中文站点标题、固定链接、首页、新闻页、菜单、分类和演示内容；编辑角色可管理新闻但不可安装插件或修改主题。

- [x] **Step 1: 写后台流程失败测试**

增加测试：编辑账号可以新建新闻、上传特色图片、保存草稿并发布；不能进入插件安装页；普通编辑无法修改站点设置。

- [ ] **Step 2: 运行测试确认失败**

Run: `cd wordpress && npx playwright test tests/e2e/admin.spec.ts`

Expected: FAIL，角色和初始化脚本尚未完成。

- [x] **Step 3: 实现初始化和角色约束**

脚本使用环境变量创建管理员，不把密码写入仓库；创建“内容编辑”角色并限制 `manage_options`、`install_plugins`、`switch_themes`；为内容类型列表增加缩略图、排序和字段摘要。

- [ ] **Step 4: 验证编辑流程**

Run: `cd wordpress && npx playwright test tests/e2e/admin.spec.ts`

Expected: PASS，草稿不会出现在匿名前台，发布后首页和新闻页可见。

- [ ] **Step 5: 提交**

```bash
git add wordpress/scripts/init-site.sh wordpress/wp-content/plugins/wuhanyingyuan-core/inc/admin-columns.php wordpress/wp-content/plugins/wuhanyingyuan-core/inc/content-types.php wordpress/tests/e2e/admin.spec.ts
git commit -m "feat: add site bootstrap and editor workflow"
```

### Task 10: 接入第三方 WordPress 新闻发布工具

**Files:**
- Create: `wordpress/wp-content/plugins/wuhanyingyuan-core/inc/publisher-role.php`
- Create: `wordpress/scripts/setup-publisher-user.sh`
- Create: `wordpress/docs/publisher-integration.md`
- Modify: `wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php`
- Modify: `wordpress/docker/nginx/default.conf`
- Modify: `wordpress/.env.example`
- Test: `wordpress/tests/e2e/publisher.spec.ts`
- Modify: `wordpress/tests/playwright.config.ts`

**Interfaces:**
- Consumes: Task 1 的 Nginx/PHP-FPM 链路、Task 6 的新闻文章与分类、Task 9 的 WordPress 用户和角色机制。
- Produces: 角色键 `yy_publisher`（显示名“新闻发布接口”）；通过 HTTPS 和 Application Password 调用 `/wp-json/wp/v2/posts`、`/wp-json/wp/v2/media` 和 `/wp-json/wp/v2/categories` 的能力；工具字段映射和运维说明。

- [x] **Step 1: 固化第三方工具的 WordPress 适配契约**

在 `publisher-integration.md` 记录工具名称、版本、官方 WordPress 接入文档、请求端点、鉴权头、文章状态、分类、特色图片和更新文章所需字段。按以下暂定映射填写，但以工具官方文档为最终依据：

| 工具字段 | WordPress 值 |
| --- | --- |
| 网站框架 | WordPress |
| 域名 | `https://www.wuhanyingyuan.com`，不带尾部 `/` |
| `API_KEY` | 专用发布账号 `publisher` 的用户名；若官方适配要求插件生成的发布 Key，则记录为发布 Key |
| `API_PASS` | 专用发布账号的 Application Password；若官方适配要求插件生成的网站密钥，则记录为网站密钥 |
| 后台登录账号 | `publisher` |
| 验证分类 ID | 新闻目标分类的数字 Term ID |

Run: `cd wordpress && docker compose run --rm wpcli wp term list category --fields=term_id,name,slug`

Expected: 能取得新闻分类的数字 `term_id`，并写入接入文档。

- [x] **Step 2: 写发布接口失败测试**

在 `publisher.spec.ts` 增加测试 `publisher REST contract`，覆盖：无凭据和错误 Application Password 创建文章返回 401；`yy_publisher` 可创建草稿并上传特色图片；匿名访问草稿返回 404；发布后前台可见；分类和特色图片已关联；按首次响应中的文章 ID 更新不会新增第二篇文章；访问站点设置和插件管理接口返回 401 或 403。

- [ ] **Step 3: 运行测试确认失败**

Run: `cd wordpress && npx playwright test tests/e2e/publisher.spec.ts --grep "publisher REST contract"`

Expected: FAIL，因为专用角色、测试账号或 REST 鉴权链路尚未配置。

- [x] **Step 4: 实现专用发布角色、账号配置和请求头转发**

在 `publisher-role.php` 中提供幂等的 `register_publisher_role()`，仅授予 `read`、`edit_posts`、`publish_posts`、`upload_files`、`edit_published_posts`；插件激活和部署初始化时调用。`setup-publisher-user.sh` 使用 WP-CLI 创建或复用专用账号并签发 Application Password，凭据只输出到本次配置环境，不写入文件。

在 Nginx FastCGI 配置中传递 `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`，并验证原生 REST API 可创建、更新文章和上传媒体。若工具官方文档明确要求插件专属“发布 Key/网站密钥”，只按其协议增加最小兼容层；不得自行发明协议，也不得把该字段解释为管理员密码。

- [x] **Step 5: 使用临时测试凭据运行接口测试**

Run: `cd wordpress && npx playwright test tests/e2e/publisher.spec.ts --grep "publisher REST contract"`

Expected: PASS；错误密码被拒绝，专用账号只能操作新闻和媒体，草稿在发布前匿名不可见。Playground 已用专用 `publisher` 账号和 Application Password 在 3 个视口通过该契约；生产 Nginx 的 `Authorization` 转发仍须在 Docker 环境验证。

- [ ] **Step 6: 用发布工具验证站点并保存**

在工具中填写 WordPress、正式域名、专用账号、Application Password 和验证分类 ID，执行“验证网站并保存”；创建一篇草稿并确认作者、分类和特色图片正确。若工具要求不同字段含义，先更新接入文档，再调整实现和自动化测试。

- [ ] **Step 7: 提交**

```bash
git add wordpress/wp-content/plugins/wuhanyingyuan-core/inc/publisher-role.php wordpress/wp-content/plugins/wuhanyingyuan-core/wuhanyingyuan-core.php wordpress/scripts/setup-publisher-user.sh wordpress/docs/publisher-integration.md wordpress/docker/nginx/default.conf wordpress/.env.example wordpress/tests/e2e/publisher.spec.ts wordpress/tests/playwright.config.ts
git commit -m "feat: add wordpress publisher integration"
```

### Task 11: 容器化生产部署、HTTPS 与重启恢复

**Files:**
- Modify: `wordpress/docker-compose.prod.yml`
- Modify: `wordpress/docker/nginx/default.conf`
- Create: `wordpress/docker/nginx/tls.conf`
- Create: `wordpress/scripts/deploy.sh`
- Modify: `wordpress/.env.example`
- Test: `wordpress/tests/e2e/public.spec.ts`

**Interfaces:**
- Consumes: Task 1 的 Compose 基线和正式域名 DNS。
- Produces: 生产模式启动命令、自动申请/续期证书、健康检查、容器重启策略和部署回滚步骤。

- [x] **Step 1: 写部署失败检查**

增加 shell 检查：缺少 `SITE_URL`、数据库密码或邮箱时部署脚本非零退出；已有站点从 HTTP 访问时收到 301 到 HTTPS。

- [ ] **Step 2: 运行检查确认失败**

Run: `cd wordpress && ./scripts/deploy.sh --check`

Expected: FAIL，脚本尚未实现或环境变量不完整。

- [x] **Step 3: 实现生产配置**

生产 Compose 设置 `restart: unless-stopped`、健康检查、只读证书挂载、数据库不暴露端口；Nginx 配置 ACME challenge 到 HTTPS 跳转，并对 `wp-login.php` 增加限速、拒绝 `xmlrpc.php` 公共请求；WordPress `WP_HOME`、`WP_SITEURL` 从 `SITE_URL` 生成。

- [ ] **Step 4: 执行重启恢复验证**

Run: `cd wordpress && docker compose -f docker-compose.yml -f docker-compose.prod.yml down && docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d`

Expected: 重启后首页、新闻、后台和已上传图片均可访问，数据库没有重新初始化。

- [ ] **Step 5: 提交**

```bash
git add wordpress/docker-compose.prod.yml wordpress/docker/nginx wordpress/scripts/deploy.sh wordpress/.env.example
git commit -m "chore: add production deployment and tls setup"
```

### Task 12: 备份、恢复和上线验收

**Files:**
- Create: `wordpress/scripts/backup.sh`
- Create: `wordpress/scripts/restore.sh`
- Create: `wordpress/docs/deploy.md`
- Modify: `wordpress/tests/e2e/public.spec.ts`
- Modify: `wordpress/tests/e2e/admin.spec.ts`

**Interfaces:**
- Consumes: 生产 Compose 服务、数据库卷和上传卷。
- Produces: 带时间戳的数据库与上传备份、可恢复脚本、完整上线验收清单和故障回滚步骤。

- [x] **Step 1: 写备份恢复验收检查**

验证脚本生成非空数据库 dump 和 uploads 压缩包；在临时 Compose 项目恢复后，文章数量、首页设置和特色图片一致。

- [ ] **Step 2: 执行一次完整备份恢复演练**

Run: `cd wordpress && ./scripts/backup.sh && ./scripts/restore.sh --latest --dry-run`

Expected: `--dry-run` 验证归档可读且服务名正确；正式恢复在测试卷完成。

- [x] **Step 3: 编写部署和运维文档**

文档写明 ECS 安全组、DNS、首次初始化、管理员创建、证书续期、日志查看、升级、备份计划、恢复步骤和回滚命令。

- [ ] **Step 4: 运行全量验收**

Run: `cd wordpress && npx playwright test && docker compose run --rm wpcli wp eval-file tests/php/content-model-test.php`

Expected: 所有公开页、后台编辑、第三方发布接口、迁移、SEO、草稿隔离、移动端和内容模型测试通过。

- [ ] **Step 5: 提交**

```bash
git add wordpress/scripts wordpress/docs wordpress/tests
git commit -m "docs: add backup recovery and launch runbook"
```

## 实施顺序与交付节点

1. 任务 1 至 3：得到可运行、可登录、可维护内容模型的 WordPress 基线。
2. 任务 4 至 6：得到完整可浏览的官网和新闻功能。
3. 任务 7：完成旧数据与链接迁移。
4. 任务 8 至 9：完成 SEO、安全和后台运营流程。
5. 任务 10：完成第三方 WordPress 新闻发布工具接入和最小权限验证。
6. 任务 11 至 12：完成 ECS 生产部署、HTTPS、备份和上线验收。

## 编码前仍需提供的环境信息

- ECS 的 Linux 发行版和 Docker/Compose 是否已安装。
- 正式域名在哪管理，DNS 是否可切到当前 ECS。
- 80/443 是否被现有 Nginx、Apache 或其他容器占用。
- 旧服务器 `data/articles.json` 与 `uploads/` 是否可访问。
- 管理后台是否接受同域 `/wp-admin/`，还是必须使用独立管理子域名。
- 第三方发布工具的名称、版本、官方 WordPress 接入文档，以及是否需要安装专属插件。
- 首期是否需要百度统计、Google Analytics、百度站长平台或其他第三方验证。

## 计划自检

- 设计稿中的首页、新闻、内容维护、第三方发布、迁移、SEO、Docker、安全和备份均对应到任务。
- 主题只负责展示，内容类型和迁移逻辑位于站点插件，避免主题切换导致数据不可见。
- 所有公开内容查询只使用已发布状态，第三方发布鉴权、草稿隔离、旧 URL 和移动端均有独立验证。
- `API_KEY` / `API_PASS` 默认映射为专用账号和 Application Password，并明确保留工具专属密钥方案的核对步骤。
- 本计划已进入实施阶段；源码和静态契约已完成，运行时验收项须在具备 Docker/PHP/Bash 的环境继续执行。
