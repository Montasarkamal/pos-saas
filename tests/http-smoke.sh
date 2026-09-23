#!/usr/bin/env bash
# KAMALTUR POS — HTTP smoke: login + sweep of key pages against the dev/CI server.
#
# Requires the PHP dev server running (php -S ... local-setup/router.php) and a
# seeded DB matching the credentials from the environment (or .env).
#
# Each swept page must: return HTTP 200, render the new app shell (<aside> +
# <body class="bg-ink-50">) and produce no PHP error output.
#
# Environment overrides: BASE_URL, SMOKE_IDENTIFIER, SMOKE_PASSWORD.
set -uo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
IDENTIFIER="${SMOKE_IDENTIFIER:-master}"
PASSWORD="${SMOKE_PASSWORD:-Master!2026}"

PAGES=(
  master/dashboard.php
  sales/index.php
  sales/create.php
  clients/index.php
  suppliers/index.php
  refunds/index.php
  reports/index.php
  settings/index.php
  settings/lists.php
  settings/company.php
  profile.php
  users/index.php
)

fail=0
JAR="$(mktemp)"
trap 'rm -f "$JAR" /tmp/kp-login.html' EXIT

echo "== login =="
http_code="$(curl -s -c "$JAR" -o /tmp/kp-login.html -w '%{http_code}' "$BASE_URL/login.php")"
if [ "$http_code" != "200" ]; then
  echo "FAIL /login.php -> $http_code (is the server up at $BASE_URL?)" >&2
  exit 1
fi

csrf="$(grep -oE 'name="csrf" value="[^"]+"' /tmp/kp-login.html | head -1 | sed -E 's/.*value="([^"]+)"/\1/')"
if [ -z "$csrf" ]; then
  echo "FAIL could not extract csrf from /login.php" >&2
  exit 1
fi

http_code="$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
  -d "identifier=$IDENTIFIER" -d "password=$PASSWORD" -d "csrf=$csrf" "$BASE_URL/login.php")"
if [ "$http_code" != "302" ]; then
  echo "FAIL login POST -> $http_code" >&2
  exit 1
fi

echo "== sweep =="
for p in "${PAGES[@]}"; do
  body="$(mktemp)"
  code="$(curl -s -b "$JAR" -o "$body" -w '%{http_code}' "$BASE_URL/$p")"
  aside="$(grep -c '<aside' "$body" || true)"
  ink="$(grep -c '<body class="bg-ink-50"' "$body" || true)"
  errs="$(grep -ciE 'fatal error|uncaught |warning: |deprecated:' "$body" || true)"
  if [ "$code" != "200" ] || [ "$aside" -lt 1 ] || [ "$ink" -lt 1 ] || [ "$errs" -gt 0 ]; then
    echo "FAIL $p -> http=$code aside=$aside shell=$ink phpErrors=$errs"
    fail=1
  else
    echo "ok   $p"
  fi
  rm -f "$body"
done

exit $fail