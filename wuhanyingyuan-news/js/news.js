(function () {
  "use strict";

  var PAGE_SIZE = 9;

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function formatDate(value) {
    if (!value) return "";
    var date = new Date(value);
    if (isNaN(date.getTime())) return "";
    var month = String(date.getMonth() + 1).padStart(2, "0");
    var day = String(date.getDate()).padStart(2, "0");
    return date.getFullYear() + "-" + month + "-" + day;
  }

  function coverHtml(article, withFallbackText) {
    if (article.cover) {
      return '<img src="' + escapeHtml(article.cover) + '" alt="' + escapeHtml(article.title) + '" loading="lazy" decoding="async">';
    }
    var fallback = withFallbackText ? escapeHtml(article.title || "").slice(0, 2) : "资讯";
    return '<span class="news-cover-fallback" aria-hidden="true">' + fallback + "</span>";
  }

  function articleCard(article) {
    return (
      '<article class="news-card">' +
      '<a href="article.html?id=' + encodeURIComponent(article.id) + '">' +
      '<div class="news-cover">' + coverHtml(article, true) + "</div>" +
      '<div class="news-body">' +
      (article.category ? '<span class="news-tag">' + escapeHtml(article.category) + "</span>" : "") +
      "<h3>" + escapeHtml(article.title) + "</h3>" +
      (article.summary ? "<p>" + escapeHtml(article.summary) + "</p>" : "") +
      '<div class="news-meta"><time datetime="' + escapeHtml(article.createdAt) + '">' + formatDate(article.createdAt) + "</time></div>" +
      "</div></a></article>"
    );
  }

  function request(url) {
    return fetch(url, { headers: { Accept: "application/json" } }).then(function (response) {
      if (!response.ok) throw new Error("HTTP " + response.status);
      return response.json();
    });
  }

  /* ---- 首页新闻区块：最新 3 条 ---- */
  function initHomeNews() {
    var grid = document.getElementById("newsGrid");
    if (!grid) return;

    request("/api/articles?page=1&pageSize=3")
      .then(function (data) {
        var list = (data && data.articles) || [];
        if (!list.length) {
          grid.innerHTML = '<p class="news-empty">资讯准备中，敬请期待。</p>';
          return;
        }
        grid.innerHTML = list.map(articleCard).join("");
      })
      .catch(function () {
        grid.innerHTML = '<p class="news-empty">资讯准备中，敬请期待。</p>';
      });
  }

  /* ---- 新闻列表页 ---- */
  function initListPage() {
    var listEl = document.getElementById("newsList");
    var pagerEl = document.getElementById("newsPager");
    if (!listEl || !pagerEl) return;

    function loadPage(page) {
      listEl.innerHTML = '<p class="news-loading">正在加载…</p>';
      pagerEl.innerHTML = "";

      request("/api/articles?page=" + page + "&pageSize=" + PAGE_SIZE)
        .then(function (data) {
          var list = (data && data.articles) || [];
          var total = (data && data.total) || 0;
          var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));

          if (!list.length) {
            listEl.innerHTML = '<p class="news-empty">暂无资讯，请稍后再来看看。</p>';
            return;
          }

          listEl.innerHTML = list.map(articleCard).join("");

          var html = "";
          html += '<button type="button" data-page="' + (page - 1) + '"' + (page <= 1 ? " disabled" : "") + ">上一页</button>";
          for (var i = 1; i <= pages; i++) {
            html += '<button type="button" data-page="' + i + '" class="' + (i === page ? "is-active" : "") + '">' + i + "</button>";
          }
          html += '<button type="button" data-page="' + (page + 1) + '"' + (page >= pages ? " disabled" : "") + ">下一页</button>";
          pagerEl.innerHTML = html;

          Array.prototype.forEach.call(pagerEl.querySelectorAll("button[data-page]"), function (btn) {
            btn.addEventListener("click", function () {
              var target = Number(btn.getAttribute("data-page"));
              if (target >= 1 && target <= pages) {
                loadPage(target);
                window.scrollTo({ top: 0, behavior: "smooth" });
              }
            });
          });
        })
        .catch(function () {
          listEl.innerHTML = '<p class="news-empty">加载失败，请稍后刷新重试。</p>';
        });
    }

    loadPage(1);
  }

  /* ---- 文章详情页 ---- */
  function initArticlePage() {
    var wrap = document.getElementById("articleWrap");
    if (!wrap) return;

    var params = new URLSearchParams(window.location.search);
    var id = params.get("id");
    if (!id) {
      wrap.innerHTML = '<p class="article-error">未找到该文章。</p>';
      return;
    }

    request("/api/articles/" + encodeURIComponent(id))
      .then(function (data) {
        var article = data && data.article;
        if (!article || article.published === false) {
          wrap.innerHTML = '<p class="article-error">文章不存在或未发布。</p>';
          return;
        }

        document.title = article.title + "｜盈圆新闻资讯";

        var meta = formatDate(article.createdAt) + (article.category ? " · " + escapeHtml(article.category) : "");
        var html =
          '<a class="article-back" href="news.html">← 返回资讯列表</a>' +
          '<header class="article-head">' +
          (article.category ? '<span class="news-tag">' + escapeHtml(article.category) + "</span>" : "") +
          "<h1>" + escapeHtml(article.title) + "</h1>" +
          '<p class="article-meta"><time datetime="' + escapeHtml(article.createdAt) + '">' + meta + "</time></p>" +
          "</header>" +
          (article.cover ? '<div class="article-cover"><img src="' + escapeHtml(article.cover) + '" alt="' + escapeHtml(article.title) + '"></div>' : "") +
          '<div class="article-content">' + (article.content || "") + "</div>";

        wrap.innerHTML = html;
      })
      .catch(function () {
        wrap.innerHTML = '<p class="article-error">加载失败或文章不存在。</p>';
      });
  }

  initHomeNews();
  initListPage();
  initArticlePage();
})();
