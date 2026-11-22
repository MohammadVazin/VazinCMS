#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
PYTHON_BIN="${PYTHON_BIN:-python3}"

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?$ ]]
[[ "$VERSION" == "11.0.0" ]]

php "$ROOT/scripts/generate-manifest.php" --check
(cd "$ROOT" && sha256sum -c MANIFEST.sha256 >/dev/null)

"$PYTHON_BIN" - "$ROOT/vazin-package.json" "$VERSION" <<'PY'
import json, sys
m = json.load(open(sys.argv[1], encoding='utf-8'))
version = sys.argv[2]
assert m['schema'] == 2 and m['product'] == 'VazinCMS' and m['type'] == 'vazincms'
assert m['version'] == version == '11.0.0'
assert m['minimum_php'] == '8.2' and m['supports_fresh_install'] is True
assert m['requires'] == {}
PY

# Security & Open-Core Sanitization Gate
! grep -Rqs 'namespace VazinOnline' "$ROOT/src"
! test -e "$ROOT/src/TravelVisaCommerceService.php"
! test -e "$ROOT/src/VisaApplicationService.php"
! test -d "$ROOT/extensions/modules/vazin-ai-site-assistant"
! grep -RqsE 'BEGIN (RSA |EC |OPENSSH |)PRIVATE KEY' "$ROOT/src" "$ROOT/extensions" "$ROOT/deploy" "$ROOT/scripts" "$ROOT/docs"

for script in "$ROOT/deploy/install.sh" "$ROOT/deploy/rollback.sh" "$ROOT/deploy/provision.sh"; do
  bash -n "$script"
done

if command -v node >/dev/null; then
  node --check "$ROOT/public/assets/telegram-assistant-1090.js"
  node "$ROOT/tests/editor-gallery-contract.js"
  node "$ROOT/tests/editor-media-picker-contract.js"
  node "$ROOT/tests/block-editor-history-contract.js"
fi

"$PYTHON_BIN" -m unittest discover -s "$ROOT/tests" -p 'test_*.py' -v

find "$ROOT" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

# Core v11 Modular, Security, Multi-Tenant & UI Contract Suite
php "$ROOT/tests/v11-modular-suite.php"
php "$ROOT/tests/smoke.php"
php "$ROOT/tests/health-contract.php"
php "$ROOT/tests/extension-kernel.php"
php "$ROOT/tests/identity-link-contract.php"
php "$ROOT/tests/admin-brand-locale-contract.php"
php "$ROOT/tests/admin-brand-locale-render-contract.php"
php "$ROOT/tests/admin-navigation-contract.php"
php "$ROOT/tests/local-return-contract.php"
php "$ROOT/tests/update-feed-contract.php"
php "$ROOT/tests/update-feed-store-contract.php"
php "$ROOT/tests/delivery-policy-contract.php"
php "$ROOT/tests/telegram-assistant-security-contract.php"
php "$ROOT/tests/telegram-business-authorization-contract.php"
php "$ROOT/tests/telegram-assistant-contract.php"
php "$ROOT/tests/telegram-appointment-scheduling-contract.php"
php "$ROOT/tests/vazinpay-gateway-contract.php"
php "$ROOT/tests/tenant-demo-preflight-contract.php"
php "$ROOT/tests/public-theme-ui-contract.php"
php "$ROOT/tests/public-documentation-contract.php"
php "$ROOT/tests/managed-site-external-upgrade-contract.php"
php "$ROOT/tests/fleet-status-contract.php"
php "$ROOT/tests/stage4-contract.php"
php "$ROOT/tests/site-isolation-gate-contract.php"
php "$ROOT/tests/local-staff-sso-contract.php"
php "$ROOT/tests/site-scope-mode-contract.php"
php "$ROOT/tests/site-scoped-content-repository-contract.php"
php "$ROOT/tests/site-scoped-write-portable-contract.php"
php "$ROOT/tests/site-unique-keys-contract.php"
php "$ROOT/tests/block-editor-columns-product-contract.php"
php "$ROOT/tests/editor-autosave-recovery-contract.php"
php "$ROOT/tests/content-revision-blocks-contract.php"
php "$ROOT/tests/editor-new-draft-manual-save-contract.php"
php "$ROOT/tests/site-design-contract.php"
php "$ROOT/tests/site-template-builder-contract.php"
php "$ROOT/tests/site-template-public-integration-contract.php"
php "$ROOT/tests/site-canvas-contract.php"
php "$ROOT/tests/site-canvas-wiring-contract.php"
php "$ROOT/tests/editor-gallery-render-contract.php"
php "$ROOT/tests/default-theme-columns-asset-contract.php"
php "$ROOT/tests/missing-browser-icon-session-contract.php"
php "$ROOT/tests/site-scoped-blocks-contract.php"
php "$ROOT/tests/site-scoped-wordpress-contract.php"
php "$ROOT/tests/site-scoped-operation-gates-contract.php"
php "$ROOT/tests/site-scoped-seo-editorial-contract.php"
php "$ROOT/tests/site-scoped-extension-token-jobs-contract.php"
php "$ROOT/tests/site-admin-session-binding-contract.php"
php "$ROOT/tests/site-scoped-oidc-admission-contract.php"
php "$ROOT/tests/site-scoped-oidc-atomic-contract.php"
php "$ROOT/tests/site-session-persistence-contract.php"
php "$ROOT/tests/site-sandbox-contract.php"
php "$ROOT/tests/site-plugin-control-plane-contract.php"
php "$ROOT/tests/site-plugin-worker-compatibility-contract.php"
php "$ROOT/tests/site-plugin-rootless-daemon-contract.php"
php "$ROOT/tests/site-plugin-inprocess-validation-denial-contract.php"
php "$ROOT/tests/site-sandbox-pinned-source-contract.php"
php "$ROOT/tests/site-sandbox-cleanup-ambiguity-contract.php"
php "$ROOT/tests/site-ui-accessibility-contract.php"
php "$ROOT/tests/site-release-gate-contract.php"
php "$ROOT/tests/site-owner-domain-consistency-contract.php"
php "$ROOT/tests/site-domain-external-proof-contract.php"
php "$ROOT/tests/site-domain-dns-challenge-contract.php"
php "$ROOT/tests/site-domain-staging-transaction-contract.php"
php "$ROOT/tests/site-tenant-create-atomic-contract.php"
php "$ROOT/tests/site-release-external-signature-contract.php"
if [[ ${EUID:-1} -eq 0 ]]; then
  php "$ROOT/tests/site-external-trust-path-contract.php"
elif command -v sudo >/dev/null && sudo -n true 2>/dev/null; then
  sudo "$(command -v php)" "$ROOT/tests/site-external-trust-path-contract.php"
fi
php "$ROOT/tests/site-multisite-signed-approval-boundary-contract.php"
php "$ROOT/tests/site-staged-tenant-migrations-contract.php"
php "$ROOT/tests/site-scoped-admin-surface-contract.php"
php "$ROOT/tests/site-scoped-integration-surface-contract.php"
php "$ROOT/tests/migration-comment-only-contract.php"
php "$ROOT/tests/site-scoped-runtime-policy-contract.php"
php "$ROOT/tests/site-tenant-migration-preflight-contract.php"
php "$ROOT/tests/site-restore-archive-integrity-contract.php"
php "$ROOT/bin/vazin" security:audit >/dev/null

echo "VazinCMS $VERSION release & security check passed."
