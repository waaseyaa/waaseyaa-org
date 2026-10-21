# waaseyaa.org deployment

Verified hosting on 2026-10-21: Intersnipe, isolated runtime user and systemd
service `waaseyaa-org`, PHP-FPM 8.5 on loopback port 19099, nginx HTTPS vhost
`/etc/nginx/sites-available/waaseyaa-org`. The Pi application is retired.

Source releases live at `/srv/waaseyaa-org/releases/<full-commit>`. Both the
systemd WorkingDirectory and nginx root/SCRIPT_FILENAME select the release.
There is no `current` symlink. Persistent data is under `/var/lib/waaseyaa-org`;
the release's `storage` symlink points to that service's storage directory.
Secrets stay in `/etc/waaseyaa-org/runtime.env`, readable only by root and the
runtime group. Read the local credential-location index and use the configured
SSH profile; never copy or print secret values into a repository or log.

## Qualify

1. Require green Quality and Production-shaped container smoke for the exact
   candidate. Run the fresh-install tutorial against its published framework
   cohort. Dependency upgrades require a regenerated spec corpus and release note.
2. Transfer an archive of the exact source commit to a new release directory.
   Install the committed lock with `composer install --no-dev --prefer-dist
   --no-interaction --optimize-autoloader`. Check platform requirements.
3. Take a consistent SQLite backup using SQLite's backup API. Copy it to an
   isolated staging storage directory owned by the runtime account. Preserve the
   production database, runtime configuration and currently selected source.
4. Under the runtime account and existing environment, override only the staging
   database/storage paths. Run `db:init`, `install:init` and `content:sync` with
   `APP_ENV=local`. The public runtime always stays `APP_ENV=production`.
5. In production mode, run `field-access:preflight --write-artifact`, warm the
   candidate, and regenerate the artifact against the settled schema. Require
   `php bin/health-probe.php` and `sh docker/smoke.sh <loopback-candidate-url>`.
   Check the rendered guide, version, release page, HTML and Markdown corpus,
   MCP tools and anonymous write refusal. Keep staging bound to loopback.

## Promote and recover

Preserve a consistent recovery snapshot immediately before the production
migrations. Quiesce only the Waaseyaa service while changing its data and source
selection. Run the same initialization and content-sync sequence against the
production database, regenerate the activation artifact, and verify readiness.
Update only this service's WorkingDirectory and this vhost's release paths.
Run `nginx -t`, reload nginx, and restart only `waaseyaa-org.service`.

Verify the public health endpoint, rendered guide, framework version, release
note, docs HTML/Markdown, MCP and route refusals. Record the deployed source SHA,
framework tag, check results and recovery snapshot location in the delivery record.

If promotion fails, stop this service, restore its database and storage snapshot,
restore its previous systemd and nginx configuration, validate nginx, and restart
the prior release. Keep both source releases and the recovery snapshot until the
new release has passed public verification. Do not restart unrelated host services
or revive the retired Pi deployment.
