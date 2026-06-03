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
  "resources/rules/app.meta.clash.yaml" \
  "public/assets/admin/app-domain-manager.js"; do
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
  echo "admin fetch route ok"
else
  echo "[6/6] skip admin route verify"
fi

echo "app meta overlay verify finished"

echo "verify finished"
