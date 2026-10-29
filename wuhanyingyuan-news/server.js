#!/usr/bin/env node
/**
 * 盈圆官网 - 新闻资讯服务（纯 Node.js，无任何第三方依赖）
 *
 * 功能：
 *   - 托管整站静态文件（与 nginx 二选一，也可只做 API 供 nginx 反代）
 *   - POST   /api/login            管理员登录（返回 Token）
 *   - GET    /api/articles         文章列表（公开，仅已发布；all=1 需登录返回全部）
 *   - POST   /api/articles         新建文章（需登录）
 *   - GET    /api/articles/:id     文章详情（公开仅已发布；携带登录 Token 可看草稿）
 *   - PUT    /api/articles/:id     更新文章（需登录）
 *   - DELETE /api/articles/:id     删除文章（需登录）
 *   - POST   /api/upload           封面图上传（需登录，base64 Data URL，≤5MB）
 *
 * 数据存储：data/articles.json（文章）、data/admin.json（管理密码）、
 *          data/secret.key（Token 签名密钥）、uploads/（上传的图片）
 *
 * 启动：node server.js        （默认端口 3789，可用环境变量 PORT 覆盖）
 * 密码：首次运行自动生成 data/admin.json，默认密码 admin123456，请立即修改！
 */

"use strict";

const http = require("http");
const https = require("https");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const PORT = Number(process.env.PORT || 3789);
const ROOT = __dirname;                 // 网站根目录（server.js 所在目录）
const DATA_DIR = path.join(ROOT, "data");
const UPLOAD_DIR = path.join(ROOT, "uploads");
const ARTICLES_FILE = path.join(DATA_DIR, "articles.json");
const ADMIN_FILE = path.join(DATA_DIR, "admin.json");
const SECRET_FILE = path.join(DATA_DIR, "secret.key");

const MAX_BODY = 8 * 1024 * 1024;       // 8MB（含 base64 图片）
const MAX_IMAGE = 5 * 1024 * 1024;      // 5MB
const TOKEN_TTL = 7 * 24 * 3600 * 1000; // Token 有效期 7 天

/* ---------------- 初始化数据目录 ---------------- */

if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
if (!fs.existsSync(UPLOAD_DIR)) fs.mkdirSync(UPLOAD_DIR, { recursive: true });

if (!fs.existsSync(ADMIN_FILE)) {
  fs.writeFileSync(
    ADMIN_FILE,
    JSON.stringify({ password: "admin123456" }, null, 2),
    "utf8"
  );
  console.log("[提示] 已生成默认管理密码 admin123456（存于 data/admin.json），请立即修改！");
}

if (!fs.existsSync(SECRET_FILE)) {
  fs.writeFileSync(SECRET_FILE, crypto.randomBytes(32).toString("hex"), "utf8");
}

const SECRET = fs.readFileSync(SECRET_FILE, "utf8").trim();

function readArticles() {
  try {
    return JSON.parse(fs.readFileSync(ARTICLES_FILE, "utf8"));
  } catch (e) {
    return [];
  }
}

function writeArticles(list) {
  const tmp = ARTICLES_FILE + ".tmp";
  fs.writeFileSync(tmp, JSON.stringify(list, null, 2), "utf8");
  fs.renameSync(tmp, ARTICLES_FILE);
}

/* ---------------- Token 签名与校验 ---------------- */

function signToken() {
  const expire = Date.now() + TOKEN_TTL;
  const payload = String(expire);
  const signature = crypto
    .createHmac("sha256", SECRET)
    .update(payload)
    .digest("hex");
  return payload + "." + signature;
}

function verifyToken(token) {
  if (!token || typeof token !== "string") return false;
  const dot = token.indexOf(".");
  if (dot < 1) return false;
  const payload = token.slice(0, dot);
  const signature = token.slice(dot + 1);
  const expected = crypto
    .createHmac("sha256", SECRET)
    .update(payload)
    .digest("hex");
  if (
    signature.length !== expected.length ||
    !crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))
  ) {
    return false;
  }
  return Number(payload) > Date.now();
}

function isAuthed(req) {
  const header = req.headers.authorization || "";
  const match = /^Bearer\s+(.+)$/i.exec(header);
  return match ? verifyToken(match[1]) : false;
}

/* ---------------- 登录限流（每 IP 每分钟 10 次） ---------------- */

const loginAttempts = new Map();

function loginAllowed(ip) {
  const now = Date.now();
  const windowStart = now - 60 * 1000;
  const list = (loginAttempts.get(ip) || []).filter((t) => t > windowStart);
  if (list.length >= 10) {
    loginAttempts.set(ip, list);
    return false;
  }
  list.push(now);
  loginAttempts.set(ip, list);
  return true;
}

/* ---------------- 工具函数 ---------------- */

const MIME = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "application/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".gif": "image/gif",
  ".webp": "image/webp",
  ".svg": "image/svg+xml",
  ".ico": "image/x-icon",
  ".woff": "font/woff",
  ".woff2": "font/woff2",
  ".ttf": "font/ttf",
  ".mp4": "video/mp4",
  ".pdf": "application/pdf"
};

function sendJson(res, status, data) {
  const body = JSON.stringify(data);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "X-Content-Type-Options": "nosniff"
  });
  res.end(body);
}

function safeId() {
  return Date.now().toString(36) + crypto.randomBytes(4).toString("hex");
}

function cleanArticle(input) {
  if (!input || typeof input !== "object") return null;
  const title = String(input.title || "").trim().slice(0, 100);
  const content = String(input.content || "").slice(0, 200 * 1024);
  if (!title || !content.trim()) return null;
  return {
    title,
    category: String(input.category || "").trim().slice(0, 20),
    summary: String(input.summary || "").trim().slice(0, 200),
    cover: String(input.cover || "").trim().slice(0, 500),
    content,
    published: input.published !== false
  };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY) {
        reject(new Error("请求体过大"));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}

function readJson(req) {
  return readBody(req).then((text) => {
    if (!text) return {};
    try {
      return JSON.parse(text);
    } catch (e) {
      throw new Error("JSON 格式错误");
    }
  });
}

function serveStatic(req, res, urlPath) {
  let pathname = decodeURIComponent(urlPath.split("?")[0]);
  if (pathname === "/") pathname = "/index.html";
  const filePath = path.normalize(path.join(ROOT, pathname));

  // 防目录穿越
  if (!filePath.startsWith(ROOT + path.sep) && filePath !== ROOT) {
    sendJson(res, 403, { error: "禁止访问" });
    return;
  }

  // 静态目录中不允许访问敏感文件
  if (/[/\\](data|uploads|server\.js)[/\\]?/i.test(pathname) && !pathname.startsWith("/uploads/")) {
    sendJson(res, 403, { error: "禁止访问" });
    return;
  }

  fs.stat(filePath, (err, stat) => {
    if (err || !stat.isFile()) {
      res.writeHead(404, { "Content-Type": "text/plain; charset=utf-8" });
      res.end("404 Not Found");
      return;
    }
    const ext = path.extname(filePath).toLowerCase();
    const type = MIME[ext] || "application/octet-stream";
    const cacheable = /\.(css|js|png|jpe?g|webp|svg|ico|woff2?|mp4)$/i.test(ext);
    res.writeHead(200, {
      "Content-Type": type,
      "Content-Length": stat.size,
      "Cache-Control": cacheable ? "public, max-age=3600" : "no-cache",
      "X-Content-Type-Options": "nosniff"
    });
    fs.createReadStream(filePath).pipe(res);
  });
}

function saveUpload(dataUrl, originalName) {
  const match = /^data:(image\/(png|jpe?g|webp|gif));base64,([A-Za-z0-9+/=]+)$/.exec(
    String(dataUrl || "")
  );
  if (!match) throw new Error("仅支持 jpg / png / webp / gif 格式的图片");
  const extMap = { "image/png": ".png", "image/jpeg": ".jpg", "image/webp": ".webp", "image/gif": ".gif" };
  const buffer = Buffer.from(match[3], "base64");
  if (buffer.length > MAX_IMAGE) throw new Error("图片不能超过 5MB");

  const base = String(originalName || "cover")
    .replace(/\.[^.]+$/, "")
    .replace(/[^\w\u4e00-\u9fa5-]+/g, "_")
    .slice(0, 40) || "cover";
  const filename = base + "_" + Date.now().toString(36) + extMap[match[1]];
  fs.writeFileSync(path.join(UPLOAD_DIR, filename), buffer);
  return "/uploads/" + filename;
}

/* ---------------- 路由 ---------------- */

const server = http.createServer((req, res) => {
  const urlPath = req.url || "/";
  const pathname = urlPath.split("?")[0];
  const ip =
    (req.headers["x-forwarded-for"] || "").split(",")[0].trim() ||
    req.socket.remoteAddress ||
    "unknown";

  let matched;

  /* --- 登录 --- */
  if (pathname === "/api/login" && req.method === "POST") {
    if (!loginAllowed(ip)) {
      sendJson(res, 429, { error: "尝试次数过多，请 1 分钟后再试" });
      return;
    }
    readJson(req)
      .then((body) => {
        let adminPassword = "admin123456";
        try {
          adminPassword = JSON.parse(fs.readFileSync(ADMIN_FILE, "utf8")).password || adminPassword;
        } catch (e) { /* 保持默认 */ }
        const input = String(body.password || "");
        const ok =
          input.length === adminPassword.length &&
          crypto.timingSafeEqual(Buffer.from(input), Buffer.from(adminPassword));
        if (!ok) {
          sendJson(res, 401, { error: "密码错误" });
          return;
        }
        sendJson(res, 200, { token: signToken() });
      })
      .catch((error) => sendJson(res, 400, { error: error.message }));
    return;
  }

  /* --- 上传 --- */
  if (pathname === "/api/upload" && req.method === "POST") {
    if (!isAuthed(req)) {
      sendJson(res, 401, { error: "未登录或登录已过期" });
      return;
    }
    readJson(req)
      .then((body) => {
        const url = saveUpload(body.data, body.name);
        sendJson(res, 200, { url });
      })
      .catch((error) => sendJson(res, 400, { error: error.message }));
    return;
  }

  /* --- 文章列表 / 新建 --- */
  if (pathname === "/api/articles") {
    if (req.method === "GET") {
      const query = new URLSearchParams(urlPath.split("?")[1] || "");
      const page = Math.max(1, Number(query.get("page") || 1));
      const pageSize = Math.min(100, Math.max(1, Number(query.get("pageSize") || 9)));
      const showAll = query.get("all") === "1" && isAuthed(req);

      const all = readArticles();
      const pool = showAll ? all : all.filter((a) => a.published);
      const sorted = pool.slice().sort((a, b) => b.createdAt - a.createdAt);
      const start = (page - 1) * pageSize;

      sendJson(res, 200, {
        total: pool.length,
        page,
        pageSize,
        articles: sorted.slice(start, start + pageSize)
      });
      return;
    }

    if (req.method === "POST") {
      if (!isAuthed(req)) {
        sendJson(res, 401, { error: "未登录或登录已过期" });
        return;
      }
      readJson(req)
        .then((body) => {
          const article = cleanArticle(body);
          if (!article) {
            sendJson(res, 400, { error: "标题和正文不能为空" });
            return;
          }
          const now = Date.now();
          const record = Object.assign({ id: safeId(), createdAt: now, updatedAt: now }, article);
          const list = readArticles();
          list.push(record);
          writeArticles(list);
          sendJson(res, 200, { article: record });
        })
        .catch((error) => sendJson(res, 400, { error: error.message }));
      return;
    }
  }

  /* --- 单篇文章 --- */
  if ((matched = /^\/api\/articles\/([\w-]+)$/.exec(pathname))) {
    const id = matched[1];

    if (req.method === "GET") {
      const authed = isAuthed(req);
      const article = readArticles().find((a) => a.id === id);
      if (!article || (!article.published && !authed)) {
        sendJson(res, 404, { error: "文章不存在或未发布" });
        return;
      }
      sendJson(res, 200, { article });
      return;
    }

    if (req.method === "PUT") {
      if (!isAuthed(req)) {
        sendJson(res, 401, { error: "未登录或登录已过期" });
        return;
      }
      readJson(req)
        .then((body) => {
          const list = readArticles();
          const index = list.findIndex((a) => a.id === id);
          if (index < 0) {
            sendJson(res, 404, { error: "文章不存在" });
            return;
          }
          // 支持仅切换发布状态
          if (Object.keys(body).length === 1 && typeof body.published === "boolean") {
            list[index].published = body.published;
          } else {
            const clean = cleanArticle(Object.assign({}, list[index], body));
            if (!clean) {
              sendJson(res, 400, { error: "标题和正文不能为空" });
              return;
            }
            list[index] = Object.assign({}, list[index], clean, { updatedAt: Date.now() });
          }
          writeArticles(list);
          sendJson(res, 200, { article: list[index] });
        })
        .catch((error) => sendJson(res, 400, { error: error.message }));
      return;
    }

    if (req.method === "DELETE") {
      if (!isAuthed(req)) {
        sendJson(res, 401, { error: "未登录或登录已过期" });
        return;
      }
      const list = readArticles();
      const index = list.findIndex((a) => a.id === id);
      if (index < 0) {
        sendJson(res, 404, { error: "文章不存在" });
        return;
      }
      list.splice(index, 1);
      writeArticles(list);
      sendJson(res, 200, { ok: true });
      return;
    }
  }

  /* --- 静态文件 / 其他 --- */
  if (req.method === "GET" || req.method === "HEAD") {
    serveStatic(req, res, urlPath);
    return;
  }

  sendJson(res, 404, { error: "接口不存在" });
});

server.listen(PORT, "127.0.0.1", () => {
  console.log("盈圆新闻资讯服务已启动: http://127.0.0.1:" + PORT);
  console.log("后台地址: /admin.html");
  console.log("提示：仅供本机访问，请通过 nginx 反向代理 /api/ 与 /uploads/ 对外提供服务。");
});
