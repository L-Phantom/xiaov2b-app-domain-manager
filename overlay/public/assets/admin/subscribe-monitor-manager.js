(function () {
  var ROUTE = "/server/subscribe-monitor";
  var state = {
    data: null,
    loading: false,
    showRules: false,
    selectedUserId: null,
    drawerTab: "signals",
    ruleTab: "levels",
    queueTab: "watch",
    showSystemStatus: false,
    filters: {
      days: 7,
      limit: 80,
      page: 1,
      per_page: 50,
      keyword: "",
      type: "",
      disposition_keyword: "",
      operator: "",
      watch_overdue_days: 7,
      risk: "",
      disposition: ""
    }
  };
  var styleMounted = false;
  var loadingPromise = null;

  function routePath() {
    var hash = window.location.hash || "";
    if (hash.indexOf("#") === 0) return hash.slice(1) || "/";
    return window.location.pathname || "/";
  }

  function isTargetRoute() {
    return routePath() === ROUTE;
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

  function request(path, params, method, payload) {
    var authorization = "";
    try { authorization = window.localStorage.getItem("authorization") || ""; } catch (e) {}
    var query = new URLSearchParams();
    Object.keys(params || {}).forEach(function (key) {
      if (params[key] !== "" && params[key] != null) query.set(key, params[key]);
    });
    var url = adminApi(path) + (query.toString() ? "?" + query.toString() : "");
    return fetch(url, {
      method: method || "GET",
      credentials: "same-origin",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "authorization": authorization
      },
      body: payload ? JSON.stringify(payload) : undefined
    }).then(function (response) {
      return response.json().catch(function () {
        throw new Error("接口返回了不可解析内容 HTTP " + response.status);
      }).then(function (json) {
        if (!response.ok) throw new Error(json.message || ("请求失败 HTTP " + response.status));
        return json;
      });
    });
  }

  function mountStyle() {
    if (styleMounted) return;
    styleMounted = true;
    var style = document.createElement("style");
    style.textContent = [
      ".smm-page{width:100%;max-width:none;padding:0 32px 32px;box-sizing:border-box;}",
      ".smm-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 0 14px;flex-wrap:wrap;}",
      ".smm-title{font-size:15px;font-weight:600;color:#2f3542;}",
      ".smm-sub{font-size:12px;color:#6b7280;margin-top:4px;}",
      ".smm-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}",
      ".smm-input,.smm-select{height:32px;border:1px solid #d8dde8;border-radius:4px;background:#fff;color:#2f3542;padding:0 10px;font-size:13px;outline:none;}",
      ".smm-input{width:260px;}",
      ".smm-btn{height:32px;border:1px solid #d8dde8;border-radius:4px;background:#fff;color:#354052;padding:0 10px;font-size:13px;cursor:pointer;white-space:nowrap;}",
      ".smm-btn:hover{border-color:#5c80ff;color:#3150b7}",
      ".smm-btn-primary{background:#5c80ff;border-color:#5c80ff;color:#fff}",
      ".smm-btn-primary:hover{background:#466ce6;color:#fff}",
      ".smm-btn-danger{background:#b42318;border-color:#b42318;color:#fff}",
      ".smm-btn-danger:hover{background:#9a1b13;color:#fff}",
      ".smm-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px;width:100%;}",
      ".smm-card{background:#fff;border:0;}",
      ".smm-span-3{grid-column:span 3}.smm-span-4{grid-column:span 4}.smm-span-6{grid-column:span 6}.smm-span-8{grid-column:span 8}.smm-span-12{grid-column:span 12}",
      ".smm-stat{padding:18px 20px;min-height:92px;}",
      ".smm-stat-label{font-size:12px;color:#7b8494;margin-bottom:8px;}",
      ".smm-stat-value{font-size:26px;line-height:1;color:#202938;font-weight:600;}",
      ".smm-stat-foot{font-size:12px;color:#8a93a3;margin-top:9px;}",
      ".smm-card-head{min-height:52px;padding:0 20px;border-bottom:1px solid #eef0f4;display:flex;align-items:center;justify-content:space-between;gap:12px;}",
      ".smm-card-head-main{min-width:0;}",
      ".smm-card-title{font-size:14px;font-weight:600;color:#2f3542;}",
      ".smm-card-body{padding:0;}",
      ".smm-cache-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-top:16px;}",
      ".smm-system-status{display:none;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px;}",
      ".smm-system-status.open{display:grid;}",
      ".smm-cache-item{padding:14px 18px;min-height:84px;}",
      ".smm-cache-main{font-size:20px;font-weight:600;color:#202938;line-height:1.1;}",
      ".smm-cache-label{font-size:12px;color:#697386;margin-top:8px;}",
      ".smm-cache-sub{font-size:12px;color:#8a93a3;margin-top:6px;line-height:1.5;}",
      ".smm-mini-list{padding:8px 0;}",
      ".smm-overview-card{padding:18px 20px;}",
      ".smm-overview-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px;}",
      ".smm-overview-item{border:1px solid #eef0f4;background:#fbfcfe;border-radius:4px;padding:12px 14px;}",
      ".smm-overview-num{font-size:22px;font-weight:600;color:#202938;line-height:1.1;}",
      ".smm-overview-label{font-size:12px;color:#697386;margin-top:7px;}",
      ".smm-overview-sub{font-size:12px;color:#8a93a3;margin-top:5px;}",
      ".smm-system-toggle{margin-left:auto;}",
      ".smm-mini-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:9px 20px;border-bottom:1px solid #f3f4f7;align-items:center;}",
      ".smm-mini-row:last-child{border-bottom:0}",
      ".smm-main-text{font-size:13px;color:#2f3542;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}",
      ".smm-sub-text{font-size:12px;color:#8a93a3;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}",
      ".smm-queue-card{margin-top:16px;}",
      ".smm-queue-head{padding-top:10px;padding-bottom:10px;}",
      ".smm-queue-tabs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end;}",
      ".smm-queue-tab{height:30px;border:1px solid #d8dde8;background:#fff;border-radius:4px;padding:0 10px;font-size:13px;color:#566070;cursor:pointer;}",
      ".smm-queue-tab.active{background:#eef2ff;border-color:#b8c5ff;color:#3150b7;font-weight:600;}",
      ".smm-queue-subhead{min-height:46px;padding:0 20px;border-bottom:1px solid #eef0f4;display:flex;align-items:center;justify-content:space-between;gap:12px;}",
      ".smm-queue-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;}",
      ".smm-count-pill{display:inline-flex;align-items:center;height:30px;border:1px solid #eef0f4;border-radius:4px;background:#fbfcfe;color:#8a93a3;padding:0 10px;font-size:12px;white-space:nowrap;}",
      ".smm-queue-table{width:100%;border-collapse:collapse;background:#fff;min-width:1180px;}",
      ".smm-queue-table th{height:42px;background:#f8f9fc;color:#566070;font-size:12px;font-weight:600;text-align:left;padding:0 12px;border-bottom:1px solid #eef0f4;white-space:nowrap;}",
      ".smm-queue-table td{font-size:13px;color:#2f3542;padding:11px 12px;border-bottom:1px solid #f0f2f5;vertical-align:middle;}",
      ".smm-queue-table tr:last-child td{border-bottom:0;}",
      ".smm-table-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}",
      ".smm-pill{display:inline-flex;align-items:center;height:22px;border-radius:4px;background:#eef2ff;color:#3150b7;padding:0 8px;font-size:12px;white-space:nowrap;}",
      ".smm-risk{display:inline-flex;align-items:center;height:24px;border-radius:4px;padding:0 8px;font-size:12px;font-weight:600;white-space:nowrap;}",
      ".smm-risk.safe{background:#e9f8ef;color:#137447}.smm-risk.mid{background:#fff7e6;color:#ad6800}.smm-risk.high{background:#fff1f0;color:#b42318}.smm-risk.critical{background:#3a0d12;color:#fff}",
      ".smm-disposition{display:inline-flex;align-items:center;height:24px;border-radius:4px;padding:0 8px;font-size:12px;font-weight:600;background:#f3f4f7;color:#566070;white-space:nowrap;}",
      ".smm-disposition.watch{background:#fff7e6;color:#ad6800}.smm-disposition.handled{background:#eef2ff;color:#3150b7}.smm-disposition.whitelist{background:#e9f8ef;color:#137447}.smm-disposition.freeze_suggested,.smm-disposition.blacklist_suggested{background:#fff1f0;color:#b42318}",
      ".smm-overdue{display:inline-flex;align-items:center;height:20px;border-radius:4px;background:#fff1f0;color:#b42318;padding:0 6px;font-size:12px;margin-top:4px;}",
      ".smm-table-wrap{overflow:auto;}",
      ".smm-table{width:100%;border-collapse:collapse;background:#fff;min-width:1180px;}",
      ".smm-table th{height:42px;background:#f8f9fc;color:#566070;font-size:12px;font-weight:600;text-align:left;padding:0 12px;border-bottom:1px solid #eef0f4;white-space:nowrap;}",
      ".smm-table td{font-size:13px;color:#2f3542;padding:11px 12px;border-bottom:1px solid #f0f2f5;vertical-align:middle;}",
      ".smm-table tr:last-child td{border-bottom:0;}",
      ".smm-table th:last-child,.smm-table td:last-child,.smm-queue-table th:last-child,.smm-queue-table td:last-child{text-align:right;}",
      ".smm-table td:last-child .smm-table-actions,.smm-queue-table td:last-child .smm-table-actions{justify-content:flex-end;}",
      ".smm-table tr.is-selected td{background:#fbfcff;}",
      ".smm-code{font-size:12px;color:#596273;background:#f6f8fb;border:1px solid #edf0f5;border-radius:4px;padding:3px 6px;white-space:nowrap;}",
      ".smm-muted{color:#8a93a3;font-size:12px;}",
      ".smm-empty{padding:34px 12px;text-align:center;color:#8a93a3;font-size:13px;}",
      ".smm-status{font-size:13px;color:#6b7280;min-height:20px;}",
      ".smm-status.error{color:#b42318}.smm-status.success{color:#137447}",
      ".smm-pager{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1px solid #eef0f4;flex-wrap:wrap;}",
      ".smm-drawer-mask{position:fixed;inset:0;background:rgba(24,31,42,.28);z-index:1080;display:flex;justify-content:flex-end;}",
      ".smm-drawer{width:min(760px,calc(100vw - 48px));height:100%;background:#fff;box-shadow:-12px 0 28px rgba(20,32,54,.16);display:flex;flex-direction:column;}",
      ".smm-drawer-head{padding:18px 22px 14px;border-bottom:1px solid #eef0f4;}",
      ".smm-drawer-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;}",
      ".smm-drawer-state{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;}",
      ".smm-drawer-title{font-size:16px;font-weight:600;color:#202938;word-break:break-all;}",
      ".smm-drawer-meta{font-size:12px;color:#8a93a3;margin-top:6px;line-height:1.7;}",
      ".smm-drawer-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;}",
      ".smm-drawer-tabs{display:flex;gap:0;border-bottom:1px solid #eef0f4;padding:0 14px;overflow:auto;}",
      ".smm-tab{height:42px;border:0;background:transparent;color:#697386;padding:0 12px;font-size:13px;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;}",
      ".smm-tab.active{color:#3150b7;border-bottom-color:#5c80ff;font-weight:600;}",
      ".smm-drawer-body{padding:16px 22px 24px;overflow:auto;flex:1;background:#fbfcfe;}",
      ".smm-section-title{font-size:12px;color:#697386;margin:2px 0 10px;}",
      ".smm-signal-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-bottom:16px;}",
      ".smm-signal{border:1px solid #edf0f5;background:#fff;border-radius:4px;padding:9px 10px;}",
      ".smm-signal-main{font-size:12px;color:#2f3542;font-weight:600;}",
      ".smm-signal-sub{font-size:12px;color:#8a93a3;margin-top:4px;line-height:1.5;word-break:break-all;}",
      ".smm-metric-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(132px,1fr));gap:8px;margin-bottom:16px;}",
      ".smm-metric-box{background:#fff;border:1px solid #edf0f5;border-radius:4px;padding:9px 10px;min-height:54px;}",
      ".smm-metric-box strong{display:block;font-size:14px;color:#2f3542;line-height:1.2;word-break:break-word;}",
      ".smm-metric-box span{display:block;font-size:12px;color:#8a93a3;margin-top:4px;}",
      ".smm-detail-table{width:100%;min-width:700px;border-collapse:collapse;background:#fff;border:1px solid #edf0f5;}",
      ".smm-detail-table th{height:36px;background:#f8f9fc;color:#566070;font-size:12px;font-weight:600;text-align:left;padding:0 10px;border-bottom:1px solid #eef0f4;white-space:nowrap;}",
      ".smm-detail-table td{font-size:12px;color:#2f3542;padding:9px 10px;border-bottom:1px solid #f0f2f5;vertical-align:top;}",
      ".smm-detail-table tr:last-child td{border-bottom:0}",
      ".smm-timeline{display:flex;flex-direction:column;gap:10px;}",
      ".smm-timeline-item{background:#fff;border:1px solid #edf0f5;border-radius:4px;padding:10px 12px;}",
      ".smm-timeline-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}",
      ".smm-timeline-meta{font-size:12px;color:#8a93a3;margin-top:6px;line-height:1.6;}",
      ".smm-preview-list{display:flex;flex-direction:column;gap:8px;}",
      ".smm-preview-row{background:#fff;border:1px solid #edf0f5;border-radius:4px;padding:10px 12px;}",
      ".smm-preview-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}",
      ".smm-preview-path{font-size:12px;color:#566070;margin-top:7px;line-height:1.6;word-break:break-all;}",
      ".smm-rule-panel{position:fixed;inset:0;background:rgba(24,31,42,.28);z-index:1090;display:flex;align-items:center;justify-content:center;padding:28px;box-sizing:border-box;}",
      ".smm-rule-dialog{width:min(1040px,100%);max-height:calc(100vh - 56px);background:#fff;display:flex;flex-direction:column;box-shadow:0 18px 44px rgba(20,32,54,.18);}",
      ".smm-rule-tabs{display:flex;gap:0;border-bottom:1px solid #eef0f4;padding:0 14px;overflow:auto;}",
      ".smm-rule-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:14px;padding:16px 20px;overflow:auto;}",
      ".smm-rule-group{display:none;grid-column:1/-1;grid-template-columns:repeat(12,minmax(0,1fr));gap:14px;}",
      ".smm-rule-group.active{display:grid;}",
      ".smm-rule-field{grid-column:span 3;}",
      ".smm-rule-field.wide{grid-column:span 6;}",
      ".smm-rule-field.full{grid-column:1/-1;}",
      ".smm-rule-label{font-size:12px;color:#697386;margin-bottom:6px;}",
      ".smm-rule-input{width:100%;height:32px;border:1px solid #d8dde8;border-radius:4px;background:#fff;color:#2f3542;padding:0 10px;font-size:13px;outline:none;box-sizing:border-box;}",
      ".smm-rule-textarea{width:100%;height:92px;border:1px solid #d8dde8;border-radius:4px;background:#fff;color:#2f3542;padding:8px 10px;font-size:13px;outline:none;resize:vertical;box-sizing:border-box;}",
      ".smm-action-note{width:100%;height:70px;border:1px solid #d8dde8;border-radius:4px;background:#fff;color:#2f3542;padding:8px 10px;font-size:13px;outline:none;resize:vertical;box-sizing:border-box;margin:10px 0;}",
      ".smm-rule-actions{display:flex;justify-content:flex-end;gap:8px;padding:0 20px 18px;border-top:1px solid #eef0f4;padding-top:14px;}",
      "@media(min-width:1600px){.smm-page{padding-right:40px}.smm-mini-row{padding-top:12px;padding-bottom:12px}.smm-stat{min-height:108px}}",
      "@media(max-width:1100px){.smm-span-3,.smm-span-4,.smm-span-6,.smm-span-8,.smm-span-12{grid-column:span 12}.smm-rule-field,.smm-rule-field.wide{grid-column:span 6}}",
      "@media(max-width:900px){.smm-page{padding:0 12px 18px}.smm-grid,.smm-cache-grid{display:block}.smm-card{margin-bottom:14px}.smm-card-head,.smm-queue-subhead{align-items:flex-start;flex-direction:column;padding-top:10px;padding-bottom:10px}.smm-queue-tabs,.smm-queue-actions,.smm-tools{width:100%;justify-content:flex-start}.smm-input,.smm-select{width:100%}.smm-drawer{width:100vw}.smm-rule-panel{padding:10px}.smm-rule-field,.smm-rule-field.wide{grid-column:1/-1}.smm-signal-list,.smm-metric-strip{grid-template-columns:1fr}}"
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
    if (title) title.textContent = "行为监管";
  }

  function formatTime(value) {
    if (!value) return "-";
    var date = new Date(Number(value) * 1000);
    if (Number.isNaN(date.getTime())) return "-";
    var pad = function (n) { return n < 10 ? "0" + n : "" + n; };
    return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate()) + " " + pad(date.getHours()) + ":" + pad(date.getMinutes());
  }

  function formatBytes(value) {
    value = Number(value || 0);
    var units = ["B", "KB", "MB", "GB", "TB", "PB"];
    var index = 0;
    while (value >= 1024 && index < units.length - 1) {
      value = value / 1024;
      index += 1;
    }
    return (index === 0 ? value.toFixed(0) : value.toFixed(2)) + " " + units[index];
  }

  function typeLabel(type) {
    var map = {
      client_subscribe: "普通订阅",
      app_subscribe: "App订阅",
      app_meta: "App Meta",
      app_get_config: "App配置"
    };
    return map[type] || type || "-";
  }

  function statusText(status) {
    return Number(status) === 1 ? "成功" : "不可用";
  }

  function regionText(region) {
    if (!region) return "";
    var parts = [region.country, region.region, region.city, region.isp].filter(Boolean);
    return parts.length ? parts.join(" / ") : (region.raw_region || "");
  }

  function riskRules() {
    var defaults = {
      levels: { medium: 30, high: 60, critical: 85 },
      ip: { tiers: [{ threshold: 2, score: 6 }, { threshold: 4, score: 14 }, { threshold: 8, score: 30 }] },
      agent: { tiers: [{ threshold: 3, score: 8 }, { threshold: 6, score: 20 }] },
      host: { tiers: [{ threshold: 2, score: 5 }, { threshold: 4, score: 14 }] },
      request: { tiers: [{ threshold: 30, score: 8 }, { threshold: 80, score: 18 }, { threshold: 200, score: 35 }] },
      token: { threshold: 2, score: 20 },
      failure: { score_each: 2, max_score: 20 },
      frequency: {
        last_10m: { threshold: 10, score: 8 },
        last_1h: { threshold: 40, score: 10 },
        today: { threshold: 150, score: 14 },
        max_per_minute: { threshold: 6, score: 10 },
        min_interval: { seconds: 10, score: 6 }
      },
      account: { min_requests: 3, expired_score: 18, no_plan_score: 12, traffic_exhausted_score: 15 },
      traffic: { no_usage_pulls: 5, no_usage_score: 45, low_usage_pulls: 8, low_usage_bytes: 10485760, low_usage_score: 32, normal_usage_bytes: 52428800, normal_usage_discount: 25 },
      client: {
        suspicious_keywords: ["curl", "wget", "python", "go-http-client", "postman", "okhttp", "httpclient"],
        trusted_keywords: ["FlClash", "Clash", "sing-box", "v2rayN", "Shadowrocket", "Stash"],
        suspicious_score_each: 10,
        suspicious_max_score: 30,
        empty_agent_score_each: 2,
        empty_agent_max_score: 20,
        trusted_discount: 8
      },
      host_policy: {
        trusted_hosts: [],
        watch_hosts: [],
        risk_hosts: [],
        watch_host_score: 8,
        risk_host_score: 25,
        unknown_host_score: 6,
        max_score: 30,
        trusted_discount: 8
      },
      share: {
        ip_users: { threshold: 3, score: 18 },
        ip_tokens: { threshold: 3, score: 18 }
      },
      geo: {
        countries: { threshold: 2, score: 18 },
        regions: { threshold: 3, score: 12 },
        cities: { threshold: 4, score: 10 },
        isps: { threshold: 3, score: 10 },
        asns: { threshold: 3, score: 15 },
        network_types: { threshold: 2, score: 12 }
      },
      network: { idc_score: 10, mobile_score: 0, fixed_score: 0, proxy_score: 35, vpn_score: 30, tor_score: 45, bot_score: 30 },
      guard: { critical_requires_core_signal: true, critical_downgrade_discount: 20 },
      queue: { watch_score: 80 }
    };
    return mergeRules(defaults, (state.data && state.data.risk_rules) || {});
  }

  function mergeRules(defaults, saved) {
    var result = Array.isArray(defaults) ? defaults.slice() : Object.assign({}, defaults);
    Object.keys(saved || {}).forEach(function (key) {
      if (saved[key] && typeof saved[key] === "object" && !Array.isArray(saved[key]) && defaults[key] && typeof defaults[key] === "object" && !Array.isArray(defaults[key])) {
        result[key] = mergeRules(defaults[key], saved[key]);
      } else {
        result[key] = saved[key];
      }
    });
    return result;
  }

  function riskClass(level) {
    if (level === "极危险") return "critical";
    if (level === "高风险") return "high";
    if (level === "中风险") return "mid";
    return "safe";
  }

  function riskKey(level) {
    if (level === "极危险") return "critical";
    if (level === "高风险") return "high";
    if (level === "中风险") return "medium";
    return "safe";
  }

  function dispositionLabel(status) {
    var map = {
      none: "未处置",
      watch: "观察",
      handled: "已处理",
      whitelist: "白名单",
      freeze_suggested: "建议冻结",
      blacklist_suggested: "建议拉黑"
    };
    return map[status || "none"] || status || "未处置";
  }

  function dispositionClass(status) {
    return status || "none";
  }

  function filteredProfiles(rows) {
    rows = rows || [];
    return rows.filter(function (row) {
      var disposition = (row.disposition || {}).status || "none";
      if (state.filters.risk && riskKey(row.risk_level) !== state.filters.risk) return false;
      if (state.filters.disposition && disposition !== state.filters.disposition) return false;
      return true;
    });
  }

  function selectedUser() {
    var rows = []
      .concat((state.data && state.data.user_profiles) || [])
      .concat((state.data && state.data.high_risk_profiles) || [])
      .concat((state.data && state.data.watch_profiles) || [])
      .concat((state.data && state.data.blacklist_profiles) || []);
    if (!state.selectedUserId) return null;
    for (var i = 0; i < rows.length; i += 1) {
      if (String(rows[i].user_id || "") === String(state.selectedUserId)) return rows[i];
    }
    return null;
  }

  function listText(values) {
    values = values || [];
    return values.length ? values.join(" / ") : "-";
  }

  function renderStats(summary) {
    summary = summary || {};
    var riskSummary = (state.data && state.data.risk_summary) || {};
    var risky = (riskSummary.medium || 0) + (riskSummary.high || 0) + (riskSummary.critical || 0);
    var high = (riskSummary.high || 0) + (riskSummary.critical || 0);
    var stats = [
      ["今日请求", summary.today || 0, "当天所有订阅/配置拉取"],
      ["风险账号", risky, "中风险及以上账号"],
      ["高危账号", high, "高风险和极危险账号"],
      ["独立 IP", summary.unique_ips || 0, "按真实来源 IP 去重"]
    ];
    return stats.map(function (item) {
      return [
        "<div class=\"smm-card smm-span-3 smm-stat\">",
        "<div class=\"smm-stat-label\">" + escapeHtml(item[0]) + "</div>",
        "<div class=\"smm-stat-value\">" + escapeHtml(item[1]) + "</div>",
        "<div class=\"smm-stat-foot\">" + escapeHtml(item[2]) + "</div>",
        "</div>"
      ].join("");
    }).join("");
  }

  function renderMiniList(title, rows, renderRow) {
    rows = rows || [];
    return [
      "<div class=\"smm-card smm-span-4\">",
      "<div class=\"smm-card-head\"><div class=\"smm-card-title\">" + escapeHtml(title) + "</div></div>",
      "<div class=\"smm-card-body\">",
      rows.length ? "<div class=\"smm-mini-list\">" + rows.map(renderRow).join("") + "</div>" : "<div class=\"smm-empty\">暂无数据</div>",
      "</div>",
      "</div>"
    ].join("");
  }

  function miniRow(main, sub, pill) {
    return [
      "<div class=\"smm-mini-row\">",
      "<div><div class=\"smm-main-text\">" + escapeHtml(main || "-") + "</div><div class=\"smm-sub-text\">" + escapeHtml(sub || "") + "</div></div>",
      "<div class=\"smm-pill\">" + escapeHtml(pill || "0") + "</div>",
      "</div>"
    ].join("");
  }

  function renderInsights(data) {
    data = data || {};
    var riskSummary = data.risk_summary || {};
    return [
      "<div class=\"smm-card smm-span-12 smm-overview-card\">",
      "<div class=\"smm-card-head\" style=\"height:auto;padding:0 0 12px;border-bottom:0;\"><div><div class=\"smm-card-title\">风险概览</div><div class=\"smm-muted\">优先看账号风险和核心证据，短期刷新订阅只作为辅助信号。</div></div><button id=\"smm-toggle-system\" class=\"smm-btn smm-system-toggle\">" + (state.showSystemStatus ? "收起系统状态" : "系统状态") + "</button><button id=\"smm-rebuild-snapshots\" class=\"smm-btn smm-btn-primary\">重算风险快照</button></div>",
      "<div class=\"smm-overview-row\">",
      [
        { label: "极危险", sub: "必须人工复核", value: riskSummary.critical || 0 },
        { label: "高风险", sub: "建议加入观察", value: riskSummary.high || 0 },
        { label: "中风险", sub: "持续观察", value: riskSummary.medium || 0 },
        { label: "无风险", sub: "当前规则下正常", value: riskSummary.safe || 0 }
      ].map(function (row) {
        return "<div class=\"smm-overview-item\"><div class=\"smm-overview-num\">" + escapeHtml(row.value) + "</div><div class=\"smm-overview-label\">" + escapeHtml(row.label) + "</div><div class=\"smm-overview-sub\">" + escapeHtml(row.sub) + "</div></div>";
      }).join(""),
      "</div>",
      renderSystemStatus(data),
      "</div>"
    ].join("");
  }

  function renderSystemStatus(data) {
    data = data || {};
    var snapshot = data.snapshot_status || {};
    var ip = data.ip_intelligence_status || {};
    var retention = data.retention_policy || {};
    var overview = data.profile_overview || {};
    var items = [
      {
        value: snapshot.enabled ? (snapshot.count || 0) : "-",
        label: "风险快照缓存",
        sub: "账号 " + escapeHtml(snapshot.users || 0) + " / 最近 " + escapeHtml(formatTime(snapshot.latest_at)) + " / 保留 " + escapeHtml(retention.risk_snapshot_days || snapshot.retention_days || "-") + " 天"
      },
      {
        value: ip.enabled ? (ip.intelligence_count || 0) : "-",
        label: "IP 情报命中",
        sub: "缓存 " + escapeHtml(ip.cache_count || 0) + " / ASN " + escapeHtml(ip.asn_count || 0) + " / IDC " + escapeHtml(ip.idc_count || 0) + " / VPN " + escapeHtml(ip.vpn_count || 0) + " / 未命中 " + escapeHtml(ip.miss_count || 0)
      },
      {
        value: ip.database_enabled ? "可用" : "缺失",
        label: "IP 库状态",
        sub: "更新时间 " + escapeHtml(formatTime(ip.database_mtime || ip.latest_at)) + " / 大小 " + escapeHtml(formatBytes(ip.database_size || 0)) + " / 缓存保留 " + escapeHtml(retention.ip_cache_days || ip.retention_days || "-") + " 天"
      },
      {
        value: retention.access_log_days || "-",
        label: "记录清理策略",
        sub: "原始记录 " + escapeHtml(retention.access_log_days || "-") + " 天 / IP缓存 " + escapeHtml(retention.ip_cache_days || "-") + " 天 / 处置日志长期保留"
      },
      {
        value: overview.global ? "全量" : "当前页",
        label: "画像统计口径",
        sub: escapeHtml(overview.message || "顶部统计与队列会优先使用风险快照缓存。")
      }
    ];
    return [
      "<div class=\"smm-system-status" + (state.showSystemStatus ? " open" : "") + "\">",
      items.map(function (item) {
        return "<div class=\"smm-card smm-cache-item\"><div class=\"smm-cache-main\">" + escapeHtml(item.value) + "</div><div class=\"smm-cache-label\">" + escapeHtml(item.label) + "</div><div class=\"smm-cache-sub\">" + item.sub + "</div></div>";
      }).join(""),
      "</div>"
    ].join("");
  }

  function renderUserProfiles(rows) {
    rows = filteredProfiles(rows);
    if (!rows.length) {
      return "<div class=\"smm-empty\">暂无账号画像</div>";
    }
    return [
      "<div class=\"smm-table-wrap\"><table class=\"smm-table\"><thead><tr>",
      "<th style=\"width:13%;\">账号</th><th style=\"width:9%;\">风险等级</th><th style=\"width:28%;\">主要原因</th><th style=\"width:14%;\">订阅 / 流量</th><th style=\"width:14%;\">IP / 入口</th><th style=\"width:8%;\">处置</th><th style=\"width:10%;\">最后拉取</th><th style=\"width:4%;\">操作</th>",
      "</tr></thead><tbody>",
      rows.map(function (row) {
        var key = String(row.user_id || "");
        var behavior = row.behavior || {};
        var geo = behavior.geo || {};
        var host = behavior.host || {};
        var client = behavior.client || {};
        var traffic = behavior.traffic || {};
        var disposition = row.disposition || {};
        var explain = row.risk_explain || {};
        var reasons = (row.risk_signals || []).filter(function (signal) {
          return Number(signal.score || 0) > 0;
        }).slice(0, 3).map(function (signal) {
          return signal.label || signal.code;
        });
        var discounts = (row.risk_signals || []).filter(function (signal) {
          return Number(signal.score || 0) < 0;
        }).slice(0, 2).map(function (signal) {
          return signal.label || signal.code;
        });
        var selected = String(state.selectedUserId || "") === key;
        return [
          "<tr" + (selected ? " class=\"is-selected\"" : "") + ">",
          "<td><div>" + escapeHtml(row.email || "-") + "</div><div class=\"smm-muted\">ID " + escapeHtml(row.user_id || "-") + " / 套餐 " + escapeHtml(row.plan_id || "-") + "</div></td>",
          "<td><span class=\"smm-risk " + riskClass(row.risk_level) + "\">" + escapeHtml(row.risk_level || "无风险") + " " + escapeHtml(row.risk_score || 0) + "</span></td>",
          "<td><div>" + escapeHtml(explain.summary || (reasons.length ? reasons.join(" / ") : "未命中核心风险")) + "</div><div class=\"smm-muted\">" + escapeHtml(discounts.length ? ("抵扣 " + discounts.join(" / ")) : (explain.suggestion || "无抵扣")) + "</div></td>",
          "<td><div>拉取 " + escapeHtml(row.total || 0) + " 次</div><div class=\"smm-muted\">增长 " + escapeHtml(formatBytes(traffic.used_delta || 0)) + " / 已用 " + escapeHtml(formatBytes(traffic.latest_used || row.traffic_used || 0)) + "</div></td>",
          "<td><div>IP " + escapeHtml(row.ips || 0) + " / 入口 " + escapeHtml(row.hosts || 0) + "</div><div class=\"smm-muted\">" + escapeHtml(listText((geo.countries || []).slice(0, 2))) + " / 客户端 " + escapeHtml(row.agents || 0) + "</div></td>",
          "<td><span class=\"smm-disposition " + dispositionClass(disposition.status) + "\">" + escapeHtml(disposition.label || dispositionLabel(disposition.status)) + "</span></td>",
          "<td><div>" + escapeHtml(formatTime(row.last_seen)) + "</div><div class=\"smm-muted\">首次 " + escapeHtml(formatTime(row.first_seen)) + "</div></td>",
          "<td><div class=\"smm-table-actions\"><button class=\"smm-btn smm-user-detail\" data-user=\"" + escapeHtml(key) + "\">详情</button></div></td>",
          "</tr>"
        ].join("");
      }).join(""),
      "</tbody></table></div>"
    ].join("");
  }

  function dispositionRows(rows, statuses) {
    rows = rows || [];
    return rows.filter(function (row) {
      var status = (row.disposition || {}).status || "none";
      return statuses.indexOf(status) >= 0;
    });
  }

  function renderDispositionQueue(title, rows, emptyText) {
    rows = rows || [];
    var isReviewQueue = title.indexOf("待复核") >= 0;
    var clearStatus = isReviewQueue ? "handled" : "none";
    var actions = rows.length
      ? "<button class=\"smm-btn smm-bulk-handled\" data-queue=\"" + escapeHtml(title) + "\">批量已处理</button><button class=\"smm-btn smm-bulk-clear\" data-clear-status=\"" + escapeHtml(clearStatus) + "\" data-queue=\"" + escapeHtml(title) + "\">批量移出队列</button>"
      : "";
    return [
      "<div class=\"smm-queue-subhead\">",
      "<div class=\"smm-card-head-main\"><div class=\"smm-card-title\">" + escapeHtml(title) + "</div><div class=\"smm-muted\">" + (isReviewQueue ? "仅显示手动观察或极危险 / 高分待复核账号" : "人工建议拉黑账号") + "</div></div>",
      "<div class=\"smm-queue-actions\">" + actions + "<div class=\"smm-count-pill\">" + escapeHtml(rows.length) + " 个账号</div></div>",
      "</div>",
      rows.length ? [
        "<div class=\"smm-table-wrap\"><table class=\"smm-queue-table\"><thead><tr>",
        "<th style=\"width:24%;\">账号</th><th style=\"width:14%;\">风险</th><th style=\"width:16%;\">处置</th><th style=\"width:18%;\">行为</th><th style=\"width:14%;\">最后拉取</th><th style=\"width:14%;\">操作</th>",
        "</tr></thead><tbody>",
        rows.slice(0, 12).map(function (row) {
          var disposition = row.disposition || {};
          var overdue = row.disposition_overdue || {};
          var key = String(row.user_id || "");
          var ageDays = overdue.days != null ? overdue.days : dispositionAgeDays(disposition);
          var ageText = isReviewQueue ? "待复核 " + ageDays + " 天" : "处置 " + ageDays + " 天";
          return [
            "<tr>",
            "<td><div class=\"smm-main-text\">" + escapeHtml(row.email || "-") + "</div><div class=\"smm-sub-text\">ID " + escapeHtml(row.user_id || "-") + " / 套餐 " + escapeHtml(row.plan_id || "-") + "</div></td>",
            "<td><span class=\"smm-risk " + riskClass(row.risk_level) + "\">" + escapeHtml(row.risk_level || "无风险") + " " + escapeHtml(row.risk_score || 0) + "</span></td>",
            "<td><span class=\"smm-disposition " + dispositionClass(disposition.status) + "\">" + escapeHtml(disposition.label || dispositionLabel(disposition.status)) + "</span><div class=\"smm-muted\">" + escapeHtml(ageText) + "</div>" + (overdue.overdue ? "<div class=\"smm-overdue\">超过 " + escapeHtml(overdue.threshold_days || state.filters.watch_overdue_days) + " 天未复核</div>" : "") + "</td>",
            "<td><div>" + escapeHtml(row.total || 0) + " 次</div><div class=\"smm-muted\">IP " + escapeHtml(row.ips || 0) + " / 入口 " + escapeHtml(row.hosts || 0) + "</div></td>",
            "<td>" + escapeHtml(formatTime(row.last_seen)) + "</td>",
            "<td><div class=\"smm-table-actions\"><button class=\"smm-btn smm-user-detail\" data-user=\"" + escapeHtml(key) + "\">详情</button><button class=\"smm-btn smm-queue-remove\" data-remove-status=\"" + escapeHtml(clearStatus) + "\" data-user=\"" + escapeHtml(key) + "\">移出</button>" + (isReviewQueue ? "<button class=\"smm-btn smm-clear-profile-row\" data-user=\"" + escapeHtml(key) + "\">清除画像</button>" : "") + "</div></td>",
            "</tr>"
          ].join("");
        }).join(""),
        "</tbody></table></div>"
      ].join("") : "<div class=\"smm-empty\">" + escapeHtml(emptyText || "暂无账号") + "</div>",
    ].join("");
  }

  function renderDispositionQueues(rows) {
    var watchRows = (state.data && state.data.watch_profiles) || dispositionRows(rows || [], ["watch"]);
    var blacklistRows = (state.data && state.data.blacklist_profiles) || dispositionRows(rows || [], ["blacklist_suggested"]);
    var activeRows = state.queueTab === "blacklist" ? blacklistRows : watchRows;
    var activeTitle = state.queueTab === "blacklist" ? "建议拉黑列表" : "待复核列表";
    return [
      "<div class=\"smm-card smm-queue-card\">",
      "<div class=\"smm-card-head smm-queue-head\"><div class=\"smm-card-head-main\"><div class=\"smm-card-title\">人工处置队列</div><div class=\"smm-muted\">中风险和普通高风险不会自动进入待复核列表</div></div><div class=\"smm-queue-tabs\"><button class=\"smm-queue-tab " + (state.queueTab === "watch" ? "active" : "") + "\" data-queue-tab=\"watch\">待复核 " + escapeHtml(watchRows.length) + "</button><button class=\"smm-queue-tab " + (state.queueTab === "blacklist" ? "active" : "") + "\" data-queue-tab=\"blacklist\">建议拉黑 " + escapeHtml(blacklistRows.length) + "</button></div></div>",
      renderDispositionQueue(activeTitle, activeRows, state.queueTab === "blacklist" ? "暂无建议拉黑的账号" : "暂无待复核账号"),
      "</div>"
    ].join("");
  }

  function renderMetricStrip(row) {
    var behavior = row.behavior || {};
    var windows = behavior.windows || {};
    var client = behavior.client || {};
    var host = behavior.host || {};
    var share = behavior.share || {};
    var geo = behavior.geo || {};
    var traffic = behavior.traffic || {};
    return [
      "<div class=\"smm-metric-strip\">",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(windows.last_10m || 0) + "</strong><span>近10分钟</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(windows.last_1h || 0) + "</strong><span>近1小时</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(windows.today || 0) + "</strong><span>今日请求</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(windows.max_per_minute || 0) + "</strong><span>分钟峰值</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(windows.min_interval ? windows.min_interval + "s" : "-") + "</strong><span>最短间隔</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(share.max_ip_users || 0) + "</strong><span>同IP账号峰值</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(client.suspicious_agent_count || 0) + "</strong><span>可疑客户端</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(client.empty_agent_hits || 0) + "</strong><span>空UA请求</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(host.risk_host_count || 0) + "</strong><span>高风险入口</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(host.unknown_host_count || 0) + "</strong><span>非可信入口</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(formatBytes(traffic.latest_used || 0)) + "</strong><span>当前已用流量</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(formatBytes(traffic.used_delta || 0)) + "</strong><span>观察期流量增长</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(geo.country_count || 0) + "</strong><span>国家/地区</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(geo.isp_count || 0) + "</strong><span>运营商</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(geo.asn_count || 0) + "</strong><span>ASN</span></div>",
      "<div class=\"smm-metric-box\"><strong>" + escapeHtml(geo.network_type_count || 0) + "</strong><span>网络类型</span></div>",
      "</div>"
    ].join("");
  }

  function renderSignalList(row) {
    var signals = row.risk_signals || [];
    var explain = row.risk_explain || {};
    var explainHtml = [
      "<div class=\"smm-signal-list\">",
      "<div class=\"smm-signal\"><div class=\"smm-signal-main\">判断结论</div><div class=\"smm-signal-sub\">" + escapeHtml(explain.summary || "暂无判断结论") + "</div></div>",
      "<div class=\"smm-signal\"><div class=\"smm-signal-main\">建议动作</div><div class=\"smm-signal-sub\">" + escapeHtml(explain.suggestion || "暂无建议") + "</div></div>",
      "</div>"
    ].join("");
    if (!signals.length) {
      return explainHtml + "<div class=\"smm-empty\" style=\"padding:16px;\">当前规则下未命中风险信号</div>";
    }
    return [
      explainHtml,
      "<div class=\"smm-signal-list\">",
      signals.map(function (signal) {
        var score = Number(signal.score || 0);
        var scoreText = score > 0 ? ("+" + score) : String(score);
        return "<div class=\"smm-signal\"><div class=\"smm-signal-main\">" + escapeHtml(signal.label || signal.code) + " " + escapeHtml(scoreText) + "</div><div class=\"smm-signal-sub\">" + escapeHtml(signal.value == null ? "" : signal.value) + "</div></div>";
      }).join(""),
      "</div>"
    ].join("");
  }

  function renderRecentTable(rows) {
    rows = rows || [];
    if (!rows.length) return "<div class=\"smm-empty\">暂无拉取明细</div>";
    return [
      "<div class=\"smm-table-wrap\"><table class=\"smm-detail-table\"><thead><tr>",
      "<th>时间</th><th>方式</th><th>入口</th><th>网络/IP</th><th>设备/客户端</th><th>状态</th>",
      "</tr></thead><tbody>",
      rows.map(function (item) {
        return [
          "<tr>",
          "<td>" + escapeHtml(formatTime(item.created_at)) + "</td>",
          "<td><span class=\"smm-pill\">" + escapeHtml(typeLabel(item.subscribe_type)) + "</span><div class=\"smm-muted\">" + escapeHtml(item.flag || "") + "</div></td>",
          "<td><div>" + escapeHtml(item.request_host || "-") + "</div><div class=\"smm-muted\">" + escapeHtml(item.request_path || "") + "</div></td>",
          "<td><span class=\"smm-code\">" + escapeHtml(item.client_ip || "-") + "</span><div class=\"smm-muted\">" + escapeHtml(item.real_ip_source || "") + "</div><div class=\"smm-muted\">" + escapeHtml(regionText(item.ip_region)) + "</div></td>",
          "<td><div class=\"smm-muted\" style=\"max-width:320px;line-height:1.5;\">" + escapeHtml(item.user_agent || "-") + "</div></td>",
          "<td>" + escapeHtml(statusText(item.status)) + "</td>",
          "</tr>"
        ].join("");
      }).join(""),
      "</tbody></table></div>"
    ].join("");
  }

  function renderRiskTimeline(rows) {
    rows = rows || [];
    if (!rows.length) return "<div class=\"smm-empty\">暂无风险变化时间线</div>";
    return [
      "<div class=\"smm-timeline\">",
      rows.map(function (item) {
        var signals = (item.signals || []).map(function (signal) {
          return (signal.label || signal.code || "") + (signal.score ? " +" + signal.score : "");
        }).filter(Boolean).slice(0, 4).join(" / ");
        return [
          "<div class=\"smm-timeline-item\">",
          "<div class=\"smm-timeline-head\"><span class=\"smm-risk " + riskClass(item.risk_level) + "\">" + escapeHtml(item.risk_level || "无风险") + " " + escapeHtml(item.risk_score || 0) + "</span><span class=\"smm-disposition " + dispositionClass(item.disposition_status) + "\">" + escapeHtml(item.disposition_label || dispositionLabel(item.disposition_status)) + "</span></div>",
          "<div class=\"smm-timeline-meta\">快照 " + escapeHtml(formatTime(item.snapshot_at)) + " / 请求 " + escapeHtml(item.request_total || 0) + " / IP " + escapeHtml(item.ip_count || 0) + " / 入口 " + escapeHtml(item.host_count || 0) + "</div>",
          "<div class=\"smm-timeline-meta\">命中信号：" + escapeHtml(signals || "无") + "</div>",
          "</div>"
        ].join("");
      }).join(""),
      "</div>"
    ].join("");
  }

  function dispositionAgeDays(disposition) {
    disposition = disposition || {};
    var start = Number(disposition.created_at || disposition.updated_at || 0);
    if (!start) return 0;
    return Math.max(0, Math.floor((Date.now() / 1000 - start) / 86400));
  }

  function renderDispatchPreview(preview) {
    preview = preview || {};
    if (preview.loading) {
      return "<div class=\"smm-empty\">正在读取当前下发预览...</div>";
    }
    if (preview.error) {
      return "<div class=\"smm-empty\">" + escapeHtml(preview.error) + "</div>";
    }
    var rows = preview.nodes || [];
    return [
      "<div class=\"smm-section-title\">当前下发预览</div>",
      "<div class=\"smm-signal-list\">",
      "<div class=\"smm-signal\"><div class=\"smm-signal-main\">订阅地址</div><div class=\"smm-signal-sub\">" + escapeHtml(preview.subscribe_url || "-") + "</div></div>",
      "<div class=\"smm-signal\"><div class=\"smm-signal-main\">命中状态</div><div class=\"smm-signal-sub\">风险 " + escapeHtml(preview.risk_level || "无风险") + " " + escapeHtml(preview.risk_score || 0) + " / 处置 " + escapeHtml(dispositionLabel(preview.disposition_status)) + "</div></div>",
      "<div class=\"smm-signal\"><div class=\"smm-signal-main\">节点统计</div><div class=\"smm-signal-sub\">总 " + escapeHtml(preview.total_nodes || 0) + " / 替换 " + escapeHtml(preview.changed_nodes || 0) + " / 隐藏 " + escapeHtml(preview.hidden_nodes || 0) + "</div></div>",
      "</div>",
      rows.length ? [
        "<div class=\"smm-preview-list\">",
        rows.map(function (item) {
          var source = item.group_name ? ("入口组 " + item.group_name) : (item.rule_name ? ("规则 " + item.rule_name) : item.action_label);
          return [
            "<div class=\"smm-preview-row\">",
            "<div class=\"smm-preview-head\"><div><strong>" + escapeHtml(item.name || ("节点 " + item.server_id)) + "</strong><span class=\"smm-muted\"> " + escapeHtml(item.server_type || "") + " #" + escapeHtml(item.server_id || "-") + "</span></div><span class=\"smm-pill\">" + escapeHtml(item.hidden ? "隐藏" : (item.action_label || "保持原入口")) + "</span></div>",
            "<div class=\"smm-preview-path\">原始 " + escapeHtml(item.original_host || "-") + ":" + escapeHtml(item.original_port || "-") + " -> 下发 " + escapeHtml(item.final_host || "-") + ":" + escapeHtml(item.final_port || "-") + "</div>",
            "<div class=\"smm-preview-path\">命中来源：" + escapeHtml(source || "-") + "</div>",
            "</div>"
          ].join("");
        }).join(""),
        "</div>"
      ].join("") : "<div class=\"smm-empty\">暂无可下发节点预览</div>"
    ].join("");
  }

  function renderDrawerTab(row) {
    var behavior = row.behavior || {};
    var geo = behavior.geo || {};
    var client = behavior.client || {};
    var host = behavior.host || {};
    var share = behavior.share || {};
    var traffic = behavior.traffic || {};
    if (state.drawerTab === "ip") {
      return [
        renderMetricStrip(row),
        "<div class=\"smm-section-title\">IP 画像</div>",
        "<div class=\"smm-signal-list\">",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">国家/地区</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(geo.countries)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">省份/区域</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(geo.regions)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">城市</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(geo.cities)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">运营商</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(geo.isps)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">ASN</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(geo.asns)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">AS 名称</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(geo.as_names)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">网络类型</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(geo.network_types)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">代理/VPN/风险类型</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(geo.ip_risk_types)) + "</div></div>",
        (share.shared_ips || []).map(function (item) {
          return "<div class=\"smm-signal\"><div class=\"smm-signal-main\">同一 IP 关联 " + escapeHtml(item.client_ip || "-") + "</div><div class=\"smm-signal-sub\">账号 " + escapeHtml(item.users || 0) + " / Token " + escapeHtml(item.tokens || 0) + "</div></div>";
        }).join(""),
        "</div>"
      ].join("");
    }
    if (state.drawerTab === "logs") {
      return renderRecentTable(row.recent);
    }
    if (state.drawerTab === "timeline") {
      return renderRiskTimeline(row.risk_timeline);
    }
    if (state.drawerTab === "dispatch") {
      return renderDispatchPreview(row.dispatch_preview);
    }
    if (state.drawerTab === "traffic") {
      return [
        "<div class=\"smm-section-title\">流量使用行为</div>",
        "<div class=\"smm-signal-list\">",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">订阅拉取次数</div><div class=\"smm-signal-sub\">" + escapeHtml(traffic.pulls || row.total || 0) + " 次</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">首次已用流量</div><div class=\"smm-signal-sub\">" + escapeHtml(formatBytes(traffic.first_used || 0)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">当前已用流量</div><div class=\"smm-signal-sub\">" + escapeHtml(formatBytes(traffic.latest_used || 0)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">观察期流量增长</div><div class=\"smm-signal-sub\">" + escapeHtml(formatBytes(traffic.used_delta || 0)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">总流量</div><div class=\"smm-signal-sub\">" + escapeHtml(formatBytes(traffic.traffic_total || row.traffic_total || 0)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">使用比例</div><div class=\"smm-signal-sub\">" + escapeHtml(((traffic.usage_ratio || 0) * 100).toFixed(2)) + "%</div></div>",
        "</div>",
        renderMetricStrip(row)
      ].join("");
    }
    if (state.drawerTab === "entry") {
      return [
        "<div class=\"smm-section-title\">入口与客户端</div>",
        "<div class=\"smm-signal-list\">",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">可信入口</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(host.trusted_hosts)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">观察入口</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(host.watch_hosts)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">高风险入口</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(host.risk_hosts)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">非可信入口</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(host.unknown_hosts)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">可信客户端</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(client.trusted_agents)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">可疑客户端</div><div class=\"smm-signal-sub\">" + escapeHtml(listText(client.suspicious_agents)) + "</div></div>",
        "</div>"
      ].join("");
    }
    if (state.drawerTab === "actions") {
      var disposition = row.disposition || {};
      var logs = row.disposition_logs || [];
      return [
        "<div class=\"smm-section-title\">人工处置</div>",
        "<div class=\"smm-signal-list\">",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">当前状态</div><div class=\"smm-signal-sub\">" + escapeHtml(disposition.label || dispositionLabel(disposition.status)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">操作人</div><div class=\"smm-signal-sub\">" + escapeHtml(disposition.operator_email || "-") + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">更新时间</div><div class=\"smm-signal-sub\">" + escapeHtml(formatTime(disposition.updated_at)) + "</div></div>",
        "<div class=\"smm-signal\"><div class=\"smm-signal-main\">备注</div><div class=\"smm-signal-sub\">" + escapeHtml(disposition.note || "-") + "</div></div>",
        "</div>",
        "<textarea id=\"smm-action-note\" class=\"smm-action-note\" placeholder=\"填写本次处置备注，方便后续复盘\"></textarea>",
        "<div class=\"smm-drawer-actions\" style=\"margin:0 0 16px;\">",
        "<button class=\"smm-btn smm-action\" data-action=\"watch\">加入观察</button>",
        "<button class=\"smm-btn smm-action\" data-action=\"none\">移出观察</button>",
        "<button class=\"smm-btn smm-action\" data-action=\"handled\">标记已处理</button>",
        "<button class=\"smm-btn smm-action\" data-action=\"whitelist\">白名单</button>",
        "<button class=\"smm-btn smm-action\" data-action=\"freeze_suggested\">建议冻结</button>",
        "<button class=\"smm-btn smm-btn-danger smm-action\" data-action=\"blacklist_suggested\">建议拉黑</button>",
        "<button class=\"smm-btn smm-btn-danger smm-clear-profile\">清除行为画像</button>",
        "</div>",
        "<div class=\"smm-section-title\">处置记录</div>",
        logs.length ? [
          "<div class=\"smm-table-wrap\"><table class=\"smm-detail-table\"><thead><tr><th>时间</th><th>动作</th><th>风险</th><th>操作人</th><th>备注</th></tr></thead><tbody>",
          logs.map(function (log) {
            return [
              "<tr>",
              "<td>" + escapeHtml(formatTime(log.created_at)) + "</td>",
              "<td>" + escapeHtml(dispositionLabel(log.to_status || log.action)) + "</td>",
              "<td>" + escapeHtml(log.risk_level || "-") + " " + escapeHtml(log.risk_score == null ? "" : log.risk_score) + "</td>",
              "<td>" + escapeHtml(log.operator_email || "-") + "</td>",
              "<td>" + escapeHtml(log.note || "-") + "</td>",
              "</tr>"
            ].join("");
          }).join(""),
          "</tbody></table></div>"
        ].join("") : "<div class=\"smm-empty\">暂无处置记录</div>"
      ].join("");
    }
    return [
      renderSignalList(row),
      "<div class=\"smm-section-title\">行为指标</div>",
      renderMetricStrip(row)
    ].join("");
  }

  function renderUserDrawer() {
    var row = selectedUser();
    if (!row) return "";
    var tabs = [
      ["signals", "风险信号"],
      ["ip", "IP画像"],
      ["traffic", "流量行为"],
      ["dispatch", "下发预览"],
      ["timeline", "风险变化时间线"],
      ["logs", "拉取明细"],
      ["entry", "入口客户端"],
      ["actions", "处置记录"]
    ];
    return [
      "<div class=\"smm-drawer-mask\" id=\"smm-drawer-mask\">",
	      "<div class=\"smm-drawer\">",
	      "<div class=\"smm-drawer-head\"><div class=\"smm-drawer-top\"><div><div class=\"smm-drawer-title\">" + escapeHtml(row.email || "-") + "</div><div class=\"smm-drawer-meta\">UID " + escapeHtml(row.user_id || "-") + " / 套餐 " + escapeHtml(row.plan_id || "-") + " / 最近拉取 " + escapeHtml(formatTime(row.last_seen)) + "</div></div><div class=\"smm-drawer-state\"><span class=\"smm-risk " + riskClass(row.risk_level) + "\">" + escapeHtml(row.risk_level || "无风险") + " " + escapeHtml(row.risk_score || 0) + "</span><span class=\"smm-disposition " + dispositionClass((row.disposition || {}).status) + "\">" + escapeHtml(((row.disposition || {}).label) || dispositionLabel((row.disposition || {}).status)) + "</span><button id=\"smm-drawer-close\" class=\"smm-btn\">关闭</button></div></div></div>",
	      row.behavior ? "" : "<div class=\"smm-empty\">当前账号来自全局队列快照。完整拉取明细请在账号风险列表当前页打开，或重新查询定位该账号。</div>",
	      "<div class=\"smm-drawer-tabs\">" + tabs.map(function (tab) {
        return "<button class=\"smm-tab" + (state.drawerTab === tab[0] ? " active" : "") + "\" data-tab=\"" + escapeHtml(tab[0]) + "\">" + escapeHtml(tab[1]) + "</button>";
      }).join("") + "</div>",
      "<div class=\"smm-drawer-body\">" + renderDrawerTab(row) + "</div>",
      "</div></div>"
    ].join("");
  }

  function ruleInput(id, label, value, wide) {
    return [
      "<div class=\"smm-rule-field" + (wide ? " wide" : "") + "\">",
      "<div class=\"smm-rule-label\">" + escapeHtml(label) + "</div>",
      "<input id=\"" + escapeHtml(id) + "\" class=\"smm-rule-input\" type=\"number\" min=\"0\" max=\"999\" value=\"" + escapeHtml(value) + "\">",
      "</div>"
    ].join("");
  }

  function ruleTextarea(id, label, value) {
    value = Array.isArray(value) ? value.join("\n") : (value || "");
    return [
      "<div class=\"smm-rule-field full\">",
      "<div class=\"smm-rule-label\">" + escapeHtml(label) + "</div>",
      "<textarea id=\"" + escapeHtml(id) + "\" class=\"smm-rule-textarea\" spellcheck=\"false\">" + escapeHtml(value) + "</textarea>",
      "</div>"
    ].join("");
  }

  function tierValue(rules, key, index, field) {
    return (((rules[key] || {}).tiers || [])[index] || {})[field] || 0;
  }

  function renderRuleGroup(key, html) {
    return "<div class=\"smm-rule-group" + (state.ruleTab === key ? " active" : "") + "\">" + html + "</div>";
  }

  function renderRulePanel() {
    if (!state.showRules) return "";
    var rules = riskRules();
    var tabs = [
      ["levels", "风险等级"],
      ["frequency", "请求频率"],
      ["geo", "IP/地区"],
      ["traffic", "流量行为"],
      ["network", "网络情报"],
      ["client", "客户端"],
      ["host", "入口域名"],
      ["share", "共享行为"]
    ];
    return [
      "<div class=\"smm-rule-panel\"><div class=\"smm-rule-dialog\">",
      "<div class=\"smm-card-head\"><div class=\"smm-card-title\">风险规则</div><div class=\"smm-muted\">阈值越低越敏感，分数线决定风险等级</div></div>",
      "<div class=\"smm-rule-tabs\">" + tabs.map(function (tab) {
        return "<button class=\"smm-tab" + (state.ruleTab === tab[0] ? " active" : "") + "\" data-rule-tab=\"" + escapeHtml(tab[0]) + "\">" + escapeHtml(tab[1]) + "</button>";
      }).join("") + "</div>",
      "<div class=\"smm-rule-grid\">",
      renderRuleGroup("levels", [
        ruleInput("smm_level_medium", "中风险分数线", rules.levels.medium),
        ruleInput("smm_level_high", "高风险分数线", rules.levels.high),
        ruleInput("smm_level_critical", "极危险分数线", rules.levels.critical),
        ruleInput("smm_queue_watch_score", "待复核分数线", rules.queue.watch_score),
        ruleInput("smm_request_t1", "请求阈值 1", tierValue(rules, "request", 0, "threshold")),
        ruleInput("smm_request_s1", "请求加分 1", tierValue(rules, "request", 0, "score")),
        ruleInput("smm_request_t2", "请求阈值 2", tierValue(rules, "request", 1, "threshold")),
        ruleInput("smm_request_s2", "请求加分 2", tierValue(rules, "request", 1, "score")),
        ruleInput("smm_request_t3", "请求阈值 3", tierValue(rules, "request", 2, "threshold")),
        ruleInput("smm_request_s3", "请求加分 3", tierValue(rules, "request", 2, "score")),
        ruleInput("smm_failure_each", "失败每次加分", rules.failure.score_each),
        ruleInput("smm_failure_max", "失败最高加分", rules.failure.max_score)
      ].join("")),
      renderRuleGroup("frequency", [
        ruleInput("smm_freq_10m_t", "近10分钟阈值", rules.frequency.last_10m.threshold),
        ruleInput("smm_freq_10m_s", "近10分钟加分", rules.frequency.last_10m.score),
        ruleInput("smm_freq_1h_t", "近1小时阈值", rules.frequency.last_1h.threshold),
        ruleInput("smm_freq_1h_s", "近1小时加分", rules.frequency.last_1h.score),
        ruleInput("smm_freq_today_t", "今日请求阈值", rules.frequency.today.threshold),
        ruleInput("smm_freq_today_s", "今日请求加分", rules.frequency.today.score),
        ruleInput("smm_freq_minute_t", "分钟突发阈值", rules.frequency.max_per_minute.threshold),
        ruleInput("smm_freq_minute_s", "分钟突发加分", rules.frequency.max_per_minute.score),
        ruleInput("smm_freq_interval_sec", "最短间隔秒数", rules.frequency.min_interval.seconds),
        ruleInput("smm_freq_interval_score", "间隔过短加分", rules.frequency.min_interval.score)
      ].join("")),
      renderRuleGroup("geo", [
        ruleInput("smm_ip_t1", "IP 阈值 1", tierValue(rules, "ip", 0, "threshold")),
        ruleInput("smm_ip_s1", "IP 加分 1", tierValue(rules, "ip", 0, "score")),
        ruleInput("smm_ip_t2", "IP 阈值 2", tierValue(rules, "ip", 1, "threshold")),
        ruleInput("smm_ip_s2", "IP 加分 2", tierValue(rules, "ip", 1, "score")),
        ruleInput("smm_ip_t3", "IP 阈值 3", tierValue(rules, "ip", 2, "threshold")),
        ruleInput("smm_ip_s3", "IP 加分 3", tierValue(rules, "ip", 2, "score")),
        ruleInput("smm_geo_countries_t", "多国家阈值", rules.geo.countries.threshold),
        ruleInput("smm_geo_countries_s", "多国家加分", rules.geo.countries.score),
        ruleInput("smm_geo_regions_t", "多省份阈值", rules.geo.regions.threshold),
        ruleInput("smm_geo_regions_s", "多省份加分", rules.geo.regions.score),
        ruleInput("smm_geo_cities_t", "多城市阈值", rules.geo.cities.threshold),
        ruleInput("smm_geo_cities_s", "多城市加分", rules.geo.cities.score),
        ruleInput("smm_geo_isps_t", "多运营商阈值", rules.geo.isps.threshold),
        ruleInput("smm_geo_isps_s", "多运营商加分", rules.geo.isps.score),
        ruleInput("smm_geo_asns_t", "多 ASN 阈值", rules.geo.asns.threshold),
        ruleInput("smm_geo_asns_s", "多 ASN 加分", rules.geo.asns.score),
        ruleInput("smm_geo_network_types_t", "多网络类型阈值", rules.geo.network_types.threshold),
        ruleInput("smm_geo_network_types_s", "多网络类型加分", rules.geo.network_types.score)
      ].join("")),
      renderRuleGroup("traffic", [
        ruleInput("smm_traffic_no_usage_pulls", "未用流量拉取阈值", rules.traffic.no_usage_pulls),
        ruleInput("smm_traffic_no_usage_score", "未用流量加分", rules.traffic.no_usage_score),
        ruleInput("smm_traffic_low_usage_pulls", "低流量拉取阈值", rules.traffic.low_usage_pulls),
        ruleInput("smm_traffic_low_usage_bytes", "低流量字节阈值", rules.traffic.low_usage_bytes),
        ruleInput("smm_traffic_low_usage_score", "低流量加分", rules.traffic.low_usage_score),
        ruleInput("smm_traffic_normal_bytes", "正常流量字节阈值", rules.traffic.normal_usage_bytes),
        ruleInput("smm_traffic_normal_discount", "正常流量减分", rules.traffic.normal_usage_discount)
      ].join("")),
      renderRuleGroup("network", [
        ruleInput("smm_network_idc_score", "IDC/机房 IP 加分", rules.network.idc_score),
        ruleInput("smm_network_mobile_score", "移动网络加分", rules.network.mobile_score),
        ruleInput("smm_network_fixed_score", "家宽/固定宽带加分", rules.network.fixed_score),
        ruleInput("smm_network_proxy_score", "代理 IP 加分", rules.network.proxy_score),
        ruleInput("smm_network_vpn_score", "VPN IP 加分", rules.network.vpn_score),
        ruleInput("smm_network_tor_score", "Tor 出口加分", rules.network.tor_score),
        ruleInput("smm_network_bot_score", "自动化/爬虫 IP 加分", rules.network.bot_score),
        ruleInput("smm_guard_critical_discount", "极危险保护减分", rules.guard.critical_downgrade_discount)
      ].join("")),
      renderRuleGroup("client", [
        ruleInput("smm_agent_t1", "客户端阈值 1", tierValue(rules, "agent", 0, "threshold")),
        ruleInput("smm_agent_s1", "客户端加分 1", tierValue(rules, "agent", 0, "score")),
        ruleInput("smm_agent_t2", "客户端阈值 2", tierValue(rules, "agent", 1, "threshold")),
        ruleInput("smm_agent_s2", "客户端加分 2", tierValue(rules, "agent", 1, "score")),
        ruleInput("smm_client_suspicious_each", "可疑客户端每项加分", rules.client.suspicious_score_each),
        ruleInput("smm_client_suspicious_max", "可疑客户端最高加分", rules.client.suspicious_max_score),
        ruleInput("smm_client_empty_each", "空 UA 每次加分", rules.client.empty_agent_score_each),
        ruleInput("smm_client_empty_max", "空 UA 最高加分", rules.client.empty_agent_max_score),
        ruleInput("smm_client_trusted_discount", "可信客户端减分", rules.client.trusted_discount),
        ruleTextarea("smm_client_suspicious_keywords", "可疑客户端关键词，一行一个", rules.client.suspicious_keywords),
        ruleTextarea("smm_client_trusted_keywords", "可信客户端关键词，一行一个", rules.client.trusted_keywords)
      ].join("")),
      renderRuleGroup("host", [
        ruleInput("smm_host_t1", "入口阈值 1", tierValue(rules, "host", 0, "threshold")),
        ruleInput("smm_host_s1", "入口加分 1", tierValue(rules, "host", 0, "score")),
        ruleInput("smm_host_t2", "入口阈值 2", tierValue(rules, "host", 1, "threshold")),
        ruleInput("smm_host_s2", "入口加分 2", tierValue(rules, "host", 1, "score")),
        ruleInput("smm_host_watch_score", "观察入口加分", rules.host_policy.watch_host_score),
        ruleInput("smm_host_risk_score", "高风险入口加分", rules.host_policy.risk_host_score),
        ruleInput("smm_host_unknown_score", "非可信入口加分", rules.host_policy.unknown_host_score),
        ruleInput("smm_host_policy_max", "入口策略最高加分", rules.host_policy.max_score),
        ruleInput("smm_host_trusted_discount", "可信入口减分", rules.host_policy.trusted_discount),
        ruleTextarea("smm_host_trusted", "可信入口域名，一行一个；填写后未命中的入口会按非可信入口计算", rules.host_policy.trusted_hosts),
        ruleTextarea("smm_host_watch", "观察入口域名，一行一个", rules.host_policy.watch_hosts),
        ruleTextarea("smm_host_risk", "高风险入口域名，一行一个", rules.host_policy.risk_hosts)
      ].join("")),
      renderRuleGroup("share", [
        ruleInput("smm_token_threshold", "多 Token 阈值", rules.token.threshold),
        ruleInput("smm_token_score", "多 Token 加分", rules.token.score),
        ruleInput("smm_share_ip_users_t", "同 IP 多账号阈值", rules.share.ip_users.threshold),
        ruleInput("smm_share_ip_users_s", "同 IP 多账号加分", rules.share.ip_users.score),
        ruleInput("smm_share_ip_tokens_t", "同 IP 多 Token 阈值", rules.share.ip_tokens.threshold),
        ruleInput("smm_share_ip_tokens_s", "同 IP 多 Token 加分", rules.share.ip_tokens.score),
        ruleInput("smm_account_min", "账号状态最少请求", rules.account.min_requests),
        ruleInput("smm_account_expired", "过期账号加分", rules.account.expired_score),
        ruleInput("smm_account_no_plan", "无套餐加分", rules.account.no_plan_score),
        ruleInput("smm_account_traffic", "流量用尽加分", rules.account.traffic_exhausted_score)
      ].join("")),
      "</div>",
      "<div class=\"smm-rule-actions\"><button id=\"smm-rule-cancel\" class=\"smm-btn\">关闭</button><button id=\"smm-rule-save\" class=\"smm-btn smm-btn-primary\">保存规则</button></div>",
      "</div></div>"
    ].join("");
  }

  function renderPage() {
    if (!isTargetRoute()) return;
    mountStyle();
    var root = getMainRoot();
    if (!root) return;
    var data = state.data || {};
    var summary = data.summary || {};
    root.innerHTML = [
      "<div id=\"smm-panel\" class=\"smm-page\">",
      "<div class=\"smm-head\">",
      "<div><div class=\"smm-title\">行为监管</div><div class=\"smm-sub\">按账号聚合订阅拉取行为，标记风险等级；详情中查看设备、IP、入口和客户端。</div></div>",
      "<div class=\"smm-tools\">",
      "<select id=\"smm-days\" class=\"smm-select\"><option value=\"1\">最近1天</option><option value=\"7\">最近7天</option><option value=\"30\">最近30天</option><option value=\"90\">最近90天</option></select>",
      "<select id=\"smm-per-page\" class=\"smm-select\"><option value=\"20\">每页20</option><option value=\"50\">每页50</option><option value=\"100\">每页100</option></select>",
      "<select id=\"smm-risk-filter\" class=\"smm-select\"><option value=\"\">全部风险</option><option value=\"critical\">极危险</option><option value=\"high\">高风险</option><option value=\"medium\">中风险</option><option value=\"safe\">无风险</option></select>",
      "<select id=\"smm-disposition-filter\" class=\"smm-select\"><option value=\"\">全部处置</option><option value=\"none\">未处置</option><option value=\"watch\">观察</option><option value=\"handled\">已处理</option><option value=\"whitelist\">白名单</option><option value=\"freeze_suggested\">建议冻结</option><option value=\"blacklist_suggested\">建议拉黑</option></select>",
      "<select id=\"smm-type\" class=\"smm-select\"><option value=\"\">全部类型</option><option value=\"client_subscribe\">普通订阅</option><option value=\"app_subscribe\">App订阅</option><option value=\"app_meta\">App Meta</option><option value=\"app_get_config\">App配置</option></select>",
      "<input id=\"smm-keyword\" class=\"smm-input\" placeholder=\"邮箱 / IP / 域名 / token hash\" value=\"" + escapeHtml(state.filters.keyword) + "\">",
      "<input id=\"smm-disposition-keyword\" class=\"smm-input\" placeholder=\"处置备注 / 操作人\" value=\"" + escapeHtml(state.filters.disposition_keyword) + "\">",
      renderOperatorSelect(data.disposition_filter_options),
      "<select id=\"smm-watch-overdue\" class=\"smm-select\"><option value=\"3\">3天未复核</option><option value=\"7\">7天未复核</option><option value=\"14\">14天未复核</option><option value=\"30\">30天未复核</option></select>",
      "<button id=\"smm-search\" class=\"smm-btn smm-btn-primary\">查询</button>",
      "<button id=\"smm-rules\" class=\"smm-btn\">风险规则</button>",
      "<button id=\"smm-refresh\" class=\"smm-btn\">" + (state.loading ? "加载中" : "刷新") + "</button>",
      "</div></div>",
      "<div id=\"smm-status\" class=\"smm-status\"></div>",
      renderRulePanel(),
      data.installed === false ? "<div class=\"smm-card\"><div class=\"smm-empty\">行为监管数据表不存在，请先执行迁移脚本。</div></div>" : "",
      "<div class=\"smm-grid\">" + renderStats(summary) + "</div>",
      "<div class=\"smm-grid\" style=\"margin-top:16px;\">" + renderInsights(data) + "</div>",
      "<div class=\"smm-card\" style=\"margin-top:16px;\">",
      "<div class=\"smm-card-head\"><div class=\"smm-card-title\">账号风险列表</div><div class=\"smm-muted\">先按账号判断风险，点开详情查看拉取行为</div></div>",
      "<div class=\"smm-card-body\">" + renderUserProfiles(data.user_profiles) + "</div>",
      renderPager(data.pagination),
      "</div>",
      renderDispositionQueues(data.user_profiles),
      "</div>",
      renderUserDrawer()
    ].join("");
    bindControls();
  }

  function bindControls() {
    var days = document.getElementById("smm-days");
    var riskFilter = document.getElementById("smm-risk-filter");
    var dispositionFilter = document.getElementById("smm-disposition-filter");
    var perPage = document.getElementById("smm-per-page");
    var type = document.getElementById("smm-type");
    var keyword = document.getElementById("smm-keyword");
    var dispositionKeyword = document.getElementById("smm-disposition-keyword");
    var operator = document.getElementById("smm-operator");
    var watchOverdue = document.getElementById("smm-watch-overdue");
    var search = document.getElementById("smm-search");
    var rules = document.getElementById("smm-rules");
    var refresh = document.getElementById("smm-refresh");
    var rebuildSnapshots = document.getElementById("smm-rebuild-snapshots");
    var toggleSystem = document.getElementById("smm-toggle-system");
    if (days) days.value = String(state.filters.days);
    if (riskFilter) riskFilter.value = state.filters.risk;
    if (dispositionFilter) dispositionFilter.value = state.filters.disposition;
    if (perPage) perPage.value = String(state.filters.per_page);
    if (type) type.value = state.filters.type;
    if (operator) operator.value = state.filters.operator;
    if (watchOverdue) watchOverdue.value = String(state.filters.watch_overdue_days);
    if (search) search.onclick = function () {
      state.filters.days = Number(days ? days.value : state.filters.days) || 7;
      state.filters.risk = riskFilter ? riskFilter.value : "";
      state.filters.disposition = dispositionFilter ? dispositionFilter.value : "";
      state.filters.type = type ? type.value : "";
      state.filters.per_page = Number(perPage ? perPage.value : state.filters.per_page) || 50;
      state.filters.disposition_keyword = dispositionKeyword ? dispositionKeyword.value.trim() : "";
      state.filters.operator = operator ? operator.value : "";
      state.filters.watch_overdue_days = Number(watchOverdue ? watchOverdue.value : state.filters.watch_overdue_days) || 7;
      state.filters.page = 1;
      state.filters.keyword = keyword ? keyword.value.trim() : "";
      loadAll(true);
    };
    if (riskFilter) riskFilter.onchange = function () {
      state.filters.risk = riskFilter.value;
      renderPage();
    };
    if (dispositionFilter) dispositionFilter.onchange = function () {
      state.filters.disposition = dispositionFilter.value;
      renderPage();
    };
    if (refresh) refresh.onclick = function () { loadAll(true); };
    if (toggleSystem) toggleSystem.onclick = function () {
      state.showSystemStatus = !state.showSystemStatus;
      renderPage();
    };
    Array.prototype.forEach.call(document.querySelectorAll(".smm-queue-tab"), function (button) {
      button.onclick = function () {
        state.queueTab = button.getAttribute("data-queue-tab") || "watch";
        renderPage();
      };
    });
    Array.prototype.forEach.call(document.querySelectorAll(".smm-page-btn"), function (button) {
      button.onclick = function () {
        state.filters.page = Number(button.getAttribute("data-page") || 1) || 1;
        loadAll(true);
      };
    });
    if (rebuildSnapshots) rebuildSnapshots.onclick = function () {
      setStatus("正在重算风险快照...");
      request("/server/subscribe-monitor/snapshots/rebuild", state.filters, "POST", {}).then(function (response) {
        var data = response.data || {};
        setStatus("风险快照已重算：" + (data.rebuilt || 0) + " 个账号，新增 " + (data.stored || 0) + " 条", "success");
        return loadAll(true);
      }).catch(function (error) {
        setStatus(error.message || "重算失败", "error");
      });
    };
    if (rules) rules.onclick = function () {
      state.showRules = !state.showRules;
      renderPage();
    };
    var cancel = document.getElementById("smm-rule-cancel");
    if (cancel) cancel.onclick = function () {
      state.showRules = false;
      renderPage();
    };
    var save = document.getElementById("smm-rule-save");
    if (save) save.onclick = saveRules;
    Array.prototype.forEach.call(document.querySelectorAll(".smm-user-detail"), function (button) {
      button.onclick = function () {
        var key = button.getAttribute("data-user") || "";
        state.selectedUserId = key;
        state.drawerTab = "signals";
        renderPage();
      };
    });
    Array.prototype.forEach.call(document.querySelectorAll(".smm-drawer-tabs .smm-tab"), function (button) {
      button.onclick = function () {
        state.drawerTab = button.getAttribute("data-tab") || "signals";
        if (state.drawerTab === "dispatch") loadDispatchPreview();
        renderPage();
      };
    });
    Array.prototype.forEach.call(document.querySelectorAll(".smm-action"), function (button) {
      button.onclick = function () {
        var row = selectedUser();
        if (!row) return;
        saveDisposition(row, button.getAttribute("data-action") || "watch");
      };
    });
    Array.prototype.forEach.call(document.querySelectorAll(".smm-clear-profile"), function (button) {
      button.onclick = function () {
        var row = selectedUser();
        if (!row) return;
        clearProfile(row);
      };
    });
    Array.prototype.forEach.call(document.querySelectorAll(".smm-queue-remove"), function (button) {
      button.onclick = function () {
        var key = button.getAttribute("data-user") || "";
        var status = button.getAttribute("data-remove-status") || "none";
        var rows = ((state.data && state.data.watch_profiles) || []).concat((state.data && state.data.blacklist_profiles) || []);
        var row = rows.find(function (item) { return String(item.user_id || "") === String(key); });
        if (row) saveDisposition(row, status);
      };
    });
    Array.prototype.forEach.call(document.querySelectorAll(".smm-clear-profile-row"), function (button) {
      button.onclick = function () {
        var key = button.getAttribute("data-user") || "";
        var rows = (state.data && state.data.watch_profiles) || [];
        var row = rows.find(function (item) { return String(item.user_id || "") === String(key); });
        if (row) clearProfile(row);
      };
    });
    Array.prototype.forEach.call(document.querySelectorAll(".smm-bulk-handled,.smm-bulk-clear"), function (button) {
      button.onclick = function () {
        var title = button.getAttribute("data-queue") || "";
        var rows = title.indexOf("黑名单") >= 0
          ? ((state.data && state.data.blacklist_profiles) || [])
          : ((state.data && state.data.watch_profiles) || []);
        bulkDisposition(rows, button.className.indexOf("smm-bulk-clear") >= 0 ? (button.getAttribute("data-clear-status") || "none") : "handled");
      };
    });
    Array.prototype.forEach.call(document.querySelectorAll("[data-rule-tab]"), function (button) {
      button.onclick = function () {
        state.ruleTab = button.getAttribute("data-rule-tab") || "levels";
        renderPage();
      };
    });
    var drawerClose = document.getElementById("smm-drawer-close");
    if (drawerClose) drawerClose.onclick = function () {
      state.selectedUserId = null;
      renderPage();
    };
    var drawerMask = document.getElementById("smm-drawer-mask");
    if (drawerMask) drawerMask.onclick = function (event) {
      if (event.target === drawerMask) {
        state.selectedUserId = null;
        renderPage();
      }
    };
    [keyword, dispositionKeyword].forEach(function (input) {
      if (input) input.onkeydown = function (event) {
        if (event.key === "Enter" && search) search.click();
      };
    });
  }

  function setStatus(message, type) {
    var el = document.getElementById("smm-status");
    if (!el) return;
    el.className = "smm-status" + (type ? " " + type : "");
    el.textContent = message || "";
  }

  function numberValue(id) {
    var el = document.getElementById(id);
    return el ? Number(el.value || 0) || 0 : 0;
  }

  function textListValue(id) {
    var el = document.getElementById(id);
    if (!el) return [];
    return String(el.value || "").split(/[\r\n,]+/).map(function (item) {
      return item.trim();
    }).filter(Boolean);
  }

  function collectRules() {
    return {
      levels: {
        medium: numberValue("smm_level_medium"),
        high: numberValue("smm_level_high"),
        critical: numberValue("smm_level_critical")
      },
      ip: { tiers: [
        { threshold: numberValue("smm_ip_t1"), score: numberValue("smm_ip_s1") },
        { threshold: numberValue("smm_ip_t2"), score: numberValue("smm_ip_s2") },
        { threshold: numberValue("smm_ip_t3"), score: numberValue("smm_ip_s3") }
      ] },
      host: { tiers: [
        { threshold: numberValue("smm_host_t1"), score: numberValue("smm_host_s1") },
        { threshold: numberValue("smm_host_t2"), score: numberValue("smm_host_s2") }
      ] },
      agent: { tiers: [
        { threshold: numberValue("smm_agent_t1"), score: numberValue("smm_agent_s1") },
        { threshold: numberValue("smm_agent_t2"), score: numberValue("smm_agent_s2") }
      ] },
      request: { tiers: [
        { threshold: numberValue("smm_request_t1"), score: numberValue("smm_request_s1") },
        { threshold: numberValue("smm_request_t2"), score: numberValue("smm_request_s2") },
        { threshold: numberValue("smm_request_t3"), score: numberValue("smm_request_s3") }
      ] },
      token: {
        threshold: numberValue("smm_token_threshold"),
        score: numberValue("smm_token_score")
      },
      failure: {
        score_each: numberValue("smm_failure_each"),
        max_score: numberValue("smm_failure_max")
      },
      frequency: {
        last_10m: { threshold: numberValue("smm_freq_10m_t"), score: numberValue("smm_freq_10m_s") },
        last_1h: { threshold: numberValue("smm_freq_1h_t"), score: numberValue("smm_freq_1h_s") },
        today: { threshold: numberValue("smm_freq_today_t"), score: numberValue("smm_freq_today_s") },
        max_per_minute: { threshold: numberValue("smm_freq_minute_t"), score: numberValue("smm_freq_minute_s") },
        min_interval: { seconds: numberValue("smm_freq_interval_sec"), score: numberValue("smm_freq_interval_score") }
      },
      account: {
        min_requests: numberValue("smm_account_min"),
        expired_score: numberValue("smm_account_expired"),
        no_plan_score: numberValue("smm_account_no_plan"),
        traffic_exhausted_score: numberValue("smm_account_traffic")
      },
      traffic: {
        no_usage_pulls: numberValue("smm_traffic_no_usage_pulls"),
        no_usage_score: numberValue("smm_traffic_no_usage_score"),
        low_usage_pulls: numberValue("smm_traffic_low_usage_pulls"),
        low_usage_bytes: numberValue("smm_traffic_low_usage_bytes"),
        low_usage_score: numberValue("smm_traffic_low_usage_score"),
        normal_usage_bytes: numberValue("smm_traffic_normal_bytes"),
        normal_usage_discount: numberValue("smm_traffic_normal_discount")
      },
      client: {
        suspicious_keywords: textListValue("smm_client_suspicious_keywords"),
        trusted_keywords: textListValue("smm_client_trusted_keywords"),
        suspicious_score_each: numberValue("smm_client_suspicious_each"),
        suspicious_max_score: numberValue("smm_client_suspicious_max"),
        empty_agent_score_each: numberValue("smm_client_empty_each"),
        empty_agent_max_score: numberValue("smm_client_empty_max"),
        trusted_discount: numberValue("smm_client_trusted_discount")
      },
      host_policy: {
        trusted_hosts: textListValue("smm_host_trusted"),
        watch_hosts: textListValue("smm_host_watch"),
        risk_hosts: textListValue("smm_host_risk"),
        watch_host_score: numberValue("smm_host_watch_score"),
        risk_host_score: numberValue("smm_host_risk_score"),
        unknown_host_score: numberValue("smm_host_unknown_score"),
        max_score: numberValue("smm_host_policy_max"),
        trusted_discount: numberValue("smm_host_trusted_discount")
      },
      share: {
        ip_users: { threshold: numberValue("smm_share_ip_users_t"), score: numberValue("smm_share_ip_users_s") },
        ip_tokens: { threshold: numberValue("smm_share_ip_tokens_t"), score: numberValue("smm_share_ip_tokens_s") }
      },
      geo: {
        countries: { threshold: numberValue("smm_geo_countries_t"), score: numberValue("smm_geo_countries_s") },
        regions: { threshold: numberValue("smm_geo_regions_t"), score: numberValue("smm_geo_regions_s") },
        cities: { threshold: numberValue("smm_geo_cities_t"), score: numberValue("smm_geo_cities_s") },
        isps: { threshold: numberValue("smm_geo_isps_t"), score: numberValue("smm_geo_isps_s") },
        asns: { threshold: numberValue("smm_geo_asns_t"), score: numberValue("smm_geo_asns_s") },
        network_types: { threshold: numberValue("smm_geo_network_types_t"), score: numberValue("smm_geo_network_types_s") }
      },
      network: {
        idc_score: numberValue("smm_network_idc_score"),
        mobile_score: numberValue("smm_network_mobile_score"),
        fixed_score: numberValue("smm_network_fixed_score"),
        proxy_score: numberValue("smm_network_proxy_score"),
        vpn_score: numberValue("smm_network_vpn_score"),
        tor_score: numberValue("smm_network_tor_score"),
        bot_score: numberValue("smm_network_bot_score")
      },
      guard: {
        critical_requires_core_signal: true,
        critical_downgrade_discount: numberValue("smm_guard_critical_discount")
      },
      queue: {
        watch_score: numberValue("smm_queue_watch_score")
      }
    };
  }

  function renderPager(pagination) {
    pagination = pagination || {};
    var page = Number(pagination.page || state.filters.page || 1);
    var totalPages = Number(pagination.total_pages || 1);
    var totalUsers = Number(pagination.total_users || 0);
    return [
      "<div class=\"smm-pager\">",
      "<span class=\"smm-muted\">账号 " + escapeHtml(totalUsers) + " / 第 " + escapeHtml(page) + " / " + escapeHtml(totalPages) + " 页</span>",
      "<button class=\"smm-btn smm-page-btn\" data-page=\"" + escapeHtml(Math.max(1, page - 1)) + "\"" + (page <= 1 ? " disabled" : "") + ">上一页</button>",
      "<button class=\"smm-btn smm-page-btn\" data-page=\"" + escapeHtml(Math.min(totalPages, page + 1)) + "\"" + (page >= totalPages ? " disabled" : "") + ">下一页</button>",
      "</div>"
    ].join("");
  }

  function renderOperatorSelect(options) {
    options = options || {};
    var operators = options.operators || [];
    return [
      "<select id=\"smm-operator\" class=\"smm-select\"><option value=\"\">全部操作人</option>",
      operators.map(function (email) {
        return "<option value=\"" + escapeHtml(email) + "\">" + escapeHtml(email) + "</option>";
      }).join(""),
      "</select>"
    ].join("");
  }

  function saveRules() {
    setStatus("正在保存风险规则...");
    request("/server/subscribe-monitor/config", null, "POST", { rules: collectRules() }).then(function () {
      setStatus("风险规则已保存", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "保存失败", "error");
    });
  }

  function saveDisposition(row, status) {
    var noteEl = document.getElementById("smm-action-note");
    var note = noteEl ? noteEl.value.trim() : "";
    if ((status === "freeze_suggested" || status === "blacklist_suggested") && !note) {
      note = status === "freeze_suggested" ? "建议冻结，待人工确认" : "建议拉黑，待人工确认";
    }
    setStatus("正在保存处置...");
    request("/server/subscribe-monitor/disposition", null, "POST", {
      user_id: row.user_id,
      email: row.email,
      status: status,
      risk_level: row.risk_level,
      risk_score: row.risk_score,
      note: note
    }).then(function () {
      setStatus("处置已保存", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "处置保存失败", "error");
    });
  }

  function clearProfile(row) {
    if (!row || !row.user_id) return;
    if (!window.confirm("确定清除这个账号的行为画像吗？这会删除该账号的订阅拉取记录、风险快照和当前处置状态。")) {
      return;
    }
    var noteEl = document.getElementById("smm-action-note");
    var note = noteEl ? noteEl.value.trim() : "";
    setStatus("正在清除行为画像...");
    request("/server/subscribe-monitor/profile/clear", null, "POST", {
      user_id: row.user_id,
      note: note || "人工确认正常，清除行为画像"
    }).then(function () {
      state.selectedUserId = null;
      setStatus("行为画像已清除", "success");
      return loadAll(true);
    }).catch(function (error) {
      setStatus(error.message || "行为画像清除失败", "error");
    });
  }

  function updateSelectedUser(mutator) {
    var rows = (state.data && state.data.user_profiles) || [];
    for (var i = 0; i < rows.length; i += 1) {
      if (String(rows[i].user_id || "") === String(state.selectedUserId || "")) {
        mutator(rows[i]);
        break;
      }
    }
  }

  function loadDispatchPreview() {
    var row = selectedUser();
    if (!row || row.dispatch_preview && !row.dispatch_preview.error && !row.dispatch_preview.loading) return;
    updateSelectedUser(function (item) {
      item.dispatch_preview = { loading: true };
    });
    request("/server/subscribe-monitor/dispatch-preview", { user_id: row.user_id }).then(function (response) {
      updateSelectedUser(function (item) {
        item.dispatch_preview = response.data || {};
      });
      renderPage();
    }).catch(function (error) {
      updateSelectedUser(function (item) {
        item.dispatch_preview = { error: error.message || "下发预览读取失败" };
      });
      renderPage();
    });
  }

  function bulkDisposition(rows, status) {
    rows = rows || [];
    if (!rows.length) {
      setStatus("当前列表没有可批量处理的账号", "error");
      return;
    }
    setStatus("正在批量保存处置...");
    var index = 0;
    function next() {
      if (index >= rows.length) {
        setStatus("批量处置完成：" + rows.length + " 个账号", "success");
        return loadAll(true);
      }
      var row = rows[index++];
      return request("/server/subscribe-monitor/disposition", null, "POST", {
        user_id: row.user_id,
        email: row.email,
        status: status,
        risk_level: row.risk_level,
        risk_score: row.risk_score,
        note: status === "none" ? "批量清除观察/建议状态" : "批量标记已处理"
      }).then(next);
    }
    next().catch(function (error) {
      setStatus(error.message || "批量处置失败", "error");
    });
  }

  function loadAll(force) {
    if (loadingPromise && !force) return loadingPromise;
    state.loading = true;
    renderPage();
    loadingPromise = request("/server/subscribe-monitor/fetch", state.filters).then(function (response) {
      state.data = response.data || {};
      if (state.data.filters) {
        state.filters = Object.assign({}, state.filters, state.data.filters);
      }
      renderPage();
      setStatus("");
    }).catch(function (error) {
      renderPage();
      setStatus(error.message || "加载失败", "error");
    }).finally(function () {
      state.loading = false;
      loadingPromise = null;
      renderPage();
    });
    return loadingPromise;
  }

  function maybeRender() {
    if (!isTargetRoute()) return;
    if (!document.getElementById("smm-panel")) renderPage();
    setHeaderTitle();
    if (!state.data && !loadingPromise) loadAll();
  }

  window.addEventListener("hashchange", function () { setTimeout(maybeRender, 50); });
  window.addEventListener("load", function () { setTimeout(maybeRender, 50); });
  setInterval(maybeRender, 800);
})();
