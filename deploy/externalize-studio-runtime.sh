#!/usr/bin/env bash
# One-time, rollback-aware migration of Studio's mutable runtime out of its code tree.
set -Eeuo pipefail
umask 077

[[ ${EUID:-1} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
ROOT='/var/www/vazin-sites/studio-vazin-online'
HOST='studio.vazin.online'
SITE_KEY='studio-vazin-online'
EXPECTED_VERSION='10.28.0'
RUNTIME_BASE="/var/lib/vazincms-runtime/$SITE_KEY"
RUNTIME_DATA="$RUNTIME_BASE/data"
STORAGE="$RUNTIME_DATA/storage"
UPLOADS="$RUNTIME_DATA/uploads"
ENV_FILE="$ROOT/.env"

[[ -d "$ROOT" && ! -L "$ROOT" && -f "$ROOT/VERSION" && -f "$ENV_FILE" && ! -L "$ENV_FILE" ]] || { echo 'Unsafe Studio root.' >&2; exit 2; }
[[ "$(tr -d '\r\n' < "$ROOT/VERSION")" == "$EXPECTED_VERSION" ]] || { echo 'Unexpected Studio version.' >&2; exit 2; }
[[ "$(stat -c '%u:%g:%a' "$ENV_FILE")" == "0:33:640" ]] || { echo 'Unsafe Studio environment permissions.' >&2; exit 70; }
[[ -d "$ROOT/storage" && ! -L "$ROOT/storage" && -d "$ROOT/public/uploads" && ! -L "$ROOT/public/uploads" ]] || { echo 'Legacy Studio runtime is missing or unsafe.' >&2; exit 70; }
find "$ROOT/storage" "$ROOT/public/uploads" -xdev -type l -print -quit | grep -q . && { echo 'Runtime symlink rejected.' >&2; exit 70; }

STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"
BACKUP="/var/backups/vazincms/$SITE_KEY/runtime-externalize-$STAMP"
mkdir -p "$BACKUP" "$RUNTIME_BASE"
chown root:www-data "$RUNTIME_BASE"
chmod 0750 "$RUNTIME_BASE"
tar -czf "$BACKUP/legacy-runtime.tar.gz" -C "$ROOT" storage public/uploads
install -m 0600 -o root -g root "$ENV_FILE" "$BACKUP/environment.original"

set -a
. "$ENV_FILE"
set +a
[[ "${DB_CONNECTION:-pgsql}" == 'sqlite' ]] || { echo 'Studio runtime migration currently requires SQLite.' >&2; exit 2; }
[[ -n "${DB_DATABASE:-}" && -f "$DB_DATABASE" && ! -L "$DB_DATABASE" ]] || { echo 'Studio SQLite database is unavailable.' >&2; exit 70; }
sqlite3 "$DB_DATABASE" ".backup '$BACKUP/database.sqlite'"
sqlite3 "$BACKUP/database.sqlite" 'PRAGMA integrity_check;' | grep -qx ok

TMP_DATA="$(mktemp -d "$RUNTIME_BASE/.data.new-$STAMP-XXXXXX")"
TMP_ENV="$(mktemp "$ROOT/.env.new-$STAMP-XXXXXX")"
LEGACY_STORAGE="$ROOT/.storage.legacy-$STAMP"
LEGACY_UPLOADS="$ROOT/public/.uploads.legacy-$STAMP"
cleanup() { rm -rf "$TMP_DATA" "$TMP_ENV"; }
trap cleanup EXIT
install -d -m 0750 -o root -g www-data "$TMP_DATA"
install -d -m 0700 -o www-data -g www-data "$TMP_DATA/storage" "$TMP_DATA/uploads"
rsync -a --delete --no-owner --no-group "$ROOT/storage/" "$TMP_DATA/storage/"
rsync -a --delete --no-owner --no-group "$ROOT/public/uploads/" "$TMP_DATA/uploads/"
chown -R www-data:www-data "$TMP_DATA/storage" "$TMP_DATA/uploads"
find "$TMP_DATA/storage" "$TMP_DATA/uploads" -xdev -type d -exec chmod 0700 {} +
find "$TMP_DATA/storage" "$TMP_DATA/uploads" -xdev -type f -exec chmod 0600 {} +

python3 - "$ENV_FILE" "$TMP_ENV" "$TMP_DATA" <<'PY'
import os, sys
source, target, data = sys.argv[1:]
values = {
    'VAZINCMS_STORAGE_PATH': data + '/storage',
    'VAZINCMS_UPLOADS_PATH': data + '/uploads',
    'DB_DATABASE': data + '/storage/database.sqlite',
}
seen = set()
out = []
for raw in open(source, encoding='utf-8'):
    stripped = raw.strip()
    key = stripped.split('=', 1)[0].strip() if '=' in stripped and not stripped.startswith('#') else None
    if key in values:
        out.append(key + '=' + values[key] + '\n')
        seen.add(key)
    else:
        out.append(raw if raw.endswith('\n') else raw + '\n')
for key, value in values.items():
    if key not in seen:
        out.append(key + '=' + value + '\n')
with open(target, 'w', encoding='utf-8') as handle:
    handle.writelines(out)
    handle.flush()
    os.fsync(handle.fileno())
PY
install -m 0640 -o root -g www-data "$TMP_ENV" "$TMP_ENV.checked"
mv -T "$TMP_ENV.checked" "$TMP_ENV"
env VAZINCMS_STORAGE_PATH="$TMP_DATA/storage" VAZINCMS_UPLOADS_PATH="$TMP_DATA/uploads" DB_CONNECTION=sqlite DB_DATABASE="$TMP_DATA/storage/database.sqlite" php "$ROOT/scripts/migrate.php" >/dev/null
sqlite3 "$TMP_DATA/storage/database.sqlite" 'PRAGMA integrity_check;' | grep -qx ok

mv -T "$TMP_DATA" "$RUNTIME_DATA"
TMP_DATA=''
mv -T "$TMP_ENV" "$ENV_FILE"
TMP_ENV=''
mv -T "$ROOT/storage" "$LEGACY_STORAGE"
mv -T "$ROOT/public/uploads" "$LEGACY_UPLOADS"
# PHP-FPM workers retain putenv values between requests. A reload keeps those
# workers alive, so a restart is required before validating the new .env.
systemctl restart php8.5-fpm
if ! curl -fsS --resolve "$HOST:443:127.0.0.1" "https://$HOST/health" | python3 -c 'import json,sys; x=json.load(sys.stdin); assert x=={"ok":True,"service":"VazinCMS","version":"10.28.0"}'; then
  install -m 0640 -o root -g www-data "$BACKUP/environment.original" "$ENV_FILE"
  [[ -d "$LEGACY_STORAGE" && ! -e "$ROOT/storage" ]] && mv -T "$LEGACY_STORAGE" "$ROOT/storage"
  [[ -d "$LEGACY_UPLOADS" && ! -e "$ROOT/public/uploads" ]] && mv -T "$LEGACY_UPLOADS" "$ROOT/public/uploads"
  systemctl restart php8.5-fpm
  echo 'Studio health failed; legacy runtime and environment restored.' >&2
  exit 70
fi
echo "Studio external runtime ready. Backup: $BACKUP"
