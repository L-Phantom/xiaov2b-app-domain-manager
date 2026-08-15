#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR="${1:-}"

if [[ -z "$TARGET_DIR" || ! -f "$TARGET_DIR/artisan" ]]; then
  echo "Usage: bash verify.sh /path/to/v2board-root" >&2
  exit 1
fi

while IFS= read -r rel_path; do
  [[ -n "$rel_path" ]] || continue
  [[ -f "$TARGET_DIR/$rel_path" ]] || {
    echo "Missing installed file: $rel_path" >&2
    exit 1
  }
done < "$ROOT_DIR/manifest.txt"

PHP_BIN=""
if command -v php82 >/dev/null 2>&1; then
  PHP_BIN="php82"
elif command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
fi

if [[ -n "$PHP_BIN" ]]; then
  while IFS= read -r file; do
    "$PHP_BIN" -l "$file" >/dev/null
  done < <(find "$ROOT_DIR/overlay" "$ROOT_DIR/scripts" -type f -name '*.php' | sort)
fi

if command -v node >/dev/null 2>&1; then
  node --check "$TARGET_DIR/public/assets/admin/app-domain-manager.js" >/dev/null
fi

grep -q "entryAssignmentForUser" "$TARGET_DIR/app/Services/AppDomainService.php"
grep -q "applyAssignedEntranceToServer" "$TARGET_DIR/app/Services/AppDomainService.php"
grep -q "previewGlobalHostReplace" "$TARGET_DIR/app/Services/AppDomainService.php"
grep -q "rollbackGlobalHostReplace" "$TARGET_DIR/app/Services/AppDomainService.php"
grep -q "global-replace/preview" "$TARGET_DIR/app/Http/Routes/V1/AdminRoute.php"
grep -q "全局切换" "$TARGET_DIR/public/assets/admin/app-domain-manager.js"

if grep -RInE "SubscribeMonitor|subscribe-monitor|SubscribeAccessLog|SubscribeRiskSnapshot|SubscribeIpCache|SubscribeDisposition|行为监管" \
  "$TARGET_DIR/app" "$TARGET_DIR/public/assets/admin/app-domain-manager.js" "$TARGET_DIR/resources/views/admin.blade.php" "$TARGET_DIR/routes/web.php"; then
  echo "Removed behavior-monitor code is still present in the installed overlay" >&2
  exit 1
fi

echo "Domain distribution overlay verification passed."
