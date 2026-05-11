(function () {
  var ROUTE = "/server/app-domain-plugin";
  var stateCache = null;
  var loadingPromise = null;
  var styleMounted = false;

  function routePath() {
    var hash = window.location.hash || "";
    if (hash.indexOf("#") === 0) return hash.slice(1) || "/";
    return window.location.pathname || "/";
  }

  function isTargetRoute() { return routePath() === ROUTE; }

  function adminApi(path) {
    return "/api/v1/" + window.settings.secure_path + path;
  }

  function mountStyle() {
    if (styleMounted) return;
    styleMounted = true;
    var style = document.createElement("style");
    style.textContent = [
      ".adm-page{padding:0 24px 24px;max-width:1200px;}",
      ".adm-card{background:#fff;border-radius:8px;border:1px solid #f0f0f0;margin-bottom:16px;}",
      ".adm-card-head{padding:16px 24px 12px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;}",
      ".adm-card-title{font-size:16px;font-weight:600;color:#1f2937;margin:0;}",
      ".adm-card-body{padding:24px;}",
      ".adm-row{display:flex;gap:24px;flex-wrap:wrap;}",
      ".adm-col{flex:1;min-width:280px;}",
      ".adm-form-item{margin-bottom:20px;}",
      ".adm-form-label{display:block;font-size:14px;font-weight:500;color:#374151;margin-bottom:6px;}",
      ".adm-form-desc{font-size:12px;color:#9ca3af;margin-top:4px;line-height:1.6;}",
      ".adm-input{width:100%;height:36px;padding:6px 12px;border:1px solid #d9d9d9;border-radius:6px;font-size:14px;color:#1f2937;background:#fff;outline:none;transition:border-color .2s;}",
      ".adm-input:focus{border-color:#1890ff;box-shadow:0 0 0 2px rgba(24,144,255,.1);}",
      ".adm-textarea{width:100%;padding:8px 12px;border:1px solid #d9d9d9;border-radius:6px;font-size:14px;color:#1f2937;background:#fff;outline:none;resize:vertical;transition:border-color .2s;font-family:inherit;}",
      ".adm-textarea:focus{border-color:#1890ff;box-shadow:0 0 0 2px rgba(24,144,255,.1);}",
      ".adm-switch{position:relative;display:inline-block;width:40px;height:22px;cursor:pointer;}",
      ".adm-switch input{opacity:0;width:0;height:0;}",
      ".adm-switch-slider{position:absolute;inset:0;background:#d9d9d9;border-radius:11px;transition:.2s;}",
      ".adm-switch-slider:before{content:'';position:absolute;width:18px;height:18px;left:2px;top:2px;background:#fff;border-radius:50%;transition:.2s;}",
      ".adm-switch input:checked+.adm-switch-slider{background:#1890ff;}",
      ".adm-switch input:checked+.adm-switch-slider:before{transform:translateX(18px);}",
      ".adm-switch-row{display:flex;align-items:center;gap:10px;}",
      ".adm-switch-text{font-size:13px;color:#6b7280;}",
      ".adm-btn{display:inline-flex;align-items:center;justify-content:center;height:32px;padding:0 16px;border-radius:6px;font-size:14px;font-weight:500;cursor:pointer;border:1px solid #d9d9d9;background:#fff;color:#374151;transition:all .2s;}",
      ".adm-btn:hover{border-color:#1890ff;color:#1890ff;}",
      ".adm-btn-primary{background:#1890ff;border-color:#1890ff;color:#fff;}",
      ".adm-btn-primary:hover{background:#40a9ff;border-color:#40a9ff;color:#fff;}",
      ".adm-btn:disabled{opacity:.5;cursor:not-allowed;}",
      ".adm-alert{padding:12px 16px;border-radius:6px;font-size:13px;line-height:1.7;margin-bottom:16px;}",
      ".adm-alert-info{background:#e6f7ff;border:1px solid #91d5ff;color:#0050b3;}",
      ".adm-alert-success{background:#f6ffed;border:1px solid #b7eb8f;color:#135200;}",
      ".adm-alert-error{background:#fff2f0;border:1px solid #ffccc7;color:#a8071a;}",
      ".adm-preview{background:#fafafa;border:1px solid #f0f0f0;border-radius:6px;padding:12px 14px;font-size:13px;color:#595959;word-break:break-all;line-height:1.8;}",
      ".adm-preview-empty{color:#bfbfbf;font-style:italic;}",
      ".adm-divider{height:1px;background:#f0f0f0;margin:20px 0;}",
      "@media(max-width:768px){.adm-row{flex-direction:column;gap:0;}.adm-col{min-width:100%;}}"
    ].join("\n");
    document.head.appendChild(style);
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;")
      .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
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
    if (title) title.textContent = "App 域名管理";
  }

  function getSubscribeExample() {
    var host = document.getElementById("adm_public_host");
    var path = document.getElementById("adm_subscribe_path");
    var value = path && path.value ? path.value : "/api/v1/client/custom_app/subscribe";
    var normalizedPath = value.indexOf("/") === 0 ? value : "/" + value;
    if (host && host.value.trim()) {
      return "https://" + host.value.trim() + normalizedPath + "?token=YOUR_TOKEN";
    }
    return normalizedPath + "?token=YOUR_TOKEN";
  }

  function getApiExamples() {
    var textarea = document.getElementById("adm_api_hosts");
    var hosts = textarea ? textarea.value.split(/\n+/).map(function (item) {
      return item.trim().replace(/^https?:\/\//i, "").replace(/\/+$/, "");
    }).filter(Boolean) : [];
    if (!hosts.length) {
      return '<span class="adm-preview-empty">尚未配置 API 域名</span>';
    }
    return hosts.map(function (host) {
      return "<div>https://" + escapeHtml(host) + "/api/v2/app/bootstrap</div>";
    }).join("");
  }

  function updatePreview() {
    var subscribeEl = document.getElementById("adm-preview-subscribe");
    var apiEl = document.getElementById("adm-preview-api");
    if (subscribeEl) subscribeEl.textContent = getSubscribeExample();
    if (apiEl) apiEl.innerHTML = getApiExamples();
  }

  function switchHtml(id, checked, label) {
    return '<div class="adm-switch-row">' +
      '<label class="adm-switch"><input type="checkbox" id="' + id + '"' + (checked ? " checked" : "") + '><span class="adm-switch-slider"></span></label>' +
      '<span class="adm-switch-text">' + escapeHtml(label) + '</span></div>';
  }

  function formItem(label, content, desc) {
    return '<div class="adm-form-item"><label class="adm-form-label">' + escapeHtml(label) + '</label>' +
      '<div class="adm-form-control">' + content + '</div>' +
      (desc ? '<div class="adm-form-desc">' + desc + '</div>' : '') + '</div>';
  }

  function renderPage(state) {
    mountStyle();
    setHeaderTitle();
    var root = getMainRoot();
    if (!root) return false;

    root.innerHTML = [
      '<div class="adm-page" id="adm-panel">',
      '  <div id="adm-alert" class="adm-alert adm-alert-info">',
      '    配置 App 客户端的订阅域名、节点入口域名替换、API 多域名池。常规 Clash / V2rayN 订阅不受此处配置影响。',
      '  </div>',

      '  <div class="adm-card">',
      '    <div class="adm-card-head"><h3 class="adm-card-title">节点入口与订阅</h3></div>',
      '    <div class="adm-card-body">',
      '      <div class="adm-row">',
      '        <div class="adm-col">',
               formItem("节点入口域名替换", switchHtml("adm_domain_enable", state.app_domain_enable, "开启后 App 下发节点统一替换为指定入口域名")),
               formItem("入口域名", '<input class="adm-input" id="adm_replace_host" placeholder="edge.example.com" value="' + escapeHtml(state.app_domain_replace_host) + '">', "App 下发节点的 address 字段会被替换为此域名"),
      '        </div>',
      '        <div class="adm-col">',
               formItem("App 订阅域名", '<input class="adm-input" id="adm_public_host" placeholder="app.example.com" value="' + escapeHtml(state.app_domain_public_host) + '">', "用户在 App 内获取订阅时使用的域名，只填域名不带协议"),
               formItem("订阅路径", '<input class="adm-input" id="adm_subscribe_path" placeholder="/api/v1/client/custom_app/subscribe" value="' + escapeHtml(state.app_domain_subscribe_path) + '">', "App 专用订阅接口路径，必须以 / 开头"),
      '        </div>',
      '      </div>',
      '      <div class="adm-divider"></div>',
      '      <div class="adm-row">',
      '        <div class="adm-col">',
      '          <label class="adm-form-label">订阅链接预览</label>',
      '          <div class="adm-preview" id="adm-preview-subscribe"></div>',
      '        </div>',
      '        <div class="adm-col">',
      '          <label class="adm-form-label">API Bootstrap 预览</label>',
      '          <div class="adm-preview" id="adm-preview-api"></div>',
      '        </div>',
      '      </div>',
      '    </div>',
      '  </div>',

      '  <div class="adm-card">',
      '    <div class="adm-card-head"><h3 class="adm-card-title">API 多域名池</h3></div>',
      '    <div class="adm-card-body">',
      '      <div class="adm-row">',
      '        <div class="adm-col">',
               formItem("启用多域名", switchHtml("adm_api_enable", state.app_api_domain_enable, "开启后 bootstrap 接口返回多域名供客户端轮询")),
               formItem("域名列表", '<textarea class="adm-textarea" id="adm_api_hosts" rows="6" placeholder="api1.example.com&#10;api2.example.com">' + escapeHtml(state.app_api_domain_hosts.join("\n")) + '</textarea>', "每行一个域名，客户端按顺序轮询"),
      '        </div>',
      '        <div class="adm-col">',
               formItem("加密下发", switchHtml("adm_encrypt_enable", state.app_api_domain_encrypt_enable, "返回 encrypted_api_urls 加密字段")),
               formItem("加密密钥", '<input class="adm-input" id="adm_encrypt_key" placeholder="留空则不输出加密字段" value="' + escapeHtml(state.app_api_domain_encrypt_key) + '">', "AES-256-CBC 加密，客户端内置对应密钥解密"),
      '        </div>',
      '      </div>',
      '    </div>',
      '  </div>',

      '  <div style="text-align:right;padding-top:8px;">',
      '    <button class="adm-btn adm-btn-primary" id="adm-save-btn">保存</button>',
      '  </div>',
      '</div>'
    ].join("\n");

    bindEvents();
    updatePreview();
    return true;
  }

  function showAlert(type, message) {
    var alert = document.getElementById("adm-alert");
    if (!alert) return;
    alert.className = "adm-alert " + (type === "error" ? "adm-alert-error" : type === "success" ? "adm-alert-success" : "adm-alert-info");
    alert.textContent = message;
  }

  function bindEvents() {
    ["adm_public_host", "adm_subscribe_path", "adm_api_hosts"].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener("input", updatePreview);
    });
    var saveBtn = document.getElementById("adm-save-btn");
    if (saveBtn) saveBtn.addEventListener("click", saveConfig);
  }

  function collectForm() {
    return {
      app_domain_enable: document.getElementById("adm_domain_enable").checked ? 1 : 0,
      app_domain_public_host: document.getElementById("adm_public_host").value.trim(),
      app_domain_subscribe_path: document.getElementById("adm_subscribe_path").value.trim() || "/api/v1/client/custom_app/subscribe",
      app_domain_replace_host: document.getElementById("adm_replace_host").value.trim(),
      app_api_domain_enable: document.getElementById("adm_api_enable").checked ? 1 : 0,
      app_api_domain_hosts: document.getElementById("adm_api_hosts").value.split(/\n+/).map(function (item) { return item.trim(); }).filter(Boolean),
      app_api_domain_encrypt_enable: document.getElementById("adm_encrypt_enable").checked ? 1 : 0,
      app_api_domain_encrypt_key: document.getElementById("adm_encrypt_key").value.trim()
    };
  }

  function request(method, path, payload) {
    var authorization = "";
    try { authorization = window.localStorage.getItem("authorization") || ""; } catch (e) {}
    var finalPayload = payload ? Object.assign({}, payload) : null;
    if (finalPayload && authorization) finalPayload.auth_data = authorization;
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
        if (!response.ok) throw new Error(json.message || "请求失败");
        return json;
      });
    });
  }

  function loadConfig(force) {
    if (stateCache && !force) { renderPage(stateCache); return Promise.resolve(stateCache); }
    if (loadingPromise && !force) return loadingPromise;
    loadingPromise = request("GET", "/server/app-domain/fetch").then(function (json) {
      stateCache = normalizeState(json.data || {});
      renderPage(stateCache);
      return stateCache;
    }).catch(function (error) {
      showAlert("error", error.message || "加载失败");
      throw error;
    }).finally(function () { loadingPromise = null; });
    return loadingPromise;
  }

  function saveConfig() {
    var button = document.getElementById("adm-save-btn");
    if (!button) return;
    var payload = collectForm();
    button.disabled = true;
    button.textContent = "保存中...";
    showAlert("info", "正在保存...");
    request("POST", "/server/app-domain/save", payload).then(function () {
      stateCache = normalizeState(payload);
      renderPage(stateCache);
      showAlert("success", "保存成功");
    }).catch(function (error) {
      showAlert("error", error.message || "保存失败");
    }).finally(function () {
      button.disabled = false;
      button.textContent = "保存";
    });
  }

  function maybeRender() {
    if (!isTargetRoute()) return;
    if (document.getElementById("adm-panel")) { setHeaderTitle(); return; }
    if (renderPage(stateCache || normalizeState({})) && !stateCache) loadConfig();
  }

  window.addEventListener("hashchange", function () { setTimeout(maybeRender, 50); });
  window.addEventListener("load", function () { setTimeout(maybeRender, 50); });
  setInterval(maybeRender, 800);
})();
