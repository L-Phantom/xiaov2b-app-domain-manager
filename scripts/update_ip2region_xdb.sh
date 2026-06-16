#!/usr/bin/env bash
set -euo pipefail

TARGET_DIR="${1:-}"
SOURCE_URL="${2:-https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/ip2region_v4.xdb}"

if [ -z "$TARGET_DIR" ]; then
  if [ -f "$(pwd)/artisan" ]; then
    TARGET_DIR="$(pwd)"
  else
    echo "Usage: bash scripts/update_ip2region_xdb.sh /path/to/v2board-root [xdb-url]" >&2
    exit 1
  fi
fi

if [ ! -d "$TARGET_DIR" ] || [ ! -f "$TARGET_DIR/artisan" ]; then
  echo "Target site not found or artisan missing: $TARGET_DIR" >&2
  exit 1
fi

DEST_DIR="$TARGET_DIR/storage/ip2region"
DEST_FILE="$DEST_DIR/ip2region_v4.xdb"
TMP_FILE="$DEST_FILE.tmp.$$"

mkdir -p "$DEST_DIR"

if command -v curl >/dev/null 2>&1; then
  curl -L --fail --connect-timeout 15 --max-time 180 -o "$TMP_FILE" "$SOURCE_URL"
elif command -v wget >/dev/null 2>&1; then
  wget -O "$TMP_FILE" "$SOURCE_URL"
else
  echo "curl or wget is required to download ip2region xdb" >&2
  exit 1
fi

SIZE="$(wc -c < "$TMP_FILE" | tr -d ' ')"
if [ "$SIZE" -lt 102400 ]; then
  rm -f "$TMP_FILE"
  echo "Downloaded xdb is too small: ${SIZE} bytes" >&2
  exit 1
fi

mv "$TMP_FILE" "$DEST_FILE"
chmod 0644 "$DEST_FILE"

if command -v shasum >/dev/null 2>&1; then
  HASH="$(shasum -a 256 "$DEST_FILE" | awk '{print $1}')"
else
  HASH="$(sha256sum "$DEST_FILE" | awk '{print $1}')"
fi

echo "ip2region xdb installed"
echo "Path: $DEST_FILE"
echo "Size: $SIZE"
echo "SHA256: $HASH"
