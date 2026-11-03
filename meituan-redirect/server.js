const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { URLSearchParams } = require('url');

const PORT = process.env.PORT || 3000;
const DATA_DIR = path.join(__dirname, 'data');
const CONFIG_FILE = path.join(DATA_DIR, 'config.json');
const TOKENS_FILE = path.join(DATA_DIR, 'tokens.json');
const REPORTS_FILE = path.join(DATA_DIR, 'reports.json');

const DEFAULT_CONFIG = {
  admin_password: 'mt2026',
  account_id: '',
  user_action_set_id: '',
  action_type: 'VIEW_PAGE',
  client_id: '',
  client_secret: '',
  base_url: 'https://meituan-jump-12453.app.workbuddy.host',
  default_target: 'https://click.meituan.com/t?t=1&c=2&p=I6aRI71zNhl9',
  sharecode: 'LMN7FDRTEL',
  gdt_key: '1225347100',
  report_mode: 'js'
};

function ensureDataDir() { if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true }); }
function loadJson(file, fallback) {
  ensureDataDir();
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { return fallback; }
}
function saveJson(file, obj) {
  ensureDataDir();
  fs.writeFileSync(file, JSON.stringify(obj, null, 2), 'utf8');
}
function getConfig() {
  const cfg = loadJson(CONFIG_FILE, {});
  return Object.assign({}, DEFAULT_CONFIG, cfg);
}
function saveConfig(patch) {
  const cfg = getConfig();
  Object.assign(cfg, patch);
  saveJson(CONFIG_FILE, cfg);
  return cfg;
}

/* ---------- 报表日志 ---------- */
function appendReport(entry) {
  const list = loadJson(REPORTS_FILE, []);
  list.unshift(Object.assign({ time: new Date().toISOString() }, entry));
  if (list.length > 200) list.length = 200;
  saveJson(REPORTS_FILE, list);
}
function getReports() { return loadJson(REPORTS_FILE, []); }

/* ---------- HTTPS 请求工具 ---------- */
function httpsRequest(method, urlStr, headers, body) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const req = https.request({
      hostname: u.hostname,
      path: u.pathname + u.search,
      method,
      headers
    }, (res) => {
      let data = '';
      res.on('data', (c) => { data += c; });
      res.on('end', () => resolve({ status: res.statusCode, body: data }));
    });
    req.on('error', reject);
    req.setTimeout(10000, () => { req.destroy(new Error('timeout')); });
    if (body) req.write(body);
    req.end();
  });
}
function randNonce() { return crypto.randomBytes(8).toString('hex'); }

/* ---------- Token 管理 ---------- */
function loadTokens() { return loadJson(TOKENS_FILE, null); }
function saveTokens(t) { saveJson(TOKENS_FILE, t); }

async function refreshAccessToken() {
  const cfg = getConfig();
  const tk = loadTokens();
  if (!tk || !tk.refresh_token) throw new Error('尚未完成授权，请先到管理后台完成 OAuth 授权');
  const qs = new URLSearchParams({
    client_id: cfg.client_id,
    client_secret: cfg.client_secret,
    grant_type: 'refresh_token',
    refresh_token: tk.refresh_token
  }).toString();
  const resp = await httpsRequest('GET', 'https://api.e.qq.com/oauth/token?' + qs, {}, null);
  let data;
  try { data = JSON.parse(resp.body); } catch (e) { throw new Error('刷新 token 响应异常: ' + resp.body.slice(0, 200)); }
  if (data.code !== 0 || !data.data || !data.data.access_token) {
    throw new Error('刷新 token 失败: code=' + data.code + ' message=' + (data.message || '') + (data.message_cn || ''));
  }
  const d = data.data;
  const newTk = {
    access_token: d.access_token,
    refresh_token: d.refresh_token || tk.refresh_token,
    access_expires_at: Date.now() + (d.access_token_expires_in || d.expires_in || 86400) * 1000,
    refresh_expires_at: Date.now() + (d.refresh_token_expires_in || 2592000) * 1000,
    account_id: tk.account_id || cfg.account_id,
    updated_at: new Date().toISOString()
  };
  saveTokens(newTk);
  return newTk;
}

async function getAccessToken() {
  const tk = loadTokens();
  if (!tk || !tk.access_token) return refreshAccessToken();
  if (Date.now() > tk.access_expires_at - 3600 * 1000) {
    try { return await refreshAccessToken(); } catch (e) {
      if (Date.now() < tk.access_expires_at) return tk;
      throw e;
    }
  }
  return tk;
}

/* ---------- 转化回传 ---------- */
async function reportConversion(clickId, extra) {
  const cfg = getConfig();
  if (!cfg.account_id || !cfg.user_action_set_id) {
    throw new Error('缺少 account_id 或 user_action_set_id，请到管理后台填写');
  }
  const tk = await getAccessToken();
  const action = {
    outer_action_id: 'pv_' + clickId,
    action_time: Math.floor(Date.now() / 1000),
    action_type: cfg.action_type || 'VIEW_PAGE',
    trace: { click_id: clickId }
  };
  if (extra && extra.url) action.url = extra.url;
  const payload = JSON.stringify({
    account_id: Number(cfg.account_id),
    user_action_set_id: Number(cfg.user_action_set_id),
    actions: [action]
  });
  const qs = new URLSearchParams({
    access_token: tk.access_token,
    timestamp: String(Math.floor(Date.now() / 1000)),
    nonce: randNonce()
  }).toString();
  const resp = await httpsRequest('POST', 'https://api.e.qq.com/v3.0/user_actions/add?' + qs, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(payload)
  }, payload);
  let data;
  try { data = JSON.parse(resp.body); } catch (e) { data = { code: -1, message: '响应解析失败', raw: resp.body.slice(0, 300) }; }
  appendReport({ click_id: clickId, action_type: action.action_type, code: data.code, message: data.message || data.message_cn || '', resp_status: resp.status });
  if (data.code !== 0) throw new Error('回传失败 code=' + data.code + ' ' + (data.message || ''));
  return data;
}

/* ---------- OAuth ---------- */
const oauthStates = new Set();
function callbackUrl(cfg) {
  return cfg.base_url.replace(/\/+$/, '') + '/oauth/callback' + (cfg.sharecode ? '?sharecode=' + encodeURIComponent(cfg.sharecode) : '');
}
function buildAuthorizeUrl(cfg) {
  const state = crypto.randomBytes(8).toString('hex');
  oauthStates.add(state);
  if (oauthStates.size > 20) oauthStates.delete(oauthStates.values().next().value);
  const qs = new URLSearchParams({
    client_id: cfg.client_id,
    redirect_uri: callbackUrl(cfg),
    state
  }).toString();
  return 'https://developers.e.qq.com/oauth/authorize?' + qs;
}
async function exchangeCode(code) {
  const cfg = getConfig();
  const qs = new URLSearchParams({
    client_id: cfg.client_id,
    client_secret: cfg.client_secret,
    grant_type: 'authorization_code',
    authorization_code: code,
    redirect_uri: callbackUrl(cfg)
  }).toString();
  const resp = await httpsRequest('GET', 'https://api.e.qq.com/oauth/token?' + qs, {}, null);
  let data;
  try { data = JSON.parse(resp.body); } catch (e) { throw new Error('token 响应异常: ' + resp.body.slice(0, 200)); }
  if (data.code !== 0 || !data.data || !data.data.access_token) {
    throw new Error('换取 token 失败: code=' + data.code + ' ' + (data.message || '') + (data.message_cn || ''));
  }
  const d = data.data;
  const tk = {
    access_token: d.access_token,
    refresh_token: d.refresh_token,
    access_expires_at: Date.now() + (d.access_token_expires_in || d.expires_in || 86400) * 1000,
    refresh_expires_at: Date.now() + (d.refresh_token_expires_in || 2592000) * 1000,
    updated_at: new Date().toISOString()
  };
  saveTokens(tk);
  return tk;
}

/* ---------- 页面模板 ---------- */
function renderIndex(cfg) {
  let html = fs.readFileSync(path.join(__dirname, 'index.html'), 'utf8');
  const reportUrl = '/report' + (cfg.sharecode ? '?sharecode=' + encodeURIComponent(cfg.sharecode) : '');
  html = html.split('__GDT_KEY__').join(cfg.gdt_key || '');
  html = html.split('__REPORT_URL__').join(reportUrl);
  return html;
}
function withShare(pathname, cfg) {
  if (!cfg.sharecode) return pathname;
  return pathname + (pathname.includes('?') ? '&' : '?') + 'sharecode=' + encodeURIComponent(cfg.sharecode);
}

function adminHtml(cfg, tk, msg) {
  const tokenStatus = !tk ? '<span style="color:#c00">未授权</span>'
    : (Date.now() < tk.access_expires_at ? '<span style="color:#080">有效</span>' : '<span style="color:#c00">已过期（将自动刷新）</span>');
  const reports = getReports().slice(0, 30).map(r =>
    '<tr><td>' + r.time + '</td><td>' + (r.click_id || '') + '</td><td>' + r.action_type + '</td><td>' + r.code + '</td><td>' + (r.message || '') + '</td></tr>'
  ).join('');
  return `<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>回传管理后台</title>
<style>
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f6f7;margin:0;padding:24px;color:#222}
.card{background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;max-width:860px}
h2{font-size:16px;margin:0 0 12px}
label{display:block;font-size:13px;color:#666;margin:10px 0 4px}
input{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #ddd;border-radius:8px;font-size:14px}
button{margin-top:12px;background:#1a73e8;color:#fff;border:0;border-radius:8px;padding:9px 18px;font-size:14px;cursor:pointer}
button.gray{background:#666}
table{width:100%;border-collapse:collapse;font-size:12px}
td,th{border-bottom:1px solid #eee;padding:6px 4px;text-align:left;word-break:break-all}
.msg{background:#e8f0fe;border-radius:8px;padding:10px;margin-bottom:16px;font-size:13px}
</style></head><body>
${msg ? '<div class="msg">' + msg + '</div>' : ''}
<div class="card"><h2>Token 状态：<span>${tokenStatus}</span></h2>
<p style="font-size:13px;color:#666">授权账号：${tk && tk.account_id ? tk.account_id : '未知'}　更新时间：${tk && tk.updated_at ? tk.updated_at : '-'}</p>
<a href="${withShare('/oauth/start', cfg)}"><button>① 前往腾讯授权（需先填 client_id）</button></a>
<form method="POST" action="${withShare('/admin/test', cfg)}" style="margin-top:8px">
<label>手动测试回传（API 方式测试，填一个真实 gdt_vid）</label><input name="click_id" placeholder="gdt_vid">
<input type="hidden" name="key" value="${cfg.admin_password}"><button class="gray">测试回传</button></form>
</div>
<div class="card"><h2>配置（腾讯广告后台获取）</h2>
<form method="POST" action="${withShare('/admin/save', cfg)}">
<label>管理密码</label><input name="admin_password" value="${cfg.admin_password}">
<label>上报方式 report_mode：js = 页面 JS 上报（当前可用）；api = MKT API 回传；both = 两者都开（注意去重）</label><input name="report_mode" value="${cfg.report_mode}">
<label>gdt_key 上报密钥（腾讯 JS 代码里的密钥，当前为 user_action_set_id）</label><input name="gdt_key" value="${cfg.gdt_key}">
<label>sharecode 站点访问码（勿改）</label><input name="sharecode" value="${cfg.sharecode}">
<label>account_id 广告主账号 ID</label><input name="account_id" value="${cfg.account_id}">
<label>user_action_set_id 数据源 ID</label><input name="user_action_set_id" value="${cfg.user_action_set_id}">
<label>action_type 行为类型（关键页面访问 = VIEW_PAGE）</label><input name="action_type" value="${cfg.action_type}">
<label>client_id 应用 ID（developers.e.qq.com 应用管理，走 API 回传才需要）</label><input name="client_id" value="${cfg.client_id}">
<label>client_secret 应用 Secret</label><input name="client_secret" value="${cfg.client_secret}">
<label>base_url 本服务公网地址（OAuth 回调用，勿随意改）</label><input name="base_url" value="${cfg.base_url}">
<input type="hidden" name="key" value="${cfg.admin_password}"><button>保存配置</button></form>
</div>
<div class="card"><h2>最近回传记录</h2>
<table><tr><th>时间</th><th>click_id</th><th>类型</th><th>code</th><th>说明</th></tr>${reports || '<tr><td colspan="5">暂无</td></tr>'}</table>
</div></body></html>`;
}

/* ---------- HTTP 服务 ---------- */
function send(res, status, body, headers) {
  res.writeHead(status, Object.assign({ 'Content-Type': 'text/html; charset=utf-8' }, headers || {}));
  res.end(body);
}
function readBody(req) {
  return new Promise((resolve) => {
    let d = '';
    req.on('data', (c) => { d += c; if (d.length > 1e6) req.destroy(); });
    req.on('end', () => resolve(d));
  });
}
function parseForm(body) {
  const out = {};
  new URLSearchParams(body).forEach((v, k) => { out[k] = v.trim(); });
  return out;
}
const server = http.createServer(async (req, res) => {
  const urlObj = new URL(req.url, 'http://localhost');
  const p = urlObj.pathname;
  const cfg = getConfig();
  try {
    if (p === '/healthz') { res.writeHead(200); return res.end('ok'); }

    if (p === '/' || p === '/index.html') return send(res, 200, renderIndex(cfg));

    if (p === '/report' && req.method === 'POST') {
      const body = await readBody(req);
      let clickId = '';
      let url = '';
      try {
        const j = JSON.parse(body || '{}');
        clickId = (j.click_id || '').trim();
        url = j.url || '';
      } catch (e) {}
      if (!clickId) {
        appendReport({ click_id: '(空)', code: -2, message: '页面未携带 gdt_vid，可能流量未来自腾讯广告' });
        res.writeHead(204); return res.end();
      }
      if ((cfg.report_mode || 'js').toLowerCase() === 'js') {
        appendReport({ click_id: clickId || '(空)', code: 0, message: '已由页面 JS SDK 上报，服务端不重复回传' });
        res.writeHead(204); return res.end();
      }
      reportConversion(clickId, { url }).catch((e) => {
        appendReport({ click_id: clickId, code: -1, message: e.message });
      });
      res.writeHead(204); return res.end();
    }

    if (p === '/oauth/start') {
      if (!cfg.client_id) return send(res, 200, '<h3>请先在管理后台填写 client_id</h3><a href="' + withShare('/admin?key=' + cfg.admin_password, cfg) + '">返回后台</a>');
      return send(res, 302, '', { Location: buildAuthorizeUrl(cfg) });
    }

    if (p === '/oauth/callback') {
      const code = urlObj.searchParams.get('authorization_code') || urlObj.searchParams.get('code');
      if (!code) return send(res, 200, '<h3>授权未返回 authorization_code</h3>');
      const tk = await exchangeCode(code);
      return send(res, 200, '<h3>授权成功，token 已保存，服务端会自动刷新</h3><a href="' + withShare('/admin?key=' + cfg.admin_password, cfg) + '">返回管理后台</a>');
    }

    if (p === '/admin') {
      if (urlObj.searchParams.get('key') !== cfg.admin_password) return send(res, 200, '<h3>密码错误</h3>');
      return send(res, 200, adminHtml(cfg, loadTokens(), urlObj.searchParams.get('msg') || ''));
    }

    if (p === '/admin/save' && req.method === 'POST') {
      const f = parseForm(await readBody(req));
      if (f.key !== cfg.admin_password) return send(res, 200, '<h3>密码错误</h3>');
      saveConfig({
        admin_password: f.admin_password || cfg.admin_password,
        account_id: f.account_id, user_action_set_id: f.user_action_set_id,
        action_type: f.action_type || 'VIEW_PAGE',
        client_id: f.client_id, client_secret: f.client_secret,
        base_url: f.base_url || cfg.base_url,
        sharecode: f.sharecode || cfg.sharecode,
        gdt_key: f.gdt_key, report_mode: f.report_mode || cfg.report_mode
      });
      return send(res, 302, '', { Location: withShare('/admin?key=' + encodeURIComponent(f.admin_password || cfg.admin_password) + '&msg=' + encodeURIComponent('配置已保存'), getConfig()) });
    }

    if (p === '/admin/test' && req.method === 'POST') {
      const f = parseForm(await readBody(req));
      if (f.key !== cfg.admin_password) return send(res, 200, '<h3>密码错误</h3>');
      let msg;
      try { await reportConversion(f.click_id, {}); msg = '测试回传成功（code=0）'; }
      catch (e) { msg = '测试回传失败：' + e.message; }
      return send(res, 302, '', { Location: withShare('/admin?key=' + encodeURIComponent(f.key) + '&msg=' + encodeURIComponent(msg), cfg) });
    }

    res.writeHead(404); return res.end('not found');
  } catch (e) {
    return send(res, 500, '<h3>服务器错误</h3><pre>' + (e.stack || e.message) + '</pre>');
  }
});

server.listen(PORT, '0.0.0.0', () => {
  console.log('server listening on', PORT);
});
