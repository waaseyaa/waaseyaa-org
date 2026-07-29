#!/bin/sh
# Production-shaped smoke checks against a running container.
# Usage: docker/smoke.sh http://127.0.0.1:8080
set -eu

BASE="${1:?usage: smoke.sh <base-url>}"
FAILURES=0

fail() {
    echo "FAIL: $1"
    FAILURES=$((FAILURES + 1))
}

pass() {
    echo "ok:   $1"
}

expect_200() {
    path="$1"
    code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE$path")
    if [ "$code" = "200" ]; then pass "$path -> 200"; else fail "$path -> $code (wanted 200)"; fi
}

for p in / /start /docs /docs/specs/entity-system /sitemap.xml /llms.txt /healthz /.well-known/mcp.json; do
    expect_200 "$p"
done

# llms.txt: current framework version and a real corpus index.
LLMS=$(curl -s "$BASE/llms.txt")
echo "$LLMS" | grep -q 'Framework version: v0.1.0' \
    && pass 'llms.txt carries a framework version' \
    || fail 'llms.txt missing framework version'
LINKS=$(echo "$LLMS" | grep -c '/docs/specs/.*\.md)' || true)
if [ "$LINKS" -ge 80 ]; then pass "llms.txt links $LINKS specs (>=80)"; else fail "llms.txt links only $LINKS specs"; fi

# Sitemap lists the corpus, not an empty urlset.
URLS=$(curl -s "$BASE/sitemap.xml" | grep -c '<url>' || true)
if [ "$URLS" -ge 80 ]; then pass "sitemap has $URLS urls (>=80)"; else fail "sitemap has only $URLS urls"; fi

# MCP tools/list exposes exactly the three read-only spec tools.
TOOLS=$(curl -s -X POST "$BASE/mcp" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
for tool in spec_list spec_search spec_read; do
    echo "$TOOLS" | grep -q "\"$tool\"" && pass "MCP exposes $tool" || fail "MCP missing $tool"
done

# Markdown negotiation returns markdown, not HTML.
MD_TYPE=$(curl -s -o /dev/null -w '%{content_type}' -H 'Accept: text/markdown' "$BASE/docs/specs/entity-system")
case "$MD_TYPE" in
    text/markdown*) pass 'markdown negotiation serves text/markdown' ;;
    *) fail "markdown negotiation served $MD_TYPE" ;;
esac

# Production cookies carry the Secure flag (session forced secure in
# production config; the CI container serves plain HTTP so Secure here
# proves the config path, not HTTPS detection).
HEADERS=$(curl -s -D - -o /dev/null "$BASE/")
if echo "$HEADERS" | grep -i '^set-cookie: PHPSESSID' | grep -qi 'secure'; then
    pass 'session cookie carries Secure in production config'
else
    fail 'session cookie missing Secure flag'
fi

# Unknown routes return a body with no exception internals.
NOTFOUND=$(curl -s "$BASE/definitely-not-a-route-12345")
for leak in 'Exception' 'Stack trace' '/app/' 'SQLSTATE'; do
    if echo "$NOTFOUND" | grep -q "$leak"; then fail "not-found body leaks '$leak'"; fi
done
pass 'not-found body free of internals'

# Health endpoint reports ready with only pass/fail values.
HEALTH=$(curl -s "$BASE/healthz")
echo "$HEALTH" | grep -q '"status":"ok"' && pass 'healthz ready' || fail "healthz not ok: $HEALTH"
echo "$HEALTH" | grep -Eq '/(app|home|srv)/' && fail 'healthz discloses paths' || pass 'healthz discloses nothing'

if [ "$FAILURES" -gt 0 ]; then
    echo "$FAILURES smoke check(s) failed."
    exit 1
fi
echo 'All smoke checks passed.'
