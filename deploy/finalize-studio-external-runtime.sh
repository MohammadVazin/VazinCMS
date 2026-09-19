#!/usr/bin/env bash
# Finalize Studio after its dedicated PHP-FPM pool has already proven the external runtime healthy.
set -Eeuo pipefail
umask 077

[[ ${EUID:-1} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
ROOT='/var/www/vazin-sites/studio-vazin-online'
HOST='studio.vazin.online'
SITE_KEY='studio-vazin-online'
DATA="/var/lib/vazincms-runtime/$SITE_KEY/data"
ENV_FILE="$ROOT/.env"
[[ -f "$ENV_FILE" && ! -L "$ENV_FILE" && -d "$ROOT/storage" && ! -L "$ROOT/storage" && -d "$ROOT/public/uploads" && ! -L "$ROOT/public/uploads" ]] || { echo 'Studio finalization preconditions failed.' >&2; exit 2; }
[[ -f "$DATA/storage/database.sqlite" && ! -L "$DATA/storage/database.sqlite" ]] || { echo 'External SQLite runtime is unavailable.' >&2; exit 70; }
sqlite3 "$DATA/storage/database.sqlite" 'PRAGMA integrity_check;' | grep -qx ok

STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"
BACKUP="/var/backups/vazincms/$SITE_KEY/runtime-finalize-$STAMP"
mkdir -p "$BACKUP"
tar -czf "$BACKUP/legacy-runtime.tar.gz" -C "$ROOT" storage public/uploads
install -m 0600 -o root -g root "$ENV_FILE" "$BACKUP/environment.original"
TMP_ENV="$(mktemp "$ROOT/.env.finalize-$STAMP-XXXXXX")"
trap 'rm -f "$TMP_ENV"' EXIT
python3 - "$ENV_FILE" "$TMP_ENV" "$DATA" <<'PY'
import os, sys
source, target, data = sys.argv[1:]
values = {
    'VAZINCMS_STORAGE_PATH': data + '/storage',
    'VAZINCMS_UPLOADS_PATH': data + '/uploads',
    'DB_DATABASE': data + '/storage/database.sqlite',
}
seen, out = set(), []
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
LEGACY_STORAGE="$ROOT/.storage.legacy-$STAMP"
LEGACY_UPLOADS="$ROOT/public/.uploads.legacy-$STAMP"
mv -T "$TMP_ENV" "$ENV_FILE"
TMP_ENV=''
mv -T "$ROOT/storage" "$LEGACY_STORAGE"
mv -T "$ROOT/public/uploads" "$LEGACY_UPLOADS"
systemctl restart php8.5-fpm
if ! curl -fsS --resolve "$HOST:443:127.0.0.1" "https://$HOST/health" | python3 -c 'import json,sys; x=json.load(sys.stdin); assert x=={"ok":True,"service":"VazinCMS","version":"10.28.0"}'; then
  install -m 0640 -o root -g www-data "$BACKUP/environment.original" "$ENV_FILE"
  [[ -d "$LEGACY_STORAGE" && ! -e "$ROOT/storage" ]] && mv -T "$LEGACY_STORAGE" "$ROOT/storage"
  [[ -d "$LEGACY_UPLOADS" && ! -e "$ROOT/public/uploads" ]] && mv -T "$LEGACY_UPLOADS" "$ROOT/public/uploads"
  systemctl restart php8.5-fpm
  echo 'Studio finalization failed; legacy runtime and environment restored.' >&2
  exit 70
fi
echo "Studio runtime finalized. Backup: $BACKUP"
