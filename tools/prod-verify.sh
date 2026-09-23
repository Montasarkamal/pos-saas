#!/usr/bin/env bash
# Production verification: login + page sweep + REAL data markers.
# Read-only (GET) — no writes to production DB.
set -uo pipefail

BASE="https://pos.kamaltur.com"
MARKERS=(
  '260084'          # invoice number (agency 6)
  'FEHMI BEJI'      # client
  'SKYTEAM'         # supplier
  'KAMAL TUR'       # agency
)
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

login_and_sweep() {
  local ident="$1" pass="$2" label="$3"
  local jar body code csrf
  jar="$(mktemp)"; body="$(mktemp)"
  echo "===== USER: $label ($ident) ====="

  code="$(curl -sk -c "$jar" -o "$body" -w '%{http_code}' "$BASE/login.php")"
  echo "login GET -> $code"
  if [ "$code" != "200" ]; then rm -f "$jar" "$body"; return 1; fi

  csrf="$(grep -oE 'name="csrf" value="[^"]+"' "$body" | head -1 | sed -E 's/.*value="([^"]+)"/\1/')"
  if [ -z "$csrf" ]; then
    echo "  no csrf token found"; rm -f "$jar" "$body"; return 1
  fi

  code="$(curl -sk -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' \
    -d "identifier=$ident" -d "password=$pass" -d "csrf=$csrf" "$BASE/login.php")"
  echo "login POST -> $code"
  if [ "$code" != "302" ]; then
    echo "  LOGIN FAILED (wrong password?) — stopping for $label"
    rm -f "$jar" "$body"; return 1
  fi

  local p aside ink errs markers_hit total_markers=0
  echo "-- page sweep (shell + no PHP errors) --"
  for p in "${PAGES[@]}"; do
    code="$(curl -sk -b "$jar" -o "$body" -w '%{http_code}' "$BASE/$p")"
    aside="$(grep -c '<aside' "$body" || true)"
    ink="$(grep -c '<body class="bg-ink-50"' "$body" || true)"
    errs="$(grep -ciE 'fatal error|uncaught |warning:|deprecated:|exception' "$body" || true)"
    if [ "$code" != "200" ] || [ "$aside" -lt 1 ] || [ "$ink" -lt 1 ] || [ "$errs" -gt 0 ]; then
      echo "FAIL $p -> http=$code aside=$aside shell=$ink phpErrors=$errs"
    else
      echo "ok   $p"
    fi
  done

  echo "-- real data markers across dashboard + sales list --"
  curl -sk -b "$jar" "$BASE/dashboard.php" > "$body"
  for m in "${MARKERS[@]}"; do
    hit="$(grep -c "$m" "$body" || true)"
    echo "dashboard: '$m' -> $hit hit(s)"
  done
  curl -sk -b "$jar" "$BASE/sales/index.php" > "$body"
  for m in "${MARKERS[@]}"; do
    hit="$(grep -c "$m" "$body" || true)"
    echo "sales/index: '$m' -> $hit hit(s)"
  done
  rm -f "$jar" "$body"
}

login_and_sweep "master"    "Master!2026" "master (superadmin)"
echo ""
login_and_sweep "montasar"  "Admin!2026"  "montasar (admin, KAMAL TUR)"
echo ""
echo "VERIFICATION DONE"