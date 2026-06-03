(function () {
  var ROUTES = ["/server/app-domain", "/server/app-domain-plugin"];
  var state = {
    config: null,
    rules: [],
    options: {},
    filter: "",
    groupFilter: "",
    activeTab: "basic",
    expandedRules: {},
    modalRule: null,
    loading: false
  };
  var styleMounted = false;
  var loadingPromise = null;
  var configSaveTimer = null;

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

  function defaultSchemeForEndpoint(value) {
    var host = normalizeHost(value).replace(/\/.*$/, "");
    if (/^(\d{1,3}\.){3}\d{1,3}(:\d+)?$/.test(host)) return "http";
    if (/:(?!443$)\d+$/.test(host)) return "http";
    return "https";
  }

  function normalizeEndpoint(value) {
    var raw = String(value || "").trim().replace(/\/+$/, "");
    if (!raw) return "";
    if (!/^https?:\/\//i.test(raw)) raw = defaultSchemeForEndpoint(raw) + "://" + raw;
    try {
      var url = new URL(raw);
      if (url.protocol !== "http:" && url.protocol !== "https:") return "";
      return url.protocol + "//" + url.host;
    } catch (e) {
      return "";
    }
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
      app_api_domain_encrypt_key: data.app_api_domain_encrypt_key || ""
    };
  }

  function normalizeBinding(binding) {
    binding = binding || {};
    return {
      id: binding.id || "",
      group_id: binding.group_id || "",
      enable: Number(binding.enable == null ? 1 : binding.enable),
      sort: Number(binding.sort || 0),
      server_type: binding.server_type || "",
      server_id: binding.server_id || "",
      port: binding.port || "",
      remark: binding.remark || ""
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
      remark: rule.remark || "",
      bindings: Array.isArray(rule.bindings) ? rule.bindings.map(normalizeBinding) : []
    };
  }

  function emptyRule() {
    return normalizeRule({
      enable: 1,
      sort: (state.rules || []).length + 1
    });
  }

  function mountStyle() {
    if (styleMounted) return;
    styleMounted = true;
    var style = document.createElement("style");
    style.textContent = [
      ".adm-page{padding:0 24px 24px;max-width:1320px;}",
      ".adm-native-block{background:#fff;}",
      ".adm-page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 0 14px;flex-wrap:wrap;}",
      ".adm-tabs{display:flex;align-items:center;gap:30px;border-bottom:1px solid #e8e8e8;margin-bottom:0;padding:0 20px;}",
      ".adm-tab{height:46px;border:0;background:transparent;color:#495057;font-size:14px;padding:0;cursor:pointer;position:relative;}",
      ".adm-tab.active{color:#1890ff;font-weight:500;}",
      ".adm-tab.active:after{content:'';position:absolute;left:0;right:0;bottom:-1px;height:2px;background:#1890ff;}",
      ".adm-tab-panel{display:none;}",
      ".adm-tab-panel.active{display:block;}",
      ".adm-setting-row{display:flex;align-items:center;padding:20px;border-bottom:1px solid #eee;gap:20px;}",
      ".adm-setting-main{flex:1;min-width:0;}",
      ".adm-setting-title{font-weight:600;color:#2f3542;margin-bottom:5px;}",
      ".adm-setting-desc{font-size:12px;color:#666;line-height:1.6;}",
      ".adm-setting-control{width:50%;min-width:280px;text-align:right;}",
      ".adm-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}",
      ".adm-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;}",
      ".adm-span-2{grid-column:span 2}.adm-span-3{grid-column:span 3}.adm-span-4{grid-column:span 4}.adm-span-6{grid-column:span 6}.adm-span-8{grid-column:span 8}.adm-span-9{grid-column:span 9}.adm-span-12{grid-column:span 12}",
      ".adm-block{background:#fff;border:0;margin-bottom:0;}",
      ".adm-block-header{padding:16px 20px;border-bottom:1px solid #eef0f4;display:flex;align-items:center;justify-content:space-between;gap:12px;}",
      ".adm-block-title{font-size:14px;font-weight:600;color:#2f3542;margin:0;}",
      ".adm-block-body{padding:0;}",
      ".adm-form-label{display:block;font-size:13px;color:#4a5568;margin-bottom:6px;font-weight:500;}",
      ".adm-form-control{width:100%;height:34px;border:1px solid #d8dde8;border-radius:4px;padding:6px 10px;font-size:13px;background:#fff;color:#2f3542;outline:none;}",
      ".adm-form-control:focus{border-color:#5c80ff;box-shadow:0 0 0 2px rgba(92,128,255,.12);}",
      ".adm-field{margin-bottom:14px;}",
      ".adm-switch-line{height:34px;display:flex;align-items:center;gap:8px;white-space:nowrap;}",
      ".adm-setting-control .adm-switch-line{justify-content:flex-end;}",
      ".adm-modal-switches{display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding:2px 0 8px;}",
      ".adm-modal-switch{display:inline-flex;align-items:center;gap:8px;min-width:120px;white-space:nowrap;}",
      ".adm-modal-switch-label{font-size:13px;color:#4a5568;font-weight:500;white-space:nowrap;}",
      ".adm-switch{position:relative;width:38px;height:20px;display:inline-block;}",
      ".adm-switch input{display:none;}",
      ".adm-switch span{position:absolute;inset:0;background:#c6ccd8;border-radius:20px;transition:.18s;}",
      ".adm-switch span:before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#fff;border-radius:50%;transition:.18s;box-shadow:0 1px 3px rgba(0,0,0,.25);}",
      ".adm-switch input:checked+span{background:#5c80ff;}",
      ".adm-switch input:checked+span:before{transform:translateX(18px);}",
      ".adm-status{font-size:13px;color:#6b7280;min-height:20px;}",
      ".adm-status.success{color:#1c7c4c}.adm-status.error{color:#b42318}",
      ".adm-preview{font-size:13px;line-height:1.7;color:#4b5563;background:#f7f8fb;border:1px solid #edf0f5;border-radius:4px;padding:10px 12px;word-break:break-all;min-height:38px;text-align:left;}",
      ".adm-table-tools{padding:16px 20px;border-bottom:1px solid #eef0f4;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}",
      ".adm-table-wrap{overflow:auto;border:0;border-radius:0;}",
      ".adm-table{width:100%;border-collapse:collapse;background:#fff;min-width:980px;}",
      ".adm-table th{height:42px;background:#f8f9fc;color:#566070;font-size:12px;font-weight:600;text-align:left;padding:0 12px;border-bottom:1px solid #eef0f4;white-space:nowrap;}",
      ".adm-table td{font-size:13px;color:#2f3542;padding:12px;border-bottom:1px solid #f0f2f5;vertical-align:middle;}",
      ".adm-table tr:last-child td{border-bottom:0;}",
      ".adm-rule-name{font-weight:600;color:#2f3542;line-height:1.5;}",
      ".adm-rule-remark{font-size:13px;color:#7b8494;line-height:1.5;margin-top:2px;}",
      ".adm-rule-domain{display:inline-block;max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#d9488a;font-size:12px;vertical-align:middle;}",
      ".adm-scope-cell{display:flex;flex-direction:column;gap:8px;align-items:flex-start;justify-content:center;min-height:46px;}",
      ".adm-scope-line{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}",
      ".adm-node-preview{display:flex;gap:6px;flex-wrap:wrap;align-items:center;max-width:720px;}",
      ".adm-node-chip{display:inline-flex;align-items:center;max-width:210px;height:24px;padding:0 8px;border-radius:4px;background:#f4f6f9;color:#626c7c;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}",
      ".adm-node-toggle{height:24px;border:0;background:transparent;color:#1890ff;padding:0 2px;font-size:12px;cursor:pointer;}",
      ".adm-node-toggle:hover{color:#096dd9;text-decoration:underline;}",
      ".adm-rule-detail-row td{background:#fbfcfe;padding:0 20px 18px 132px!important;vertical-align:top!important;}",
      ".adm-node-detail{width:100%;border:1px solid #edf0f5;background:#fff;border-radius:4px;}",
      ".adm-node-detail-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid #edf0f5;color:#606a7a;font-size:12px;}",
      ".adm-node-detail-body{display:flex;flex-direction:column;}",
      ".adm-node-map{display:grid;grid-template-columns:minmax(160px,1fr) minmax(220px,1.2fr) 28px minmax(220px,1.2fr);gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid #f0f2f5;font-size:12px;color:#5f6978;}",
      ".adm-node-map:last-child{border-bottom:0;}",
      ".adm-node-map-name{font-weight:500;color:#3f4652;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}",
      ".adm-node-map-code{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#606a7a;background:#fff;border:1px solid #eef1f5;border-radius:4px;padding:4px 6px;}",
      ".adm-node-map-arrow{text-align:center;color:#a0a8b5;font-size:12px;}",
      ".adm-node-detail-edit{height:26px;padding:0 8px;font-size:12px;}",
      ".adm-badge{display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:4px;font-size:12px;background:#eef2ff;color:#3150b7;white-space:nowrap;}",
      ".adm-badge.gray{background:#f1f3f5;color:#596273}.adm-badge.green{background:#e9f8ef;color:#137447}.adm-badge.red{background:#fff1f0;color:#b42318}",
      ".adm-tags{display:flex;gap:6px;flex-wrap:wrap;max-width:360px;}",
      ".adm-btn{height:32px;border:1px solid #d8dde8;border-radius:4px;background:#fff;color:#354052;padding:0 10px;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;justify-content:center;}",
      ".adm-btn:hover{border-color:#5c80ff;color:#3150b7}",
      ".adm-btn-primary{background:#5c80ff;border-color:#5c80ff;color:#fff}",
      ".adm-btn-primary:hover{background:#466ce6;color:#fff}",
      ".adm-btn-danger{border-color:#ffd2d0;color:#b42318}",
      ".adm-btn-text{border-color:transparent;background:transparent;padding:0 6px}",
      ".adm-btn[disabled]{opacity:.55;cursor:not-allowed}",
      ".adm-empty{padding:38px 12px;text-align:center;color:#8a93a3;font-size:13px;}",
      ".adm-modal-mask{position:fixed;inset:0;background:rgba(17,24,39,.42);z-index:9998;display:flex;align-items:center;justify-content:center;padding:24px;}",
      ".adm-modal{width:min(980px,100%);max-height:calc(100vh - 48px);overflow:auto;background:#fff;border-radius:8px;box-shadow:0 24px 60px rgba(15,23,42,.22);}",
      ".adm-modal-head{height:56px;padding:0 20px;border-bottom:1px solid #eef0f4;display:flex;align-items:center;justify-content:space-between;}",
      ".adm-modal-title{font-size:15px;font-weight:600;color:#2f3542;}",
      ".adm-modal-body{padding:20px;}",
      ".adm-modal-foot{padding:14px 20px;border-top:1px solid #eef0f4;display:flex;justify-content:flex-end;gap:8px;}",
      ".adm-checks{display:flex;gap:8px;flex-wrap:wrap;}",
      ".adm-check{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 10px;border:1px solid #d8dde8;border-radius:4px;font-size:13px;color:#354052;}",
      ".adm-check input{margin:0}",
      ".adm-node-tools{display:flex;align-items:center;gap:8px;margin:8px 0 10px;flex-wrap:wrap;}",
      ".adm-node-table-wrap{max-height:320px;overflow:auto;border:1px solid #eef0f4;border-radius:6px;background:#fff;}",
      ".adm-node-table{width:100%;border-collapse:collapse;min-width:820px;}",
      ".adm-node-table th{height:36px;background:#f8f9fc;color:#566070;font-size:12px;font-weight:600;text-align:left;padding:0 10px;border-bottom:1px solid #eef0f4;white-space:nowrap;}",
      ".adm-node-table td{font-size:13px;color:#2f3542;padding:9px 10px;border-bottom:1px solid #f0f2f5;vertical-align:middle;}",
      ".adm-node-table tr:last-child td{border-bottom:0}",
      ".adm-node-table code{font-size:12px;color:#4b5563;}",
      ".adm-port-input{width:92px;height:30px}",
      "@media(max-width:900px){.adm-page{padding:0 12px 18px}.adm-tabs{gap:18px;overflow:auto}.adm-setting-row{display:block;padding:16px}.adm-setting-control{width:100%;min-width:0;text-align:left;margin-top:12px}.adm-setting-control .adm-switch-line{justify-content:flex-start}.adm-grid{display:block}.adm-span-2,.adm-span-3,.adm-span-4,.adm-span-6,.adm-span-8,.adm-span-9,.adm-span-12{grid-column:auto}.adm-block-body{padding:0}.adm-actions{width:100%}.adm-form-control.adm-filter{width:100%!important}}"
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

  function switchField(label, id, checked) {
    return '<div class="adm-field"><label class="adm-form-label">' + escapeHtml(label) + '</label>' +
      '<div class="adm-switch-line">' + switchHtml(id, checked) + '<span class="adm-status">' + (checked ? "开启" : "关闭") + '</span></div></div>';
  }

  function settingRow(title, description, controlHtml, child) {
    return '<div class="adm-setting-row' + (child ? ' v2board-config-children' : '') + '">' +
      '<div class="adm-setting-main"><div class="adm-setting-title">' + escapeHtml(title) + '</div>' +
      '<div class="adm-setting-desc">' + escapeHtml(description || "") + '</div></div>' +
      '<div class="adm-setting-control">' + controlHtml + '</div></div>';
  }

  function configInput(id, value, placeholder) {
    return '<input class="adm-form-control adm-config-input" id="' + id + '" value="' + escapeHtml(value || "") + '" placeholder="' + escapeHtml(placeholder || "") + '">';
  }

  function configTextarea(id, value, placeholder) {
    return '<textarea class="adm-form-control adm-config-input" style="height:96px;resize:vertical;line-height:1.6" id="' + id + '" placeholder="' + escapeHtml(placeholder || "") + '">' + escapeHtml(value || "") + '</textarea>';
  }

  function configSwitch(id, checked) {
    return '<div class="adm-switch-line">' + switchHtml(id, checked) + '<span class="adm-status">' + (checked ? "开启" : "关闭") + '</span></div>';
  }

  function switchInline(label, id, checked) {
    return '<div class="adm-modal-switch"><span class="adm-modal-switch-label">' + escapeHtml(label) + '</span>' +
      switchHtml(id, checked) + '<span class="adm-status">' + (checked ? "开启" : "关闭") + '</span></div>';
  }

  function tag(text, cls) {
    return '<span class="adm-badge ' + (cls || "") + '">' + escapeHtml(text) + '</span>';
  }

  function endpoint(host, port) {
    return host ? host + (port ? ":" + port : "") : "-";
  }

  function nodeKey(type, id) {
    return String(type || "") + ":" + String(id || "");
  }

  function findNode(type, id) {
    return (state.options.nodes || []).find(function (node) {
      return node.type === type && Number(node.id) === Number(id);
    }) || null;
  }

  function bindingMap(rule) {
    var map = {};
    (rule.bindings || []).forEach(function (binding) {
      map[nodeKey(binding.server_type, binding.server_id)] = binding;
    });
    return map;
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

  function nodeSummary(rule) {
    var bindings = rule.bindings || [];
    if (!bindings.length) return "未绑定";
    return nodeLabels(rule).join(" / ");
  }

  function nodeLabels(rule) {
    return (rule.bindings || []).map(function (binding) {
      var node = findNode(binding.server_type, binding.server_id);
      var name = node ? node.name : binding.server_id;
      return binding.server_type + " #" + binding.server_id + " " + name + (binding.port ? ":" + binding.port : "");
    });
  }

  function nodeMappings(rule) {
    return (rule.bindings || []).map(function (binding) {
      var node = findNode(binding.server_type, binding.server_id);
      var nodeName = node ? node.name : binding.server_id;
      var origin = endpoint(node ? node.host : "", node ? node.port : "");
      var target = endpoint(rule.domain, binding.port || (node ? node.port : ""));
      return {
        label: binding.server_type + " #" + binding.server_id + " " + nodeName + (binding.port ? ":" + binding.port : ""),
        name: binding.server_type + " #" + binding.server_id + " " + nodeName,
        origin: origin,
        target: target
      };
    });
  }

  function nodeDetail(rule, mappings) {
    if (!mappings.length) return "";
    return '<div class="adm-node-detail">' +
      '<div class="adm-node-detail-head"><span>入口映射预览</span><div class="adm-actions"><button class="adm-node-toggle" type="button" data-rule-collapse="' + escapeHtml(rule.id) + '">收起</button><button class="adm-btn adm-btn-text adm-node-detail-edit" type="button" data-rule-edit-inline="' + escapeHtml(rule.id) + '">' + icon("pencil") + '编辑</button></div></div>' +
      '<div class="adm-node-detail-body">' + mappings.map(function (item) {
        return '<div class="adm-node-map">' +
          '<div class="adm-node-map-name" title="' + escapeHtml(item.name) + '">' + escapeHtml(item.name) + '</div>' +
          '<div class="adm-node-map-code" title="' + escapeHtml(item.origin) + '">' + escapeHtml(item.origin || "-") + '</div>' +
          '<div class="adm-node-map-arrow">=&gt;</div>' +
          '<div class="adm-node-map-code" title="' + escapeHtml(item.target) + '">' + escapeHtml(item.target || "-") + '</div>' +
        '</div>';
      }).join("") + '</div></div>';
  }

  function scopeTags(rule) {
    var options = state.options || {};
    var mappings = nodeMappings(rule);
    var labels = mappings.map(function (item) { return item.label; });
    var visible = labels.slice(0, 3);
    var remain = Math.max(labels.length - visible.length, 0);
    var nodes = labels.length
      ? '<div class="adm-node-preview">' + visible.map(function (label) {
          return '<span class="adm-node-chip" title="' + escapeHtml(label) + '">' + escapeHtml(label) + '</span>';
        }).join("") + (remain > 0
          ? '<button class="adm-node-toggle" type="button" data-rule-expand="' + escapeHtml(rule.id) + '">展开 ' + remain + ' 个</button>'
          : (labels.length ? '<button class="adm-node-toggle" type="button" data-rule-expand="' + escapeHtml(rule.id) + '">查看映射</button>' : '')) + '</div>'
      : '<div class="adm-node-preview">' + tag("节点 未绑定", "gray") + '</div>';
    return '<div class="adm-scope-cell">' +
      '<div class="adm-scope-line">' +
        tag("组 " + selectedText(rule.user_group_ids, options.user_groups), "gray") +
        tag("套餐 " + selectedText(rule.plan_ids, options.plans), "gray") +
      '</div>' +
      nodes +
      '</div>';
  }

  function filteredRules() {
    var q = String(state.filter || "").trim().toLowerCase();
    var groupId = String(state.groupFilter || "");
    return (state.rules || []).filter(function (rule) {
      if (q && [rule.name, rule.domain, rule.remark, nodeSummary(rule)].join(" ").toLowerCase().indexOf(q) === -1) return false;
      if (groupId && rule.user_group_ids.length && rule.user_group_ids.map(String).indexOf(groupId) === -1) return false;
      if (groupId && !rule.user_group_ids.length) return true;
      return true;
    });
  }

  function subscribePreview(config) {
    var path = config.app_domain_subscribe_path || "/api/v1/client/custom_app/subscribe";
    if (path.charAt(0) !== "/") path = "/" + path;
    var host = normalizeEndpoint(config.app_domain_public_host);
    return host ? host + path + "?token=YOUR_TOKEN" : path + "?token=YOUR_TOKEN";
  }

  function apiPreview(config) {
    if (!config.app_api_domain_hosts.length) return "未配置";
    return config.app_api_domain_hosts.map(function (host) {
      return normalizeEndpoint(host) + "/api/v1/client/app";
    }).join("    ");
  }

  function renderConfig(config) {
    return [
      settingRow("入口域名规则", "开启后 App 订阅按入口规则匹配节点、套餐和权限组；未命中的节点保持原入口。", configSwitch("adm_rule_enable", config.app_domain_rule_enable)),
      settingRow("App 订阅域名", "用于生成 App 专用订阅地址，留空时只使用订阅路径。", configInput("adm_public_host", config.app_domain_public_host, "app.example.com")),
      settingRow("订阅路径", "App 专用订阅接口路径，必须以 / 开头。", configInput("adm_subscribe_path", config.app_domain_subscribe_path, "/api/v1/client/custom_app/subscribe")),
      settingRow("订阅预览", "当前 App 客户端会拿到的订阅地址预览。", '<div class="adm-preview" id="adm-preview-subscribe">' + escapeHtml(subscribePreview(config)) + '</div>')
    ].join("\n");
  }

  function renderApiConfig(config) {
    return [
      settingRow("API 多域名", "开启后客户端启动时可从域名池选择可用 API 入口。", configSwitch("adm_api_enable", config.app_api_domain_enable)),
      settingRow("API 域名池", "每行一个 API 域名或 IP:端口；系统会自动补全 http / https。", configTextarea("adm_api_hosts", config.app_api_domain_hosts.join("\n"), "api1.example.com\napi2.example.com")),
      settingRow("加密下发", "开启后打包后台 manifest 内的 API 域名池会按密钥加密。", configSwitch("adm_encrypt_enable", config.app_api_domain_encrypt_enable)),
      settingRow("加密密钥", "客户端 branding.dart 中配置的 manifest 解密密钥。", configInput("adm_encrypt_key", config.app_api_domain_encrypt_key, "")),
      settingRow("API 预览", "当前域名池会生成的客户端 API 地址。", '<div class="adm-preview" id="adm-preview-api">' + escapeHtml(apiPreview(config)) + '</div>')
    ].join("\n");
  }

  function renderRuleRows() {
    var rules = filteredRules();
    if (!rules.length) {
      return '<tr><td colspan="7"><div class="adm-empty">暂无规则</div></td></tr>';
    }
    return rules.map(function (rule, index) {
      var expanded = !!state.expandedRules[String(rule.id)];
      var row = '<tr data-rule-id="' + escapeHtml(rule.id) + '">' +
        '<td>' + escapeHtml(rule.sort || index + 1) + '</td>' +
        '<td><div class="adm-rule-name">' + escapeHtml(rule.name) + '</div>' + (rule.remark ? '<div class="adm-rule-remark">' + escapeHtml(rule.remark) + '</div>' : '') + '</td>' +
        '<td><div class="adm-switch-line">' + switchHtml("adm_rule_enable_" + rule.id, rule.enable) + '<span class="adm-status">' + (rule.enable ? "开启" : "关闭") + '</span></div></td>' +
        '<td><span class="adm-rule-domain" title="' + escapeHtml(rule.domain) + '">' + escapeHtml(rule.domain) + '</span></td>' +
        '<td>' + scopeTags(rule) + '</td>' +
        '<td>' + tag((rule.bindings || []).length + " 个节点", "green") + '</td>' +
        '<td><button class="adm-btn adm-rule-edit">' + icon("pencil") + '编辑</button> <button class="adm-btn adm-btn-danger adm-rule-drop">' + icon("trash") + '删除</button></td>' +
      '</tr>';
      if (expanded) {
        row += '<tr class="adm-rule-detail-row" data-rule-detail-id="' + escapeHtml(rule.id) + '"><td colspan="7">' + nodeDetail(rule, nodeMappings(rule)) + '</td></tr>';
      }
      return row;
    }).join("");
  }

  function renderGroupOptions() {
    return '<option value="">全部用户组</option>' + ((state.options.user_groups || []).map(function (item) {
      return '<option value="' + escapeHtml(item.id) + '"' + (String(state.groupFilter) === String(item.id) ? " selected" : "") + '>' + escapeHtml(item.name) + '</option>';
    }).join(""));
  }

  function renderCheckboxes(name, values, selected) {
    selected = Array.isArray(selected) ? selected.map(String) : [];
    return '<div class="adm-checks">' + values.map(function (item) {
      var value = item.value == null ? item.id : item.value;
      var label = item.label == null ? item.name : item.label;
      return '<label class="adm-check"><input type="checkbox" name="' + name + '" value="' + escapeHtml(value) + '"' + (selected.indexOf(String(value)) !== -1 ? " checked" : "") + '> ' + escapeHtml(label) + '</label>';
    }).join("") + '</div>';
  }

  function renderNodeBindings(rule) {
    var nodes = state.options.nodes || [];
    if (!nodes.length) {
      return '<div class="adm-empty" style="padding:18px 0">暂无节点可选</div>';
    }
    var bindings = bindingMap(rule);
    return '<div class="adm-node-tools">' +
      '<input class="adm-form-control" style="width:220px" id="adm_node_filter" placeholder="搜索节点">' +
      '<button class="adm-btn" id="adm_node_select_visible" type="button">' + icon("check") + '选择当前</button>' +
      '<button class="adm-btn" id="adm_node_clear" type="button">' + icon("close") + '清空节点</button>' +
    '</div>' +
    '<div class="adm-node-table-wrap"><table class="adm-node-table">' +
      '<thead><tr><th style="width:42px"><input type="checkbox" id="adm_node_check_all"></th><th style="width:90px">节点ID</th><th>节点</th><th style="width:110px">类型</th><th>原入口</th><th style="width:120px">入口端口</th><th>下发预览</th></tr></thead>' +
      '<tbody>' + nodes.map(function (node) {
        var key = nodeKey(node.type, node.id);
        var binding = bindings[key] || {};
        var checked = !!binding.id;
        var origin = endpoint(node.host, node.port);
        var port = binding.port || "";
        var target = endpoint(rule.domain || "入口域名", port || node.port);
        var searchText = [node.id, node.type, node.name, node.host, node.port].join(" ").toLowerCase();
        return '<tr data-node-row="1" data-search="' + escapeHtml(searchText) + '" data-node-key="' + escapeHtml(key) + '">' +
          '<td><input type="checkbox" name="adm_rule_nodes" value="' + escapeHtml(key) + '"' + (checked ? " checked" : "") + '></td>' +
          '<td>#' + escapeHtml(node.id) + '</td>' +
          '<td>' + escapeHtml(node.name) + '</td>' +
          '<td>' + tag(node.type, "gray") + '</td>' +
          '<td><code>' + escapeHtml(origin) + '</code></td>' +
          '<td><input class="adm-form-control adm-port-input" name="adm_rule_node_port" data-node-key="' + escapeHtml(key) + '" value="' + escapeHtml(port) + '" placeholder="沿用"></td>' +
          '<td><code data-preview-key="' + escapeHtml(key) + '">' + escapeHtml(target) + '</code></td>' +
        '</tr>';
      }).join("") + '</tbody></table></div>';
  }

  function renderModal() {
    if (!state.modalRule) return "";
    var rule = state.modalRule;
    var options = state.options || {};
    return [
      '<div class="adm-modal-mask">',
      '  <div class="adm-modal">',
      '    <div class="adm-modal-head"><div class="adm-modal-title">' + (rule.id ? "编辑规则" : "添加规则") + '</div><button class="adm-btn adm-btn-text" id="adm-modal-close">' + icon("close") + '</button></div>',
      '    <div class="adm-modal-body"><div class="adm-grid">',
      '      <div class="adm-span-6">' + field("规则名称", "adm_rule_name", rule.name, "", "") + '</div>',
      '      <div class="adm-span-6">' + field("入口域名", "adm_rule_domain", rule.domain, "", "edge.example.com") + '</div>',
      '      <div class="adm-span-3">' + field("排序", "adm_rule_sort", rule.sort, "", "") + '</div>',
      '      <div class="adm-span-9">' + field("备注", "adm_rule_remark", rule.remark, "", "") + '</div>',
      '      <div class="adm-span-12"><div class="adm-modal-switches">' + switchInline("启用", "adm_rule_enable_input", rule.enable) + '</div></div>',
      '      <div class="adm-span-12"><label class="adm-form-label">用户组</label>' + renderCheckboxes("adm_rule_groups", options.user_groups || [], rule.user_group_ids) + '</div>',
      '      <div class="adm-span-12"><label class="adm-form-label">套餐</label>' + renderCheckboxes("adm_rule_plans", options.plans || [], rule.plan_ids) + '</div>',
      '      <div class="adm-span-12"><label class="adm-form-label">节点入口</label>' + renderNodeBindings(rule) + '</div>',
      '    </div></div>',
      '    <div class="adm-modal-foot"><button class="adm-btn" id="adm-modal-cancel">取消</button><button class="adm-btn adm-btn-primary" id="adm-rule-save">' + icon("check") + '保存</button></div>',
      '  </div>',
      '</div>'
    ].join("\n");
  }

  function renderPage() {
    mountStyle();
    setHeaderTitle();
    var root = getMainRoot();
    if (!root) return false;
    var config = state.config || normalizeConfig({});
    var activeTab = state.activeTab || "basic";
    root.innerHTML = [
      '<div class="adm-page" id="adm-panel">',
      '  <div class="adm-page-head"><div class="adm-status" id="adm-status"></div><div class="adm-actions"><button class="adm-btn" id="adm-refresh-btn">' + icon("refresh") + '刷新</button></div></div>',
      '  <div class="adm-native-block block border-bottom">',
      '    <div class="adm-tabs"><button class="adm-tab ' + (activeTab === "basic" ? "active" : "") + '" data-adm-tab="basic">基础</button><button class="adm-tab ' + (activeTab === "rules" ? "active" : "") + '" data-adm-tab="rules">入口规则</button><button class="adm-tab ' + (activeTab === "api" ? "active" : "") + '" data-adm-tab="api">API 域名池</button></div>',
      '    <div class="adm-tab-panel ' + (activeTab === "basic" ? "active" : "") + '" data-adm-panel="basic">' + renderConfig(config) + '</div>',
      '    <div class="adm-tab-panel ' + (activeTab === "rules" ? "active" : "") + '" data-adm-panel="rules">',
      '      <div class="adm-table-tools"><div class="adm-actions"><input class="adm-form-control adm-filter" style="width:220px" id="adm-rule-filter" value="' + escapeHtml(state.filter) + '" placeholder="搜索规则"><select class="adm-form-control adm-filter" style="width:160px" id="adm-group-filter">' + renderGroupOptions() + '</select></div><button class="adm-btn adm-btn-primary" id="adm-rule-add">' + icon("plus") + '添加规则</button></div>',
      '      <div class="adm-table-wrap"><table class="adm-table"><thead><tr><th>排序</th><th>规则</th><th>状态</th><th>入口域名</th><th>匹配对象</th><th>绑定</th><th>操作</th></tr></thead><tbody>' + renderRuleRows() + '</tbody></table></div>',
      '    </div>',
      '    <div class="adm-tab-panel ' + (activeTab === "api" ? "active" : "") + '" data-adm-panel="api">' + renderApiConfig(config) + '</div>',
      '  </div>',
      '</div>',
      renderModal()
    ].join("\n");
    bindEvents();
    updatePreview();
    return true;
  }

  function checked(id) {
    var el = document.getElementById(id);
    return el && el.checked ? 1 : 0;
  }

  function checkedOrConfig(id, key, config) {
    var el = document.getElementById(id);
    if (!el) return Number(config[key] || 0);
    return el.checked ? 1 : 0;
  }

  function selectedValues(name) {
    return Array.prototype.slice.call(document.querySelectorAll('input[name="' + name + '"]:checked')).map(function (input) {
      return Number(input.value);
    }).filter(function (id) { return id > 0; });
  }

  function collectConfig(readDom) {
    var config = state.config || normalizeConfig({});
    var publicHost = document.getElementById("adm_public_host");
    var subscribePath = document.getElementById("adm_subscribe_path");
    var apiHosts = document.getElementById("adm_api_hosts");
    var encryptKey = document.getElementById("adm_encrypt_key");
    return {
      app_domain_enable: Number(config.app_domain_enable || 0),
      app_domain_rule_enable: checkedOrConfig("adm_rule_enable", "app_domain_rule_enable", config),
      app_domain_public_host: publicHost ? publicHost.value : config.app_domain_public_host,
      app_domain_subscribe_path: subscribePath ? subscribePath.value : config.app_domain_subscribe_path,
      app_domain_replace_host: config.app_domain_replace_host,
      app_api_domain_enable: checkedOrConfig("adm_api_enable", "app_api_domain_enable", config),
      app_api_domain_hosts: apiHosts ? apiHosts.value.split(/\n+/).map(normalizeEndpoint).filter(Boolean) : config.app_api_domain_hosts,
      app_api_domain_encrypt_enable: checkedOrConfig("adm_encrypt_enable", "app_api_domain_encrypt_enable", config),
      app_api_domain_encrypt_key: encryptKey ? encryptKey.value : config.app_api_domain_encrypt_key
    };
  }

  function collectRule() {
    var current = state.modalRule || emptyRule();
    return {
      id: current.id || undefined,
      name: (document.getElementById("adm_rule_name") || {}).value || "",
      enable: checked("adm_rule_enable_input"),
      sort: Number((document.getElementById("adm_rule_sort") || {}).value || 0),
      domain: normalizeHost((document.getElementById("adm_rule_domain") || {}).value || ""),
      user_group_ids: selectedValues("adm_rule_groups"),
      plan_ids: selectedValues("adm_rule_plans"),
      remark: (document.getElementById("adm_rule_remark") || {}).value || ""
    };
  }

  function collectBindings(groupId) {
    var current = state.modalRule || emptyRule();
    var existing = bindingMap(current);
    return Array.prototype.slice.call(document.querySelectorAll('input[name="adm_rule_nodes"]:checked')).map(function (input, index) {
      var parts = String(input.value || "").split(":");
      var key = input.value;
      var portEl = document.querySelector('input[name="adm_rule_node_port"][data-node-key="' + key + '"]');
      var port = Number((portEl || {}).value || 0) || null;
      return {
        id: existing[key] ? existing[key].id : undefined,
        group_id: groupId,
        enable: 1,
        sort: index + 1,
        server_type: parts[0] || "",
        server_id: Number(parts[1] || 0),
        port: port,
        remark: existing[key] ? existing[key].remark : ""
      };
    });
  }

  function updatePreview() {
    var config = collectConfig(false);
    var subscribe = document.getElementById("adm-preview-subscribe");
    var api = document.getElementById("adm-preview-api");
    if (subscribe) subscribe.textContent = subscribePreview(config);
    if (api) api.textContent = apiPreview(config);
    updateNodePreviews();
  }

  function updateNodePreviews() {
    if (!state.modalRule) return;
    var domain = normalizeHost((document.getElementById("adm_rule_domain") || {}).value || state.modalRule.domain || "入口域名");
    Array.prototype.slice.call(document.querySelectorAll("[data-preview-key]")).forEach(function (preview) {
      var key = preview.getAttribute("data-preview-key");
      var parts = key.split(":");
      var node = findNode(parts[0], parts[1]);
      var portEl = document.querySelector('input[name="adm_rule_node_port"][data-node-key="' + key + '"]');
      var port = Number((portEl || {}).value || 0) || (node ? node.port : "");
      preview.textContent = endpoint(domain, port);
    });
  }

  function bindEvents() {
    var refresh = document.getElementById("adm-refresh-btn");
    if (refresh) refresh.addEventListener("click", function () {
      flushConfigSave();
      loadAll(true);
    });
    Array.prototype.slice.call(document.querySelectorAll("[data-adm-tab]")).forEach(function (tab) {
      tab.addEventListener("click", function () {
        flushConfigSave();
        state.activeTab = tab.getAttribute("data-adm-tab") || "basic";
        renderPage();
      });
    });
    ["adm_rule_enable", "adm_api_enable", "adm_encrypt_enable"].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener("change", function () {
        updateSwitchStatus(el);
        saveConfigForm(true);
      });
    });
    ["adm_public_host", "adm_subscribe_path", "adm_api_hosts", "adm_encrypt_key"].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener("input", function () {
        updatePreview();
        scheduleConfigSave();
      });
    });
    var filter = document.getElementById("adm-rule-filter");
    if (filter) filter.addEventListener("input", function () { state.filter = filter.value; refreshRuleTable(); });
    var groupFilter = document.getElementById("adm-group-filter");
    if (groupFilter) groupFilter.addEventListener("change", function () { state.groupFilter = groupFilter.value; refreshRuleTable(); });
    var add = document.getElementById("adm-rule-add");
    if (add) add.addEventListener("click", function () { state.modalRule = emptyRule(); renderPage(); });
    bindRuleRows();
    var close = document.getElementById("adm-modal-close");
    var cancel = document.getElementById("adm-modal-cancel");
    var save = document.getElementById("adm-rule-save");
    if (close) close.addEventListener("click", closeModal);
    if (cancel) cancel.addEventListener("click", closeModal);
    if (save) save.addEventListener("click", saveRuleForm);
    bindNodeTools();
  }

  function refreshRuleTable() {
    var tbody = document.querySelector(".adm-table tbody");
    if (!tbody) return;
    tbody.innerHTML = renderRuleRows();
    bindRuleRows();
  }

  function bindRuleRows() {
    Array.prototype.slice.call(document.querySelectorAll("[data-rule-id]")).forEach(function (row) {
      var id = Number(row.getAttribute("data-rule-id"));
      var rule = state.rules.find(function (item) { return Number(item.id) === id; });
      var edit = row.querySelector(".adm-rule-edit");
      var drop = row.querySelector(".adm-rule-drop");
      var enable = document.getElementById("adm_rule_enable_" + id);
      if (edit) edit.addEventListener("click", function () { state.modalRule = normalizeRule(rule); renderPage(); });
      if (drop) drop.addEventListener("click", function () { dropRule(rule); });
      if (enable) enable.addEventListener("change", function () { saveRuleEnable(rule, enable.checked ? 1 : 0); });
    });
    Array.prototype.slice.call(document.querySelectorAll("[data-rule-expand]")).forEach(function (button) {
      button.addEventListener("click", function () {
        state.expandedRules[String(button.getAttribute("data-rule-expand"))] = true;
        refreshRuleTable();
      });
    });
    Array.prototype.slice.call(document.querySelectorAll("[data-rule-collapse]")).forEach(function (button) {
      button.addEventListener("click", function () {
        delete state.expandedRules[String(button.getAttribute("data-rule-collapse"))];
        refreshRuleTable();
      });
    });
    Array.prototype.slice.call(document.querySelectorAll("[data-rule-edit-inline]")).forEach(function (button) {
      button.addEventListener("click", function () {
        var id = Number(button.getAttribute("data-rule-edit-inline"));
        var rule = state.rules.find(function (item) { return Number(item.id) === id; });
        if (rule) {
          state.modalRule = normalizeRule(rule);
          renderPage();
        }
      });
    });
  }

  function updateSwitchStatus(input) {
    var line = input && input.closest ? input.closest(".adm-switch-line") : null;
    var label = line ? line.querySelector(".adm-status") : null;
    if (label) label.textContent = input.checked ? "开启" : "关闭";
  }

  function scheduleConfigSave() {
    if (configSaveTimer) clearTimeout(configSaveTimer);
    setStatus("正在保存...");
    configSaveTimer = setTimeout(function () {
      configSaveTimer = null;
      saveConfigForm(false);
    }, 1500);
  }

  function flushConfigSave() {
    if (!configSaveTimer) return;
    clearTimeout(configSaveTimer);
    configSaveTimer = null;
    saveConfigForm(false);
  }

  function bindNodeTools() {
    var filter = document.getElementById("adm_node_filter");
    var selectVisible = document.getElementById("adm_node_select_visible");
    var clear = document.getElementById("adm_node_clear");
    var checkAll = document.getElementById("adm_node_check_all");
    var domain = document.getElementById("adm_rule_domain");
    var rows = function () {
      return Array.prototype.slice.call(document.querySelectorAll("[data-node-row]"));
    };
    var visibleRows = function () {
      return rows().filter(function (row) { return row.style.display !== "none"; });
    };
    if (domain) domain.addEventListener("input", updateNodePreviews);
    Array.prototype.slice.call(document.querySelectorAll('input[name="adm_rule_node_port"]')).forEach(function (input) {
      input.addEventListener("input", updateNodePreviews);
    });
    if (filter) filter.addEventListener("input", function () {
      var q = String(filter.value || "").trim().toLowerCase();
      rows().forEach(function (row) {
        row.style.display = !q || String(row.getAttribute("data-search") || "").indexOf(q) !== -1 ? "" : "none";
      });
    });
    if (selectVisible) selectVisible.addEventListener("click", function () {
      visibleRows().forEach(function (row) {
        var input = row.querySelector('input[name="adm_rule_nodes"]');
        if (input) input.checked = true;
      });
    });
    if (clear) clear.addEventListener("click", function () {
      rows().forEach(function (row) {
        var input = row.querySelector('input[name="adm_rule_nodes"]');
        if (input) input.checked = false;
      });
      if (checkAll) checkAll.checked = false;
    });
    if (checkAll) checkAll.addEventListener("change", function () {
      visibleRows().forEach(function (row) {
        var input = row.querySelector('input[name="adm_rule_nodes"]');
        if (input) input.checked = checkAll.checked;
      });
    });
  }

  function saveConfigForm(refreshAfter) {
    setStatus("保存中...");
    request("POST", "/server/app-domain/config", collectConfig()).then(function () {
      state.config = collectConfig();
      setStatus("保存成功", "success");
      if (refreshAfter) return loadAll(true);
      updatePreview();
    }).catch(function (error) {
      setStatus(error.message || "保存失败", "error");
    });
  }

  function saveRuleEnable(rule, enable) {
    if (!rule) return;
    var payload = {
      id: rule.id,
      name: rule.name,
      enable: enable,
      sort: rule.sort,
      domain: rule.domain,
      user_group_ids: rule.user_group_ids || [],
      plan_ids: rule.plan_ids || [],
      remark: rule.remark || ""
    };
    setStatus("保存中...");
    request("POST", "/server/app-domain/group/save", payload).then(function () {
      setStatus("保存成功", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "保存失败", "error");
      return loadAll(true);
    });
  }

  function saveRuleForm() {
    var payload = collectRule();
    if (!payload.name || !payload.domain) {
      setStatus("规则名称和入口域名不能为空", "error");
      return;
    }
    request("POST", "/server/app-domain/group/save", payload).then(function (response) {
      var groupId = Number(response.data || payload.id || 0);
      var selected = collectBindings(groupId);
      var selectedKeys = {};
      selected.forEach(function (binding) {
        selectedKeys[nodeKey(binding.server_type, binding.server_id)] = true;
      });
      var existingDrops = (state.modalRule.bindings || []).filter(function (binding) {
        return !selectedKeys[nodeKey(binding.server_type, binding.server_id)];
      });
      var tasks = selected.map(function (binding) {
        return request("POST", "/server/app-domain/binding/save", binding);
      }).concat(existingDrops.map(function (binding) {
        return request("POST", "/server/app-domain/binding/drop", { id: binding.id });
      }));
      return Promise.all(tasks);
    }).then(function () {
      state.modalRule = null;
      setStatus("规则已保存", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "规则保存失败", "error");
    });
  }

  function dropRule(rule) {
    if (!rule || !window.confirm("删除规则：" + rule.name + "？")) return;
    request("POST", "/server/app-domain/group/drop", { id: rule.id }).then(function () {
      setStatus("规则已删除", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "删除失败", "error");
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
      request("GET", "/server/app-domain/groups"),
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
