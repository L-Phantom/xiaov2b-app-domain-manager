# xiaov2b App Domain Manager

Reusable domain-distribution overlay for xiaov2b and V2Board-style Laravel panels.

## Features

- App subscription and API-domain bootstrap endpoints.
- Entrance host and port dispatch by node, plan, and server group.
- Explicit per-user cohort assignments for stable lane routing.
- Assignment-only entrance groups that do not match unassigned users.
- Global host replacement with preview, audit history, conflict checks, and rollback.
- Node visibility and per-node domain replacement controls.
- V1 and V2 App compatibility endpoints.

The overlay intentionally does not collect subscription access history or build user profiles. Existing cohort assignments remain valid until an operator or external controller changes them.

## Layout

- `overlay/`: files copied into a target panel.
- `manifest.txt`: exact overlay file list.
- `install.sh`: dry-run capable installer with file backups.
- `uninstall.sh`: restores an installer backup.
- `scripts/migrate_app_domain.php`: idempotent domain table migration.
- `scripts/configure_entry_cohort_groups.php`: configures fixed cohort entrance groups.
- `scripts/runtime_verify.php`: runtime and table checks.
- `scripts/fresh_upstream_drill.sh`: applies the overlay to a fresh upstream checkout.
- `scripts/package_release.sh`: builds a checksummed release archive.

## Install

```bash
php scripts/preflight.php /path/to/panel-root
php scripts/migrate_app_domain.php /path/to/panel-root --dry-run
php scripts/migrate_app_domain.php /path/to/panel-root --apply
bash install.sh --dry-run /path/to/panel-root
bash install.sh /path/to/panel-root
bash verify.sh /path/to/panel-root
php scripts/runtime_verify.php /path/to/panel-root
```

Use the target server's versioned PHP binary when required.

## Upgrade Drill

```bash
UPSTREAM_REF=858effa102656df146b1bdde0a9387405ee92cc3 \
  bash scripts/fresh_upstream_drill.sh
```

For a local upstream tree:

```bash
UPSTREAM_LOCAL_PATH=/path/to/v2board \
  bash scripts/fresh_upstream_drill.sh
```

## Rollback

Every installation creates a backup under:

```text
/path/to/panel-root/.app-domain-manager-backups/<timestamp>
```

Restore it with:

```bash
bash uninstall.sh /path/to/panel-root /path/to/panel-root/.app-domain-manager-backups/<timestamp>
```

Never commit production domains, IPs, user identifiers, tokens, credentials, or private keys.
