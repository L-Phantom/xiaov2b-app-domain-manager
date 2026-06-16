# Maintenance Notes

This repository is a reusable overlay package for xiaov2b / V2Board-style panels. Keep the public repository generic and free of production identifiers.

## Public Repository Rules

- Do not commit real production IP addresses, domains, customer names, brand names, tokens, secrets, or server paths.
- Use placeholders such as `panel.example.com`, `app-entry.example.com`, `/path/to/panel-root`, and RFC 5737 documentation IPs.
- Keep local packaging or brand-management data out of the public overlay unless it is intentionally promoted as a separate project.
- If a feature needs environment-specific values, document the variable shape instead of committing real values.

## Release Checklist

Before pushing:

```bash
bash -n install.sh
bash -n uninstall.sh
bash -n verify.sh
bash -n scripts/package_release.sh
git diff --check
```

When PHP is available on the target server:

```bash
php scripts/preflight.php /path/to/panel-root
php scripts/migrate_app_domain.php /path/to/panel-root --dry-run
php scripts/migrate_app_domain.php /path/to/panel-root --apply
bash install.sh --dry-run /path/to/panel-root
bash install.sh /path/to/panel-root
bash verify.sh /path/to/panel-root
php scripts/runtime_verify.php /path/to/panel-root
php scripts/scenario_verify.php /path/to/panel-root app-entry.example.com
```

Package:

```bash
bash scripts/package_release.sh
```

## Current Architecture

- `域名分发` handles entrance host/port dispatch through rule groups and bindings.
- Behavior-scoped groups match `risk_levels` or `disposition_statuses` and can affect both App and plain subscriptions.
- Ordinary App groups only affect App-specific subscription flows.
- `行为监管` records subscription pulls, builds account risk profiles, stores risk snapshots, and supports manual review/disposition.
- `ip2region_v4.xdb` is installed into `storage/ip2region/` during `install.sh` if missing.
- Optional IP intelligence can be imported through CSV or free public-source scripts.
- Payment overlay files are included in `manifest.txt` for forks that rely on those callback changes.

## Compatibility Notes

- Treat the target as a Laravel 8-style panel with custom route loaders under `app/Http/Routes/`.
- Prefer additive services/controllers/models over broad rewrites.
- Keep `manifest.txt` as the source of truth for installed files.
- Keep migrations dry-run-first and idempotent.
- `verify.sh` should fail loudly when required runtime assets, tables, or IP database files are missing.

## Deployment Notes

- Always take a file and database backup before applying the overlay to a production panel.
- Save the rollback command printed by `install.sh`.
- If admin assets look stale after deployment, clear browser cache and server caches, then restart Webman if used.
- After changing risk rules, rebuild risk snapshots from the admin UI or with a dedicated server-side workflow.

## Next Development Items

- Export/import risk rule presets.
- More scenario tests for behavior-scoped dispatch and mixed node types.
- Better audit views for manual disposition changes.
- Scheduled maintenance examples for cleanup and snapshot rebuild.
- Clearer optional packaging for payment overlay files.
