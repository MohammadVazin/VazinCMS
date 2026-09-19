#!/usr/bin/env bash
# Atomic external-runtime upgrade for the two Vazin Travel/Visa managed sites.
set -Eeuo pipefail
umask 077

[[ $EUID -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
SOURCE="$(cd "$(dirname "$0")/.." && pwd -P)"
NEW_VERSION='10.29.0'
OLD_VERSION='10.28.0'
STATE_BASE='/var/lib/vazincms-deploy'
RUNTIME_BASE='/var/lib/vazincms-runtime'
BACKUP_BASE='/var/backups/vazincms'
HELPER="$SOURCE/deploy/atomic_release.py"
MANIFEST="$SOURCE/MANIFEST.sha256"
SITE_ROOT="$(printenv INSTALL_DIR 2>/dev/null || true)"
RECOVER_ONLY=0

usage() {
  echo "Usage: $0 [--site-root <exact-managed-root>] [--recover-only]" >&2
}

while (($#)); do
  case "$1" in
    --site-root)
      (($# >= 2)) || { usage; exit 2; }
      SITE_ROOT="$2"
      shift 2
      ;;
    --recover-only)
      RECOVER_ONLY=1
      shift
      ;;
    *)
      usage
      exit 2
      ;;
  esac
done

case "$SITE_ROOT" in
  /var/www/vazin-sites/travel-vazin-online)
    SITE_KEY='travel-vazin-online'
    SITE_HOST='travel.vazin.online'
    ;;
  /var/www/vazin-sites/visa-vazin-online)
    SITE_KEY='visa-vazin-online'
    SITE_HOST='visa.vazin.online'
    ;;
  /var/www/vazin-sites/studio-vazin-online)
    SITE_KEY='studio-vazin-online'
    SITE_HOST='studio.vazin.online'
    ;;
  *)
    echo 'Site root is not in the exact managed-site allowlist.' >&2
    exit 2
    ;;
esac

STATE_ROOT="$STATE_BASE/$SITE_KEY"
RUNTIME_SITE="$RUNTIME_BASE/$SITE_KEY"
RUNTIME_DATA="$RUNTIME_SITE/data"
RUNTIME_STORAGE="$RUNTIME_DATA/storage"
RUNTIME_UPLOADS="$RUNTIME_DATA/uploads"
BACKUP_ROOT="$BACKUP_BASE/$SITE_KEY"
DURABLE_HELPER="$STATE_ROOT/atomic-release-$NEW_VERSION.py"
DURABLE_ROLLBACK="$STATE_ROOT/rollback-$NEW_VERSION.sh"

for command in python3 php rsync nginx curl sha256sum find sort xargs tar flock install stat grep awk sed mktemp getent id mv runuser systemctl pg_dump; do
  command -v "$command" >/dev/null || { echo "Missing dependency: $command" >&2; exit 3; }
done
[[ "$SOURCE" != "$SITE_ROOT" && "$SOURCE" != "$SITE_ROOT/"* ]] || {
  echo 'Extract the release outside the live target.' >&2
  exit 2
}
python3 "$HELPER" validate-source "$SOURCE"
[[ -s "$MANIFEST" && ! -L "$MANIFEST" ]] || { echo 'Release manifest is missing or unsafe.' >&2; exit 2; }
(cd "$SOURCE" && sha256sum -c MANIFEST.sha256 >/dev/null)
[[ "$(tr -d '\r\n' < "$SOURCE/VERSION")" == "$NEW_VERSION" ]] || { echo 'Release VERSION mismatch.' >&2; exit 2; }
MANIFEST_SHA="$(sha256sum "$MANIFEST" | awk '{print $1}')"

python3 "$HELPER" ensure-dir "$STATE_BASE" /var/lib
python3 "$HELPER" ensure-dir "$STATE_ROOT" "$STATE_BASE"
exec 9>"$STATE_ROOT/install.lock"
chmod 0600 "$STATE_ROOT/install.lock"
flock -n 9 || { echo 'Another CMS release operation is active for this site.' >&2; exit 75; }

HELPER_TMP="$(mktemp "$STATE_ROOT/.atomic-release.XXXXXX")"
ROLLBACK_TMP="$(mktemp "$STATE_ROOT/.rollback.XXXXXX")"
install -m 0700 -o root -g root "$HELPER" "$HELPER_TMP"
install -m 0700 -o root -g root "$SOURCE/deploy/rollback.sh" "$ROLLBACK_TMP"
python3 "$HELPER" fsync-file "$HELPER_TMP"
python3 "$HELPER" fsync-file "$ROLLBACK_TMP"
mv -T "$HELPER_TMP" "$DURABLE_HELPER"
mv -T "$ROLLBACK_TMP" "$DURABLE_ROLLBACK"
python3 "$HELPER" fsync-file "$DURABLE_HELPER"
python3 "$HELPER" fsync-file "$DURABLE_ROLLBACK"
[[ "$(sha256sum "$HELPER" | awk '{print $1}')" == "$(sha256sum "$DURABLE_HELPER" | awk '{print $1}')" ]] || exit 70
[[ "$(sha256sum "$SOURCE/deploy/rollback.sh" | awk '{print $1}')" == "$(sha256sum "$DURABLE_ROLLBACK" | awk '{print $1}')" ]] || exit 70

getent passwd www-data >/dev/null
getent group www-data >/dev/null
SERVICE_UID="$(id -u www-data)"
SERVICE_GID="$(id -g www-data)"
python3 "$HELPER" ensure-dir "$BACKUP_BASE" /var/backups
python3 "$HELPER" ensure-dir "$BACKUP_ROOT" "$BACKUP_BASE"
python3 "$HELPER" ensure-runtime-root "$RUNTIME_BASE" /var/lib "$SERVICE_GID"
python3 "$HELPER" ensure-runtime-root "$RUNTIME_SITE" "$RUNTIME_BASE" "$SERVICE_GID"
python3 "$HELPER" ensure-runtime-generation "$RUNTIME_DATA" "$RUNTIME_SITE" "$SERVICE_UID" "$SERVICE_GID"

as_www() {
  /usr/sbin/runuser -u www-data -- "$@"
}

validate_external_environment() {
  python3 - "$1" "$RUNTIME_STORAGE" "$RUNTIME_UPLOADS" <<'PY'
import os, stat, sys

path, storage, uploads = sys.argv[1:]
info = os.lstat(path)
if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode):
    raise SystemExit("CMS environment is unsafe")
values = {}
for raw in open(path, encoding="utf-8"):
    line = raw.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
if values.get("VAZINCMS_STORAGE_PATH") != storage or values.get("VAZINCMS_UPLOADS_PATH") != uploads:
    raise SystemExit("CMS external runtime paths do not match this managed site")
if values.get("DB_CONNECTION", "pgsql") == "sqlite" and values.get("DB_DATABASE") != storage + "/database.sqlite":
    raise SystemExit("CMS SQLite database is not bound to the external runtime")
PY
}

health() {
  local expected="$1" body='' attempt
  for attempt in $(seq 1 30); do
    body="$(curl -fsS --max-time 2 --resolve "$SITE_HOST:443:127.0.0.1" "https://$SITE_HOST/health" 2>/dev/null || true)"
    if BODY="$body" EXPECTED="$expected" python3 - <<'PY'
import json, os
try:
    value = json.loads(os.environ.get("BODY", ""))
except Exception:
    raise SystemExit(1)
raise SystemExit(0 if isinstance(value, dict) and value.get("ok") is True and value.get("service") == "VazinCMS" and value.get("version") == os.environ["EXPECTED"] else 1)
PY
    then
      return 0
    fi
    sleep 1
  done
  return 1
}

recover_pending() {
  [[ "$(python3 "$DURABLE_HELPER" status "$STATE_ROOT")" == pending ]] || return 0
  systemctl stop nginx
  python3 "$DURABLE_HELPER" recover "$STATE_ROOT" "$SITE_ROOT" "$OLD_VERSION" "$NEW_VERSION"
  nginx -t
  systemctl start nginx
  health "$OLD_VERSION" || {
    systemctl stop nginx
    echo 'Recovered CMS did not pass exact old-version health.' >&2
    return 70
  }
}

recover_pending
if [[ $RECOVER_ONLY -eq 1 ]]; then
  [[ "$(python3 "$DURABLE_HELPER" status "$STATE_ROOT")" == none ]] || {
    echo 'CMS recovery journal remains pending; site stays fenced for manual review.' >&2
    exit 70
  }
  echo "No pending CMS deployment remains for $SITE_KEY."
  exit 0
fi
if [[ -f "$SITE_ROOT/VERSION" && "$(tr -d '\r\n' < "$SITE_ROOT/VERSION")" == "$NEW_VERSION" ]]; then
  python3 "$DURABLE_HELPER" completed-status "$STATE_ROOT" "$SITE_ROOT" "$OLD_VERSION" "$NEW_VERSION" >/dev/null
  health "$NEW_VERSION" || { echo 'Completed CMS release is not healthy.' >&2; exit 70; }
  echo "VazinCMS $NEW_VERSION is already installed for $SITE_KEY."
  exit 0
fi

[[ -d "$SITE_ROOT" && ! -L "$SITE_ROOT" && -f "$SITE_ROOT/VERSION" ]] || { echo 'Managed site root is missing or unsafe.' >&2; exit 2; }
[[ "$(tr -d '\r\n' < "$SITE_ROOT/VERSION")" == "$OLD_VERSION" ]] || { echo "Expected exact current version $OLD_VERSION." >&2; exit 2; }
[[ -s "$SITE_ROOT/.env" && ! -L "$SITE_ROOT/.env" && -d "$SITE_ROOT/public" && ! -L "$SITE_ROOT/public" ]] || {
  echo 'Managed site code or environment is missing or unsafe.' >&2
  exit 2
}
[[ "$(stat -c '%u:%g:%a' "$SITE_ROOT/.env")" == "0:$SERVICE_GID:640" ]] || {
  echo 'Managed site environment permissions are unsafe.' >&2
  exit 70
}
python3 "$DURABLE_HELPER" validate-live "$SITE_ROOT" .env
validate_external_environment "$SITE_ROOT/.env"
[[ ! -e "$SITE_ROOT/storage" && ! -L "$SITE_ROOT/storage" && ! -e "$SITE_ROOT/public/uploads" && ! -L "$SITE_ROOT/public/uploads" ]] || {
  echo 'Managed site unexpectedly contains a legacy mutable runtime.' >&2
  exit 70
}
python3 "$DURABLE_HELPER" probe-exchange "$(dirname "$SITE_ROOT")"
systemctl is-active --quiet nginx || { echo 'Nginx must be active before a live CMS upgrade.' >&2; exit 70; }

STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"
CORRELATION="cms-$SITE_KEY-$STAMP"
FRESH="$(mktemp -d "$(dirname "$SITE_ROOT")/.$SITE_KEY.new-$STAMP-XXXXXX")"
PREVIOUS="$(dirname "$SITE_ROOT")/.$SITE_KEY.previous-$STAMP"
FAILED="$(dirname "$SITE_ROOT")/.$SITE_KEY.failed-$STAMP"
rsync -a --delete --no-owner --no-group --exclude=.env --exclude=storage/ --exclude=public/uploads/ --exclude='__pycache__/' --exclude='*.pyc' "$SOURCE/" "$FRESH/"
install -m 0640 -o root -g www-data "$SITE_ROOT/.env" "$FRESH/.env"
validate_external_environment "$FRESH/.env"
find "$FRESH" -xdev -type l -print -quit | grep -q . && { echo 'Candidate symlink rejected.' >&2; exit 70; }
chown -R root:root "$FRESH"
find "$FRESH" -xdev -type d -exec chmod 0755 {} +
find "$FRESH" -xdev -type f -exec chmod 0644 {} +
chown root:www-data "$FRESH/.env"
chmod 0640 "$FRESH/.env"
find "$FRESH" -xdev -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
as_www php "$FRESH/tests/identity-link-contract.php"
as_www php "$FRESH/tests/admin-brand-locale-contract.php"
as_www php "$FRESH/tests/admin-brand-locale-render-contract.php"
as_www php "$FRESH/tests/admin-navigation-contract.php"
as_www php "$FRESH/tests/travel-alerts-responsive-contract.php"
as_www php "$FRESH/tests/extension-kernel.php"
as_www php "$FRESH/tests/travel-visa-platform-contract.php"
as_www php "$FRESH/tests/manual-visa-no-checkout-contract.php"
as_www php "$FRESH/tests/agency-inquiry-workflow-contract.php"
as_www php "$FRESH/tests/telegram-alert-readiness-contract.php"
(cd "$FRESH" && sha256sum -c MANIFEST.sha256 >/dev/null)
python3 "$DURABLE_HELPER" seal-tree "$FRESH"

BACKUP_CHILD="$(python3 "$DURABLE_HELPER" create-child "$BACKUP_ROOT" "cms-$STAMP-")"
tar --exclude=.env --exclude=storage --exclude=public/uploads --exclude='__pycache__' --exclude='*.pyc' -czf "$BACKUP_CHILD/code.tar.gz" -C "$SITE_ROOT" .
install -m 0600 -o root -g root "$SITE_ROOT/.env" "$BACKUP_CHILD/environment.original"
tar -czf "$BACKUP_CHILD/runtime.tar.gz" -C "$RUNTIME_DATA" storage uploads
for item in code.tar.gz environment.original runtime.tar.gz; do
  python3 "$DURABLE_HELPER" fsync-file "$BACKUP_CHILD/$item"
done
DB_DRIVER="$(python3 - "$SITE_ROOT/.env" <<'PY'
import sys
values = {}
for raw in open(sys.argv[1], encoding="utf-8"):
    line = raw.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
driver = values.get("DB_CONNECTION", "pgsql")
if driver not in {"pgsql", "sqlite"}:
    raise SystemExit("unsupported database driver")
print(driver)
PY
)"
if [[ "$DB_DRIVER" == sqlite ]]; then
  DB_BACKUP="$BACKUP_CHILD/database.sqlite"
  python3 - "$SITE_ROOT/.env" "$RUNTIME_STORAGE/database.sqlite" "$DB_BACKUP" <<'PY'
import sqlite3, sys

env, default, out = sys.argv[1:]
values = {}
for raw in open(env, encoding="utf-8"):
    line = raw.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
source_path = values.get("DB_DATABASE", default)
source = sqlite3.connect("file:" + source_path + "?mode=ro", uri=True)
target = sqlite3.connect(out)
try:
    if source.execute("PRAGMA quick_check").fetchone()[0] != "ok":
        raise SystemExit("source database quick_check failed")
    source.backup(target)
    if target.execute("PRAGMA quick_check").fetchone()[0] != "ok":
        raise SystemExit("backup database quick_check failed")
finally:
    target.close()
    source.close()
PY
  chmod 0600 "$DB_BACKUP"
  python3 "$DURABLE_HELPER" fsync-file "$DB_BACKUP"
else
  DB_BACKUP="$BACKUP_CHILD/database.dump"
  python3 - "$SITE_ROOT/.env" "$DB_BACKUP" <<'PY'
import os, subprocess, sys

env_file, out = sys.argv[1:]
values = {}
for raw in open(env_file, encoding="utf-8"):
    line = raw.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
required = ["DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"]
if any(not values.get(key) or any(ord(char) < 32 or ord(char) == 127 for char in values[key]) for key in required):
    raise SystemExit("database backup configuration invalid")
environment = os.environ.copy()
environment["PGPASSWORD"] = values["DB_PASSWORD"]
command = ["pg_dump", "--format=custom", "--no-owner", "--no-privileges", "--host", values["DB_HOST"], "--port", values["DB_PORT"], "--username", values["DB_USERNAME"], "--file", out, values["DB_DATABASE"]]
result = subprocess.run(command, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True, timeout=300)
if result.returncode != 0:
    raise SystemExit("database backup failed")
os.chmod(out, 0o600)
PY
  python3 "$DURABLE_HELPER" fsync-file "$DB_BACKUP"
fi
(cd "$BACKUP_CHILD" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 -r sha256sum > SHA256SUMS && sha256sum -c SHA256SUMS >/dev/null)
chmod 0600 "$BACKUP_CHILD/SHA256SUMS"
python3 "$DURABLE_HELPER" fsync-file "$BACKUP_CHILD/SHA256SUMS"
python3 "$DURABLE_HELPER" seal-tree "$BACKUP_CHILD"

python3 "$DURABLE_HELPER" begin "$STATE_ROOT" "$SITE_ROOT" "$FRESH" "$PREVIOUS" "$FAILED" "$OLD_VERSION" "$NEW_VERSION" "$CORRELATION" "$MANIFEST_SHA" \
  --runtime-active "$RUNTIME_DATA" \
  --legacy-storage "$SITE_ROOT/storage" --legacy-uploads "$SITE_ROOT/public/uploads" \
  --runtime-storage "$RUNTIME_STORAGE" --runtime-uploads "$RUNTIME_UPLOADS" \
  --service-uid "$SERVICE_UID" --service-gid "$SERVICE_GID" \
  "$BACKUP_CHILD/code.tar.gz" "$BACKUP_CHILD/environment.original" "$BACKUP_CHILD/runtime.tar.gz" "$DB_BACKUP" "$BACKUP_CHILD/SHA256SUMS"

rollback_on_exit() {
  local code=$?
  trap - EXIT HUP INT TERM
  set +e
  if [[ $code -ne 0 && "$(python3 "$DURABLE_HELPER" status "$STATE_ROOT" 2>/dev/null)" == pending ]]; then
    systemctl stop nginx >/dev/null 2>&1 || true
    if python3 "$DURABLE_HELPER" recover "$STATE_ROOT" "$SITE_ROOT" "$OLD_VERSION" "$NEW_VERSION" && nginx -t >/dev/null 2>&1 && systemctl start nginx >/dev/null 2>&1 && health "$OLD_VERSION"; then
      :
    else
      echo 'CMS recovery is ambiguous; Nginx remains stopped and journal retained.' >&2
      code=70
    fi
  elif [[ $code -ne 0 ]]; then
    systemctl start nginx >/dev/null 2>&1 || true
  fi
  exit "$code"
}
trap rollback_on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

systemctl stop nginx
python3 "$DURABLE_HELPER" validate-live "$SITE_ROOT" .env
validate_external_environment "$SITE_ROOT/.env"
python3 "$DURABLE_HELPER" swap "$STATE_ROOT" "$SITE_ROOT" "$OLD_VERSION" "$NEW_VERSION"
as_www php "$SITE_ROOT/scripts/migrate.php"
as_www php "$SITE_ROOT/scripts/extensions.php" sync
as_www php "$SITE_ROOT/scripts/configure-site-profile.php"
as_www php -r 'require $argv[1]."/src/bootstrap.php";$pdo=VazinCMS\Database::connection();if(!$pdo->query("SELECT 1")->fetchColumn()||VazinCMS\Version::current()!=="10.29.0")exit(1);' "$SITE_ROOT"
nginx -t
systemctl start nginx
health "$NEW_VERSION" || { systemctl status nginx --no-pager >&2 || true; false; }
python3 "$DURABLE_HELPER" complete "$STATE_ROOT" "$SITE_ROOT" "$OLD_VERSION" "$NEW_VERSION"
trap - EXIT HUP INT TERM
echo "VazinCMS $NEW_VERSION installed for $SITE_KEY. Correlation: $CORRELATION"
echo "Verified backup: $BACKUP_CHILD"
