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
  - `overlay/app/Services/ServerService.php`
  - `overlay/app/Utils/Helper.php`
  - admin-facing App domain management overlay files

## Upgrade Workflow

1. Upgrade upstream panel first.
2. Re-run `bash install.sh /path/to/site`.
3. Run:
   - `bash verify.sh /path/to/site`
   - optional HTTP verify with real `base-url`, `token`, and `admin-auth`
4. If verification fails, diff only the patch-surface files against upstream.

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
- `custom_app/subscribe?flag=app_meta`
- admin `server/app-domain/fetch`

## Future Hardening

- Mirror remote rule providers to your own domain instead of direct GitHub URLs
- Add a small upstream baseline note whenever the patch is rebased
- Keep `verify.sh` aligned with every new App-specific route or template
