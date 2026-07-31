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

## Entities: git-sourced, read-only at runtime

This app registers three entity types (`release`, `roadmap_item`,
`case_study` in `src/Entity/`), all revisionable, `group: 'content'`,
`api: true`. They are the proof engine: real entities the site dogfoods.
The ONLY writer is `content:sync` (a one-shot deploy step); there is no
admin surface, no accounts, and no runtime write path. Publishing is a
git push: author frontmatter markdown under `content/{releases,roadmap,
case-studies}/`, and the sync creates entities, saves a new revision on
change, and unpublishes (status=false) when a file is deleted.
`config/waaseyaa.php` closes the JSON:API world to exactly these three
types (`api.entity_type_allowlist`). Chat transcripts remain plain
tables via `DatabaseInterface` (`src/Chat/ChatSchema.php`).

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
│   ├── Tool/{SpecListTool,SpecSearchTool,SpecReadTool,ReleaseListTool,RoadmapReadTool}.php
│   ├── McpEndpointController.php  adapts framework McpEndpoint -> Symfony Response
│   └── PublicServerCard.php    /.well-known/mcp.json (auth: none)
├── Content/     The proof engine's content pipeline
│   ├── FrontMatter.php         `---`-delimited YAML-lite front matter parser
│   ├── ContentReader.php       reads release/roadmap_item/case_study entities for controllers + MCP tools
│   ├── ContentSync.php         content/*.md -> entities: create, revise on change, unpublish on delete
│   ├── ContentSyncReport.php   summary value object returned by ContentSync::sync()
│   └── ContentSyncException.php
├── Cli/         ContentSyncHandler.php  `content:sync` command handler (one-shot deploy step)
├── Entity/      Release.php, RoadmapItem.php, CaseStudy.php  revisionable, group: 'content', api: true
├── Controller/  HomeController, DocsController, DocsChatController, LlmsTxtController,
│                SitemapController, StaticPageController, ReleasesController, RoadmapController,
│                ProductionController
├── Provider/    AppServiceProvider (home/why/compare), DocsServiceProvider (docs/markdown/llms/mcp/chat),
│                ContentServiceProvider (entity types, content:sync command, releases/roadmap/production routes)
└── Support/     SpecCorpus URLs (SiteUrl), FrameworkVersion, PiTelemetry, Db
resources/specs/ synced corpus + manifest.json (committed; do not hand-edit)
content/         git-authored source of truth for entities: releases/, roadmap/, case-studies/
                 (frontmatter markdown; the only writer of the entities is content:sync)
templates/       base, home, docs-index, docs-spec, why, compare (schema.org JSON-LD in heads)
bin/sync-specs.php      build-time corpus sync (see below)
bin/scaffold-release.php  scaffold a new content/releases/*.md front matter file
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
| releases index | `/releases` | ReleasesController::index | `ContentServiceProvider` |
| release show | `/releases/{version}` | ReleasesController::show | " |
| roadmap | `/roadmap` | RoadmapController::page | " |
| production index | `/production` | ProductionController::index | " |
| production show | `/production/{slug}` | ProductionController::show | " |

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
**Every framework bump also needs a new release note**: run `php
bin/scaffold-release.php vX.Y.Z-alpha.N` for the locked version, then edit the
summary and body by hand and commit it under `content/releases/`.
`tests/Unit/ReleaseHonestyTest.php` enforces that the newest release note's
`version` matches `manifest.json`'s `framework_version` exactly, so a
framework bump without a matching release note fails CI.

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
php bin/scaffold-release.php vX.Y.Z-alpha.N         # scaffold a content/releases/*.md front matter file
APP_ENV=local vendor/bin/waaseyaa content:sync      # sync content/*.md into release/roadmap_item/case_study
                                                     # entities (APP_ENV=local for the same reason as db:init
                                                     # below: production won't boot against a fresh DB)
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
- **`content:sync` follow-up (required before the next deploy, not yet
  landed):** `deploy-waaseyaa-org.yml` in `waaseyaa-infra` must also run the
  one-shot `content:sync` right after `db:init`, with the same `-e
  APP_ENV=local` pattern and for the same reason (production won't boot
  against the freshly-created DB). Without this the entities never get
  created/updated from `content/*.md` on a real deploy; `ReleaseHonestyTest`
  only guards the corpus, not the running database.
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
