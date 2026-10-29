# Linux ECS 从零部署步骤

本文以 Ubuntu 22.04/24.04 为例，目标是部署到：

```text
https://www.wuhanyingyuan.com
```

完整架构、备份恢复和安全设计见 [deploy.md](deploy.md)。

## 1. 准备域名和端口

1. 将 `www.wuhanyingyuan.com` 的 A 记录指向 ECS 公网 IP。
2. ECS 安全组只开放 `22`、`80`、`443`。
3. 不要向公网开放 `3306`。
4. 首次申请证书前，确认域名已经生效，并且公网可以访问服务器的 `80` 端口。

可在本地电脑检查 DNS：

```bash
nslookup www.wuhanyingyuan.com
```

## 2. 登录服务器并安装依赖

```bash
ssh <deploy-user>@<ECS_PUBLIC_IP>
```

安装 Docker 官方仓库需要的软件：

```bash
sudo apt-get update
sudo apt-get install -y \
  ca-certificates curl git cron nano openssl rsync tar util-linux dnsutils
```

安装 Docker Engine、Buildx 和 Compose v2：

```bash
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

sudo apt-get update
sudo apt-get install -y \
  docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"
```

退出 SSH 并重新登录，使 Docker 用户组生效：

```bash
exit
ssh <deploy-user>@<ECS_PUBLIC_IP>
docker version
docker compose version
flock --help >/dev/null
tar --version
```

如果启用了 UFW，再开放 Web 端口：

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

## 3. 上传项目

创建部署目录：

```bash
sudo mkdir -p /opt/wuhanyingyuan
sudo chown "$USER":"$USER" /opt/wuhanyingyuan
```

推荐使用 Git 部署：

```bash
git clone <GIT_REPOSITORY_URL> /opt/wuhanyingyuan
cd /opt/wuhanyingyuan/wordpress
```

如果项目尚未进入 Git 仓库，可在本地电脑上传 `wordpress` 目录：

```bash
rsync -avz \
  --exclude '.env' \
  --exclude 'backups/' \
  --exclude 'certs/' \
  --exclude 'logs/' \
  --exclude 'docker/nginx/tls.generated.conf' \
  ./wordpress/ <deploy-user>@<ECS_PUBLIC_IP>:/opt/wuhanyingyuan/wordpress/
```

上传后回到服务器确认目录：

```bash
cd /opt/wuhanyingyuan/wordpress
ls -la
```

## 4. 配置生产环境变量

```bash
cd /opt/wuhanyingyuan/wordpress
cp .env.example .env
chmod 600 .env
```

生成不含特殊字符的随机密码，分别填入 `.env`：

```bash
openssl rand -hex 32
openssl rand -hex 32
openssl rand -hex 24
```

编辑配置：

```bash
nano .env
```

至少确认以下字段：

```dotenv
SITE_URL=https://www.wuhanyingyuan.com
DOMAIN=www.wuhanyingyuan.com
CERTBOT_EMAIL=ops@your-domain.com

DB_NAME=wordpress
DB_USER=wordpress
DB_PASSWORD=<第一组随机值>
DB_ROOT_PASSWORD=<第二组随机值>

ADMIN_USER=siteadmin
ADMIN_PASSWORD=<第三组随机值>
ADMIN_EMAIL=admin@your-domain.com

EDITOR_USER=editor
EDITOR_PASSWORD=<编辑器密码，不需要时留空>
EDITOR_EMAIL=editor@your-domain.com

PUBLISHER_USER=publisher
PUBLISHER_EMAIL=publisher@your-domain.com
PUBLISHER_APP_NAME=新闻发布工具
NEWS_CATEGORY_SLUG=industry-news
```

注意：

- `SITE_URL` 必须严格等于 `https://` 加 `DOMAIN`，末尾不能有 `/`。
- 不要把 `.env` 上传到 Git 仓库、对象存储或聊天工具。
- 如果手工设置包含空格、`#` 或 `$` 的密码，必须使用单引号包起来。

## 5. 部署前检查

先检查 DNS：

```bash
dig +short A www.wuhanyingyuan.com
```

返回结果必须是当前 ECS 公网 IP。

检查 Compose 配置和 TLS 模板：

```bash
cd /opt/wuhanyingyuan/wordpress
bash scripts/deploy.sh --check
```

检查异常时不要继续申请证书，先修正 `.env`、DNS 或端口。

## 6. 启动容器并申请 HTTPS 证书

```bash
cd /opt/wuhanyingyuan/wordpress
bash scripts/deploy.sh
```

脚本会依次完成：

1. 构建并拉取固定版本镜像。
2. 通过 HTTP 启动数据库、WordPress 和 Nginx。
3. 使用 Let's Encrypt HTTP-01 验证域名。
4. 生成并校验 `docker/nginx/tls.generated.conf`。
5. 启动 80/443 生产入口。

首次部署必须能从日志中确认镜像拉取、证书申请和 Nginx 校验均成功。证书申请失败时，优先检查：

```bash
dig +short A www.wuhanyingyuan.com
sudo ss -lntp | grep -E ':80|:443'
curl -I http://www.wuhanyingyuan.com/
```

## 7. 初始化 WordPress

```bash
cd /opt/wuhanyingyuan/wordpress
bash scripts/init-site.sh
```

初始化包括：

- 安装 WordPress 和中文语言包。
- 启用 `wuhanyingyuan-core` 插件和 `wuhanyingyuan` 主题。
- 创建首页、新闻页、菜单、分类和初始化内容。
- 在配置了 `EDITOR_PASSWORD` 时创建内容编辑账号。

管理后台与 C 端主题分离，地址为：

```text
https://www.wuhanyingyuan.com/wp-admin/
```

普通重跑 `init-site.sh` 会保留运营人员已经修改的设置和内容。只有明确需要恢复初始化演示内容时，才先备份再执行：

```bash
bash scripts/backup.sh
bash scripts/init-site.sh --overwrite
```

## 8. 创建新闻发布工具凭据

WordPress 初始化完成并创建新闻分类后，执行：

```bash
cd /opt/wuhanyingyuan/wordpress
bash scripts/setup-publisher-user.sh
```

输出会包含：

```text
SITE_URL=https://www.wuhanyingyuan.com
API_KEY=publisher
API_PASS=<WordPress Application Password>
NEWS_CATEGORY_ID=<新闻分类数字 ID>
AUTH_MODE=WordPress Application Password (HTTP Basic)
```

第三方发布工具按以下方式配置：

| 工具字段 | 填写内容 |
| --- | --- |
| 网站框架 | `WordPress` |
| `API_KEY` | `PUBLISHER_USER`，默认 `publisher` |
| `API_PASS` | 本次生成的 Application Password |
| `NEWS_CATEGORY_ID` | 脚本输出的新闻分类数字 ID |

`API_PASS` 通常只显示一次。密码丢失或需要轮换时执行：

```bash
bash scripts/setup-publisher-user.sh --rotate
```

详细接口说明见 [publisher-integration.md](publisher-integration.md)。

## 9. 上线验收

检查容器状态：

```bash
cd /opt/wuhanyingyuan/wordpress
docker compose \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  ps
```

检查站点和 HTTPS：

```bash
curl -fsS https://www.wuhanyingyuan.com/healthz
curl -I http://www.wuhanyingyuan.com/
curl -I https://www.wuhanyingyuan.com/
curl -I https://www.wuhanyingyuan.com/wp-admin/
```

预期结果：

- `db`、`wordpress`、`nginx` 为 `healthy` 或 `running`。
- 80 端口跳转到 HTTPS。
- HTTPS 首页和 `/wp-admin/` 返回 `200` 或登录页面。
- 管理后台可以登录，且没有被 C 端主题接管。
- 创建一篇草稿后，匿名访问文章详情返回 404；发布后新闻列表可见。
- 外部发布工具可以使用 `API_KEY`、`API_PASS` 创建和更新新闻。

查看日志：

```bash
docker compose \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  logs --tail=200 nginx wordpress db
```

## 10. 配置定时任务

先执行一次手工备份：

```bash
cd /opt/wuhanyingyuan/wordpress
mkdir -p logs
bash scripts/backup.sh
bash scripts/restore.sh --latest --dry-run
```

编辑当前用户的 cron：

```bash
crontab -e
```

加入：

```cron
17 3 * * * cd /opt/wuhanyingyuan/wordpress && bash scripts/renew-cert.sh >> logs/renew-cert.log 2>&1
30 3 * * * cd /opt/wuhanyingyuan/wordpress && bash scripts/backup.sh >> logs/backup.log 2>&1
```

确认当前用户属于 `docker` 组，否则 cron 中的 Docker 命令会失败。

## 11. 后续更新

更新前先备份：

```bash
cd /opt/wuhanyingyuan/wordpress
bash scripts/backup.sh
```

使用 Git 时：

```bash
git -C /opt/wuhanyingyuan pull --ff-only
cd /opt/wuhanyingyuan/wordpress
bash scripts/deploy.sh
bash scripts/init-site.sh
```

上传新版本的 `wordpress` 目录后，同样执行：

```bash
cd /opt/wuhanyingyuan/wordpress
bash scripts/deploy.sh
bash scripts/init-site.sh
```

更新时必须保留以下生产数据：

- `.env`
- `certs/`
- `backups/`
- `logs/`
- `docker/nginx/tls.generated.conf`
- Docker 命名卷 `db_data`
- Docker 命名卷 `uploads_data`
- Docker 命名卷 `wordpress_data`

严禁执行 `docker compose down -v`，该命令会删除数据库和上传文件。

## 12. 常见故障

### Docker 提示 permission denied

```bash
sudo usermod -aG docker "$USER"
```

退出 SSH 后重新登录，再执行 `docker version`。

### HTTPS 证书申请失败

依次检查：

```bash
dig +short A www.wuhanyingyuan.com
sudo ss -lntp | grep -E ':80|:443'
curl -I http://www.wuhanyingyuan.com/
docker compose \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  logs --tail=200 nginx
```

常见原因是 DNS 尚未生效、安全组未开放 80、宿主机已有 Nginx/Apache 占用端口，或者域名开启了不允许 HTTP-01 验证的代理模式。

### 发布工具返回 401 或 403

- `401`：检查 `API_PASS` 是否使用 Application Password，确认没有填写管理员登录密码。
- `403`：检查 `PUBLISHER_USER` 是否仍具有 `yy_publisher` 角色。
- 分类错误：重新执行配置检查，确认 `NEWS_CATEGORY_ID` 是数字 Term ID。

### 日志位置

```text
wordpress/logs/backup.log
wordpress/logs/renew-cert.log
docker compose logs nginx wordpress db
```
