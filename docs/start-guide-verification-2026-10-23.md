# Start guide verification, October 23, 2026

The public `/start` walkthrough targets framework `v0.1.0-alpha.307`, matching
this site's pinned dependency and reference corpus. This change verifies that
version and clarifies the setup checkpoints. It does not upgrade the website.

## Reproduced setup drift

A clean execution of the displayed command selected the alpha.307 starter but
resolved its `^0.1.0-alpha.307` framework requirement to alpha.308. The existing
fresh-install verifier correctly refused that mismatch before initializing the
application. A starter tag alone does not pin its dependency graph.

The guide now sets an exact framework requirement with `composer require
waaseyaa/framework:v0.1.0-alpha.307 --no-update`, then resolves `waaseyaa/*`
together with `--with-all-dependencies`. Updating the framework package alone
failed because the previously selected development package retained the newer
cohort. The complete cohort update succeeded. The guide asks the reader to
check the selected framework version and retain `composer.lock`.

CI follows the same two pinning commands before its fresh walkthrough. The
existing exact-version guard remains in place.

## Documentation corrections

- Explain that the seed defines site identity and the published-page contract;
  it does not insert example content or create the application-owned Todo code.
- Identify the generated contract, ownership record, provider, recipe files,
  templates and verification entrypoint.
- Explain when the complete entity-registration example can replace the empty
  fresh-project array and when existing registrations must be preserved.
- Put a provider-registration checkpoint beside the installation commands.
- Document a missing-provider recovery that retains the database and existing
  providers, then rebuilds autoloading and the manifest and verifies the site.

The seven displayed application files are byte-identical to those extracted
from the captured public guide. Application behavior and the tutorial component
examples did not change.

## Observed validation

Native Windows, PHP 8.5.5, Composer 2.9.5, SQLite extensions enabled:

- A fresh starter with the corrected exact pin resolved all 67 Waaseyaa
  production/development packages to alpha.307.
- The displayed application passed initialization, strict site verification,
  empty and blank-title cases, escaped output, create/toggle/delete persistence,
  unknown-ID 404s and denial when development authentication was disabled.
- The documented generated files existed, the fresh entity-registration array
  was empty, and adding Todo preserved the generated files.
- Removing the Todo provider and rebuilding the manifest reproduced `/todos`
  returning 404. Restoring it and repeating the four documented commands
  restored the page and the same saved todo ID and title.
- A separate fresh alpha.308 starter, with all 67 packages at alpha.308, passed
  the same application and recovery checks. Its evidence is compatibility
  evidence, not an upgrade of this site's dependency or reference corpus.
- PHPUnit: 170 tests, 1,998 assertions passed. PHPStan passed with a 512 MiB
  CLI memory limit after the host's 128 MiB default exhausted a worker.
- Composer validation and production dependency audit, coding style, PHP
  syntax and spec-corpus regeneration checks passed. No corpus drift occurred.

Both temporary HTTP servers were owned by the verifier and stopped afterward.
The fresh consumers, original version-mismatch failure and failed one-package
pin attempt were retained. No live website deployment occurred. These native
development checks are not production/S1 certification. Linux CI and container
checks are separate evidence recorded on the pull request.
