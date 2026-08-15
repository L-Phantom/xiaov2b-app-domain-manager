# Maintenance Notes

Keep this repository generic and limited to domain distribution.

## Release Checks

```bash
bash -n install.sh uninstall.sh verify.sh scripts/package_release.sh
php -l scripts/migrate_app_domain.php
node --check overlay/public/assets/admin/app-domain-manager.js
git diff --check
bash scripts/package_release.sh
```

Run the fresh-upstream drill whenever V2Board advances. Production deployments require a database backup, a file rollback copy, a migration dry-run, and a single-site canary before the remaining sites are changed.

## Preserved Data

- `v2_app_domain_groups`
- `v2_app_domain_rules`
- `v2_app_domain_bindings`
- `v2_app_domain_assignments`
- `v2_app_domain_replace_batches`

Explicit user assignments are authoritative for cohort routing. Assignment-only groups must never fall through to plan or server-group matching.

## Repository Rules

- Keep `manifest.txt` as the installer source of truth.
- Keep migrations idempotent and dry-run capable.
- Do not add production values or credentials.
- Do not make external user classification a prerequisite for domain routing.
