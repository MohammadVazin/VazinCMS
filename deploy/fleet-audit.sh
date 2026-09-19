#!/usr/bin/env bash
# Read-only VazinCMS fleet gate. It never reads secrets and never changes a site.
set -Eeuo pipefail

SOURCE="$(cd "$(dirname "$0")/.." && pwd -P)"
EXPECTED_VERSION="${1:-$(tr -d '\r\n' < "$SOURCE/VERSION")}"
[[ "$EXPECTED_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Expected semantic version required.' >&2; exit 2; }

declare -a SITES=(
  'cms.vazin.online|/opt/VazinCMS-10.28.0'
  'studio.vazin.online|/var/www/vazin-sites/studio-vazin-online'
  'travel.vazin.online|/var/www/vazin-sites/travel-vazin-online'
  'visa.vazin.online|/var/www/vazin-sites/visa-vazin-online'
  'russiafa.ru|/var/www/russiafa-app/current'
  'learn.vazin.online|/var/www/vazin-learn-cms/current'
)

failures=0
for item in "${SITES[@]}"; do
  host="${item%%|*}"
  root="${item#*|}"
  version_file="$root/VERSION"
  manifest="$root/MANIFEST.sha256"
  version=''
  body=''
  if [[ -f "$version_file" ]]; then version="$(tr -d '\r\n' < "$version_file")"; fi
  if ! body="$(curl -fsS --max-time 10 "https://$host/health" 2>/dev/null)"; then
    printf 'FAIL host=%s reason=health-unreachable\n' "$host" >&2; failures=1; continue
  fi
  if ! BODY="$body" EXPECTED="$EXPECTED_VERSION" ROOT_VERSION="$version" python3 - <<'PY'
import json, os
try:
    health = json.loads(os.environ['BODY'])
except ValueError:
    raise SystemExit(1)
expected = os.environ['EXPECTED']
raise SystemExit(0 if health == {'ok': True, 'service': 'VazinCMS', 'version': expected}
                 and os.environ['ROOT_VERSION'] == expected else 1)
PY
  then
    printf 'FAIL host=%s reason=version-or-health-mismatch\n' "$host" >&2; failures=1; continue
  fi
  if ! (cd "$root" && sha256sum -c "$manifest" >/dev/null); then
    printf 'FAIL host=%s reason=manifest-mismatch\n' "$host" >&2; failures=1; continue
  fi
  printf 'PASS host=%s version=%s\n' "$host" "$EXPECTED_VERSION"
done

[[ "$failures" -eq 0 ]] || exit 70
printf 'PASS fleet=%s\n' "$EXPECTED_VERSION"
