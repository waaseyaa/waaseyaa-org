# waaseyaa.org Mission 1: the proof engine

Date: 2026-07-31
Status: approved design, pre-implementation
Scope: first of three missions taking waaseyaa.org from skeleton to great for 2026+

## Context and goal

The site today is a solid but thin skeleton: five pages, 83 specs published
near-verbatim, one tested tutorial, no entities, and nothing that shows the
framework moving. The audiences are (balanced) PHP developers evaluating the
framework, Nations and organizations deciding whether to trust it, and AI
agents/AI search treating it as the canonical source.

Mission 1 makes the site *living proof*: real entities under the hood,
momentum visible on the surface, and the site dogfooding the entity pipeline
it advertises. Later missions: a curated guides tier over the specs
(Mission 2), long-form case-study prose (Mission 3).

## Decisions taken during brainstorming

- Entity-driven: yes. The site crosses from "no entities" to entity-backed
  content, deliberately.
- Authoring is git-driven: content lives as frontmatter markdown in the repo,
  synced into entities at deploy. No accounts, no web write surface. The
  no-auth security story is unchanged.
- Living surfaces in scope: releases/changelog, roadmap, case studies plus
  live Pi telemetry. A devlog/blog is explicitly out of scope.
- Docs strategy (Mission 2, not this mission): guides layer over verbatim
  specs, two-tier.

## Section A: content model and sync

Three new entity types, all real `ContentEntityBase` entities, revisionable,
registered with `#[ContentEntityType]` attributes, `api: true`:

| Type | Key fields |
|------|-----------|
| `release` | `version` (slug key, e.g. `v0.1.0-alpha.276`), `released_at`, `summary`, `body` (markdown), `breaking` (bool), `tag_url` |
| `roadmap_item` | `title`, `horizon` (`now` / `next` / `later`), `status`, `body`, related spec names |
| `case_study` | `title`, `org`, `site_url`, `summary`, `body` |

Field read levels: public for everything rendered; deny-by-default field
access rules of the framework apply.

### Git-driven sync

Content files live in the repo:

```
content/releases/*.md      frontmatter + markdown body
content/roadmap/*.md
content/case-studies/*.md
```

A new idempotent CLI command `content:sync`:

- Upserts entities keyed by stable slug (filename-derived).
- Unchanged file: no-op (hash comparison).
- Changed file: saves a new revision. Revision history is itself a visible
  liveness proof.
- Removed file: entity hidden from all public surfaces, history kept. The
  mechanism is a boolean `published` field on each type (set false on
  removal), filtered in every listing/read path; if the framework's entity
  keys already provide a status flag, use that instead of a bespoke field.
- Malformed frontmatter: sync fails loudly; deploy fails; nothing
  half-published.

A scaffold helper `bin/scaffold-release.php` pre-fills a release file from a
GitHub release's notes so changelog upkeep is minutes per release.

CLAUDE.md's "this app has NO entities" section is updated to describe the new
boundary: entities exist, git-sourced, read-only at runtime.

## Section B: public surfaces

All new pages are `allowAll()`, GET, and markdown-negotiated exactly like the
spec pages (same URL serves `.md` suffix or `Accept: text/markdown`).

- `/releases`: index, newest first; version, date, summary, breaking marker.
- `/releases/{version}`: full release page with a provenance line (revision
  N, synced from content path, framework version). Schema.org
  `SoftwareApplication` release metadata.
- `/roadmap`: one page grouped Now / Next / Later, items linking into the
  specs they depend on. Copy states horizons are stage-based, not dated.
- `/production`: case-study index plus `/production/{slug}` pages (FNPI,
  oiatc.ca), and a live Pi status block (uptime, temperature, framework
  version, response time) read via the existing `PiTelemetry` when the
  telemetry JSON is fresh. Header chip unchanged; block omitted when
  stale/missing.

Machine surfaces:

- JSON:API comes from the framework because the types are `api: true`. This
  is the site's first real JSON:API surface. Anonymous read only.
- MCP: two new tools added to the explicit whitelist in the
  `SpecToolRegistry` style: `release_list` and `roadmap_read`, same
  `SpecReaderAccount` capability, read-only. Case studies stay HTML/MD-only
  in v1.
- `/llms.txt` and `/sitemap.xml` gain the new URLs. The MCP server card
  lists the new tools.

Home page updates:

- Hero stage line becomes live: version, release date, link to `/releases`.
- New section "This site is the demo": copy-pasteable `curl` for the JSON:API
  latest-release call and the MCP call, both covered by integration tests so
  they cannot rot (same guarantee as `/start`).
- Nav gains Releases and Roadmap. Proof chips updated.

## Section C: testing, deploy, failure behavior

Testing:

- `content:sync`: idempotency (second run no-op), revision-on-change,
  unpublish-on-delete, loud failure on malformed frontmatter.
- Route tests for all new pages in HTML and Markdown renderings; 404s for
  unknown slugs.
- JSON:API: anonymous can read the three types; POST/PATCH/DELETE are
  rejected (executable security boundary).
- MCP tests for `release_list` / `roadmap_read` through the same
  production-shaped harness as the spec tools.
- `ContentHonestyTest` extended to new templates and content files. New
  check: the latest release shown on the home page must match the locked
  framework version in the manifest.
- Sitemap and llms.txt tests assert the new URLs.

Deploy and failure behavior:

- The waaseyaa-infra deploy workflow adds a one-shot `content:sync` after
  `db:init` (same `APP_ENV=local` one-shot pattern). A failed sync fails the
  deploy; the previous container keeps serving.
- Fresh database with no synced content: index pages render an honest empty
  state, not an error.
- Telemetry staleness rule unchanged.

## Out of scope for Mission 1

- Guides docs tier (Mission 2).
- Long-form case-study prose (Mission 3); pages ship with the current honest
  short versions.
- MCP registry submission (deploy-time follow-up).
- Visual redesign (not a felt gap; the design system stays).
