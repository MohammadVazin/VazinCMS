#!/usr/bin/env bash
# UNRELEASED CANDIDATE. Code rollback for the VazinCMS 10.30.3 upgrade bridge.
set -Eeuo pipefail
umask 077

[[ $EUID -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
SITE_ROOT="$(printenv INSTALL_DIR 2>/dev/null || true)"
while (($#)); do
  case "$1" in
    --site-root)
      (($# >= 2)) || { echo 'Usage: rollback.sh --site-root <exact-managed-root>' >&2; exit 2; }
      SITE_ROOT="$2"
      shift 2
      ;;
    *)
      echo 'Usage: rollback.sh --site-root <exact-managed-root>' >&2
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

OLD_VERSION='10.29.0'
NEW_VERSION='10.30.3'
STATE_ROOT="/var/lib/vazincms-deploy/$SITE_KEY"
HELPER="$STATE_ROOT/atomic-release-$NEW_VERSION.py"
FENCE_HELPER="$STATE_ROOT/site-fence-guard-$NEW_VERSION.py"
FENCE_FILE="${VAZINCMS_SITE_FENCE_TOKEN_FILE:-}"


for command in python3 nginx curl flock chmod systemctl; do
  command -v "$command" >/dev/null || { echo "Missing dependency: $command" >&2; exit 3; }
done
[[ -n "$FENCE_FILE" && "$FENCE_FILE" = /* ]] || { echo 'NO_GO: missing per-site traffic fence token-file.' >&2; exit 70; }
[[ -s "$FENCE_HELPER" && ! -L "$FENCE_HELPER" ]] || exit 70
python3 "$FENCE_HELPER" check --host "$SITE_HOST" --token-file "$FENCE_FILE"
[[ -s "$HELPER" && ! -L "$HELPER" ]] || { echo 'Durable CMS release helper is missing or unsafe.' >&2; exit 70; }
exec 9>"$STATE_ROOT/install.lock"
chmod 0600 "$STATE_ROOT/install.lock"
flock -n 9 || { echo 'Another CMS release operation is active.' >&2; exit 75; }
systemctl is-active --quiet nginx || { echo 'Nginx must be active before a live CMS rollback.' >&2; exit 70; }

site_fenced() {
  python3 "$FENCE_HELPER" check --host "$SITE_HOST" --token-file "$FENCE_FILE"
}

health() {
  local expected="$1" attempt
  for attempt in $(seq 1 30); do
    if python3 "$FENCE_HELPER" health --host "$SITE_HOST" --token-file "$FENCE_FILE" --version "$expected"; then
      return 0
    fi
    sleep 1
  done
  return 1
}

state="$(python3 "$HELPER" rollback-begin "$STATE_ROOT" "$SITE_ROOT" "$OLD_VERSION" "$NEW_VERSION")"
if [[ "$state" == already ]]; then
  health "$OLD_VERSION" || { echo 'Rolled-back CMS is not healthy.' >&2; exit 70; }
  echo "VazinCMS $OLD_VERSION is already live for $SITE_KEY."
  exit 0
fi

rollback_on_exit() {
  local code=$?
  trap - EXIT HUP INT TERM
  set +e
  if [[ $code -ne 0 ]]; then
    if site_fenced && python3 "$HELPER" recover "$STATE_ROOT" "$SITE_ROOT" "$OLD_VERSION" "$NEW_VERSION" && nginx -t >/dev/null 2>&1 && health "$OLD_VERSION"; then
      :
    else
      echo 'NO_GO: site fence or recovery ambiguous; shared Nginx untouched and journal retained.' >&2
      code=70
    fi
  fi
  exit "$code"
}
trap rollback_on_exit EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

site_fenced || { echo 'NO_GO: per-site fence disappeared before rollback.' >&2; exit 70; }
python3 "$HELPER" recover "$STATE_ROOT" "$SITE_ROOT" "$OLD_VERSION" "$NEW_VERSION"
nginx -t
site_fenced || { echo 'NO_GO: site fence disappeared after rollback.' >&2; exit 70; }
health "$OLD_VERSION" || { systemctl status nginx --no-pager >&2 || true; false; }
trap - EXIT HUP INT TERM
echo "VazinCMS code rolled back to $OLD_VERSION for $SITE_KEY. External runtime and schema remain preserved."
echo "Per-site Nginx traffic fence remains ACTIVE for manual owner acceptance."
