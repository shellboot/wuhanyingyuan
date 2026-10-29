# 第三方 WordPress 新闻发布接入

## 前提

工具必须支持“自托管 WordPress + REST API + Application Password”，并通过 HTTPS 调用：

```text
https://www.wuhanyingyuan.com/wp-json/wp/v2/
```

本方案不使用 XML-RPC。Nginx 已明确拒绝公网访问 `/xmlrpc.php`。

如果工具只支持 WordPress.com OAuth、旧版 XML-RPC，或要求输入网站管理员明文密码，则不能用当前接口上线；需要先确认工具的官方 WordPress 适配版本和协议。

## 字段映射

| 工具字段 | 填写值 |
| --- | --- |
| 网站框架 | `WordPress` |
| 网站域名 | `https://www.wuhanyingyuan.com`，末尾不带 `/` |
| `API_KEY` | 专用发布账号 `PUBLISHER_USER`，默认 `publisher` |
| `API_PASS` | 该账号的 WordPress Application Password |
| 后台登录账号 | `PUBLISHER_USER`，只用于识别账号 |
| 后台登录密码 | 不要填写管理员密码，也不要把管理员密码用于接口 |
| 验证分类 | `NEWS_CATEGORY_SLUG` 对应的数字 WordPress Term ID，默认分类 slug 为 `industry-news` |
| 发布状态 | 按工具流程选择 `draft` 或 `publish` |

`API_KEY` 的“Key”在 WordPress 场景下是用户名，不是 OAuth Consumer Key。`API_PASS` 是 WordPress 在创建 Application Password 时返回的一次性密码，通常带空格，原样填入工具即可。

## 创建凭据

先完成站点初始化：

```bash
cd /opt/wuhanyingyuan/wordpress
bash scripts/init-site.sh
```

生成发布账号和凭据：

```bash
bash scripts/setup-publisher-user.sh
```

脚本会先确认 `NEWS_CATEGORY_SLUG` 已存在并且能解析为数字分类 ID。分类不存在、slug 配置错误或插件未启用时，脚本会在修改账号角色、邮箱或密码之前直接失败；不会留下“凭据已轮换但分类不可用”的中间状态。

输出示例：

```text
SITE_URL=https://www.wuhanyingyuan.com
API_KEY=publisher
API_PASS=xxxx xxxx xxxx xxxx xxxx xxxx
NEWS_CATEGORY_ID=2
AUTH_MODE=WordPress Application Password (HTTP Basic)
```

这是密码唯一一次显示。把它填入第三方工具后，不要写入仓库、环境模板或普通文本日志。需要轮换时：

```bash
bash scripts/setup-publisher-user.sh --rotate
```

脚本只授予 `yy_publisher` 角色所需的新闻和媒体权限，不具备站点设置、插件安装或主题切换权限。

如果发布账号已经存在至少一个 Application Password，普通执行会在修改账号之前失败。必须先确认旧凭据要撤销，再使用 `--rotate`；该参数会撤销旧密码并立即生成新密码。

## 工具应调用的接口

| 动作 | 方法与路径 |
| --- | --- |
| 验证登录 | `GET /wp-json/wp/v2/users/me` |
| 创建新闻 | `POST /wp-json/wp/v2/posts` |
| 更新新闻 | `POST /wp-json/wp/v2/posts/<id>` |
| 查询分类 | `GET /wp-json/wp/v2/categories` |
| 上传媒体 | `POST /wp-json/wp/v2/media` |
| 设置封面 | 更新文章时填写 `featured_media: <attachment-id>` |

HTTP Basic 认证头由 WordPress Application Password 生成。Nginx 已把 `Authorization` 头传给 PHP-FPM，不应在工具或代理层删除该请求头。

## 新闻字段

创建文章示例：

```json
{
  "title": "新闻标题",
  "content": "<p>正文</p>",
  "excerpt": "摘要",
  "status": "draft",
  "categories": [2],
  "featured_media": 123
}
```

- `title` 和 `content` 由 WordPress 清洗和保存。
- `categories` 使用数字 Term ID，不是分类名称。
- 先上传封面到媒体库，再把返回的附件 ID 写入 `featured_media`。
- 创建成功响应中的 `id` 必须由工具保存；后续编辑使用该 ID 更新，不能每次都重新创建文章。
- 本地草稿不应通过匿名请求读取。发布前由 WordPress 原生权限控制可见性。

## 验证与排查

| 状态 | 常见原因 |
| --- | --- |
| `401` | API_PASS 填成了管理员密码、Application Password 已撤销、Authorization 头没有转发 |
| `403` | 账号被误设为无发布权限的角色，或试图修改站点设置 |
| `404` | REST 固定链接未刷新、分类 ID/文章 ID 不存在 |
| `500` | 检查 `docker compose logs --tail=200 wordpress nginx db` |

生产验证建议：

1. 使用测试标题创建一篇 `draft`。
2. 用响应中的文章 ID 更新摘要，确认文章数量没有增加。
3. 上传一张封面，确认 `featured_media` 正确。
4. 匿名访问草稿地址应返回 404。
5. 发布后确认新闻列表和首页最新资讯出现。
6. 用发布账号访问 `/wp-json/wp/v2/settings`，应返回 `401` 或 `403`。

工具如有自己的“网站验证”按钮，应先验证域名、分类 ID 和凭据，再导入正式新闻。工具的版本及官方 WordPress 帮助页应保存在上线工单中，避免把字段映射误当成自定义协议。
