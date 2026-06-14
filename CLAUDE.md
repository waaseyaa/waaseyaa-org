# CLAUDE.md

Guidance for Claude Code (and any agent) working in this repository.

## Overview

**waaseyaa.org** is the Waaseyaa framework's own public site, built as a Waaseyaa
consuming app (composer-depends on `waaseyaa/framework`, alpha.203). It is the
source of truth for humans *and* agents and is meant to dogfood the framework.

The architecture is **one docs corpus, three renderings**:

1. **HTML** — server-rendered Twig pages for humans and AI-search crawlers.
2. **Markdown** — the *same* docs URL returns clean Markdown when the request
   sends `Accept: text/markdown` (or appends `.md`).
3. **MCP** — the same corpus is queryable over a public, read-only MCP endpoint
   at `/mcp` (server card at `/.well-known/mcp.json`).

The docs corpus is the framework's own `docs/specs/*.md`, synced at build time
with provenance (spec name + framework version). The site is **alpha** and runs
in production on a Raspberry Pi (live at https://waaseyaa.org). Be honest in all
copy: no em dashes, no "cutting edge", stage labels accurate (enforced by tests,
see `tests/Unit/ContentHonestyTest.php`).

## This app has NO entities

It is a read-only public site over a synced file corpus plus a docs chat. There
are no `EntityType`s; `src/Entity`, `src/Access`, `src/Seed` etc. are empty
skeleton stubs. Chat transcripts are stored in plain tables via
`DatabaseInterface` (`src/Chat/ChatSchema.php`), which is the correct layer for
non-entity tables. Do not add entities unless a feature genuinely needs the
entity pipeline.

## Architecture

```
src/
├── Docs/        The corpus + search engine
│   ├── SpecCorpus.php          reads resources/specs/ + manifest (provenance)
│   ├── SpecIndex.php           FTS5 title-weighted ranking (waaseyaa/search) over the corpus; lazy rebuild keyed on framework version
│   ├── SpecSearch.php          line-level substring search, scanned in SpecIndex rank order (shared by MCP + chat)
│   ├── Keywords.php            shared query tokenizer (stopwords) for SpecIndex + DocsRetriever
│   └── MarkdownNegotiation.php Accept: text/markdown detection
├── Chat/        Corpus-grounded docs chat (workspace-chat-surface contract)
│   ├── DocsRetriever.php       SpecIndex-ranked specs -> best-section passages
│   ├── ChatPrompt.php          system/user prompts; answers ONLY from passages, always cite
│   ├── ExtractiveAnswerer.php  no-key fallback: quotes passages verbatim w/ citations
│   ├── ConversationStore.php   visitor-scoped transcripts (random cookie; no public accounts)
│   ├── ChatSchema.php          raw chat tables (DatabaseInterface)
│   └── Passage.php
├── Mcp/         Public, unauthenticated, READ-ONLY MCP surface
│   ├── PublicSpecsAuth.php     resolves every request to SpecReaderAccount
│   ├── SpecReaderAccount.php   one capability: site.specs.read
│   ├── SpecToolRegistry.php    the ONLY tools exposed (explicit list = security boundary)
│   ├── Tool/{SpecListTool,SpecSearchTool,SpecReadTool}.php
│   ├── McpEndpointController.php  adapts framework McpEndpoint -> Symfony Response
│   └── PublicServerCard.php    /.well-known/mcp.json (auth: none)
├── Controller/  HomeController, DocsController, DocsChatController,
│                LlmsTxtController, SitemapController, StaticPageController
├── Provider/    AppServiceProvider (home/why/compare), DocsServiceProvider (docs/markdown/llms/mcp/chat)
└── Support/     SpecCorpus URLs (SiteUrl), FrameworkVersion, PiTelemetry, Db
resources/specs/ synced corpus + manifest.json (committed; do not hand-edit)
templates/       base, home, docs-index, docs-spec, why, compare (schema.org JSON-LD in heads)
bin/sync-specs.php  build-time corpus sync (see below)
```

### Routes (all `allowAll()`, GET unless noted)

| Route | Path | Controller | Provider |
|-------|------|-----------|----------|
| home / why / compare | `/`, `/why`, `/compare` | Home/StaticPage | `AppServiceProvider` |
| docs index | `/docs` | DocsController::index | `DocsServiceProvider` |
| spec page | `/docs/specs/{name}` (HTML or `.md`/Accept) | DocsController::spec | " |
| llms.txt | `/llms.txt` | LlmsTxtController | " |
| sitemap | `/sitemap.xml` | SitemapController | " |
| MCP (public) | `/mcp` (POST/GET, csrfExempt) | McpEndpointController | " |
| server card | `/.well-known/mcp.json` | PublicServerCard | " |
| chat send | `/docs-chat/send` (POST, csrfExempt) | DocsChatController::send | " |
| chat messages | `/docs-chat/{id}/messages` | DocsChatController::messages | " |

`DocsServiceProvider` overrides the framework's default `/mcp` and
`mcp.server_card` routes (`removeRoute()` then re-add) because the framework
default is bearer-auth and returns a value object the SSR dispatcher can't
convert. App providers register after framework providers, so this wins.

## The docs corpus (build step)

`bin/sync-specs.php` copies `vendor/waaseyaa/framework/docs/specs/*.md` into
`resources/specs/` and writes `manifest.json` (framework version + per-spec
title/sha1). The vendor dist is the canonical source — it is version-locked by
composer, so provenance is exact. **Rerun `php bin/sync-specs.php` after every
`composer update` of `waaseyaa/framework`** and commit the result. v1 ships
specs close to as-is behind the index + chat; editorial rewrites are later.

## Chat

Reuses the shared `waaseyaa/workspace` SSE chat client (alpha.203), mounted on
the home docs surface and themed with site CSS tokens. Backend implements the
`workspace-chat-surface.md` contract (SSE `meta`/`delta`/`done`, paginated
`messages`). Retrieval (`DocsRetriever`) ranks specs through `SpecIndex`, the
same title-weighted FTS5 index (waaseyaa/search) the MCP `spec_search` tool
scans, so what the assistant reads, an agent can fetch itself. With
`ANTHROPIC_API_KEY` set it streams `claude-sonnet-4-6` grounded on the
passages; without it, `ExtractiveAnswerer` quotes the passages verbatim. **Every
answer carries at least one citation link by construction** (docs index on a
retrieval miss) — `tests/Integration/DocsChatTest.php` enforces this; keep it
true.

## Development

```bash
composer install
php -S 127.0.0.1:8098 -t public public/index.php   # dev server (port 8098)
./vendor/bin/phpunit                                # tests (must stay green)
php bin/sync-specs.php                              # re-sync corpus after a framework bump
```

`.env`: `APP_URL`/`WAASEYAA_ORG_CANONICAL_URL` set the canonical origin (falls
back to APP_URL), `WAASEYAA_ORG_PI_STATUS_FILE` points at a telemetry JSON for
the Pi status chip (chip is hidden when unset/stale). Use `getenv()`, never
`$_ENV`.

## Deploy (Raspberry Pi)

Deployed via `waaseyaa-infra` (the shared Pi stack), per its
`runbooks/03-add-a-site.md`: a composer-only `compose/waaseyaa-org/Dockerfile`
that clones this repo at a pinned `WAASEYAA_ORG_REF`, a Caddy vhost
(`waaseyaa.org`, `www`, `waaseyaa.oiatc.ca`), and `deploy-waaseyaa-org.yml`.

- **Cut a deploy:** push this repo's `main`, then bump `WAASEYAA_ORG_REF` in
  `waaseyaa-infra/compose/docker-compose.yml` and push → the GitHub Action
  rebuilds on the Pi over Tailscale. Don't build by hand.
- **First-deploy DB quirk:** in `APP_ENV=production` the kernel won't boot with
  a missing SQLite file, and that abort precedes `db:init` registration. The
  deploy workflow runs the one-shot `db:init` with `-e APP_ENV=local` so the DB
  is created/migrated; the long-running container stays production.
- Secrets (incl. `ANTHROPIC_API_KEY`) come from the `waaseyaa-infra` ansible
  vault, never committed. Caddyfile changes need `docker compose up -d
  --force-recreate caddy` (not `restart`).

## Conventions

- Framework invariants (forbidden deps, DI methods, persistence pipeline) live
  in `.claude/rules/waaseyaa-*.md` — always active. It is **NOT** Laravel/Drupal.
- PHP 8.5, `declare(strict_types=1)`, `final class` by default, PSR-4
  one-class-per-file, namespace matches directory.
- Service providers extend `Waaseyaa\Foundation\ServiceProvider\ServiceProvider`
  (`register()` for bindings, `boot()` for setup, `routes()` for routes).
- schema.org JSON-LD on every page (SoftwareApplication on home, TechArticle on
  specs/why, FAQPage on compare).

## Known gaps

- **Chat retrieval quality:** retrieval now ranks specs via `SpecIndex`
  (waaseyaa/search FTS5 with the spec title weighted above the body), so "how do
  I add an entity type?" surfaces entity-system. The remaining refinements are a
  later quality pass: the title-match signal uses substring (not stemmed) token
  comparison, so a plural query ("revisions") and a long title that repeats the
  package name ("Bimaaji install ...") can still mis-order the canonical spec
  below a body-mention. The honest "not covered" miss and the >=1-citation
  invariant hold throughout.
- **Pi status chip** stays hidden until a telemetry JSON is wired via
  `WAASEYAA_ORG_PI_STATUS_FILE`.
- MCP registry listing submission is a deploy-time follow-up.

## Gotchas

- **Never use `$_ENV`** — Waaseyaa's `EnvLoader` only populates `getenv()`.
- **SQLite write access** — the `.sqlite` file AND its parent dir need write
  perms (WAL/journal).
- **Don't hand-edit `resources/specs/`** — it is generated; change the corpus by
  re-running `bin/sync-specs.php` against an updated framework.
