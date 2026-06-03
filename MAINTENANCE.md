# Maintenance Plan

## Goal

Keep the patch pack upgrade-friendly when upstream `wyx2685/v2board` changes.

## Patch Surface

This repository should keep App-specific behavior isolated to a small overlay set:

- `overlay/app/Http/Controllers/V1/Client/ClientController.php`
- `overlay/app/Protocols/ClashMeta.php`
- `overlay/resources/rules/app.meta.clash.yaml`
- existing App domain management files under:
  - `overlay/app/Http/Controllers/V1/Client/AppController.php`
  - `overlay/app/Http/Routes/V1/ClientRoute.php`
  - `overlay/app/Services/AppDomainService.php`
  - `overlay/app/Services/ServerService.php`
  - `overlay/app/Utils/Helper.php`
  - admin-facing App domain management overlay files
- node visibility / node domain replacement files under:
  - `overlay/app/Http/Controllers/V1/Admin/Server/*Controller.php`
  - `overlay/app/Http/Requests/Admin/Server*Update.php`
  - `overlay/public/assets/admin/umi.js`
  - `overlay/public/assets/admin/app-domain-manager.js`
  - `overlay/resources/views/admin.blade.php`
  - `overlay/routes/web.php`

## Current Production Context

- The paired client app is already in production / formal delivery state at `1.0.3`.
- The important client-side v1.0.3 commit is `0e87b0b` from 2026-05-21:
  - force update
  - platform icon fixes
  - register email verification fallback
  - forgot password API fix
  - `pubspec.yaml` version bump to `1.0.3`
- The latest confirmed client maintenance commit is `cecd95b` from 2026-05-22/23:
  - Windows VC runtime DLL bundling
  - global Dio connect / receive timeouts
  - diagnostic logging in critical catch blocks
  - `apply_branding.dart` icon fetching updates
- Build flow for that client line:
  - macOS: local build
  - Android: local build
  - Windows: GitHub Actions build
- Do not treat this project as a pre-delivery candidate by default. Backend work should be planned as production maintenance / staged rollout.

## Packaging Backend

- The packaging / branding backend is the untracked `platform/` module in this repository.
- It provides the Brand Manager for:
  - brand configuration
  - API pool and encrypted manifest generation
  - manifest / subscribe secrets
  - OSS manifest URLs
  - landing page and support IDs
  - brand icon upload
  - Android / macOS / Windows package upload
  - release history and force update
  - `branding.dart` preview
- Local `platform/data` only contains `.gitkeep`; production brand data is expected to live on the deployed server.
- If this backend is prepared for long-term production maintenance, first decide whether to formally track `platform/` in git, then add deployment docs, default credential handling, package checksum recording, and rollback notes.

## Upgrade Workflow

1. Upgrade upstream panel first.
2. Before installing the overlay, run a preflight pass on the upgraded test platform:
   - confirm `artisan` exists
   - confirm the site has the expected node tables / fields or has migrations for `app_show` and `app_domain_replace`
   - confirm PHP CLI is available on the server
   - confirm the admin secure path and one test user token are available
3. Re-run `bash install.sh /path/to/site`.
4. Run:
   - `bash verify.sh /path/to/site`
   - optional HTTP verify with real `base-url`, `token`, and `admin-auth`
5. If verification fails, diff only the patch-surface files against upstream.

## Next Staged Test Plan

- The user will manually upgrade the test platform first.
- After the test platform upgrade is complete, Codex should apply this overlay package to the test platform.
- Current state on 2026-06-02: waiting for the user's manual test-platform upgrade; do not install the overlay before that confirmation.
- First intervention after the user confirms the upgrade:
  1. inspect the upgraded platform version and current files
  2. compare the current upstream files against `manifest.txt`
  3. check database schema for `app_show` and `app_domain_replace`
  4. install the overlay with backup
  5. clear/cache config and reload webman if present
  6. run local runtime verification
  7. run HTTP verification for App bootstrap, App config, App subscribe, App meta subscribe, and admin fetch
  8. record exact changed files, backup path, route results, and rollback command

## Compatibility Strategy

The App should continue to support two stages:

1. Bootstrap stage:
   - `custom_app/subscribe`
   - `app/getConfig`
2. Full stage:
   - `custom_app/subscribe?flag=app_meta`
   - fallback to generic `flag=meta` on unpatched panels

This means panel upgrades do not have to preserve App-specific full-meta logic for the client to remain usable.

## Recommended Validation

- Login via app-facing auth routes
- `custom_app/subscribe`
- `app/getConfig`
- `app/getVersion`
- `app/bootstrap`
- `custom_app/subscribe?flag=app_meta`
- admin `server/app-domain/fetch`
- admin `server/app-domain/save`
- admin `server/app-domain/config`
- admin `server/app-domain/rules`
- admin `server/app-domain/options`
- admin route `/#/server/app-domain`
- `app_show=0` nodes are not returned to App-only subscription
- `app_domain_replace=0` nodes keep their original host when global App domain replacement is enabled
- when `app_domain_rule_enable=1`, unmatched nodes keep their original host instead of falling back to the global replacement host
- normal web subscription keeps original behavior and does not use the App-only template

## Future Hardening

- Mirror remote rule providers to your own domain instead of direct GitHub URLs
- Add a small upstream baseline note whenever the patch is rebased
- Keep `verify.sh` aligned with every new App-specific route or template
- Keep `scripts/preflight.php` aligned with schema, PHP CLI, route, config, opcache, and webman reload checks
- Add `--dry-run` support to the installer so production can preview backup and overwrite scope
- Add checksum / diff summary for every overlay file before install
- Make admin secret fields write-only or masked by default so existing secrets are not exposed or accidentally overwritten
- Add package backend deployment notes if `platform/` becomes part of the production source of truth

## Admin Asset Cache Strategy

- Admin UI assets should not rely only on `config('app.version')` for cache busting.
- The overlay admin shell now calculates an `asset_version` from the newest mtime of the admin JS/CSS assets.
- This means that after `umi.js`, `app-domain-manager.js`, or other admin assets are overwritten by a patch, the browser/CDN URL changes automatically.
- Installer behavior should prefer a full Webman restart after overlay install; a `SIGUSR1` reload may leave old routes or old static shell state in memory on upgraded panels.

## App Domain Rule UI

- Daily入口域名分发 should be managed from `入口域名规则`, not from node management.
- UI should keep the native rule-management shape. Internally, entrance groups behave like parent rules: they carry the replacement host and user/plan scope. Bindings behave like child node entries: they carry server type/id and optional replacement port.
- Binding `port` is optional and only affects node entry delivery. Empty `port` keeps the original node port for backward compatibility.
- When rule mode is enabled, matching bindings override node host/port even if the legacy per-node `app_domain_replace` field is 0. That legacy field only gates the old global replacement path.
- Old `v2_app_domain_rules` rows remain available as compatibility fallback, but normal UI should not expose them as the primary operation surface.
- The node management `app_domain_replace` field remains available to existing controllers and old data, but its visible `域名替换` table/mobile controls are hidden to avoid a second competing policy surface.
