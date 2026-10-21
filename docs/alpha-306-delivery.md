# Alpha 306 guide, qualification and deployment

Authorized by Russell on 2026-10-21: repair the guide, verify the complete fresh
install walkthrough, cut Framework alpha.306 after its checks pass, and deploy
waaseyaa.org with the matching published dependencies and documentation.

Base site: d577aec70a9f2b52611b4d01e7513cd6fd23758e.
Framework release base at inspection: cf2566e01067a84d40b8e804979471abda9621de.

The tutorial must provide every application file, follow create, site contract,
install, verify and serve, and exercise create/list/toggle/delete through HTTP
and an actual browser. A published-package scratch install is required. Tutorial
examples are extracted from the rendered guide so tests cannot silently verify
different code. Existing entity/route tests remain component evidence only.

Scope: tutorial template, focused tutorial verification, CI wiring, deployment
documentation, exact framework dependency pins, generated specs and release note.
Use focused regression checks before the site's existing quality and container
gates. Framework release-cut retains all five pre-tag gates and split publication
verification. No mandatory gate is waived. Record source, dependency and runtime
identities with results here before publication and after deployment.

The production target is the existing isolated waaseyaa-org service on the
Intersnipe host. The old Pi instructions are obsolete. Preserve the current
release and a consistent SQLite recovery snapshot before promotion, reuse the
existing server-only secrets, initialize and qualify the candidate in isolation,
then verify public HTML, Markdown, MCP and health after cutover.

## Guide qualification before the release cut

Guide PR [#30](https://github.com/waaseyaa/waaseyaa-org/pull/30) merged on
2026-10-21 at 20:16:44 UTC as `e7e2bbca4608e6954bd5f0280637e1071acc02c1`.
The exact PR head was `5e546b8bb5fcdbeef113c96e537c46d3f872f485`. Its Quality
workflow run 39668195163 and Production-shaped container smoke run 39668195163
passed; CodeQL run 39668188443 passed. The merge used the one-owner-admin
allowance under the repository's existing `enforce_admins: false` setting and
Russell's deployment authorization. This records self-review and does not claim
independent code-owner approval.

Canonical release-cut run [39667684622](https://github.com/waaseyaa/framework/actions/runs/39667684622)
passed all five pre-tag gates for `028ca8b3e5c1e650d685f532c63667d551c4fba1`.
Exact-source full CI run 39667817611 and Release Readiness run 39669415861 passed.
The unsharded random-order proof passed 1873 tests / 45782 assertions; the final
packaged-form fresh-install boot proof passed. The canonical workflow created
`v0.1.0-alpha.306` at that same commit. Split publication run
[39671193929](https://github.com/waaseyaa/framework/actions/runs/39671193929)
passed package/tag parity and verified every package is installable. Published
starter smoke run [39671238212](https://github.com/waaseyaa/framework/actions/runs/39671238212)
passed. The [GitHub release](https://github.com/waaseyaa/framework/releases/tag/v0.1.0-alpha.306)
was published at 20:45:44 UTC on 2026-10-21.

The corrected guide supplies seven complete files, including its Twig template
and a permission-based Todo access policy. Generated pages use `/pages/{slug}`
so they cannot capture `/todos`. The provider implements `register()`. All Todo
routes require `manage todos`; disabling the starter's development account
denies anonymous access. No framework runtime or site authorization policy was
changed. CSRF exemption remains explicitly limited to the local exercise.

On 2026-10-21 the published alpha.305 starter was created in a new directory at
`C:/dev/waaseyaa/tutorial-alpha305-20261021`. The verifier extracted the displayed
files, ran site:init, install:init, optimize:manifest and site-verify, then passed
17 HTTP assertions covering empty/blank input, creation, escaped title, priority,
both typed toggles, persistence, deletion, missing ids and anonymous denial.
The starter's generated verification also passed six tests / 17 assertions.
Earlier failing scratch installations exposed the missing lifecycle, route
collision and absent entity policy; the passing installation contains no debug
patches. A separate browser check exercised the same corrected snippets through
create, complete, mark pending, reload and delete.

Local site evidence: PHPUnit 169 tests / 2002 assertions; PHPStan clean with
`--memory-limit=512M` (the native 128M limit was insufficient); coding style clean;
Composer validate strict, dependency audit and spec-corpus reproducibility clean.
The audit required patch updates to symfony/expression-language, http-foundation
and yaml, all to 7.4.21. Waaseyaa remains alpha.305 in this guide-only candidate;
the published alpha.306 dependency upgrade is a separate PR.

Self-review: the verifier writes only inside an explicitly supplied disposable
starter, preserves generated providers, uses bounded subprocesses, binds servers
to loopback and stops its children on success or failure. It fails on a framework
version mismatch. The authored code is documentation for a local example, not a
change to the public site's authorization or persistence implementation. Existing
quality and production-container gates remain required; no release gate changed.

## Published alpha.306 website upgrade

The dedicated upgrade pins the five direct Waaseyaa requirements to alpha.306.
All 67 locked Waaseyaa packages resolve to the same release, with no non-Waaseyaa
package version changes. The generator produced 119 specifications from the
published framework. Four retired specs were removed and two current specs were
added. The Build page now links to the current entity-storage-v2 specification;
its existing link test caught the retired two-axis target. Release-date and MCP
latest-release expectations were updated to alpha.306 without weakening them.

A second, completely fresh published starter was created at
`C:/dev/waaseyaa/tutorial-alpha306-20261021`. The displayed-guide verifier passed
its full installation lifecycle and all 17 HTTP assertions. Browser verification
on the same installation passed create, complete, reload persistence, mark
pending, delete and reload. Its loopback server was stopped after verification.
The completed-state screenshot is retained with the local task evidence.

Composer strict validation, the locked production dependency audit, PHPStan and
coding style passed. The final local PHPUnit run passed 169 tests / 1993
assertions. Exact PR checks are recorded with the upgrade pull request.
Deployment uses the isolated staging and recovery
procedure in `docs/deployment.md`; the resulting deployed SHA and public checks
are recorded in the pull request and retained deployment receipt.
