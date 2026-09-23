#!/usr/bin/env bash
# =====================================================================
# KAMALTUR POS — one-shot local setup + dev server (macOS, arm64)
#
# Prerequisite (one time):
#   /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
#   brew install php@8.3 mariadb
#
# Usage:
#   ./local-setup/run-local.sh        # setup (once)
#   ./local-setup/run-local.sh serve  # start dev server
# =====================================================================
set -euo pipefail

export HOMEBREW_NO_AUTO_UPDATE=1
eval "$(/opt/homebrew/bin/brew shellenv 2>/dev/null || true)"
# php@8.3 is keg-only — put its bin + mariadb client on PATH
export PATH="/opt/homebrew/opt/php@8.3/bin:/opt/homebrew/bin:$PATH"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DB_NAME="kamaltur"
DB_USER="pos"
DB_HOST="127.0.0.1"
DB_PORT="3306"

PORT="${PORT:-8080}"

# ---------------------------------------------------------------------
ensure_mysql() {
  if ! command -v mysql >/dev/null 2>&1; then
    echo "MariaDB not installed yet. Run:  brew install php@8.3 mariadb"
    exit 1
  fi
  if ! command -v php >/dev/null 2>&1; then
    echo "PHP not installed yet. Run:  brew install php@8.3 mariadb"
    exit 1
  fi
}

ensure_server() {
  # Start MariaDB (LaunchAgent in the user session — no sudo).
  if ! brew services list 2>/dev/null | grep -q mariadb; then
    brew services start mariadb
  fi
  for _ in $(seq 1 30); do
    if mysqladmin ping -h "$DB_HOST" -u "$DB_USER" --silent 2>/dev/null; then
      return 0
    fi
    if mysqladmin ping -h "$DB_HOST" --silent 2>/dev/null; then
      return 0
    fi
    sleep 1
  done
  echo "MariaDB did not come up."; exit 1
}

random_pass() { LC_ALL=C tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 20; }

setup() {
  ensure_mysql
  ensure_server

  # .env — reuse if present
  if [[ ! -f .env ]]; then
    DB_PASS="pos-$(random_pass)"
    SECRET="$(LC_ALL=C tr -dc 'a-f0-9' < /dev/urandom | head -c 64)"
    cat > .env <<EOF
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
PUBLIC_LINK_SECRET=$SECRET
OPENAI_API_KEY=
EOF
  fi
  # shellcheck disable=SC2046
  set -a; source .env; set +a

  echo "==> Creating database '$DB_NAME' and user '$DB_USER'"
  # Admin connection: current macOS user has all privileges via unix socket
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

  echo "==> Applying schema"
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < local-setup/schema.sql

  echo "==> Seeding"
  DB_PASS="$DB_PASS" php local-setup/seed.php

  echo
  echo "Setup complete. Start with: ./local-setup/run-local.sh serve"
}

serve() {
  ensure_mysql
  ensure_server
  [[ -f .env ]] || { echo "Run setup first: ./local-setup/run-local.sh"; exit 1; }
  echo "==> http://127.0.0.1:${PORT}  (Ctrl+C to stop)"
  exec php -S "127.0.0.1:${PORT}" -t "$ROOT" "$ROOT/local-setup/router.php"
}

case "${1:-}" in
  serve) serve ;;
  *)     setup ;;
esac