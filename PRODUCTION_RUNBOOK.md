# Production Runbook

## Before Deployment

1. Record the upstream commit and runtime versions.
2. Back up the database and current site files outside the web root.
3. Preserve `.env`, `config/v2board.php`, runtime storage, uploads, and custom public assets.
4. Build a fresh upstream staging tree instead of pulling into a dirty production worktree.

## Apply

```bash
SITE_ROOT=/path/to/panel-root
PHP_BIN=php

$PHP_BIN scripts/preflight.php "$SITE_ROOT"
$PHP_BIN scripts/migrate_app_domain.php "$SITE_ROOT" --dry-run
$PHP_BIN scripts/migrate_app_domain.php "$SITE_ROOT" --apply
bash install.sh --dry-run "$SITE_ROOT"
bash install.sh "$SITE_ROOT"
bash verify.sh "$SITE_ROOT"
$PHP_BIN scripts/runtime_verify.php "$SITE_ROOT"
```

## Smoke Checks

- Admin domain-distribution page loads config, groups, bindings, node inventory, and replacement history.
- Existing cohort assignments resolve to the same entrance groups.
- Ordinary and App subscriptions return valid node data.
- Global replacement preview is read-only until explicitly confirmed.
- No removed admin route or asset is referenced by the panel shell.

## Rollback

Use the exact backup directory printed by `install.sh`, then clear Laravel caches and reload the panel runtime. Keep at least one known-good database and file backup until all sites have passed the smoke checks.
