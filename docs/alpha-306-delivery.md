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
