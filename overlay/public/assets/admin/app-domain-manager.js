(function () {
  var ROUTES = ["/server/app-domain", "/server/app-domain-plugin"];
  var state = {
    config: null,
    rules: [],
    options: {},
    filter: "",
    groupFilter: "",
    loading: false,
    saving: false,
    modalRule: null
  };
  var styleMounted = false;
  var loadingPromise = null;

  function routePath() {
    var hash = window.location.hash || "";
    if (hash.indexOf("#") === 0) return hash.slice(1) || "/";
    return window.location.pathname || "/";
  }

  function isTargetRoute() {
    return ROUTES.indexOf(routePath()) !== -1;
  }

  function adminApi(path) {
    return "/api/v1/" + window.settings.secure_path + path;
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function normalizeHost(value) {
    return String(value || "").trim().replace(/^https?:\/\//i, "").replace(/\/+$/, "");
  }

  function normalizeConfig(data) {
    data = data || {};
    return {
      app_domain_enable: Number(data.app_domain_enable || 0),
      app_domain_rule_enable: Number(data.app_domain_rule_enable || 0),
      app_domain_public_host: data.app_domain_public_host || "",
      app_domain_subscribe_path: data.app_domain_subscribe_path || "/api/v1/client/custom_app/subscribe",
      app_domain_replace_host: data.app_domain_replace_host || "",
      app_api_domain_enable: Number(data.app_api_domain_enable || 0),
      app_api_domain_hosts: Array.isArray(data.app_api_domain_hosts) ? data.app_api_domain_hosts : [],
      app_api_domain_encrypt_enable: Number(data.app_api_domain_encrypt_enable || 0),
      app_api_domain_encrypt_key: data.app_api_domain_encrypt_key || "",
      preview: data.preview || {}
    };
  }

  function normalizeRule(rule) {
    rule = rule || {};
    return {
      id: rule.id || "",
      name: rule.name || "",
      enable: Number(rule.enable == null ? 1 : rule.enable),
      sort: Number(rule.sort || 0),
      domain: rule.domain || "",
      user_group_ids: Array.isArray(rule.user_group_ids) ? rule.user_group_ids : [],
      plan_ids: Array.isArray(rule.plan_ids) ? rule.plan_ids : [],
      server_types: Array.isArray(rule.server_types) ? rule.server_types : [],
      server_ids: Array.isArray(rule.server_ids) ? rule.server_ids : [],
      protocols: Array.isArray(rule.protocols) ? rule.protocols : [],
      replace_node_host: Number(rule.replace_node_host == null ? 1 : rule.replace_node_host),
      replace_subscribe_host: Number(rule.replace_subscribe_host || 0),
      remark: rule.remark || ""
    };
  }

  function emptyRule() {
    return normalizeRule({
      enable: 1,
      sort: (state.rules || []).length + 1,
      replace_node_host: 1,
      replace_subscribe_host: 0
    });
  }

  function mountStyle() {
    if (styleMounted) return;
    styleMounted = true;
    var style = document.createElement("style");
    style.textContent = [
      ".adm-page{padding:0 24px 24px;max-width:1320px;}",
      ".adm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap;}",
      ".adm-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}",
      ".adm-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;}",
      ".adm-span-4{grid-column:span 4;}.adm-span-6{grid-column:span 6;}.adm-span-8{grid-column:span 8;}.adm-span-12{grid-column:span 12;}",
      ".adm-block{background:#fff;border:1px solid #eef0f4;border-radius:8px;margin-bottom:16px;}",
      ".adm-block-header{padding:14px 18px;border-bottom:1px solid #eef0f4;display:flex;align-items:center;justify-content:space-between;gap:12px;}",
      ".adm-block-title{font-size:15px;font-weight:600;color:#2f3542;margin:0;}",
      ".adm-block-body{padding:18px;}",
      ".adm-form-label{display:block;font-size:13px;color:#4a5568;margin-bottom:6px;font-weight:500;}",
      ".adm-form-control{width:100%;height:34px;border:1px solid #d8dde8;border-radius:4px;padding:6px 10px;font-size:13px;background:#fff;color:#2f3542;outline:none;}",
      ".adm-form-control:focus{border-color:#5c80ff;box-shadow:0 0 0 2px rgba(92,128,255,.12);}",
      ".adm-textarea{min-height:94px;resize:vertical;line-height:1.6;}",
      ".adm-field{margin-bottom:14px;}",
      ".adm-switch-line{height:34px;display:flex;align-items:center;gap:8px;white-space:nowrap;}",
      ".adm-switch-line .adm-status{min-height:0;white-space:nowrap;line-height:20px;}",
      ".adm-modal-switches{display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding:2px 0 8px;}",
      ".adm-modal-switch{display:inline-flex;align-items:center;gap:8px;min-width:150px;white-space:nowrap;}",
      ".adm-modal-switch-label{font-size:13px;color:#4a5568;font-weight:500;white-space:nowrap;}",
      ".adm-modal-switch .adm-status{min-height:0;line-height:20px;white-space:nowrap;}",
      ".adm-switch{position:relative;width:38px;height:20px;display:inline-block;}",
      ".adm-switch input{display:none;}",
      ".adm-switch span{position:absolute;inset:0;background:#c6ccd8;border-radius:20px;transition:.18s;}",
      ".adm-switch span:before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#fff;border-radius:50%;transition:.18s;box-shadow:0 1px 3px rgba(0,0,0,.25);}",
      ".adm-switch input:checked+span{background:#5c80ff;}",
      ".adm-switch input:checked+span:before{transform:translateX(18px);}",
      ".adm-status{font-size:13px;color:#6b7280;min-height:20px;}",
      ".adm-status.success{color:#1c7c4c;}.adm-status.error{color:#b42318;}",
      ".adm-preview{font-size:13px;line-height:1.7;color:#4b5563;background:#f7f8fb;border:1px solid #edf0f5;border-radius:6px;padding:10px 12px;word-break:break-all;min-height:38px;}",
      ".adm-table-wrap{overflow:auto;border:1px solid #eef0f4;border-radius:6px;}",
      ".adm-table{width:100%;border-collapse:collapse;background:#fff;min-width:980px;}",
      ".adm-table th{height:42px;background:#f8f9fc;color:#566070;font-size:12px;font-weight:600;text-align:left;padding:0 12px;border-bottom:1px solid #eef0f4;white-space:nowrap;}",
      ".adm-table td{font-size:13px;color:#2f3542;padding:12px;border-bottom:1px solid #f0f2f5;vertical-align:top;}",
      ".adm-table tr:last-child td{border-bottom:0;}",
      ".adm-badge{display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:4px;font-size:12px;background:#eef2ff;color:#3150b7;white-space:nowrap;}",
      ".adm-badge.gray{background:#f1f3f5;color:#596273;}.adm-badge.green{background:#e9f8ef;color:#137447;}.adm-badge.red{background:#fff1f0;color:#b42318;}",
      ".adm-tags{display:flex;gap:6px;flex-wrap:wrap;max-width:280px;}",
      ".adm-btn{height:32px;border:1px solid #d8dde8;border-radius:4px;background:#fff;color:#354052;padding:0 10px;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;justify-content:center;}",
      ".adm-btn:hover{border-color:#5c80ff;color:#3150b7;}",
      ".adm-btn-primary{background:#5c80ff;border-color:#5c80ff;color:#fff;}",
      ".adm-btn-primary:hover{background:#466ce6;color:#fff;}",
      ".adm-btn-danger{border-color:#ffd2d0;color:#b42318;}",
      ".adm-btn-text{border-color:transparent;background:transparent;padding:0 6px;}",
      ".adm-btn[disabled]{opacity:.55;cursor:not-allowed;}",
      ".adm-empty{padding:38px 12px;text-align:center;color:#8a93a3;font-size:13px;}",
      ".adm-modal-mask{position:fixed;inset:0;background:rgba(17,24,39,.42);z-index:9998;display:flex;align-items:center;justify-content:center;padding:24px;}",
      ".adm-modal{width:min(900px,100%);max-height:calc(100vh - 48px);overflow:auto;background:#fff;border-radius:8px;box-shadow:0 24px 60px rgba(15,23,42,.22);}",
      ".adm-modal-head{height:56px;padding:0 20px;border-bottom:1px solid #eef0f4;display:flex;align-items:center;justify-content:space-between;}",
      ".adm-modal-title{font-size:15px;font-weight:600;color:#2f3542;}",
      ".adm-modal-body{padding:20px;}",
      ".adm-modal-foot{padding:14px 20px;border-top:1px solid #eef0f4;display:flex;justify-content:flex-end;gap:8px;}",
      ".adm-checks{display:flex;gap:8px;flex-wrap:wrap;}",
      ".adm-check{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 10px;border:1px solid #d8dde8;border-radius:4px;font-size:13px;color:#354052;}",
      ".adm-check input{margin:0;}",
      ".adm-node-list{max-height:150px;overflow:auto;padding:10px;border:1px solid #e3e7ef;border-radius:6px;background:#fbfcfe;}",
      ".adm-node-list .adm-check{margin-bottom:8px;background:#fff;}",
      "@media(max-width:900px){.adm-page{padding:0 12px 18px}.adm-grid{display:block}.adm-span-4,.adm-span-6,.adm-span-8,.adm-span-12{grid-column:auto}.adm-block-body{padding:14px}.adm-toolbar{align-items:flex-start}.adm-actions{width:100%}.adm-form-control.adm-filter{width:100%!important}}"
    ].join("\n");
    document.head.appendChild(style);
  }

  function getMainRoot() {
    return document.querySelector("#main-container > .p-0.p-lg-4")
      || document.querySelector("#main-container > div")
      || document.querySelector("#main-container");
  }

  function setHeaderTitle() {
    var title = document.querySelector(".v2board-container-title");
    if (title) title.textContent = "中转域名分发";
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

  function setStatus(message, type) {
    var el = document.getElementById("adm-status");
    if (!el) return;
    el.className = "adm-status" + (type ? " " + type : "");
    el.textContent = message || "";
  }

  function icon(name) {
    return '<i class="si si-' + name + '"></i>';
  }

  function switchHtml(id, checked) {
    return '<label class="adm-switch"><input type="checkbox" id="' + id + '"' + (checked ? " checked" : "") + '><span></span></label>';
  }

  function field(label, id, value, extraClass, placeholder) {
    return '<div class="adm-field"><label class="adm-form-label" for="' + id + '">' + escapeHtml(label) + '</label>' +
      '<input class="adm-form-control ' + (extraClass || "") + '" id="' + id + '" value="' + escapeHtml(value || "") + '" placeholder="' + escapeHtml(placeholder || "") + '"></div>';
  }

  function textarea(label, id, value, placeholder) {
    return '<div class="adm-field"><label class="adm-form-label" for="' + id + '">' + escapeHtml(label) + '</label>' +
      '<textarea class="adm-form-control adm-textarea" id="' + id + '" placeholder="' + escapeHtml(placeholder || "") + '">' + escapeHtml(value || "") + '</textarea></div>';
  }

  function switchField(label, id, checked) {
    return '<div class="adm-field"><label class="adm-form-label">' + escapeHtml(label) + '</label>' +
      '<div class="adm-switch-line">' + switchHtml(id, checked) + '<span class="adm-status">' + (checked ? "开启" : "关闭") + '</span></div></div>';
  }

  function switchInline(label, id, checked) {
    return '<div class="adm-modal-switch"><span class="adm-modal-switch-label">' + escapeHtml(label) + '</span>' +
      switchHtml(id, checked) + '<span class="adm-status">' + (checked ? "开启" : "关闭") + '</span></div>';
  }

  function tag(text, cls) {
    return '<span class="adm-badge ' + (cls || "") + '">' + escapeHtml(text) + '</span>';
  }

  function selectedText(ids, list) {
    ids = Array.isArray(ids) ? ids : [];
    if (!ids.length) return "全部";
    var names = ids.map(function (id) {
      var found = (list || []).find(function (item) { return Number(item.id) === Number(id); });
      return found ? found.name : id;
    });
    return names.join(" / ");
  }

  function scopeTags(rule) {
    var options = state.options || {};
    var html = [];
    html.push(tag("组 " + selectedText(rule.user_group_ids, options.user_groups), "gray"));
    html.push(tag("套餐 " + selectedText(rule.plan_ids, options.plans), "gray"));
    html.push(tag("类型 " + (rule.server_types.length ? rule.server_types.join(" / ") : "全部"), "gray"));
    if (rule.server_ids.length) html.push(tag("节点 " + selectedNodeText(rule.server_ids, rule.server_types), "gray"));
    if (rule.protocols.length) html.push(tag("协议 " + rule.protocols.join(" / "), "gray"));
    return '<div class="adm-tags">' + html.join("") + '</div>';
  }

  function behaviorTags(rule) {
    var html = [];
    if (rule.replace_node_host) html.push(tag("节点入口", "green"));
    if (rule.replace_subscribe_host) html.push(tag("订阅入口", "green"));
    if (!html.length) html.push(tag("仅匹配", "gray"));
    return '<div class="adm-tags">' + html.join("") + '</div>';
  }

  function filteredRules() {
    var q = String(state.filter || "").trim().toLowerCase();
    var groupId = String(state.groupFilter || "");
    return (state.rules || []).filter(function (rule) {
      if (q && [rule.name, rule.domain, rule.remark].join(" ").toLowerCase().indexOf(q) === -1) return false;
      if (groupId && rule.user_group_ids.length && rule.user_group_ids.map(String).indexOf(groupId) === -1) return false;
      if (groupId && !rule.user_group_ids.length) return true;
      return true;
    });
  }

  function renderRuleRows() {
    var rules = filteredRules();
    if (!rules.length) {
      return '<tr><td colspan="8"><div class="adm-empty">暂无规则</div></td></tr>';
    }
    return rules.map(function (rule, index) {
      return '<tr data-rule-id="' + escapeHtml(rule.id) + '">' +
        '<td>' + escapeHtml(rule.sort || index + 1) + '</td>' +
        '<td><strong>' + escapeHtml(rule.name) + '</strong>' + (rule.remark ? '<div class="adm-status">' + escapeHtml(rule.remark) + '</div>' : '') + '</td>' +
        '<td>' + (rule.enable ? tag("启用", "green") : tag("停用", "red")) + '</td>' +
        '<td><code>' + escapeHtml(rule.domain) + '</code></td>' +
        '<td>' + scopeTags(rule) + '</td>' +
        '<td>' + behaviorTags(rule) + '</td>' +
        '<td>' +
          '<button class="adm-btn adm-btn-text adm-rule-up" title="上移">' + icon("arrow-up") + '</button>' +
          '<button class="adm-btn adm-btn-text adm-rule-down" title="下移">' + icon("arrow-down") + '</button>' +
        '</td>' +
        '<td>' +
          '<button class="adm-btn adm-rule-edit">' + icon("pencil") + '编辑</button> ' +
          '<button class="adm-btn adm-btn-danger adm-rule-drop">' + icon("trash") + '删除</button>' +
        '</td>' +
      '</tr>';
    }).join("");
  }

  function renderGroupOptions() {
    return '<option value="">全部用户组</option>' + ((state.options.user_groups || []).map(function (item) {
      return '<option value="' + escapeHtml(item.id) + '"' + (String(state.groupFilter) === String(item.id) ? " selected" : "") + '>' + escapeHtml(item.name) + '</option>';
    }).join(""));
  }

  function subscribePreview(config) {
    var path = config.app_domain_subscribe_path || "/api/v1/client/custom_app/subscribe";
    if (path.charAt(0) !== "/") path = "/" + path;
    var host = normalizeHost(config.app_domain_public_host);
    return host ? "https://" + host + path + "?token=YOUR_TOKEN" : path + "?token=YOUR_TOKEN";
  }

  function apiPreview(config) {
    if (!config.app_api_domain_hosts.length) return "未配置";
    return config.app_api_domain_hosts.map(function (host) {
      return "https://" + normalizeHost(host) + "/api/v1/client/app";
    }).join("    ");
  }

  function renderConfig(config) {
    return [
      '<div class="adm-grid">',
      '  <div class="adm-span-4">' + switchField("全局节点入口", "adm_domain_enable", config.app_domain_enable) + '</div>',
      '  <div class="adm-span-4">' + switchField("规则匹配", "adm_rule_enable", config.app_domain_rule_enable) + '</div>',
      '  <div class="adm-span-4">' + switchField("API 多域名", "adm_api_enable", config.app_api_domain_enable) + '</div>',
      '  <div class="adm-span-4">' + field("入口域名", "adm_replace_host", config.app_domain_replace_host, "", "edge.example.com") + '</div>',
      '  <div class="adm-span-4">' + field("App 订阅域名", "adm_public_host", config.app_domain_public_host, "", "app.example.com") + '</div>',
      '  <div class="adm-span-4">' + field("订阅路径", "adm_subscribe_path", config.app_domain_subscribe_path, "", "/api/v1/client/custom_app/subscribe") + '</div>',
      '  <div class="adm-span-6">' + textarea("API 域名池", "adm_api_hosts", config.app_api_domain_hosts.join("\\n"), "api1.example.com\\napi2.example.com") + '</div>',
      '  <div class="adm-span-6">',
           switchField("加密下发", "adm_encrypt_enable", config.app_api_domain_encrypt_enable),
           field("加密密钥", "adm_encrypt_key", config.app_api_domain_encrypt_key, "", ""),
      '  </div>',
      '  <div class="adm-span-6"><label class="adm-form-label">订阅预览</label><div class="adm-preview" id="adm-preview-subscribe">' + escapeHtml(subscribePreview(config)) + '</div></div>',
      '  <div class="adm-span-6"><label class="adm-form-label">API 预览</label><div class="adm-preview" id="adm-preview-api">' + escapeHtml(apiPreview(config)) + '</div></div>',
      '</div>'
    ].join("\n");
  }

  function renderPage() {
    mountStyle();
    setHeaderTitle();
    var root = getMainRoot();
    if (!root) return false;
    var config = state.config || normalizeConfig({});
    root.innerHTML = [
      '<div class="adm-page" id="adm-panel">',
      '  <div class="adm-toolbar">',
      '    <div class="adm-status" id="adm-status"></div>',
      '    <div class="adm-actions">',
      '      <button class="adm-btn" id="adm-refresh-btn">' + icon("refresh") + '刷新</button>',
      '      <button class="adm-btn adm-btn-primary" id="adm-save-config-btn">' + icon("check") + '保存配置</button>',
      '    </div>',
      '  </div>',
      '  <div class="adm-block">',
      '    <div class="adm-block-header"><h3 class="adm-block-title">全局配置</h3></div>',
      '    <div class="adm-block-body">' + renderConfig(config) + '</div>',
      '  </div>',
      '  <div class="adm-block">',
      '    <div class="adm-block-header">',
      '      <h3 class="adm-block-title">分发规则</h3>',
      '      <div class="adm-actions">',
      '        <input class="adm-form-control adm-filter" style="width:220px" id="adm-rule-filter" value="' + escapeHtml(state.filter) + '" placeholder="搜索规则">',
      '        <select class="adm-form-control adm-filter" style="width:160px" id="adm-group-filter">' + renderGroupOptions() + '</select>',
      '        <button class="adm-btn adm-btn-primary" id="adm-rule-add">' + icon("plus") + '添加规则</button>',
      '      </div>',
      '    </div>',
      '    <div class="adm-block-body">',
      '      <div class="adm-table-wrap"><table class="adm-table">',
      '        <thead><tr><th>排序</th><th>规则</th><th>状态</th><th>域名</th><th>范围</th><th>覆盖</th><th>排序</th><th>操作</th></tr></thead>',
      '        <tbody>' + renderRuleRows() + '</tbody>',
      '      </table></div>',
      '    </div>',
      '  </div>',
      '</div>',
      renderModal()
    ].join("\n");
    bindEvents();
    updatePreview();
    return true;
  }

  function renderCheckboxes(name, values, selected) {
    selected = Array.isArray(selected) ? selected.map(String) : [];
    return '<div class="adm-checks">' + values.map(function (item) {
      var value = item.value == null ? item.id : item.value;
      var label = item.label == null ? item.name : item.label;
      return '<label class="adm-check"><input type="checkbox" name="' + name + '" value="' + escapeHtml(value) + '"' + (selected.indexOf(String(value)) !== -1 ? " checked" : "") + '> ' + escapeHtml(label) + '</label>';
    }).join("") + '</div>';
  }

  function selectedNodeText(ids, types) {
    ids = Array.isArray(ids) ? ids : [];
    types = Array.isArray(types) ? types : [];
    if (!ids.length) return "全部";
    var nodes = state.options.nodes || [];
    var names = [];
    ids.forEach(function (id) {
      var matches = nodes.filter(function (node) {
        return Number(node.id) === Number(id) && (!types.length || types.indexOf(node.type) !== -1);
      });
      if (!matches.length) {
        names.push("#" + id);
      } else {
        matches.forEach(function (node) {
          names.push(node.type + " #" + node.id + " " + node.name);
        });
      }
    });
    return names.join(" / ");
  }

  function renderNodeChecks(selectedIds, selectedTypes) {
    var nodes = state.options.nodes || [];
    if (!nodes.length) {
      return '<div class="adm-empty" style="padding:18px 0">暂无节点可选</div>';
    }
    selectedIds = Array.isArray(selectedIds) ? selectedIds.map(String) : [];
    selectedTypes = Array.isArray(selectedTypes) ? selectedTypes : [];
    return '<div class="adm-node-list">' + nodes.map(function (node) {
      var checked = selectedIds.indexOf(String(node.id)) !== -1 && (!selectedTypes.length || selectedTypes.indexOf(node.type) !== -1);
      return '<label class="adm-check" title="' + escapeHtml(node.host || "") + '">' +
        '<input type="checkbox" name="adm_rule_nodes" value="' + escapeHtml(node.id) + '" data-type="' + escapeHtml(node.type) + '"' + (checked ? " checked" : "") + '> ' +
        escapeHtml(node.type + " #" + node.id + " " + node.name) +
      '</label>';
    }).join("") + '</div>';
  }

  function renderModal() {
    if (!state.modalRule) return "";
    var rule = state.modalRule;
    var options = state.options || {};
    var serverTypes = (options.server_types || []).map(function (value) { return { value: value, label: value }; });
    var protocols = (options.protocols || []).map(function (value) { return { value: value, label: value }; });
    return [
      '<div class="adm-modal-mask" id="adm-rule-modal">',
      '  <div class="adm-modal">',
      '    <div class="adm-modal-head"><div class="adm-modal-title">' + (rule.id ? "编辑规则" : "添加规则") + '</div><button class="adm-btn adm-btn-text" id="adm-modal-close">' + icon("close") + '</button></div>',
      '    <div class="adm-modal-body">',
      '      <div class="adm-grid">',
      '        <div class="adm-span-6">' + field("规则名称", "adm_rule_name", rule.name, "", "") + '</div>',
      '        <div class="adm-span-6">' + field("入口域名", "adm_rule_domain", rule.domain, "", "edge.example.com") + '</div>',
      '        <div class="adm-span-3">' + field("排序", "adm_rule_sort", rule.sort, "", "") + '</div>',
      '        <div class="adm-span-9">' + field("备注", "adm_rule_remark", rule.remark, "", "") + '</div>',
      '        <div class="adm-span-12"><div class="adm-modal-switches">' + switchInline("启用", "adm_rule_enable_input", rule.enable) + switchInline("覆盖节点入口", "adm_rule_replace_node", rule.replace_node_host) + switchInline("覆盖订阅入口", "adm_rule_replace_subscribe", rule.replace_subscribe_host) + '</div></div>',
      '        <div class="adm-span-12"><label class="adm-form-label">用户组</label>' + renderCheckboxes("adm_rule_groups", options.user_groups || [], rule.user_group_ids) + '</div>',
      '        <div class="adm-span-12"><label class="adm-form-label">套餐</label>' + renderCheckboxes("adm_rule_plans", options.plans || [], rule.plan_ids) + '</div>',
      '        <div class="adm-span-12"><label class="adm-form-label">节点类型</label>' + renderCheckboxes("adm_rule_types", serverTypes, rule.server_types) + '</div>',
      '        <div class="adm-span-12"><label class="adm-form-label">协议</label>' + renderCheckboxes("adm_rule_protocols", protocols, rule.protocols) + '</div>',
      '        <div class="adm-span-12"><label class="adm-form-label">节点</label>' + renderNodeChecks(rule.server_ids, rule.server_types) + '</div>',
      '      </div>',
      '    </div>',
      '    <div class="adm-modal-foot">',
      '      <button class="adm-btn" id="adm-modal-cancel">取消</button>',
      '      <button class="adm-btn adm-btn-primary" id="adm-rule-save">' + icon("check") + '保存</button>',
      '    </div>',
      '  </div>',
      '</div>'
    ].join("\n");
  }

  function bindEvents() {
    var refresh = document.getElementById("adm-refresh-btn");
    if (refresh) refresh.addEventListener("click", function () { loadAll(true); });
    var saveConfig = document.getElementById("adm-save-config-btn");
    if (saveConfig) saveConfig.addEventListener("click", saveConfigForm);
    ["adm_public_host", "adm_subscribe_path", "adm_api_hosts"].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener("input", updatePreview);
    });
    var filter = document.getElementById("adm-rule-filter");
    if (filter) filter.addEventListener("input", function () { state.filter = filter.value; renderPage(); });
    var groupFilter = document.getElementById("adm-group-filter");
    if (groupFilter) groupFilter.addEventListener("change", function () { state.groupFilter = groupFilter.value; renderPage(); });
    var add = document.getElementById("adm-rule-add");
    if (add) add.addEventListener("click", function () { state.modalRule = emptyRule(); renderPage(); });
    Array.prototype.slice.call(document.querySelectorAll("[data-rule-id]")).forEach(function (row) {
      var id = Number(row.getAttribute("data-rule-id"));
      var rule = state.rules.find(function (item) { return Number(item.id) === id; });
      var edit = row.querySelector(".adm-rule-edit");
      var drop = row.querySelector(".adm-rule-drop");
      var up = row.querySelector(".adm-rule-up");
      var down = row.querySelector(".adm-rule-down");
      if (edit) edit.addEventListener("click", function () { state.modalRule = normalizeRule(rule); renderPage(); });
      if (drop) drop.addEventListener("click", function () { dropRule(rule); });
      if (up) up.addEventListener("click", function () { moveRule(rule, -1); });
      if (down) down.addEventListener("click", function () { moveRule(rule, 1); });
    });
    var close = document.getElementById("adm-modal-close");
    var cancel = document.getElementById("adm-modal-cancel");
    var save = document.getElementById("adm-rule-save");
    if (close) close.addEventListener("click", closeModal);
    if (cancel) cancel.addEventListener("click", closeModal);
    if (save) save.addEventListener("click", saveRuleForm);
  }

  function updatePreview() {
    var config = collectConfig(false);
    var subscribe = document.getElementById("adm-preview-subscribe");
    var api = document.getElementById("adm-preview-api");
    if (subscribe) subscribe.textContent = subscribePreview(config);
    if (api) api.textContent = apiPreview(config);
  }

  function checked(id) {
    var el = document.getElementById(id);
    return el && el.checked ? 1 : 0;
  }

  function collectConfig(readDom) {
    var config = state.config || normalizeConfig({});
    if (readDom === false && !document.getElementById("adm_domain_enable")) return config;
    return {
      app_domain_enable: checked("adm_domain_enable"),
      app_domain_rule_enable: checked("adm_rule_enable"),
      app_domain_public_host: (document.getElementById("adm_public_host") || {}).value || config.app_domain_public_host,
      app_domain_subscribe_path: (document.getElementById("adm_subscribe_path") || {}).value || config.app_domain_subscribe_path,
      app_domain_replace_host: (document.getElementById("adm_replace_host") || {}).value || config.app_domain_replace_host,
      app_api_domain_enable: checked("adm_api_enable"),
      app_api_domain_hosts: ((document.getElementById("adm_api_hosts") || {}).value || "").split(/\n+/).map(normalizeHost).filter(Boolean),
      app_api_domain_encrypt_enable: checked("adm_encrypt_enable"),
      app_api_domain_encrypt_key: (document.getElementById("adm_encrypt_key") || {}).value || config.app_api_domain_encrypt_key
    };
  }

  function selectedValues(name) {
    return Array.prototype.slice.call(document.querySelectorAll('input[name="' + name + '"]:checked')).map(function (input) {
      var raw = input.value;
      return /^\d+$/.test(raw) ? Number(raw) : raw;
    });
  }

  function selectedNodeValues() {
    return Array.prototype.slice.call(document.querySelectorAll('input[name="adm_rule_nodes"]:checked')).map(function (input) {
      return {
        id: Number(input.value),
        type: input.getAttribute("data-type") || ""
      };
    }).filter(function (item) {
      return item.id > 0;
    });
  }

  function parseIds(value) {
    return String(value || "").split(/[,，\s]+/).map(function (item) {
      return Number(item);
    }).filter(function (id) { return id > 0; });
  }

  function collectRule() {
    var current = state.modalRule || emptyRule();
    var selectedNodes = selectedNodeValues();
    var selectedTypes = selectedValues("adm_rule_types");
    selectedNodes.forEach(function (node) {
      if (node.type && selectedTypes.indexOf(node.type) === -1) selectedTypes.push(node.type);
    });
    return {
      id: current.id || undefined,
      name: (document.getElementById("adm_rule_name") || {}).value || "",
      enable: checked("adm_rule_enable_input"),
      sort: Number((document.getElementById("adm_rule_sort") || {}).value || 0),
      domain: normalizeHost((document.getElementById("adm_rule_domain") || {}).value || ""),
      user_group_ids: selectedValues("adm_rule_groups").map(Number),
      plan_ids: selectedValues("adm_rule_plans").map(Number),
      server_types: selectedTypes,
      server_ids: selectedNodes.map(function (node) { return node.id; }),
      protocols: selectedValues("adm_rule_protocols"),
      replace_node_host: checked("adm_rule_replace_node"),
      replace_subscribe_host: checked("adm_rule_replace_subscribe"),
      remark: (document.getElementById("adm_rule_remark") || {}).value || ""
    };
  }

  function saveConfigForm() {
    var button = document.getElementById("adm-save-config-btn");
    if (button) button.disabled = true;
    setStatus("保存中...");
    request("POST", "/server/app-domain/config", collectConfig()).then(function () {
      setStatus("保存成功", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "保存失败", "error");
    }).finally(function () {
      if (button) button.disabled = false;
    });
  }

  function saveRuleForm() {
    var payload = collectRule();
    if (!payload.name || !payload.domain) {
      setStatus("规则名称和入口域名不能为空", "error");
      return;
    }
    request("POST", "/server/app-domain/rule/save", payload).then(function () {
      state.modalRule = null;
      setStatus("规则已保存", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "规则保存失败", "error");
    });
  }

  function dropRule(rule) {
    if (!rule || !window.confirm("删除规则：" + rule.name + "？")) return;
    request("POST", "/server/app-domain/rule/drop", { id: rule.id }).then(function () {
      setStatus("规则已删除", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "删除失败", "error");
    });
  }

  function moveRule(rule, direction) {
    var rules = (state.rules || []).slice().sort(function (a, b) {
      return Number(a.sort || 0) - Number(b.sort || 0) || Number(a.id || 0) - Number(b.id || 0);
    });
    var index = rules.findIndex(function (item) { return Number(item.id) === Number(rule.id); });
    var next = index + direction;
    if (index < 0 || next < 0 || next >= rules.length) return;
    var tmp = rules[index];
    rules[index] = rules[next];
    rules[next] = tmp;
    request("POST", "/server/app-domain/rule/sort", {
      rule_ids: rules.map(function (item) { return item.id; })
    }).then(function () {
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "排序失败", "error");
    });
  }

  function closeModal() {
    state.modalRule = null;
    renderPage();
  }

  function loadAll(force) {
    if (loadingPromise && !force) return loadingPromise;
    state.loading = true;
    loadingPromise = Promise.all([
      request("GET", "/server/app-domain/config"),
      request("GET", "/server/app-domain/rules"),
      request("GET", "/server/app-domain/options")
    ]).then(function (responses) {
      state.config = normalizeConfig(responses[0].data || {});
      state.rules = (responses[1].data || []).map(normalizeRule);
      state.options = responses[2].data || {};
      renderPage();
      setStatus("");
    }).catch(function (error) {
      renderPage();
      setStatus(error.message || "加载失败", "error");
    }).finally(function () {
      state.loading = false;
      loadingPromise = null;
    });
    return loadingPromise;
  }

  function maybeRender() {
    if (!isTargetRoute()) return;
    if (routePath() === "/server/app-domain-plugin") {
      window.location.hash = "#/server/app-domain";
      return;
    }
    if (!document.getElementById("adm-panel")) renderPage();
    setHeaderTitle();
    if (!state.config && !loadingPromise) loadAll();
  }

  window.addEventListener("hashchange", function () { setTimeout(maybeRender, 50); });
  window.addEventListener("load", function () { setTimeout(maybeRender, 50); });
  setInterval(maybeRender, 800);
})();
