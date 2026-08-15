#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR=""
DRY_RUN=0
MANIFEST_FILE="$ROOT_DIR/manifest.txt"
RETIRED_MANIFEST_FILE="$ROOT_DIR/retired-manifest.txt"
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
INSTALL_SUMMARY="$BACKUP_DIR/install-summary.tsv"

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

hash_file() {
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{print $1}'
  else
    sha256sum "$1" | awk '{print $1}'
  fi
}

print_plan_header() {
  printf '%-10s %-64s %-64s %s\n' "status" "source_sha256" "target_sha256" "path"
}

print_plan_row() {
  local status="$1"
  local src_hash="$2"
  local dst_hash="$3"
  local rel_path="$4"
  printf '%-10s %-64s %-64s %s\n' "$status" "$src_hash" "$dst_hash" "$rel_path"
}

print_plan_header
while IFS= read -r rel_path; do
  [ -n "$rel_path" ] || continue
  src="$OVERLAY_DIR/$rel_path"
  dst="$TARGET_DIR/$rel_path"

  if [ ! -f "$src" ]; then
    echo "Overlay file missing: $src" >&2
    exit 1
  fi

  total_count=$((total_count + 1))
  src_hash="$(hash_file "$src")"
  if [ -e "$dst" ]; then
    dst_hash="$(hash_file "$dst")"
    if cmp -s "$src" "$dst"; then
      same_count=$((same_count + 1))
      print_plan_row "same" "$src_hash" "$dst_hash" "$rel_path"
    else
      overwrite_count=$((overwrite_count + 1))
      print_plan_row "overwrite" "$src_hash" "$dst_hash" "$rel_path"
    fi
  else
    create_count=$((create_count + 1))
    print_plan_row "create" "$src_hash" "-" "$rel_path"
  fi
done < "$MANIFEST_FILE"

if [ -f "$RETIRED_MANIFEST_FILE" ]; then
  while IFS= read -r rel_path; do
    [ -n "$rel_path" ] || continue
    dst="$TARGET_DIR/$rel_path"
    if [ -e "$dst" ]; then
      print_plan_row "retire" "-" "$(hash_file "$dst")" "$rel_path"
    fi
  done < "$RETIRED_MANIFEST_FILE"
fi

echo
echo "Summary: total=$total_count overwrite=$overwrite_count create=$create_count same=$same_count"
if [ "$DRY_RUN" = "1" ]; then
  echo "Dry-run finished. Re-run without --dry-run to apply."
  exit 0
fi

mkdir -p "$BACKUP_DIR"
: > "$STATE_FILE"
: > "$INSTALL_SUMMARY"
printf 'status\tsource_sha256\ttarget_sha256\tpath\n' > "$INSTALL_SUMMARY"

while IFS= read -r rel_path; do
  [ -n "$rel_path" ] || continue
  src="$OVERLAY_DIR/$rel_path"
  dst="$TARGET_DIR/$rel_path"
  bak="$BACKUP_DIR/$rel_path"
  src_hash="$(hash_file "$src")"

  if [ ! -f "$src" ]; then
    echo "Overlay file missing: $src" >&2
    exit 1
  fi

  mkdir -p "$(dirname "$bak")"
  if [ -e "$dst" ]; then
    dst_hash="$(hash_file "$dst")"
    cp -a "$dst" "$bak"
    printf 'present\t%s\n' "$rel_path" >> "$STATE_FILE"
    if cmp -s "$src" "$dst"; then
      printf 'same\t%s\t%s\t%s\n' "$src_hash" "$dst_hash" "$rel_path" >> "$INSTALL_SUMMARY"
    else
      printf 'overwrite\t%s\t%s\t%s\n' "$src_hash" "$dst_hash" "$rel_path" >> "$INSTALL_SUMMARY"
    fi
  else
    printf 'missing\t%s\n' "$rel_path" >> "$STATE_FILE"
    printf 'create\t%s\t-\t%s\n' "$src_hash" "$rel_path" >> "$INSTALL_SUMMARY"
  fi

  mkdir -p "$(dirname "$dst")"
  cp -a "$src" "$dst"
done < "$MANIFEST_FILE"

if [ -f "$RETIRED_MANIFEST_FILE" ]; then
  while IFS= read -r rel_path; do
    [ -n "$rel_path" ] || continue
    dst="$TARGET_DIR/$rel_path"
    bak="$BACKUP_DIR/$rel_path"
    if [ -e "$dst" ]; then
      mkdir -p "$(dirname "$bak")"
      cp -a "$dst" "$bak"
      printf 'retired\t%s\n' "$rel_path" >> "$STATE_FILE"
      rm -f "$dst"
      printf 'retired\t-\t%s\t%s\n' "$(hash_file "$bak")" "$rel_path" >> "$INSTALL_SUMMARY"
    fi
  done < "$RETIRED_MANIFEST_FILE"
fi

ln -sfn "$BACKUP_DIR" "$BACKUP_BASE/latest"

PHP_BIN=""
if command -v php82 >/dev/null 2>&1; then
  PHP_BIN="php82"
elif command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
fi

if [ -n "$PHP_BIN" ]; then
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

  (
    cd "$TARGET_DIR"
    "$PHP_BIN" artisan optimize:clear || true
    "$PHP_BIN" artisan route:clear || true
    "$PHP_BIN" artisan view:clear || true
    "$PHP_BIN" artisan config:clear || true
    "$PHP_BIN" artisan cache:clear || true
    "$PHP_BIN" artisan config:cache || true
  )

  if ! [[ "$WEBMAN_PID" =~ ^[0-9]+$ ]] && [ -d /proc ]; then
    TARGET_REALPATH="$(cd "$TARGET_DIR" && pwd)"
    WEBMAN_PID="$(
      for proc in /proc/[0-9]*; do
        [ -r "$proc/cmdline" ] || continue
        cmd="$(tr '\0' ' ' < "$proc/cmdline" 2>/dev/null || true)"
        [ "${cmd#*webman.php}" != "$cmd" ] || continue
        cwd="$(readlink "$proc/cwd" 2>/dev/null || true)"
        [ "$cwd" = "$TARGET_REALPATH" ] || continue
        basename "$proc"
        break
      done
    )"
  fi

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
        WEBMAN_PHP=("$PHP_BIN")
        if [ -f cli-php.ini ]; then
          WEBMAN_PHP=("$PHP_BIN" -c cli-php.ini)
        fi
        "${WEBMAN_PHP[@]}" webman.php stop || true
        sleep 2
        "${WEBMAN_PHP[@]}" webman.php start -d || kill -USR1 "$WEBMAN_PID" || true
      else
        kill -USR1 "$WEBMAN_PID" || true
      fi
    )
  fi
fi

echo "Installed successfully."
echo "Target: $TARGET_DIR"
echo "Backup: $BACKUP_DIR"
echo "Install summary: $INSTALL_SUMMARY"
echo "Rollback: bash $ROOT_DIR/uninstall.sh $TARGET_DIR $BACKUP_DIR"
