# 盈圆 WordPress 生产部署手册

## 运行架构

- `nginx`：公网入口，监听 80/443，转发 PHP 请求到 `wordpress`。
- `wordpress`：PHP-FPM 与 WordPress 运行容器。
- `db`：仅在内网提供 MariaDB，不映射宿主机端口。
- `wpcli`：按需运行的运维工具容器。
- `certbot`：按需运行，负责首次签发和后续续期证书。
- `wordpress_data`、`uploads_data`、`db_data`：Docker 命名卷，保存 WordPress 核心、配置、上传文件和数据库。
- `wp-content/themes` 与 `wp-content/plugins`：从仓库只读挂载，避免命名卷遮住部署代码。

所有常驻容器使用 Docker `json-file` 日志轮转，单文件上限 `10m`，最多保留 `5` 个文件。

公共主题只接管前台。WordPress 管理后台仍使用标准页面：

```text
https://www.wuhanyingyuan.com/wp-admin/
```

它不是前台主题中的页面，登录态和权限均由 WordPress 核心管理。

## ECS 前置条件

1. 使用 Ubuntu 22.04/24.04 或兼容 Docker 的 Linux 发行版。
2. 安装 Docker Engine 和 Docker Compose v2，并确认 `docker compose version` 可执行。
3. 安装 `flock`（Ubuntu 的 `util-linux` 包）和 GNU `tar`，并确认 `flock --help`、`tar --version` 可执行。
4. ECS 安全组只开放公网 `22`、`80`、`443`；不要开放 `3306`。
5. 将 `www.wuhanyingyuan.com` 的 A 记录指向 ECS 公网 IP。
6. 如需支持裸域 `wuhanyingyuan.com`，在 DNS 服务商处把它 301 到 `https://www.wuhanyingyuan.com/`。
7. 确保域名可以完成 HTTP-01 验证，首次部署前 `80` 端口必须能从公网访问。

## 首次部署

```bash
cd /opt/wuhanyingyuan/wordpress
cp .env.example .env
chmod 600 .env
```

编辑 `.env`，至少替换以下值：

- `CERTBOT_EMAIL`
- `DB_PASSWORD`
- `DB_ROOT_PASSWORD`
- `ADMIN_PASSWORD`
- `ADMIN_EMAIL`
- `EDITOR_PASSWORD`，如果首期需要创建内容编辑账号
- `PUBLISHER_EMAIL`

包含空格、`#`、`$` 等字符的密码必须使用单引号包起来，例如：

```dotenv
DB_PASSWORD='replace with a long random password'
```

`SITE_URL` 必须严格写成 `https://www.wuhanyingyuan.com`，末尾不能带 `/`。

先验证环境变量和 TLS 模板：

```bash
bash scripts/deploy.sh --check
```

首次部署：

```bash
bash scripts/deploy.sh
```

部署脚本会：

1. 先构建固定版本的 WordPress 镜像。
2. 以 HTTP 启动 Nginx。
3. 通过 HTTP-01 为 `DOMAIN` 申请证书，并确认 `fullchain.pem` 和 `privkey.pem` 都存在。
4. 用真实域名生成 `docker/nginx/tls.generated.conf`。
5. 在一次性 Nginx 容器中执行 `nginx -t`；校验失败时不会重建公网入口。
6. 启动 80/443 生产入口并重载 Nginx。

`tls.generated.conf`、`certs/`、`.env` 和备份文件均已加入 `.gitignore`。

镜像标签必须在 ECS 上能够实际拉取。静态契约不能证明 Docker Hub 的远端可用性，首次部署和每次升级都必须观察 `docker compose pull`/`build` 的输出。

## 初始化站点

```bash
bash scripts/init-site.sh
```

该脚本完成：

- WordPress 核心安装和中文语言文件安装。
- 启用 `wuhanyingyuan-core` 插件和 `wuhanyingyuan` 主题。
- 创建首页、新闻页、菜单、分类和可编辑演示内容。
- 删除新安装自带的示例文章和示例页面。
- 在配置了 `EDITOR_PASSWORD` 时创建内容编辑账号。

普通重跑 `bash scripts/init-site.sh` 会保留已有的站点标题、副标题、时区、固定链接结构、首页设置，以及运营人员已经修改的新闻、菜单和首页内容；脚本只补齐缺失的分类、页面和初始化内容。

需要把初始化数据恢复到仓库中的默认版本时，先完成备份，再执行：

```bash
bash scripts/backup.sh
bash scripts/init-site.sh --overwrite
```

`--overwrite` 会重写 WordPress 的初始化核心选项，并重新写入带初始化标记的平台、服务、优势、流程、案例、常见问题、成果数据、数据效果图和示例新闻内容。运营人员对这些种子内容的修改可能被覆盖；已有菜单项不会因该参数自动重建，请先确认确实需要恢复默认内容再执行。

登录地址：

```text
https://www.wuhanyingyuan.com/wp-admin/
```

站点设置入口在左侧“盈圆设置”，新闻在“文章”中维护，首页各区块在对应的“投放平台、服务内容、服务优势、合作流程、客户案例、常见问题、数据效果图、成果数据”菜单中维护。

## 第三方发布账号

第一次执行：

```bash
bash scripts/setup-publisher-user.sh
```

脚本输出一次性的 `API_KEY`、`API_PASS` 和数字分类 ID。把凭据直接填入第三方工具，不要把 `API_PASS` 写入 `.env`、脚本、Git 或工单。

如果工具里的旧密码丢失或需要轮换：

```bash
bash scripts/setup-publisher-user.sh --rotate
```

轮换会撤销该发布账号已有的 Application Password，并立即生成新密码。

详细字段映射见 [publisher-integration.md](publisher-integration.md)。

## 旧文章迁移

备份先于导入：

```bash
bash scripts/backup.sh
bash scripts/import-legacy.sh \
  --articles /path/to/legacy/data/articles.json \
  --uploads /path/to/legacy/uploads
```

迁移按旧文章 ID 幂等更新。正文中的旧图片导入失败时，文章仍会导入，并保留原图片 URL；封面导入失败时文章仍会导入，但不会设置特色图片。每条失败都会输出 `legacy #<旧ID>` 警告，最终总结 JSON 中的 `warnings` 字段记录警告总数。请根据警告清单人工检查缺失图片。

`/news.html`、`/article.html?id=<旧ID>` 和 `/admin.html` 会在主题插件中保留 301 跳转。

## 证书续期

`certbot` 不常驻轮询。使用宿主机 cron 每天检查一次：

```bash
mkdir -p /opt/wuhanyingyuan/wordpress/logs
crontab -e
```

加入：

```cron
17 3 * * * cd /opt/wuhanyingyuan/wordpress && bash scripts/renew-cert.sh >> logs/renew-cert.log 2>&1
```

脚本只在成功检查或续期后重载 Nginx。也可以手动执行：

```bash
bash scripts/renew-cert.sh
```

## 备份与恢复

每日备份示例：

```cron
30 3 * * * cd /opt/wuhanyingyuan/wordpress && bash scripts/backup.sh >> logs/backup.log 2>&1
```

备份包含：

- `yy-db-<时间>.sql.gz`
- `yy-uploads-<时间>.tar.gz`
- `yy-checksums-<时间>.sha256`

默认保存在 `wordpress/backups/`，保留天数由 `BACKUP_RETENTION_DAYS` 控制。生产环境建议再把该目录同步到对象存储或另一台机器。

数据库和上传文件会先写入隐藏临时文件，并通过 `gzip -t`、`tar -tzf` 校验后才发布为正式备份。上传包先发布，数据库包最后发布，因此 dump 或归档中途失败不会留下可被 `restore.sh --latest` 选中的半成品。

`backup.sh` 和 `restore.sh` 共用宿主机上的 `.yy-operation.lock`，通过 `flock` 保证同一站点同一时间只执行一个备份或恢复。恢复前的安全备份会复用恢复进程已经持有的锁，因此不会自锁。锁被占用时脚本直接失败，不修改数据库或上传文件。

查看并验证最新备份：

```bash
bash scripts/restore.sh --latest --dry-run
```

正式恢复会先自动创建一份安全备份：

```bash
bash scripts/restore.sh --latest --yes
```

恢复前会检查上传归档的所有成员：只允许 `uploads/` 下的目录和普通文件，拒绝绝对路径、任意 `..` 路径组件、目录外顶层成员、符号链接、硬链接和设备文件等特殊条目。该检查在进入维护模式、创建安全备份或改动站点数据之前执行。

恢复期间启用 WordPress 维护模式。上传备份 `tar.gz` 会直接流式传给容器内的 `tar -xzf`，先解压到 `wp-content/uploads/.yy-restore-<时间>-<进程号>` 暂存目录并确认其中存在 `uploads/`，随后使用同文件系统切换：旧上传文件移入隐藏回滚目录，新文件移入正式位置。只有数据库恢复、缓存刷新和上传切换全部成功后，脚本才删除旧上传文件并退出维护模式。上传切换或数据库导入失败时，脚本会恢复旧上传文件，并尝试使用恢复前安全备份回退数据库；只要有一步回滚失败，维护模式会保持开启，避免公网访问不一致的数据。暂存和回退期间需要为上传目录预留额外磁盘空间。

`--no-safety-backup` 只用于已经另有数据库快照的受控演练；关闭安全备份后，数据库恢复中途失败将无法由脚本自动回退，站点会保持维护模式，必须按外部快照人工恢复。

## 日常运维

查看状态和健康检查：

```bash
docker compose \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  ps
docker compose \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  logs --tail=200 nginx wordpress db
```

发布新版本：

```bash
cd /opt/wuhanyingyuan/wordpress
bash scripts/backup.sh
# 在确认备份成功后更新代码
bash scripts/deploy.sh
bash scripts/init-site.sh
```

不要删除 `db_data`、`uploads_data` 或 `wordpress_data` 命名卷。重建容器不会删除站点内容。

WordPress 镜像升级到新的固定标签后，入口脚本会比较持久卷与镜像中的核心文件。版本不同或同版本核心文件损坏时，脚本先校验镜像核心必需文件、复制到隐藏暂存目录并备份旧核心，再逐项切换；切换失败会尝试恢复旧核心。`wp-content`、`wp-config.php`、`.htaccess`、`robots.txt`、`favicon.ico`、`.user.ini` 和 `.well-known/` 始终保留，其他顶层条目视为 WordPress 核心并由镜像同步。升级前仍必须先完成备份，并在测试环境验证目标镜像标签存在且可启动。

## 上线验收

1. `https://www.wuhanyingyuan.com/` 返回 200。
2. 80 端口跳转到 HTTPS。
3. `/wp-admin/` 可以登录，前台主题不接管后台。
4. 发布一篇草稿，匿名访问详情页返回 404；发布后新闻列表可见。
5. 第三方工具使用验证接口返回成功。
6. 首页在 375px、768px、1440px 下无横向溢出。
7. 创建测试文章后执行备份、`--dry-run` 和测试环境恢复，文章、图片和设置一致。
8. 观察首次 `docker compose build wordpress` 和镜像拉取成功，且 `docker compose ps` 中服务达到 healthy/running。
9. 故意使用缺失证书文件的测试目录执行部署前检查，确认不会重建公网 Nginx。
