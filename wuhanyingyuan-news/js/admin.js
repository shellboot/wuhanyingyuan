(function () {
  "use strict";

  var TOKEN_KEY = "yy_admin_token";
  var editingId = null; // null = 新建

  var loginView = document.getElementById("loginView");
  var adminView = document.getElementById("adminView");
  var loginForm = document.getElementById("loginForm");
  var loginPassword = document.getElementById("loginPassword");
  var loginBtn = document.getElementById("loginBtn");
  var loginError = document.getElementById("loginError");
  var logoutBtn = document.getElementById("logoutBtn");
  var newBtn = document.getElementById("newBtn");
  var listPanel = document.getElementById("listPanel");
  var editorPanel = document.getElementById("editorPanel");
  var editorTitle = document.getElementById("editorTitle");
  var articleForm = document.getElementById("articleForm");
  var articleRows = document.getElementById("articleRows");
  var fieldTitle = document.getElementById("fieldTitle");
  var fieldCategory = document.getElementById("fieldCategory");
  var fieldSummary = document.getElementById("fieldSummary");
  var fieldCover = document.getElementById("fieldCover");
  var fieldContent = document.getElementById("fieldContent");
  var fieldPublished = document.getElementById("fieldPublished");
  var coverPreview = document.getElementById("coverPreview");
  var coverFile = document.getElementById("coverFile");
  var coverUploadBtn = document.getElementById("coverUploadBtn");
  var coverClearBtn = document.getElementById("coverClearBtn");
  var cancelBtn = document.getElementById("cancelBtn");
  var saveBtn = document.getElementById("saveBtn");
  var toast = document.getElementById("toast");

  var toastTimer = null;

  function getToken() {
    return localStorage.getItem(TOKEN_KEY) || "";
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function formatDate(value) {
    if (!value) return "-";
    var date = new Date(value);
    if (isNaN(date.getTime())) return "-";
    var month = String(date.getMonth() + 1).padStart(2, "0");
    var day = String(date.getDate()).padStart(2, "0");
    return date.getFullYear() + "-" + month + "-" + day;
  }

  function showToast(message, isError) {
    toast.textContent = message;
    toast.classList.toggle("error", Boolean(isError));
    toast.classList.add("is-show");
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () {
      toast.classList.remove("is-show");
    }, 2600);
  }

  function api(path, options) {
    options = options || {};
    var headers = { "Content-Type": "application/json" };
    var token = getToken();
    if (token) headers.Authorization = "Bearer " + token;

    return fetch(path, {
      method: options.method || "GET",
      headers: headers,
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (data) {
        if (!response.ok) {
          var error = new Error((data && data.error) || "请求失败（" + response.status + "）");
          error.status = response.status;
          throw error;
        }
        return data;
      });
    });
  }

  function handleAuthError(error) {
    if (error && error.status === 401) {
      localStorage.removeItem(TOKEN_KEY);
      showLogin();
      showToast("登录已过期，请重新登录", true);
      return true;
    }
    return false;
  }

  /* ---------- 视图切换 ---------- */

  function showLogin() {
    adminView.hidden = true;
    loginView.hidden = false;
  }

  function showAdmin() {
    loginView.hidden = true;
    adminView.hidden = false;
    loadList();
  }

  /* ---------- 文章列表 ---------- */

  function loadList() {
    articleRows.innerHTML = "<tr><td colspan=\"5\">正在加载…</td></tr>";

    api("/api/articles?page=1&pageSize=200&all=1")
      .then(function (data) {
        var list = (data && data.articles) || [];
        if (!list.length) {
          articleRows.innerHTML = "<tr><td colspan=\"5\">还没有文章，点击右上角「新建文章」发布第一条资讯。</td></tr>";
          return;
        }
        articleRows.innerHTML = list.map(function (article) {
          var status = article.published
            ? '<span class="badge on">已发布</span>'
            : '<span class="badge off">草稿</span>';
          return (
            "<tr>" +
            '<td class="t-title"><a href="article.html?id=' + encodeURIComponent(article.id) + '" target="_blank" rel="noopener">' + escapeHtml(article.title) + "</a></td>" +
            '<td class="hide-sm">' + escapeHtml(article.category || "-") + "</td>" +
            '<td class="hide-sm">' + formatDate(article.createdAt) + "</td>" +
            "<td>" + status + "</td>" +
            '<td><div class="row-actions">' +
            '<button class="btn-mini" type="button" data-act="edit" data-id="' + escapeHtml(article.id) + '">编辑</button>' +
            '<button class="btn-mini" type="button" data-act="toggle" data-id="' + escapeHtml(article.id) + '">' + (article.published ? "转草稿" : "发布") + "</button>" +
            '<button class="btn-mini danger" type="button" data-act="delete" data-id="' + escapeHtml(article.id) + '" data-title="' + escapeHtml(article.title) + '">删除</button>' +
            "</div></td>" +
            "</tr>"
          );
        }).join("");
      })
      .catch(function (error) {
        if (handleAuthError(error)) return;
        articleRows.innerHTML = '<tr><td colspan="5">加载失败：' + escapeHtml(error.message) + "</td></tr>";
      });
  }

  articleRows.addEventListener("click", function (event) {
    var button = event.target.closest("button[data-act]");
    if (!button) return;

    var action = button.getAttribute("data-act");
    var id = button.getAttribute("data-id");

    if (action === "edit") {
      openEditor(id);
      return;
    }

    if (action === "toggle") {
      api("/api/articles/" + encodeURIComponent(id), { method: "GET" })
        .then(function (data) {
          var article = data.article;
          return api("/api/articles/" + encodeURIComponent(id), {
            method: "PUT",
            body: { published: !article.published }
          });
        })
        .then(function () {
          showToast("已更新发布状态");
          loadList();
        })
        .catch(function (error) {
          if (handleAuthError(error)) return;
          showToast(error.message, true);
        });
      return;
    }

    if (action === "delete") {
      var title = button.getAttribute("data-title") || "";
      if (!window.confirm("确定删除文章「" + title + "」吗？删除后不可恢复。")) return;
      api("/api/articles/" + encodeURIComponent(id), { method: "DELETE" })
        .then(function () {
          showToast("文章已删除");
          loadList();
        })
        .catch(function (error) {
          if (handleAuthError(error)) return;
          showToast(error.message, true);
        });
    }
  });

  /* ---------- 编辑器 ---------- */

  function openEditor(id) {
    editingId = id || null;
    listPanel.hidden = true;
    editorPanel.hidden = false;
    editorTitle.textContent = id ? "编辑文章" : "新建文章";
    articleForm.reset();
    coverPreview.hidden = true;

    if (!id) return;

    api("/api/articles/" + encodeURIComponent(id))
      .then(function (data) {
        var article = data.article;
        fieldTitle.value = article.title || "";
        fieldCategory.value = article.category || "";
        fieldSummary.value = article.summary || "";
        fieldCover.value = article.cover || "";
        fieldContent.value = article.content || "";
        fieldPublished.checked = article.published !== false;
        updateCoverPreview();
      })
      .catch(function (error) {
        if (handleAuthError(error)) return;
        showToast(error.message, true);
        closeEditor();
      });
  }

  function closeEditor() {
    editorPanel.hidden = true;
    listPanel.hidden = false;
    editingId = null;
  }

  newBtn.addEventListener("click", function () {
    openEditor(null);
  });

  cancelBtn.addEventListener("click", closeEditor);

  function updateCoverPreview() {
    var url = fieldCover.value.trim();
    if (url) {
      coverPreview.src = url;
      coverPreview.hidden = false;
    } else {
      coverPreview.hidden = true;
    }
  }

  fieldCover.addEventListener("change", updateCoverPreview);

  coverUploadBtn.addEventListener("click", function () {
    coverFile.click();
  });

  coverClearBtn.addEventListener("click", function () {
    fieldCover.value = "";
    updateCoverPreview();
  });

  coverFile.addEventListener("change", function () {
    var file = coverFile.files && coverFile.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
      showToast("图片不能超过 5MB", true);
      coverFile.value = "";
      return;
    }

    var reader = new FileReader();
    reader.onload = function () {
      showToast("正在上传图片…");
      api("/api/upload", {
        method: "POST",
        body: { name: file.name, data: String(reader.result) }
      })
        .then(function (data) {
          fieldCover.value = data.url;
          updateCoverPreview();
          showToast("封面上传成功");
        })
        .catch(function (error) {
          if (handleAuthError(error)) return;
          showToast(error.message, true);
        });
    };
    reader.readAsDataURL(file);
  });

  articleForm.addEventListener("submit", function (event) {
    event.preventDefault();

    var payload = {
      title: fieldTitle.value.trim(),
      category: fieldCategory.value.trim(),
      summary: fieldSummary.value.trim(),
      cover: fieldCover.value.trim(),
      content: fieldContent.value,
      published: fieldPublished.checked
    };

    if (!payload.title) {
      showToast("请填写文章标题", true);
      return;
    }
    if (!payload.content.trim()) {
      showToast("请填写正文内容", true);
      return;
    }

    saveBtn.disabled = true;
    var requestPromise = editingId
      ? api("/api/articles/" + encodeURIComponent(editingId), { method: "PUT", body: payload })
      : api("/api/articles", { method: "POST", body: payload });

    requestPromise
      .then(function () {
        showToast(editingId ? "文章已保存" : "文章已创建");
        closeEditor();
        loadList();
      })
      .catch(function (error) {
        if (handleAuthError(error)) return;
        showToast(error.message, true);
      })
      .finally(function () {
        saveBtn.disabled = false;
      });
  });

  /* ---------- 登录 / 退出 ---------- */

  loginForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var password = loginPassword.value;
    if (!password) return;

    loginBtn.disabled = true;
    loginError.style.display = "none";

    api("/api/login", { method: "POST", body: { password: password } })
      .then(function (data) {
        localStorage.setItem(TOKEN_KEY, data.token);
        loginPassword.value = "";
        showAdmin();
      })
      .catch(function () {
        loginError.style.display = "block";
      })
      .finally(function () {
        loginBtn.disabled = false;
      });
  });

  logoutBtn.addEventListener("click", function () {
    localStorage.removeItem(TOKEN_KEY);
    showLogin();
  });

  /* ---------- 初始化 ---------- */

  if (getToken()) {
    api("/api/articles?page=1&pageSize=1&all=1")
      .then(function () {
        showAdmin();
      })
      .catch(function (error) {
        if (error && error.status === 401) {
          localStorage.removeItem(TOKEN_KEY);
        }
        showLogin();
      });
  } else {
    showLogin();
  }
})();
