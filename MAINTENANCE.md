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
- V2 App API compatibility files under:
  - `overlay/app/Http/Routes/V2/AppRoute.php`
  - `overlay/app/Http/Controllers/V2/App/*Controller.php`
  - `overlay/app/Http/Middleware/AppUser.php`
  - `overlay/app/Services/AppClientProfileService.php`
  - `overlay/app/Http/Kernel.php` for the `app.user` middleware alias
- node visibility / node domain replacement files under:
  - `overlay/app/Http/Controllers/V1/Admin/Server/*Controller.php`
  - `overlay/app/Http/Requests/Admin/Server*Update.php`
  - `overlay/public/assets/admin/umi.js`
  - `overlay/public/assets/admin/app-domain-manager.js`
  - `overlay/resources/views/admin.blade.php`
  - `overlay/routes/web.php`
- subscription behavior monitoring files under:
  - `overlay/app/Models/SubscribeAccessLog.php`
  - `overlay/app/Services/SubscribeMonitorService.php`
  - `overlay/app/Http/Controllers/V1/Admin/Server/SubscribeMonitorController.php`
  - `overlay/public/assets/admin/subscribe-monitor-manager.js`
  - `sql/subscribe_access_logs.sql`

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
- `/api/v2/app/bootstrap`
- `/api/v2/app/capabilities`
- `/api/v2/app/client/config` when an App auth token is available
- `/api/v2/app/nodes/manifest` when an App auth token is available
- admin `server/app-domain/fetch`
- admin `server/app-domain/save`
- admin `server/app-domain/config`
- admin `server/app-domain/rules`
- admin `server/app-domain/options`
- admin `server/subscribe-monitor/fetch`
- admin route `/#/server/app-domain`
- admin route `/#/server/subscribe-monitor`
- `app_show=0` nodes are not returned to App-only subscription
- `app_domain_replace=0` nodes keep their original host when global App domain replacement is enabled
- when `app_domain_rule_enable=1`, unmatched nodes keep their original host instead of falling back to the global replacement host
- normal web subscription keeps original behavior and does not use the App-only template
- behavior monitoring remains observe-only. It records subscription/App config access best-effort and must never block, poison, rate-limit, or change subscription responses.

## Phase 5 Release Engineering

- Use `bash install.sh --dry-run /path/to/site` before every apply. The dry-run prints create/overwrite/same status plus source and target SHA256 for every manifest file.
- Every apply writes `.app-domain-manager-backups/<timestamp>/state.tsv` and `install-summary.tsv`.
- Use the exact rollback command printed by `install.sh`; do not guess the backup timestamp during production work.
- Use `bash scripts/package_release.sh` to produce the reusable overlay tarball. The package excludes untracked `platform/` by design and includes `MANIFEST-SHA256.txt`, `ROOT-SHA256.txt`, and a tarball `.sha256`.
- GitHub Actions workflow `Package Overlay` runs static validation and uploads the release tarball plus SHA256 on push/PR/manual dispatch.
- Use `bash scripts/fresh_upstream_drill.sh` to verify the package can install structurally on a fresh upstream checkout. Full runtime/database/HTTP verification still belongs on the test platform or a drill copy with vendor and DB configured.

## Future Hardening

- Mirror remote rule providers to your own domain instead of direct GitHub URLs
- Add a small upstream baseline note whenever the patch is rebased
- Keep `verify.sh` aligned with every new App-specific route or template
- Keep `scripts/preflight.php` aligned with schema, PHP CLI, route, config, opcache, and webman reload checks
- Add `--dry-run` support to the installer so production can preview backup and overwrite scope
- Add checksum / diff summary for every overlay file before install
- Make admin secret fields write-only or masked by default so existing secrets are not exposed or accidentally overwritten
- Add package backend deployment notes if `platform/` becomes part of the production source of truth

## V2 App API Surface

- The package now carries the V2 App route and controller surface that previously existed on the test platform but was not fully tracked in the overlay package.
- `AppRoute.php` is required for `/api/v2/app/*`; without it, copying `V2/App/AppController.php` alone is not enough.
- The `app.user` middleware alias in `Kernel.php` is required for authenticated V2 endpoints such as `/api/v2/app/client/config`, `/api/v2/app/user/info`, `/api/v2/app/nodes/manifest`, and orders.
- Public V2 endpoints include bootstrap, capabilities, client version/debug, disaster recovery, notices, plans, and auth login/register/email-code.
- Authenticated V2 endpoints include session/logout, client config, user info, node manifest/list, orders, and diagnostics report.
- `preflight.php` and `verify.sh` should continue to check this surface whenever V2 App API files change.

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

## Behavior Monitoring

- `行为监管` is a separate server menu item, not a subpage of `域名分发`.
- First production version is observe-only:
  - records `/api/v1/client/subscribe`
  - records custom App subscription paths such as `/api/v1/client/custom_app/subscribe`
  - records `flag=app_meta`
  - records `/api/v1/client/app/getConfig`
  - never stores raw token, only SHA256 token hash prefix is shown in UI
  - write failures are swallowed so user subscription delivery is not affected
- Admin UI shows today/range totals, unique users/IPs/tokens, host ranking, high-frequency accounts, single-IP multi-token alerts, and recent records.
- 2026-06-16 product correction: the primary UI should be account-risk oriented, not raw event-log oriented. First screen shows `账号风险画像` with `无风险 / 中风险 / 高风险 / 极危险`, risk score, reasons, request/IP/host/client counts, and last seen time. Expanding an account shows recent subscription pulls with method, device/client, network IP, entry host, path, and status.
- Do not automatically move users into special permission groups yet. The intended future direction is to let risk groups cooperate with domain distribution, but first keep the feature as observation + manual judgment to avoid false positives affecting production users.
- 2026-06-16 follow-up: risk prediction must be configurable, not hard-coded. Added `GET/POST server/subscribe-monitor/config`, persisted under `v2board.subscribe_monitor_risk_rules`, and the UI exposes a `风险规则` panel for risk level score lines plus IP/entry/client/request/token/failure thresholds and scores. The account list no longer shows risk-reason description chips; it only shows level, score, and metrics.
- 2026-06-16 database-signal hardening: before adding an IP geolocation/reputation database, the monitor now extracts every safe signal available from local subscription logs:
  - short-window frequency: last 10 minutes, last hour, today, max requests per minute, and shortest interval
  - account state: expired account, no plan, and exhausted traffic still pulling subscriptions
  - client behavior: suspicious User-Agent keywords, trusted client keywords, and empty User-Agent hits
  - entry behavior: trusted/watch/risk host lists, with optional unknown-host scoring when trusted hosts are configured
  - sharing behavior: same IP used by multiple accounts or multiple token hashes inside the selected time range
- These signals remain observe-only. They only adjust the account risk profile returned by `server/subscribe-monitor/fetch`; they do not block subscriptions, mutate users, or change permission groups.
- 2026-06-16 ip2region integration:
  - Added `v2_subscribe_ip_cache`, `SubscribeIpCache`, `Ip2RegionService`, and the official Apache-2.0 ip2region PHP xdb searcher under `app/Support/Ip2Region/Searcher.class.php`.
  - The xdb database file is intentionally not bundled in the overlay package. Use `bash scripts/update_ip2region_xdb.sh /path/to/v2board-root` to place `ip2region_v4.xdb` under `storage/ip2region/`.
  - If the xdb file is absent, behavior monitoring remains functional and simply omits geolocation signals.
  - Account behavior now exposes `geo` metrics and per-record `ip_region`; risk rules can score multi-country, multi-region, multi-city, and multi-ISP behavior.
- 2026-06-16 IP intelligence importer:
  - Added `scripts/update_ip_intelligence.php` as the stable ingestion path for ASN, AS name, network type, and proxy/VPN/Tor/Bot intelligence.
  - The importer can export recently seen IPs that still lack intelligence with `--export-missing`, then import trusted CSV data with `--dry-run` / `--apply`.
  - It intentionally does not call a live third-party API and does not infer proxy/VPN by itself. Unknown risk remains unknown until a reliable source fills `ip_risk_type`.
  - `runtime_verify.php` now reports IP cache count and rows with real intelligence so deployments can tell whether the behavior monitor is using only geography or richer network intelligence.
- 2026-06-17 behavior-monitor cache and linkage closure:
  - Added `v2_subscribe_risk_snapshots` and `SubscribeRiskSnapshot` so account risk profiles can be cached as a timeline instead of only being recalculated from raw access logs.
  - `server/subscribe-monitor/snapshots/rebuild` manually rebuilds snapshots after rule changes; unchanged profiles do not create duplicate snapshot rows.
  - Added `scripts/cleanup_subscribe_monitor.php` for retention cleanup: raw access logs, risk snapshots, and IP cache are cleaned by age; disposition logs stay long-term.
  - The behavior monitor now exposes snapshot/IP/retention status cards, risk timeline detail tab, observation and blacklist queues, batch handled/clear actions, and a basic server-side pagination foundation.
  - Added `server/subscribe-monitor/dispatch-preview` and the drawer `下发预览` tab. It shows the current user's subscribe URL, risk/disposition state, matched entrance group or legacy rule, original host/port, delivered host/port, and hidden-node decisions.
  - App domain entrance groups now accept `risk_levels`, `disposition_statuses`, and `hide_matched_nodes` through validation, so risk/disposition based domain distribution can actually be saved.
  - Added disposition note/operator filtering and watch overdue hints. Admins can filter by disposition note/operator, choose an overdue threshold, and see `超过 N 天未复核` in observation/blacklist queues.
  - IP intelligence status cards now distinguish xdb database status, cache count, ASN count, IDC count, VPN count, hit/miss count, and cache retention, so the monitor is less of a black box.
  - Snapshot-backed profile overview is now separated from the paged account table: top risk distribution, high-risk list, observation queue, and blacklist queue use latest risk snapshots for the current filter scope, while `账号风险列表` remains server-side paginated. If snapshots are unavailable, the UI clearly falls back to current-page statistics through the `画像统计口径` card.
  - This remains manual/observe-only. The system can suggest or preview risk-based distribution, but it must not automatically ban, freeze, or mutate user groups.
- 2026-06-17 blacklist entrance group closure:
  - Domain distribution now exposes a `创建黑名单入口组` UI template under `入口规则`. It preselects the `建议拉黑` disposition status and keeps the actual entrance domain/node binding in `域名分发`, not in behavior monitor.
  - Toggling an entrance group no longer drops `risk_levels`, `disposition_statuses`, or `hide_matched_nodes`, preventing a blacklist-specific group from accidentally becoming a global entrance group.
  - `scripts/scenario_verify.php` now performs a transaction-only drill for `建议拉黑` disposition routing: dedicated host/port are applied while the disposition exists, preview reports the matched group, and clearing the disposition stops the dedicated routing.
- 2026-06-17 plain subscription behavior-scope routing fix:
  - The original closure only applied entrance groups through `getAvailableAppServers()`, so `/api/v1/client/subscribe` kept original hosts even after a user was marked `建议拉黑`.
  - `ServerService::getAvailableServers()` now applies only behavior-scoped entrance groups, meaning groups with `risk_levels` or `disposition_statuses`. This lets blacklist/watch/risk entrance groups affect plain subscriptions while keeping normal App-only global/rule distribution out of the public subscription path.
  - `scripts/scenario_verify.php` now checks both plain subscribe server payloads and App subscribe payloads for blacklist dedicated host/port, plus clear-disposition rollback.
- 2026-06-17 domain distribution UI closure:
  - Admin menu/page display name is now `域名分发`.
  - `入口规则` now separates behavior-scoped entrance groups into `行为处置入口` and regular App distribution groups into `普通入口规则`, so blacklist/watch entrances are visually managed apart from normal rules.
  - The rule table now uses compact `匹配摘要` chips with full scope in hover text, fixed table column sizing, and a real `查看映射` / `收起映射` toggle for mapping previews.
  - `AppDomainService::matchBindingPayload()` sorts behavior-scoped groups before normal groups for App subscription matching, keeping runtime priority consistent with the UI sections.
  - `scripts/scenario_verify.php` also confirms ordinary entrance groups affect App subscriptions but do not affect plain `/api/v1/client/subscribe`.
- 2026-06-16 free IP intelligence updater:
  - Added `scripts/update_free_ip_intelligence.php` for the user's preferred no-cost intelligence path.
  - Current default sources: IPtoASN IPv4/IPv6 TSV for ASN / AS name / country code, plus X4BNet IPv4 Datacenter/VPN CIDR lists for coarse network/risk tagging.
  - This is intentionally approximate and should be treated as one signal among request frequency, traffic behavior, host policy, UA behavior, and same-IP sharing. It must not auto-block or auto-move users without a later explicit product decision.
- Future ASN/proxy/VPN database integrations should write into `v2_subscribe_ip_cache` through the importer format or an equivalent provider wrapper, instead of creating a separate risk model.
