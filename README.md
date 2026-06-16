# xiaov2b App Domain Manager

Reusable overlay package for xiaov2b / V2Board-style Laravel panels.

It adds two admin modules:

- `域名分发`: rule-based entrance domain and port dispatch for App subscriptions.
- `行为监管`: subscription access monitoring, account risk profiles, manual review queues, and behavior-linked dispatch.

The package is designed to be re-applied after upstream panel upgrades. It keeps a manifest, backs up overwritten files, runs database migrations explicitly, and provides local verification scripts.

## What It Deploys

- Admin UI entries under the server management area.
- App-specific V1 subscription, bootstrap, config, and version endpoints.
- `/api/v2/app/*` compatibility endpoints for App login state, user info, nodes, plans, orders, notices, and diagnostics.
- App-only Clash Meta subscription template.
- Node visibility and entrance-domain fields for supported server tables.
- Rule tables for entrance domain dispatch.
- Subscription access logs, IP cache, manual disposition, and risk snapshot tables.
- `ip2region` xdb integration for coarse IP region lookup.
- Optional payment callback overlay files listed in `manifest.txt`.

## Main Features

`域名分发` supports:

- Dispatch by node, node type, plan, user group, risk level, and manual disposition.
- Host and port replacement.
- Separate behavior-disposition entrance groups from ordinary App distribution rules.
- Plain subscription protection: ordinary App rules do not affect normal `/api/v1/client/subscribe`; only behavior-scoped groups can affect plain subscriptions.
- Preview and scenario verification for rule matching.

`行为监管` supports:

- Logging subscription/App config pulls without storing plaintext tokens.
- Account-level risk profiles based on request frequency, token/IP sharing, account state, traffic usage, client User-Agent, entry host, and IP intelligence.
- Configurable risk thresholds and scoring.
- `待复核` and `建议拉黑` queues.
- Manual disposition notes, operator filters, overdue review hints, and profile clearing.
- Risk snapshot rebuild and timeline view.
- Optional ASN / IDC / VPN style enrichment through CSV or free public IP intelligence import scripts.

## Repository Layout

- `overlay/`: files copied into the target panel.
- `manifest.txt`: exact overlay file list.
- `install.sh`: installs overlay files, creates backups, clears caches, and installs the IP xdb if missing.
- `uninstall.sh`: restores files from an installer backup.
- `verify.sh`: verifies installed files, runtime bootstrap, and optional HTTP routes.
- `scripts/preflight.php`: checks target panel readiness.
- `scripts/migrate_app_domain.php`: dry-run/apply database migrations.
- `scripts/runtime_verify.php`: checks runtime service state.
- `scripts/scenario_verify.php`: verifies dispatch scenarios without persisting test data.
- `scripts/package_release.sh`: builds a release tarball and checksum.
- `scripts/update_ip2region_xdb.sh`: manually refreshes `ip2region_v4.xdb`.
- `scripts/update_ip_intelligence.php`: exports missing IPs or imports trusted IP intelligence CSV.
- `scripts/update_free_ip_intelligence.php`: imports coarse free ASN/datacenter/VPN data.
- `scripts/cleanup_subscribe_monitor.php`: cleans old behavior-monitor records by retention policy.
- `PRODUCTION_RUNBOOK.md`: generic production deployment checklist.

## Install

Run commands on the target server or in a shell that can access the target panel root.

```bash
cd /path/to/xiaov2b-app-domain-manager

php scripts/preflight.php /path/to/panel-root
php scripts/migrate_app_domain.php /path/to/panel-root --dry-run
php scripts/migrate_app_domain.php /path/to/panel-root --apply

bash install.sh --dry-run /path/to/panel-root
bash install.sh /path/to/panel-root
bash verify.sh /path/to/panel-root
```

If the server uses a versioned PHP binary, replace `php` with that binary, for example `php82`.

`install.sh` automatically creates `storage/ip2region/` and downloads `ip2region_v4.xdb` when the file is missing or too small. To use a mirror:

```bash
IP2REGION_XDB_URL="https://mirror.example.com/ip2region_v4.xdb" bash install.sh /path/to/panel-root
```

Manual refresh is also available:

```bash
bash scripts/update_ip2region_xdb.sh /path/to/panel-root
```

## Verify

Basic local verification:

```bash
bash verify.sh /path/to/panel-root
php scripts/runtime_verify.php /path/to/panel-root
php scripts/scenario_verify.php /path/to/panel-root app-entry.example.com
```

Optional HTTP verification with real session values:

```bash
bash verify.sh /path/to/panel-root \
  "https://panel.example.com" \
  "admin-secure-path" \
  "user-token" \
  "admin-authorization-header" \
  "app-authorization-header"
```

Do not commit real domains, tokens, credentials, or production IP addresses.

## Rollback

Each install creates a backup under the target panel:

```text
/path/to/panel-root/.app-domain-manager-backups/<timestamp>
```

Use the exact rollback command printed by `install.sh`:

```bash
bash uninstall.sh /path/to/panel-root /path/to/panel-root/.app-domain-manager-backups/<timestamp>
```

## IP Intelligence

The built-in xdb lookup provides coarse region and ISP data. Extra intelligence is optional.

Export missing IPs:

```bash
php scripts/update_ip_intelligence.php /path/to/panel-root --export-missing > missing-ips.csv
```

Import a trusted CSV:

```bash
php scripts/update_ip_intelligence.php /path/to/panel-root ip-intelligence.csv --dry-run
php scripts/update_ip_intelligence.php /path/to/panel-root ip-intelligence.csv --apply
```

CSV fields:

```csv
ip,asn,as_name,network_type,ip_risk_type,ip_risk_score,source
203.0.113.10,64500,Example ASN,idc,,0,manual_csv
```

Allowed values:

- `network_type`: `idc`, `fixed`, `mobile`
- `ip_risk_type`: `proxy`, `vpn`, `tor`, `bot`

Free-source enrichment:

```bash
php scripts/update_free_ip_intelligence.php /path/to/panel-root --dry-run
php scripts/update_free_ip_intelligence.php /path/to/panel-root --apply
```

## Data Retention

Preview cleanup:

```bash
php scripts/cleanup_subscribe_monitor.php /path/to/panel-root --dry-run
```

Apply cleanup:

```bash
php scripts/cleanup_subscribe_monitor.php /path/to/panel-root --apply \
  --access-log-days=180 \
  --snapshot-days=365 \
  --ip-cache-days=90
```

Recommended retention:

- Raw access logs: 90-180 days.
- Risk snapshots: 365 days.
- IP cache: 30-90 days.
- Manual disposition logs: keep long term.

## Development Plan

Next useful improvements:

- Make risk rules easier to export/import between panels.
- Add more regression scenarios for mixed plan/group/risk dispatch.
- Improve admin-side bulk operations and audit history.
- Add scheduled command examples for risk snapshot rebuild and data cleanup.
- Keep payment overlay files optional and better documented for forks that do not need them.

## Notes

- `platform/` is a local packaging/brand-management helper and is not part of the public overlay package unless promoted as a separate project.
- This repository should stay generic. Keep production domains, IPs, brand names, secrets, and customer identifiers out of committed files.
