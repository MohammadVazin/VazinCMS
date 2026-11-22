#!/usr/bin/env bash
# File-only QA under an existing unprivileged user; NOT a rootless Docker
# runtime, plugin execution, trust grant, or worker-account provisioning.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST=site-sandbox-nonroot-cleanup-contract.php
PHP_BIN="$(command -v php)"
if [ "$(id -u)" -ne 0 ]; then
  exec "$PHP_BIN" "$ROOT/tests/$TEST"
fi
# Refuse rather than silently accepting privileged/root filesystem tests.
command -v runuser >/dev/null
id nobody >/dev/null
uid="$(id -u nobody)"; gid="$(id -g nobody)"
test "$uid" -gt 0
qa="$(mktemp -d /tmp/vazincms-nonroot-fs-XXXXXXXX)"
case "$qa" in /tmp/vazincms-nonroot-fs-*) ;; *) exit 65;; esac
cleanup(){ rm -rf -- "$qa"; }
trap cleanup EXIT
mkdir -m 0700 "$qa/src" "$qa/tests" "$qa/tmp"
cp -- "$ROOT/src/SiteScopedExtensionSandbox.php" "$qa/src/SiteScopedExtensionSandbox.php"
cp -- "$ROOT/tests/$TEST" "$qa/tests/$TEST"
chmod 0600 "$qa/src/SiteScopedExtensionSandbox.php" "$qa/tests/$TEST"
chown -R "$uid:$gid" "$qa"
runuser -u nobody -- env -i PATH=/usr/bin:/bin HOME="$qa" TMPDIR="$qa/tmp" \
  "$PHP_BIN" "$qa/tests/$TEST"
