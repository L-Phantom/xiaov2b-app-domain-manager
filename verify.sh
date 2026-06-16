#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR="${1:-}"
BASE_URL="${2:-}"
SECURE_PATH="${3:-}"
USER_TOKEN="${4:-}"
ADMIN_AUTH="${5:-}"
APP_AUTH="${6:-}"
MANIFEST_FILE="$ROOT_DIR/manifest.txt"

if [ -z "$TARGET_DIR" ]; then
  if [ -f "$(pwd)/artisan" ]; then
    TARGET_DIR="$(pwd)"
  else
    echo "Usage: bash verify.sh /path/to/v2board-root [base-url] [secure-path] [user-token] [admin-auth] [app-auth]" >&2
    exit 1
  fi
fi

if [ ! -d "$TARGET_DIR" ]; then
  echo "Target directory not found: $TARGET_DIR" >&2
  exit 1
fi

echo "[1/6] checking overlay files"
while IFS= read -r rel_path; do
  [ -n "$rel_path" ] || continue
  if [ ! -f "$TARGET_DIR/$rel_path" ]; then
    echo "Missing file: $TARGET_DIR/$rel_path" >&2
    exit 1
  fi
done < "$MANIFEST_FILE"
echo "overlay files ok"

echo "[2/6] checking important feature files"
for rel_path in \
  "app/Http/Routes/V2/AppRoute.php" \
  "app/Http/Controllers/V2/App/BaseController.php" \
  "app/Http/Controllers/V2/App/AuthController.php" \
  "app/Http/Controllers/V2/App/NodeController.php" \
  "app/Http/Controllers/V2/App/OrderController.php" \
  "app/Http/Middleware/AppUser.php" \
  "app/Models/SubscribeDisposition.php" \
  "app/Models/SubscribeDispositionLog.php" \
  "app/Models/SubscribeIpCache.php" \
  "app/Models/SubscribeRiskSnapshot.php" \
  "app/Services/Ip2RegionService.php" \
  "app/Support/Ip2Region/Searcher.class.php" \
  "resources/rules/app.meta.clash.yaml" \
  "public/assets/admin/app-domain-manager.js" \
  "public/assets/admin/subscribe-monitor-manager.js"; do
  if [ ! -f "$TARGET_DIR/$rel_path" ]; then
    echo "Missing feature file: $TARGET_DIR/$rel_path" >&2
    exit 1
  fi
done
grep -q "app.user" "$TARGET_DIR/app/Http/Kernel.php" || {
  echo "Kernel missing app.user middleware alias" >&2
  exit 1
}
grep -q "AppController@bootstrap" "$TARGET_DIR/app/Http/Routes/V2/AppRoute.php" || {
  echo "V2 AppRoute missing bootstrap route" >&2
  exit 1
}
grep -q "NodeController@manifest" "$TARGET_DIR/app/Http/Routes/V2/AppRoute.php" || {
  echo "V2 AppRoute missing node manifest route" >&2
  exit 1
}
grep -q "risk_signals" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing risk signal aggregation" >&2
  exit 1
}
grep -q "smm_freq_10m_t" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing database-signal risk rules" >&2
  exit 1
}
grep -q "Ip2RegionService" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing ip2region integration" >&2
  exit 1
}
grep -q "no_traffic_after_subscribe" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing traffic-use risk signal" >&2
  exit 1
}
grep -q "asn_count" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing ASN/network intelligence metrics" >&2
  exit 1
}
grep -q "saveDisposition" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing manual disposition workflow" >&2
  exit 1
}
grep -q "recordRiskSnapshot" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing risk snapshot cache workflow" >&2
  exit 1
}
grep -q "snapshots/rebuild" "$TARGET_DIR/app/Http/Routes/V1/AdminRoute.php" || {
  echo "AdminRoute missing risk snapshot rebuild route" >&2
  exit 1
}
grep -q "风险变化时间线" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing risk timeline UI" >&2
  exit 1
}
grep -q "重算风险快照" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing risk snapshot rebuild button" >&2
  exit 1
}
grep -q "riskSnapshotForUser" "$TARGET_DIR/app/Services/AppDomainService.php" || {
  echo "AppDomainService missing risk/disposition domain linkage" >&2
  exit 1
}
grep -q "previewDispatchForUserId" "$TARGET_DIR/app/Services/AppDomainService.php" || {
  echo "AppDomainService missing user dispatch preview workflow" >&2
  exit 1
}
grep -q "dispatch-preview" "$TARGET_DIR/app/Http/Routes/V1/AdminRoute.php" || {
  echo "AdminRoute missing subscribe monitor dispatch preview route" >&2
  exit 1
}
grep -q "risk_levels" "$TARGET_DIR/app/Http/Requests/Admin/AppDomainGroupSave.php" || {
  echo "AppDomainGroupSave missing risk/disposition linkage validation" >&2
  exit 1
}
grep -q "app_domain_hidden" "$TARGET_DIR/app/Services/ServerService.php" || {
  echo "ServerService missing sensitive-node hide filtering" >&2
  exit 1
}
grep -q "smm_traffic_no_usage_pulls" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing traffic behavior rules" >&2
  exit 1
}
grep -q "smm_network_idc_score" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing network intelligence rules" >&2
  exit 1
}
grep -q "下发预览" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing dispatch preview UI" >&2
  exit 1
}
grep -q "bulkDisposition" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing batch disposition workflow" >&2
  exit 1
}
grep -q "smm-disposition-keyword" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing disposition note/operator filter" >&2
  exit 1
}
grep -q "smm-watch-overdue" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing watch overdue filter" >&2
  exit 1
}
grep -q "dispositionOverdue" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing disposition overdue calculation" >&2
  exit 1
}
grep -q "filteredDispositionUserIds" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing disposition note/operator filtering" >&2
  exit 1
}
grep -q "miss_count" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing IP intelligence miss status" >&2
  exit 1
}
grep -q "per_page" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing server-side pagination foundation" >&2
  exit 1
}
grep -q "profileOverview" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing global snapshot-backed profile overview" >&2
  exit 1
}
grep -q "page_risk_summary" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing page/global risk summary separation" >&2
  exit 1
}
grep -q "blacklist_profiles" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing global blacklist queue payload" >&2
  exit 1
}
grep -q "画像统计口径" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing profile overview source status" >&2
  exit 1
}
grep -q "riskExplanation" "$TARGET_DIR/app/Services/SubscribeMonitorService.php" || {
  echo "SubscribeMonitorService missing productized risk explanation" >&2
  exit 1
}
grep -q "判断结论" "$TARGET_DIR/public/assets/admin/subscribe-monitor-manager.js" || {
  echo "subscribe monitor admin asset missing risk explanation UI" >&2
  exit 1
}
grep -q "创建黑名单入口组" "$TARGET_DIR/public/assets/admin/app-domain-manager.js" || {
  echo "app domain admin asset missing blacklist entrance group template" >&2
  exit 1
}
grep -q "行为处置入口" "$TARGET_DIR/public/assets/admin/app-domain-manager.js" || {
  echo "app domain admin asset missing behavior/normal entrance sections" >&2
  exit 1
}
grep -q "域名分发" "$TARGET_DIR/public/assets/admin/app-domain-manager.js" || {
  echo "app domain admin asset missing domain distribution display name" >&2
  exit 1
}
grep -q "applyBehaviorEntranceToServer" "$TARGET_DIR/app/Services/ServerService.php" || {
  echo "ServerService missing behavior-scoped entrance routing for plain subscriptions" >&2
  exit 1
}
grep -q "groupHasBehaviorScope" "$TARGET_DIR/app/Services/AppDomainService.php" || {
  echo "AppDomainService missing behavior-scoped entrance priority" >&2
  exit 1
}
grep -q "blacklist_disposition_uses_dedicated_host" "$ROOT_DIR/scripts/scenario_verify.php" || {
  echo "scenario verify missing blacklist disposition entrance drill" >&2
  exit 1
}
grep -q "blacklist_plain_subscribe_uses_dedicated_host" "$ROOT_DIR/scripts/scenario_verify.php" || {
  echo "scenario verify missing plain subscription blacklist entrance drill" >&2
  exit 1
}
grep -q "ordinary_binding_does_not_affect_plain_subscribe" "$ROOT_DIR/scripts/scenario_verify.php" || {
  echo "scenario verify missing ordinary group plain subscription guard" >&2
  exit 1
}
echo "feature files ok"

PHP_BIN=""
if command -v php82 >/dev/null 2>&1; then
  PHP_BIN="php82"
elif command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
fi

echo "[3/6] checking runtime bootstrap"
if [ -n "$PHP_BIN" ]; then
  "$PHP_BIN" "$ROOT_DIR/scripts/runtime_verify.php" "$TARGET_DIR"
else
  echo "php not found, skip runtime bootstrap verify"
fi

if [ -n "$BASE_URL" ] && [ -n "$USER_TOKEN" ]; then
  echo "[4/6] checking public app http routes"
  curl -fsS "$BASE_URL/api/v1/client/app/bootstrap?token=$USER_TOKEN" >/dev/null
  curl -fsS "$BASE_URL/api/v1/client/app/getConfig?token=$USER_TOKEN" >/dev/null
  curl -fsS "$BASE_URL/api/v1/client/app/getVersion?token=$USER_TOKEN" >/dev/null
  curl -fsS "$BASE_URL/api/v1/client/custom_app/subscribe?token=$USER_TOKEN" >/dev/null
  APP_META_BODY="$(curl -fsS "$BASE_URL/api/v1/client/custom_app/subscribe?token=$USER_TOKEN&flag=app_meta")"
  printf '%s' "$APP_META_BODY" | grep -q "rule-providers:" || {
    echo "app_meta response missing rule-providers" >&2
    exit 1
  }
  curl -fsS "$BASE_URL/api/v2/app/bootstrap" | grep -q '"data"' || {
    echo "v2 app bootstrap response missing data" >&2
    exit 1
  }
  curl -fsS "$BASE_URL/api/v2/app/capabilities" | grep -q '"feature_flags"' || {
    echo "v2 app capabilities response missing feature_flags" >&2
    exit 1
  }
  echo "public app routes ok"
else
  echo "[4/6] skip public http route verify"
fi

if [ -n "$BASE_URL" ] && [ -n "$APP_AUTH" ]; then
  echo "[5/6] checking authenticated v2 app routes"
  curl -fsS -H "authorization: $APP_AUTH" "$BASE_URL/api/v2/app/client/config" | grep -q '"subscribe_url"' || {
    echo "v2 app client config response missing subscribe_url" >&2
    exit 1
  }
  curl -fsS -H "authorization: $APP_AUTH" "$BASE_URL/api/v2/app/nodes/manifest" | grep -q '"nodes"' || {
    echo "v2 app nodes manifest response missing nodes" >&2
    exit 1
  }
  echo "authenticated v2 app routes ok"
else
  echo "[5/6] skip authenticated v2 app route verify"
fi

if [ -n "$BASE_URL" ] && [ -n "$SECURE_PATH" ] && [ -n "$ADMIN_AUTH" ]; then
  echo "[6/6] checking admin fetch route"
  curl -fsS \
    -H "Accept: application/json" \
    -H "authorization: $ADMIN_AUTH" \
    "$BASE_URL/api/v1/$SECURE_PATH/server/app-domain/fetch" >/dev/null
  curl -fsS \
    -H "Accept: application/json" \
    -H "authorization: $ADMIN_AUTH" \
    "$BASE_URL/api/v1/$SECURE_PATH/server/subscribe-monitor/fetch" >/dev/null
  echo "admin fetch route ok"
else
  echo "[6/6] skip admin route verify"
fi

echo "app meta overlay verify finished"

echo "verify finished"
