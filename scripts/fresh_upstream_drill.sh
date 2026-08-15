#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
UPSTREAM_URL="${UPSTREAM_URL:-https://github.com/wyx2685/v2board.git}"
UPSTREAM_REF="${UPSTREAM_REF:-858effa102656df146b1bdde0a9387405ee92cc3}"
UPSTREAM_LOCAL_PATH="${UPSTREAM_LOCAL_PATH:-}"
WORK_ROOT="${WORK_ROOT:-${TMPDIR:-/tmp}/xiaov2b-app-domain-drill-$(date +%Y%m%d-%H%M%S)}"
TARGET_DIR="$WORK_ROOT/v2board"

usage() {
  cat <<'EOF'
Usage:
  bash scripts/fresh_upstream_drill.sh

Environment:
  UPSTREAM_URL   Upstream v2board/xiaov2b git URL.
  UPSTREAM_REF   Upstream commit/branch/tag. Defaults to the verified latest commit.
  UPSTREAM_LOCAL_PATH  Existing local upstream checkout to copy instead of clone.
  WORK_ROOT      Drill workspace. Defaults to a temporary path.

This drill verifies that the overlay package can be applied to a fresh upstream
checkout without touching production. Runtime/database checks still require a
real installed panel with vendor/ and a configured database.
EOF
}

if [[ "${1:-}" = "--help" || "${1:-}" = "-h" ]]; then
  usage
  exit 0
fi

mkdir -p "$WORK_ROOT"

echo "=== Fresh upstream drill ==="
echo "Upstream: $UPSTREAM_URL"
echo "Ref:      $UPSTREAM_REF"
echo "Work:     $WORK_ROOT"
echo

if [[ -n "$UPSTREAM_LOCAL_PATH" ]]; then
  if [[ ! -f "$UPSTREAM_LOCAL_PATH/artisan" ]]; then
    echo "UPSTREAM_LOCAL_PATH does not look like a panel root: $UPSTREAM_LOCAL_PATH" >&2
    exit 1
  fi
  rsync -a --delete \
    --exclude='.git/' \
    --exclude='vendor/' \
    --exclude='storage/logs/' \
    "$UPSTREAM_LOCAL_PATH/" "$TARGET_DIR/"
elif [[ ! -d "$TARGET_DIR/.git" ]]; then
  git clone "$UPSTREAM_URL" "$TARGET_DIR"
fi

if [[ -d "$TARGET_DIR/.git" ]]; then
  (
    cd "$TARGET_DIR"
    git fetch --all --tags --prune
    git checkout "$UPSTREAM_REF"
  )
fi

echo "=== Package static checks ==="
bash "$ROOT_DIR/scripts/package_release.sh"

echo "=== Install dry-run on fresh upstream ==="
bash "$ROOT_DIR/install.sh" --dry-run "$TARGET_DIR"

echo "=== Apply overlay to fresh upstream copy ==="
bash "$ROOT_DIR/install.sh" "$TARGET_DIR"

echo "=== Structure verification ==="
while IFS= read -r rel_path; do
  [[ -n "$rel_path" ]] || continue
  if [[ ! -f "$TARGET_DIR/$rel_path" ]]; then
    echo "Missing applied file: $rel_path" >&2
    exit 1
  fi
done < "$ROOT_DIR/manifest.txt"

if [[ -d "$TARGET_DIR/vendor" && -f "$TARGET_DIR/bootstrap/app.php" ]]; then
  php "$ROOT_DIR/scripts/preflight.php" "$TARGET_DIR"
else
  echo "Runtime preflight skipped: fresh checkout has no vendor/bootstrap runtime."
fi

echo
echo "Fresh upstream drill finished."
echo "Drill site: $TARGET_DIR"
echo "Rollback: bash $ROOT_DIR/uninstall.sh $TARGET_DIR"
