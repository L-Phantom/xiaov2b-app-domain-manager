# Production Runbook

Generic checklist for installing this overlay on a xiaov2b / V2Board-style panel.

Do not store real domains, IP addresses, tokens, credentials, or customer names in this file.

## Variables

```bash
SITE_ROOT="/path/to/panel-root"
BASE_URL="https://panel.example.com"
SECURE_PATH="admin-secure-path"
PACKAGE="/tmp/xiaov2b-app-domain-manager.tar.gz"
WORK_DIR="/tmp/xiaov2b-app-domain-manager"
PHP_BIN="php"
```

Use a versioned PHP binary when required, for example `php82`.

## Before Install

Create normal production backups first:

- site files
- database
- current `.app-domain-manager-backups/` directory, if present

Check runtime:

```bash
cd "$SITE_ROOT"
$PHP_BIN -v
test -f artisan && echo "artisan ok"
test -f webman.php && echo "webman present" || true
```

## Unpack

```bash
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"
tar -xzf "$PACKAGE" -C "$WORK_DIR" --strip-components=1
cd "$WORK_DIR"
```

Confirm:

```bash
test -f install.sh
test -f uninstall.sh
test -f verify.sh
test -f manifest.txt
```

## Preflight And Migration

```bash
$PHP_BIN scripts/preflight.php "$SITE_ROOT"
$PHP_BIN scripts/migrate_app_domain.php "$SITE_ROOT" --dry-run
$PHP_BIN scripts/migrate_app_domain.php "$SITE_ROOT" --apply
$PHP_BIN scripts/migrate_app_domain.php "$SITE_ROOT" --dry-run
```

Stop if preflight cannot bootstrap the target panel or the migration dry-run shows unexpected changes.

## Install

```bash
bash install.sh --dry-run "$SITE_ROOT"
bash install.sh "$SITE_ROOT"
```

Save the backup path and rollback command printed by the installer.

`install.sh` also installs `storage/ip2region/ip2region_v4.xdb` when missing. To use a mirror:

```bash
IP2REGION_XDB_URL="https://mirror.example.com/ip2region_v4.xdb" bash install.sh "$SITE_ROOT"
```

## Verify

```bash
bash verify.sh "$SITE_ROOT"
$PHP_BIN scripts/runtime_verify.php "$SITE_ROOT"
$PHP_BIN scripts/scenario_verify.php "$SITE_ROOT" app-entry.example.com
```

Optional HTTP verification:

```bash
bash verify.sh "$SITE_ROOT" \
  "$BASE_URL" \
  "$SECURE_PATH" \
  "user-token" \
  "admin-authorization-header" \
  "app-authorization-header"
```

Do not save real token values in shell history or repo files.

## Cache And Service

If routes or admin assets look stale:

```bash
cd "$SITE_ROOT"
$PHP_BIN artisan view:clear || true
$PHP_BIN artisan config:clear || true
$PHP_BIN artisan config:cache || true
if [ -f webman.php ]; then
  $PHP_BIN webman.php stop || true
  sleep 2
  $PHP_BIN webman.php start -d
fi
```

## Smoke Checks

- Admin page opens at `/$SECURE_PATH#/server/app-domain`.
- Admin page opens at `/$SECURE_PATH#/server/subscribe-monitor`.
- `域名分发` can load config, options, groups, and rules.
- `行为监管` can load account profiles and queues.
- App bootstrap and capabilities endpoints return JSON.
- App subscriptions honor App-visible nodes.
- Behavior-scoped entrance groups affect intended users only.
- Ordinary App entrance groups do not affect plain subscriptions.

## Rollback

Use the exact command printed by `install.sh`:

```bash
bash uninstall.sh "$SITE_ROOT" "$SITE_ROOT/.app-domain-manager-backups/<timestamp>"
```

Then clear caches and restart Webman if the target panel uses it.
