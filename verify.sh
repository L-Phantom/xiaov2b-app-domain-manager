#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR="${1:-}"
BASE_URL="${2:-}"
SECURE_PATH="${3:-}"
USER_TOKEN="${4:-}"
ADMIN_AUTH="${5:-}"
MANIFEST_FILE="$ROOT_DIR/manifest.txt"

if [ -z "$TARGET_DIR" ]; then
  if [ -f "$(pwd)/artisan" ]; then
    TARGET_DIR="$(pwd)"
  else
    echo "Usage: bash verify.sh /path/to/v2board-root [base-url] [secure-path] [user-token] [admin-auth]" >&2
    exit 1
  fi
fi

if [ ! -d "$TARGET_DIR" ]; then
  echo "Target directory not found: $TARGET_DIR" >&2
  exit 1
fi

echo "[1/5] checking overlay files"
while IFS= read -r rel_path; do
  [ -n "$rel_path" ] || continue
  if [ ! -f "$TARGET_DIR/$rel_path" ]; then
    echo "Missing file: $TARGET_DIR/$rel_path" >&2
    exit 1
  fi
done < "$MANIFEST_FILE"
echo "overlay files ok"

PHP_BIN=""
if command -v php82 >/dev/null 2>&1; then
  PHP_BIN="php82"
elif command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
fi

echo "[2/5] checking runtime bootstrap"
if [ -n "$PHP_BIN" ]; then
  "$PHP_BIN" "$ROOT_DIR/scripts/runtime_verify.php" "$TARGET_DIR"
else
  echo "php not found, skip runtime bootstrap verify"
fi

if [ -n "$BASE_URL" ] && [ -n "$USER_TOKEN" ]; then
  echo "[3/5] checking app http routes"
  curl -fsS "$BASE_URL/api/v1/client/app/bootstrap?token=$USER_TOKEN" >/dev/null
  curl -fsS "$BASE_URL/api/v1/client/custom_app/subscribe?token=$USER_TOKEN" >/dev/null
  APP_META_BODY="$(curl -fsS "$BASE_URL/api/v1/client/custom_app/subscribe?token=$USER_TOKEN&flag=app_meta")"
  printf '%s' "$APP_META_BODY" | grep -q "rule-providers:" || {
    echo "app_meta response missing rule-providers" >&2
    exit 1
  }
  echo "public app routes ok"
else
  echo "[3/5] skip public http route verify"
fi

if [ -n "$BASE_URL" ] && [ -n "$SECURE_PATH" ] && [ -n "$ADMIN_AUTH" ]; then
  echo "[4/5] checking admin fetch route"
  curl -fsS \
    -H "Accept: application/json" \
    -H "authorization: $ADMIN_AUTH" \
    "$BASE_URL/api/v1/$SECURE_PATH/server/app-domain/fetch" >/dev/null
  echo "admin fetch route ok"
else
  echo "[4/5] skip admin route verify"
fi

echo "[5/5] app meta overlay verify finished"

echo "verify finished"
