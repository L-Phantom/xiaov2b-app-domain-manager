# Production Upgrade Runbook

This runbook is for deploying the xiaov2b App overlay package to a production
panel. It does not cover the local Brand Manager `platform/` module.

## Scope

Use this runbook when installing or upgrading:

- App-only V1 subscribe/bootstrap/config/version endpoints
- V2 App API
- App Meta subscription template
- App node visibility
- Entrance domain distribution
- Admin UI entry `中转域名分发`

Do not use this runbook to deploy the packaging backend. Keep `platform/`
managed locally unless it is later promoted as a separate project.

## Variables

Fill these before starting. Do not paste secrets into this document.

```bash
SITE_ROOT="/www/wwwroot/example.com"
BASE_URL="https://example.com"
SECURE_PATH="your-admin-secure-path"
PACKAGE="/tmp/xiaov2b-app-domain-manager.tar.gz"
WORK_DIR="/tmp/xiaov2b-app-domain-manager-prod"
PHP_BIN="php82"
USER_TOKEN="optional-user-token"
ADMIN_AUTH="optional-admin-authorization"
APP_AUTH="optional-app-authorization"
```

If the server uses `php` instead of `php82`, change `PHP_BIN`.

## Pre-Upgrade Snapshot

Record the current state before touching files:

```bash
cd "$SITE_ROOT"
pwd
date
$PHP_BIN -v
test -f artisan && echo "artisan ok"
test -f webman.php && echo "webman present" || true
```

Check Webman if used:

```bash
ps aux | grep '[w]ebman.php' || true
ss -lntp | grep -E ':(6600|8787|8790)' || true
```

Back up the site and database using the production platform's normal backup
method. At minimum, keep:

- full site file backup
- MySQL database backup
- current overlay backup directory, if any: `$SITE_ROOT/.app-domain-manager-backups`

## Unpack Package

Upload the GitHub Actions artifact tarball and SHA256 file to the server.

Verify checksum:

```bash
cd /tmp
shasum -a 256 -c "$(basename "$PACKAGE").sha256"
```

Unpack:

```bash
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"
tar -xzf "$PACKAGE" -C "$WORK_DIR" --strip-components=1
cd "$WORK_DIR"
```

Confirm package contents:

```bash
test -f install.sh
test -f uninstall.sh
test -f verify.sh
test -f manifest.txt
test -f MANIFEST-SHA256.txt
```

## Preflight

Run preflight first:

```bash
$PHP_BIN scripts/preflight.php "$SITE_ROOT"
```

Expected:

- target exists
- artisan exists
- vendor autoload exists
- bootstrap app exists
- App route/controller/middleware files are either already present or ready to be installed
- node tables have or can receive required App fields

If preflight fails because the site cannot bootstrap Laravel, stop here and fix
the panel runtime before installing the overlay.

## Database Migration

Dry-run migration:

```bash
$PHP_BIN scripts/migrate_app_domain.php "$SITE_ROOT" --dry-run
```

Apply migration only after the dry-run looks correct:

```bash
$PHP_BIN scripts/migrate_app_domain.php "$SITE_ROOT" --apply
```

Run dry-run again. It should report no pending actions:

```bash
$PHP_BIN scripts/migrate_app_domain.php "$SITE_ROOT" --dry-run
```

## Install Dry-Run

Preview file changes:

```bash
bash install.sh --dry-run "$SITE_ROOT"
```

Review:

- total file count
- overwrite count
- create count
- same count
- source SHA256
- target SHA256

If the overwrite list includes unexpected files outside the overlay surface,
stop and inspect `manifest.txt`.

## Apply Install

Apply:

```bash
bash install.sh "$SITE_ROOT"
```

Save the printed values:

- backup path
- install summary path
- exact rollback command

Example:

```bash
ROLLBACK_CMD='bash /tmp/xiaov2b-app-domain-manager-prod/uninstall.sh /www/wwwroot/example.com /www/wwwroot/example.com/.app-domain-manager-backups/20260604-120000'
```

Do not guess rollback paths. Use the exact command printed by the installer.

## Runtime Verify

Basic local verify:

```bash
bash verify.sh "$SITE_ROOT"
```

Scenario verify:

```bash
$PHP_BIN scripts/scenario_verify.php "$SITE_ROOT" app-edge.example.com
```

Optional HTTP verify with real tokens:

```bash
bash verify.sh "$SITE_ROOT" \
  "$BASE_URL" \
  "$SECURE_PATH" \
  "$USER_TOKEN" \
  "$ADMIN_AUTH" \
  "$APP_AUTH"
```

The optional token values must come from the production admin/session context.
Do not store them in repo files.

## Webman And Cache

The installer tries to clear Laravel cache and restart Webman when it can prove
the cached PID belongs to the target site.

If routes or admin assets still look old, restart manually:

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

Confirm service:

```bash
ps aux | grep '[w]ebman.php' || true
curl -I "$BASE_URL/$SECURE_PATH" | head
```

## Functional Checks

Admin:

- open `/$SECURE_PATH#/server/app-domain`
- confirm menu label `中转域名分发`
- confirm `基础`, `入口规则`, `API 域名池` tabs
- toggle a harmless setting and confirm it persists
- create/edit a test entrance rule if production policy allows it

App endpoints:

```bash
curl -fsS "$BASE_URL/api/v2/app/bootstrap" | head
curl -fsS "$BASE_URL/api/v2/app/capabilities" | head
```

With a user token:

```bash
curl -fsS "$BASE_URL/api/v1/client/app/bootstrap?token=$USER_TOKEN" | head
curl -fsS "$BASE_URL/api/v1/client/custom_app/subscribe?token=$USER_TOKEN" | head
curl -fsS "$BASE_URL/api/v1/client/custom_app/subscribe?token=$USER_TOKEN&flag=app_meta" | head
```

Subscription behavior:

- normal web subscription keeps original behavior
- App subscription only returns App-visible nodes
- entrance rule matched nodes use the configured entrance host
- binding port overrides only when configured
- unmatched nodes keep original host when rule mode is enabled

## Rollback

Use the exact command printed by install:

```bash
$ROLLBACK_CMD
```

After rollback:

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
bash "$WORK_DIR/verify.sh" "$SITE_ROOT" || true
```

Rollback restores files from the installer backup. It does not drop database
tables. If a database rollback is required, restore the database backup created
before the upgrade.

## Troubleshooting

Admin 502:

- Check Webman is running.
- Check PHP CLI `disable_functions` if Workerman refuses to start.
- Check the cached Webman PID belongs to the current site.

Admin UI unchanged:

- Check admin asset URL version changed.
- Clear browser/CDN cache.
- Confirm `public/assets/admin/app-domain-manager.js` contains the new markers.
- Restart Webman fully, not only reload.

New routes return 404:

- Confirm overlay route files were copied.
- Clear config/view cache.
- Restart Webman.
- Confirm `App\Providers\RouteServiceProvider` is loading route classes.

Authenticated V2 routes fail:

- Confirm `app/Http/Middleware/AppUser.php` exists.
- Confirm `app/Http/Kernel.php` contains `app.user`.
- Confirm the authorization header value is from the App auth session.

Node entrance mapping wrong:

- Check `入口规则` group scope: user group, plan, and selected nodes.
- Check binding port field.
- Check rule mode setting.
- Run `scenario_verify.php` to distinguish data/config issue from code issue.

Package checksum mismatch:

- Stop. Re-download the artifact.
- Do not install a package whose SHA256 does not match the GitHub artifact.

## Closeout Notes

After a successful production upgrade, record:

- package filename
- package SHA256
- commit hash
- install backup path
- rollback command
- migration result
- verify result
- any manual Webman/cache action
