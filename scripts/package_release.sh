#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/dist"
STAMP="${PACKAGE_STAMP:-$(date +%Y%m%d-%H%M%S)}"
COMMIT="$(git -C "$ROOT_DIR" rev-parse --short HEAD 2>/dev/null || echo worktree)"
PACKAGE_NAME="xiaov2b-app-domain-manager-${COMMIT}-${STAMP}"
PACKAGE_DIR="$OUT_DIR/$PACKAGE_NAME"
TARBALL="$OUT_DIR/$PACKAGE_NAME.tar.gz"

usage() {
  cat <<'EOF'
Usage:
  bash scripts/package_release.sh

Creates a release tarball under dist/ with SHA256 checksums and a manifest
checksum file. The untracked platform/ Brand Manager is intentionally excluded.
EOF
}

if [[ "${1:-}" = "--help" || "${1:-}" = "-h" ]]; then
  usage
  exit 0
fi

cd "$ROOT_DIR"

if [[ ! -f manifest.txt ]]; then
  echo "manifest.txt missing" >&2
  exit 1
fi

echo "=== Checking manifest ==="
while IFS= read -r rel_path; do
  [[ -n "$rel_path" ]] || continue
  if [[ ! -f "overlay/$rel_path" ]]; then
    echo "Missing overlay file listed in manifest: overlay/$rel_path" >&2
    exit 1
  fi
done < manifest.txt

extra_files="$(comm -13 <(sort manifest.txt) <(find overlay -type f | sed 's#^overlay/##' | sort) || true)"
if [[ -n "$extra_files" ]]; then
  echo "Overlay files not listed in manifest:" >&2
  printf '%s\n' "$extra_files" >&2
  exit 1
fi

echo "=== Syntax checks ==="
bash -n install.sh verify.sh uninstall.sh scripts/package_release.sh
PHP_BIN=""
if command -v php82 >/dev/null 2>&1; then
  PHP_BIN="php82"
elif command -v php >/dev/null 2>&1; then
  PHP_BIN="php"
elif [[ "${PACKAGE_SKIP_PHP_LINT:-0}" = "1" ]]; then
  echo "PHP lint skipped because PACKAGE_SKIP_PHP_LINT=1"
else
  echo "php or php82 is required for package validation" >&2
  exit 1
fi
if [[ -n "$PHP_BIN" ]]; then
  for php_file in $(find overlay scripts -type f -name '*.php' | sort); do
    "$PHP_BIN" -l "$php_file" >/dev/null
  done
fi
for js_file in $(find overlay -type f -name '*.js' | sort); do
  node --check "$js_file" >/dev/null
done

rm -rf "$PACKAGE_DIR"
mkdir -p "$PACKAGE_DIR" "$OUT_DIR"

echo "=== Copying package files ==="
rsync -a \
  --exclude='.git/' \
  --exclude='dist/' \
  --exclude='platform/' \
  --exclude='.DS_Store' \
  README.md MAINTENANCE.md PRODUCTION_RUNBOOK.md NATIVE_REFACTOR_PLAN.md PUBLISH_EXAMPLE.txt \
  install.sh uninstall.sh verify.sh manifest.txt retired-manifest.txt overlay scripts sql \
  "$PACKAGE_DIR/"

echo "=== Writing checksums ==="
(
  cd "$PACKAGE_DIR"
  find overlay scripts sql -type f -print | sort | xargs shasum -a 256 > MANIFEST-SHA256.txt
  shasum -a 256 install.sh uninstall.sh verify.sh manifest.txt retired-manifest.txt README.md MAINTENANCE.md PRODUCTION_RUNBOOK.md > ROOT-SHA256.txt
)

tar -C "$OUT_DIR" -czf "$TARBALL" "$PACKAGE_NAME"
shasum -a 256 "$TARBALL" > "$TARBALL.sha256"

echo
echo "Package: $TARBALL"
echo "SHA256:  $TARBALL.sha256"
