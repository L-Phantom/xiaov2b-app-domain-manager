#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR=""
DRY_RUN=0
MANIFEST_FILE="$ROOT_DIR/manifest.txt"
OVERLAY_DIR="$ROOT_DIR/overlay"

for arg in "$@"; do
  case "$arg" in
    --dry-run)
      DRY_RUN=1
      ;;
    --help|-h)
      echo "Usage: bash install.sh [--dry-run] /path/to/v2board-root"
      exit 0
      ;;
    *)
      if [ -z "$TARGET_DIR" ]; then
        TARGET_DIR="$arg"
      else
        echo "Unknown argument: $arg" >&2
        echo "Usage: bash install.sh [--dry-run] /path/to/v2board-root" >&2
        exit 1
      fi
      ;;
  esac
done

if [ -z "$TARGET_DIR" ]; then
  if [ -f "$(pwd)/artisan" ]; then
    TARGET_DIR="$(pwd)"
  else
    echo "Usage: bash install.sh [--dry-run] /path/to/v2board-root" >&2
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

echo "App Domain Manager install plan"
echo "Target: $TARGET_DIR"
echo "Manifest: $MANIFEST_FILE"
if [ "$DRY_RUN" = "1" ]; then
  echo "Mode: dry-run (no files will be changed)"
else
  echo "Mode: apply"
  echo "Backup: $BACKUP_DIR"
fi
echo

total_count=0
overwrite_count=0
create_count=0
same_count=0

while IFS= read -r rel_path; do
  [ -n "$rel_path" ] || continue
  src="$OVERLAY_DIR/$rel_path"
  dst="$TARGET_DIR/$rel_path"

  if [ ! -f "$src" ]; then
    echo "Overlay file missing: $src" >&2
    exit 1
  fi

  total_count=$((total_count + 1))
  if [ -e "$dst" ]; then
    if cmp -s "$src" "$dst"; then
      same_count=$((same_count + 1))
      printf '  %-9s %s\n' "same" "$rel_path"
    else
      overwrite_count=$((overwrite_count + 1))
      printf '  %-9s %s\n' "overwrite" "$rel_path"
    fi
  else
    create_count=$((create_count + 1))
    printf '  %-9s %s\n' "create" "$rel_path"
  fi
done < "$MANIFEST_FILE"

echo
echo "Summary: total=$total_count overwrite=$overwrite_count create=$create_count same=$same_count"
if [ "$DRY_RUN" = "1" ]; then
  echo "Dry-run finished. Re-run without --dry-run to apply."
  exit 0
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
    RESTART_WEBMAN=1
    TARGET_REALPATH="$(cd "$TARGET_DIR" && pwd)"
    if [ -d "/proc/$WEBMAN_PID" ]; then
      WEBMAN_CWD="$(readlink "/proc/$WEBMAN_PID/cwd" 2>/dev/null || true)"
      WEBMAN_CMD="$(tr '\0' ' ' < "/proc/$WEBMAN_PID/cmdline" 2>/dev/null || true)"
      if [ -n "$WEBMAN_CWD" ] && [ "$WEBMAN_CWD" != "$TARGET_REALPATH" ]; then
        echo "Skip Webman restart: cached PID $WEBMAN_PID belongs to $WEBMAN_CWD, not $TARGET_REALPATH"
        RESTART_WEBMAN=0
      elif [ -n "$WEBMAN_CMD" ] && [[ "$WEBMAN_CMD" != *"webman.php"* ]]; then
        echo "Skip Webman restart: cached PID $WEBMAN_PID is not a webman.php process"
        RESTART_WEBMAN=0
      fi
    elif ! kill -0 "$WEBMAN_PID" 2>/dev/null; then
      echo "Skip Webman restart: cached PID $WEBMAN_PID is not running"
      RESTART_WEBMAN=0
    fi

    if [ "$RESTART_WEBMAN" = "0" ]; then
      WEBMAN_PID=""
    fi
  fi

  if [[ "$WEBMAN_PID" =~ ^[0-9]+$ ]]; then
    (
      cd "$TARGET_DIR"
      if [ -f webman.php ]; then
        "$PHP_BIN" webman.php stop || true
        sleep 2
        "$PHP_BIN" webman.php start -d || kill -USR1 "$WEBMAN_PID" || true
      else
        kill -USR1 "$WEBMAN_PID" || true
      fi
    )
  fi
fi

echo "Installed successfully."
echo "Target: $TARGET_DIR"
echo "Backup: $BACKUP_DIR"
echo "Rollback: bash $ROOT_DIR/uninstall.sh $TARGET_DIR $BACKUP_DIR"
