(function () {
  var ROUTE = "/server/app-domain-plugin";
  var stateCache = null;
  var loadingPromise = null;
  var styleMounted = false;

  function routePath() {
    var hash = window.location.hash || "";
    if (hash.indexOf("#") === 0) {
      return hash.slice(1) || "/";
    }
    return window.location.pathname || "/";
  }

  function isTargetRoute() {
    return routePath() === ROUTE;
  }

  function adminApi(path) {
    return "/api/v1/" + window.settings.secure_path + path;
  }

  function mountStyle() {
    if (styleMounted) return;
    styleMounted = true;
    var style = document.createElement("style");
    style.textContent = [
      ".app-domain-page .block-title{font-size:18px;font-weight:600;}",
      ".app-domain-page .app-domain-tip{background:#f0f7ff;border:1px solid #cfe4ff;border-radius:8px;padding:14px 16px;color:#355070;line-height:1.8;}",
      ".app-domain-page .app-domain-tip strong{color:#1f3c88;}",
      ".app-domain-page .app-domain-preview{background:#f8fafc;border:1px solid #e9eef5;border-radius:8px;padding:12px 14px;word-break:break-all;}",
      ".app-domain-page .app-domain-subtle{color:#6b7280;font-size:12px;line-height:1.7;}",
      ".app-domain-page .custom-control{padding-top:6px;}",
      ".app-domain-page .form-control[readonly]{background:#f8fafc;}",
      ".app-domain-page .app-domain-empty{color:#9aa4b2;}"
    ].join("");
    document.head.appendChild(style);
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function normalizeState(data) {
    var hosts = Array.isArray(data.app_api_domain_hosts) ? data.app_api_domain_hosts : [];
    return {
      app_domain_enable: Number(data.app_domain_enable || 0),
      app_domain_public_host: data.app_domain_public_host || "",
      app_domain_subscribe_path: data.app_domain_subscribe_path || "/api/v1/client/custom_app/subscribe",
      app_domain_replace_host: data.app_domain_replace_host || "",
      app_api_domain_enable: Number(data.app_api_domain_enable || 0),
      app_api_domain_hosts: hosts,
      app_api_domain_encrypt_enable: Number(data.app_api_domain_encrypt_enable || 0),
      app_api_domain_encrypt_key: data.app_api_domain_encrypt_key || "",
      preview: data.preview || {}
    };
  }

  function getMainRoot() {
    return document.querySelector("#main-container > .p-0.p-lg-4")
      || document.querySelector("#main-container > div")
      || document.querySelector("#main-container");
  }

  function setHeaderTitle() {
    var title = document.querySelector(".v2board-container-title");
    if (title) {
      title.textContent = "App域名管理";
    }
  }

  function getSubscribeExample() {
    var host = document.getElementById("app_domain_public_host");
    var path = document.getElementById("app_domain_subscribe_path");
    var value = path && path.value ? path.value : "/api/v1/client/custom_app/subscribe";
    var normalizedPath = value.indexOf("/") === 0 ? value : "/" + value;
    if (host && host.value.trim()) {
      return "https://" + host.value.trim() + normalizedPath + "?token=用户token";
    }
    return normalizedPath + "?token=用户token";
  }

  function getApiExamples() {
    var textarea = document.getElementById("app_api_domain_hosts");
    var hosts = textarea ? textarea.value.split(/\n+/).map(function (item) {
      return item.trim().replace(/^https?:\/\//i, "").replace(/\/+$/, "");
    }).filter(Boolean) : [];
    if (!hosts.length) {
      return '<span class="app-domain-empty">未填写 App API 域名池</span>';
    }
    return hosts.map(function (host) {
      return '<div>https://' + escapeHtml(host) + '/api/v1/client/app/bootstrap</div>';
    }).join("");
  }

  function updatePreview() {
    var subscribeEl = document.getElementById("app-domain-subscribe-preview");
    var apiEl = document.getElementById("app-domain-api-preview");
    if (subscribeEl) {
      subscribeEl.textContent = getSubscribeExample();
    }
    if (apiEl) {
      apiEl.innerHTML = getApiExamples();
    }
  }

  function renderPage(state) {
    mountStyle();
    setHeaderTitle();
    var root = getMainRoot();
    if (!root) return false;

    root.innerHTML = [
      '<div class="content app-domain-page" id="app-domain-panel">',
      '  <div class="block block-bottom">',
      '    <div class="block-header block-header-default d-flex justify-content-between align-items-center">',
      '      <h3 class="block-title">App域名管理</h3>',
      '      <button type="button" class="btn btn-primary btn-sm" id="app-domain-save-btn">保存配置</button>',
      "    </div>",
      '    <div class="block-content">',
      '      <div id="app-domain-alert" class="alert alert-info app-domain-tip">',
      '        <strong>这版只保留真正会生效的功能：</strong> App订阅域名、App节点入口域名、App API 多域名，以及节点里的 App 可见开关。Clash / V2rayN 的常规订阅不受影响。',
      "      </div>",
      '      <div class="row">',
      '        <div class="col-lg-6">',
      '          <div class="form-group">',
      '            <label>启用 App 节点入口域名替换</label>',
      '            <div class="custom-control custom-switch">',
      '              <input type="checkbox" class="custom-control-input" id="app_domain_enable"' + (state.app_domain_enable ? " checked" : "") + '>',
      '              <label class="custom-control-label" for="app_domain_enable">开启后，App 下发节点统一替换为下方入口域名</label>',
      "            </div>",
      "          </div>",
      '          <div class="form-group">',
      '            <label>App 节点入口域名</label>',
      '            <input class="form-control" id="app_domain_replace_host" placeholder="edge.example.com" value="' + escapeHtml(state.app_domain_replace_host) + '">',
      '            <div class="app-domain-subtle">只替换 App 下发的节点入口 host。父节点 / 子节点会保持一致，不再引入额外端口、SNI、WSHost 配置。</div>',
      "          </div>",
      '          <div class="form-group">',
      '            <label>App 订阅域名</label>',
      '            <input class="form-control" id="app_domain_public_host" placeholder="app.example.com" value="' + escapeHtml(state.app_domain_public_host) + '">',
      '            <div class="app-domain-subtle">用户在 App 内拿订阅时使用这个域名。只填域名即可，不要带 http(s)://。</div>',
      "          </div>",
      '          <div class="form-group">',
      '            <label>App 订阅路径</label>',
      '            <input class="form-control" id="app_domain_subscribe_path" placeholder="/api/v1/client/custom_app/subscribe" value="' + escapeHtml(state.app_domain_subscribe_path) + '">',
      '            <div class="app-domain-subtle">默认就是 App 专用订阅路径。改了以后，你的 App 订阅链接展示也会跟着变。</div>',
      "          </div>",
      "        </div>",
      '        <div class="col-lg-6">',
      '          <div class="form-group">',
      '            <label>启用 App API 多域名</label>',
      '            <div class="custom-control custom-switch">',
      '              <input type="checkbox" class="custom-control-input" id="app_api_domain_enable"' + (state.app_api_domain_enable ? " checked" : "") + '>',
      '              <label class="custom-control-label" for="app_api_domain_enable">开启后，App bootstrap 会返回多域名池供客户端轮询</label>',
      "            </div>",
      "          </div>",
      '          <div class="form-group">',
      '            <label>App API 域名池</label>',
      '            <textarea class="form-control" id="app_api_domain_hosts" rows="8" placeholder="api1.example.com&#10;api2.example.com">' + escapeHtml(state.app_api_domain_hosts.join("\\n")) + '</textarea>',
      '            <div class="app-domain-subtle">每行一个域名，客户端可按返回顺序轮询登录、取版本和拉最新信息。</div>',
      "          </div>",
      '          <div class="form-group">',
      '            <label>启用 API 域名加密下发</label>',
      '            <div class="custom-control custom-switch">',
      '              <input type="checkbox" class="custom-control-input" id="app_api_domain_encrypt_enable"' + (state.app_api_domain_encrypt_enable ? " checked" : "") + '>',
      '              <label class="custom-control-label" for="app_api_domain_encrypt_enable">返回 encrypted_api_urls 字段</label>',
      "            </div>",
      "          </div>",
      '          <div class="form-group">',
      '            <label>API 域名加密密钥</label>',
      '            <input class="form-control" id="app_api_domain_encrypt_key" placeholder="留空则不输出加密字段" value="' + escapeHtml(state.app_api_domain_encrypt_key) + '">',
      '            <div class="app-domain-subtle">当前输出格式为 AES-256-CBC(base64)，你的 App 客户端后续接入时解密即可。</div>',
      "          </div>",
      "        </div>",
      "      </div>",
      '      <div class="row mt-3">',
      '        <div class="col-lg-6">',
      '          <label>App 订阅链接示例</label>',
      '          <div class="app-domain-preview" id="app-domain-subscribe-preview"></div>',
      "        </div>",
      '        <div class="col-lg-6">',
      '          <label>App API bootstrap 示例</label>',
      '          <div class="app-domain-preview" id="app-domain-api-preview"></div>',
      "        </div>",
      "      </div>",
      '      <div class="row mt-3">',
      '        <div class="col-12">',
      '          <div class="app-domain-subtle">节点 App 可见性已经合并进 <strong>节点管理</strong>，你可以直接在节点列表里单独控制某个节点是否只给 App 展示。</div>',
      "        </div>",
      "      </div>",
      "    </div>",
      "  </div>",
      "</div>"
    ].join("");

    bindEvents();
    updatePreview();
    return true;
  }

  function showAlert(type, message) {
    var alert = document.getElementById("app-domain-alert");
    if (!alert) return;
    alert.className = "alert app-domain-tip " + (type === "error" ? "alert-danger" : type === "success" ? "alert-success" : "alert-info");
    alert.textContent = message;
  }

  function bindEvents() {
    [
      "app_domain_public_host",
      "app_domain_subscribe_path",
      "app_api_domain_hosts"
    ].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) {
        el.addEventListener("input", updatePreview);
      }
    });

    var saveBtn = document.getElementById("app-domain-save-btn");
    if (saveBtn) {
      saveBtn.addEventListener("click", saveConfig);
    }
  }

  function collectForm() {
    return {
      app_domain_enable: document.getElementById("app_domain_enable").checked ? 1 : 0,
      app_domain_public_host: document.getElementById("app_domain_public_host").value.trim(),
      app_domain_subscribe_path: document.getElementById("app_domain_subscribe_path").value.trim() || "/api/v1/client/custom_app/subscribe",
      app_domain_replace_host: document.getElementById("app_domain_replace_host").value.trim(),
      app_api_domain_enable: document.getElementById("app_api_domain_enable").checked ? 1 : 0,
      app_api_domain_hosts: document.getElementById("app_api_domain_hosts").value.split(/\n+/).map(function (item) {
        return item.trim();
      }).filter(Boolean),
      app_api_domain_encrypt_enable: document.getElementById("app_api_domain_encrypt_enable").checked ? 1 : 0,
      app_api_domain_encrypt_key: document.getElementById("app_api_domain_encrypt_key").value.trim()
    };
  }

  function request(method, path, payload) {
    var authorization = "";
    try {
      authorization = window.localStorage.getItem("authorization") || "";
    } catch (e) {}
    var finalPayload = payload ? Object.assign({}, payload) : null;
    if (finalPayload && authorization) {
      finalPayload.auth_data = authorization;
    }
    return fetch(adminApi(path), {
      method: method,
      credentials: "same-origin",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "authorization": authorization
      },
      body: finalPayload ? JSON.stringify(finalPayload) : undefined
    }).then(function (response) {
      return response.json().catch(function () {
        throw new Error("接口返回了不可解析内容");
      }).then(function (json) {
        if (!response.ok) {
          throw new Error(json.message || "请求失败");
        }
        return json;
      });
    });
  }

  function loadConfig(force) {
    if (stateCache && !force) {
      renderPage(stateCache);
      return Promise.resolve(stateCache);
    }
    if (loadingPromise && !force) {
      return loadingPromise;
    }
    loadingPromise = request("GET", "/server/app-domain/fetch").then(function (json) {
      stateCache = normalizeState(json.data || {});
      renderPage(stateCache);
      return stateCache;
    }).catch(function (error) {
      showAlert("error", error.message || "加载失败");
      throw error;
    }).finally(function () {
      loadingPromise = null;
    });
    return loadingPromise;
  }

  function saveConfig() {
    var button = document.getElementById("app-domain-save-btn");
    if (!button) return;
    var payload = collectForm();
    button.disabled = true;
    button.textContent = "保存中...";
    showAlert("info", "正在保存 App 域名配置...");
    request("POST", "/server/app-domain/save", payload).then(function () {
      stateCache = normalizeState(payload);
      renderPage(stateCache);
      showAlert("success", "保存成功，配置已经写入后端。");
    }).catch(function (error) {
      showAlert("error", error.message || "保存失败");
    }).finally(function () {
      button.disabled = false;
      button.textContent = "保存配置";
    });
  }

  function maybeRender() {
    if (!isTargetRoute()) return;
    if (document.getElementById("app-domain-panel")) {
      setHeaderTitle();
      return;
    }
    if (renderPage(stateCache || {
      app_domain_enable: 0,
      app_domain_public_host: "",
      app_domain_subscribe_path: "/api/v1/client/custom_app/subscribe",
      app_domain_replace_host: "",
      app_api_domain_enable: 0,
      app_api_domain_hosts: [],
      app_api_domain_encrypt_enable: 0,
      app_api_domain_encrypt_key: "",
      preview: {}
    }) && !stateCache) {
      loadConfig();
    }
  }

  window.addEventListener("hashchange", function () {
    setTimeout(maybeRender, 50);
  });
  window.addEventListener("load", function () {
    setTimeout(maybeRender, 50);
  });
  setInterval(maybeRender, 800);
})();
