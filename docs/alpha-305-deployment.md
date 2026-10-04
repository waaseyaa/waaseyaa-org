# Alpha 305 deployment

The application pins every direct Waaseyaa dependency to `0.1.0-alpha.305`.
The generated corpus and release note describe that exact framework release.
CommonMark is updated to 2.10.3 to pass the existing dependency audit gate.

## Initialization

Use a fresh deployment database or preserve a consistent snapshot before upgrading
an existing one. Run `APP_ENV=local php vendor/bin/waaseyaa db:init`, followed by
`APP_ENV=local php vendor/bin/waaseyaa install:init`, before a full production
boot. These commands apply package migrations, create entity tables and activate
configuration authority. A blank SQLite file alone is insufficient.

The search projection is now migration-owned. Request handling must not provision
its schema. The application resolves search pointers against its public spec
corpus using the read-only spec principal. MCP authentication returns that same
authorization principal. Neither change grants content writes or account access.

After initialization, sync git-authored content with `content:sync`, generate the
field-access activation artifact and warm the application, then regenerate the
artifact against the settled schema. Keep application and auth token secrets in
protected runtime storage. Require `bin/health-probe.php`, `/healthz` and
`docker/smoke.sh` to pass before public cutover.

## Hosting and retirement

Russell selected existing Intersnipe infrastructure for the redesigned site.
The Pi application remains the current production service until the replacement
passes readiness and public route checks. Preserve a verified recovery copy before
removing the Pi application container, dedicated volumes, routing, deployment pin,
telemetry and scheduled jobs. Preserve unrelated services in the shared Pi stack.

The upgrade is a separate reviewed milestone. Its merge alone does not deploy the
redesign, change DNS or authorize a claim that Pi retirement has completed.
