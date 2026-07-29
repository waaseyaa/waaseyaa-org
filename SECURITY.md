# Security Policy

waaseyaa.org is the public documentation site for the Waaseyaa framework.
The framework itself is alpha software; this site aims to run it with a
production-grade security posture and to describe the alpha product honestly.

## Reporting a vulnerability

Use GitHub private vulnerability reporting:
https://github.com/waaseyaa/waaseyaa-org/security/advisories/new

Please do not open a public issue for a security problem. Reports about the
framework itself belong at https://github.com/waaseyaa/framework/security.

You can expect an acknowledgement within 72 hours. There is no bug bounty.

## Scope

In scope: this application (src/, templates/, public/, bin/, config/), its
public surfaces (pages, /docs, /llms.txt, /sitemap.xml, the read-only MCP
endpoint, the docs chat), and its deployment artifacts in this repository.

Out of scope: the Waaseyaa framework packages (report upstream), volumetric
denial of service, and findings that require physical access to the host.

## What this site stores

- Docs chat transcripts (question, answer, cited sources) keyed by a random
  cookie, deleted after 30 days, clearable by the visitor at any time.
- Rate-limit counters keyed by that cookie and by an HMAC of the client IP.
  Raw IP addresses are never written to storage.
- No accounts, no passwords, no payment data.

## Supported versions

Only the deployed `main` branch is supported. There are no maintained
release branches.
