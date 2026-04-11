#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR="${1:-}"
if [ -z "$TARGET_DIR" ]; then
  if [ -f "$(pwd)/artisan" ]; then
    TARGET_DIR="$(pwd)"
  else
    echo "Usage: bash uninstall.sh /path/to/v2board-root [backup-dir]" >&2
    exit 1
  fi
fi

BACKUP_BASE="$TARGET_DIR/.app-domain-manager-backups"
BACKUP_DIR="${2:-$BACKUP_BASE/latest}"
STATE_FILE="$BACKUP_DIR/state.tsv"

if [ ! -d "$TARGET_DIR" ]; then
  echo "Target directory not found: $TARGET_DIR" >&2
  exit 1
fi

if [ ! -e "$BACKUP_DIR" ]; then
  echo "Backup directory not found: $BACKUP_DIR" >&2
  exit 1
fi

if [ ! -f "$STATE_FILE" ]; then
  echo "Backup state file missing: $STATE_FILE" >&2
  exit 1
fi

while IFS=$'\t' read -r status rel_path; do
  [ -n "$rel_path" ] || continue
  dst="$TARGET_DIR/$rel_path"
  bak="$BACKUP_DIR/$rel_path"

  if [ "$status" = "present" ]; then
    mkdir -p "$(dirname "$dst")"
    cp -a "$bak" "$dst"
  else
    rm -f "$dst"
  fi
done < "$STATE_FILE"

PHP_BIN=""
if command -v php82 >/dev/null 2>&1; then
  PHP_BIN="php82"
elif command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
fi

if [ -n "$PHP_BIN" ]; then
  (
    cd "$TARGET_DIR"
    "$PHP_BIN" artisan view:clear || true
    "$PHP_BIN" artisan config:clear || true
    "$PHP_BIN" artisan config:cache || true
  )

  WEBMAN_PID="$(
    TARGET_DIR="$TARGET_DIR" "$PHP_BIN" <<'PHP' 2>/dev/null || true
<?php
$target = getenv('TARGET_DIR');
require $target . '/vendor/autoload.php';
$app = require $target . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$pid = Illuminate\Support\Facades\Cache::get('WEBMANPID');
if ($pid) {
    echo $pid;
}
PHP
  )"

  if [[ "$WEBMAN_PID" =~ ^[0-9]+$ ]]; then
    kill -USR1 "$WEBMAN_PID" || true
  fi
fi

echo "Uninstalled successfully."
echo "Target: $TARGET_DIR"
echo "Restored from: $BACKUP_DIR"
