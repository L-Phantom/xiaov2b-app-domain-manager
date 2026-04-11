#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR="${1:-}"
MANIFEST_FILE="$ROOT_DIR/manifest.txt"
OVERLAY_DIR="$ROOT_DIR/overlay"
if [ -z "$TARGET_DIR" ]; then
  if [ -f "$(pwd)/artisan" ]; then
    TARGET_DIR="$(pwd)"
  else
    echo "Usage: bash install.sh /path/to/v2board-root" >&2
    exit 1
  fi
fi

BACKUP_BASE="$TARGET_DIR/.app-domain-manager-backups"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$BACKUP_BASE/$TIMESTAMP"
STATE_FILE="$BACKUP_DIR/state.tsv"

if [ ! -d "$TARGET_DIR" ]; then
  echo "Target directory not found: $TARGET_DIR" >&2
  exit 1
fi

if [ ! -f "$TARGET_DIR/artisan" ]; then
  echo "artisan not found under target: $TARGET_DIR" >&2
  exit 1
fi

if [ ! -f "$MANIFEST_FILE" ]; then
  echo "manifest.txt missing" >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"
: > "$STATE_FILE"

while IFS= read -r rel_path; do
  [ -n "$rel_path" ] || continue
  src="$OVERLAY_DIR/$rel_path"
  dst="$TARGET_DIR/$rel_path"
  bak="$BACKUP_DIR/$rel_path"

  if [ ! -f "$src" ]; then
    echo "Overlay file missing: $src" >&2
    exit 1
  fi

  mkdir -p "$(dirname "$bak")"
  if [ -e "$dst" ]; then
    cp -a "$dst" "$bak"
    printf 'present\t%s\n' "$rel_path" >> "$STATE_FILE"
  else
    printf 'missing\t%s\n' "$rel_path" >> "$STATE_FILE"
  fi

  mkdir -p "$(dirname "$dst")"
  cp -a "$src" "$dst"
done < "$MANIFEST_FILE"

ln -sfn "$BACKUP_DIR" "$BACKUP_BASE/latest"

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

echo "Installed successfully."
echo "Target: $TARGET_DIR"
echo "Backup: $BACKUP_DIR"
