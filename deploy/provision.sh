#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

[[ $EUID -eq 0 ]] || { echo 'ERROR: run as root' >&2; exit 1; }
for cmd in openssl sudo psql; do command -v "$cmd" >/dev/null || { echo "ERROR: $cmd is required" >&2; exit 1; }; done

DB_NAME="${DB_NAME:-vazin_online}"
DB_USER="${DB_USER:-vazin_online}"
ENV_FILE="${ENV_FILE:-/root/vazin-online-production.env}"
DB_PASSWORD="$(openssl rand -base64 36 | tr -d '\n')"
APP_KEY="$(openssl rand -hex 32)"

[[ "$DB_NAME" =~ ^[a-z_][a-z0-9_]*$ ]] || { echo 'ERROR: invalid DB_NAME' >&2; exit 1; }
[[ "$DB_USER" =~ ^[a-z_][a-z0-9_]*$ ]] || { echo 'ERROR: invalid DB_USER' >&2; exit 1; }

if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -qx 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "ALTER ROLE $DB_USER WITH LOGIN PASSWORD '$DB_PASSWORD'"
else
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE ROLE $DB_USER WITH LOGIN PASSWORD '$DB_PASSWORD' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT"
fi
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -qx 1; then
  sudo -u postgres createdb --owner="$DB_USER" --encoding=UTF8 "$DB_NAME"
fi

install -m 0600 /dev/null "$ENV_FILE"
{
  echo 'APP_ENV=production'
  echo 'APP_DEBUG=false'
  echo 'APP_URL=https://vazin.online'
  echo "APP_KEY=$APP_KEY"
  echo 'DB_CONNECTION=pgsql'
  echo 'DB_HOST=127.0.0.1'
  echo 'DB_PORT=5432'
  echo "DB_DATABASE=$DB_NAME"
  echo "DB_USERNAME=$DB_USER"
  echo "DB_PASSWORD=$DB_PASSWORD"
  echo 'SESSION_SECURE=true'
  echo 'TRUSTED_PROXIES='
} > "$ENV_FILE"

echo "Database and protected environment file are ready: $ENV_FILE"
echo "Run: SOURCE_ENV=$ENV_FILE bash $(dirname "$0")/install.sh"
