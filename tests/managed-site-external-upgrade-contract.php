<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$manifest = json_decode((string) file_get_contents($root . '/vazin-package.json'), true, 32, JSON_THROW_ON_ERROR);
$expectedKeys = [
    'schema','product','type','version','minimum_php','entrypoint','upgrade_only','managed_site_upgrade_contract',
    'supports_preview','supports_rollback','compatible_targets','minimum_vazin_online','minimum_current_version',
    'supported_current_versions','release_channel','risk_level','connector_version','requires','release_notes',
];
sort($expectedKeys);
$actualKeys = array_keys($manifest);
sort($actualKeys);
$check($actualKeys === $expectedKeys, 'Upgrade manifest keys are not exact.');
$check(($manifest['version'] ?? null) === '10.28.0', 'Manifest version must be 10.28.0.');
$check(($manifest['entrypoint'] ?? null) === 'deploy/install.sh', 'Upgrade entrypoint is not selected.');
$check(($manifest['upgrade_only'] ?? null) === true, 'Release must be upgrade-only.');
$check(($manifest['managed_site_upgrade_contract'] ?? null) === 'external-runtime-v1', 'External runtime contract is missing.');
$check(($manifest['minimum_vazin_online'] ?? null) === '16.8.27', 'Upgrade requires VazinOnline 16.8.27.');
$check(($manifest['minimum_current_version'] ?? null) === '10.27.0', 'Upgrade predecessor must be exact.');
$check(($manifest['supported_current_versions'] ?? null) === ['10.27.0'], 'Supported predecessor list must be exact.');
$check(!array_key_exists('bootstrap_only', $manifest), 'Bootstrap flag must be absent from an upgrade package.');
$check(!array_key_exists('managed_site_bootstrap_contract', $manifest), 'Bootstrap contract must be absent from an upgrade package.');

$installer = (string) file_get_contents($root . '/deploy/install.sh');
foreach ([
    "NEW_VERSION='10.28.0'",
    "OLD_VERSION='10.27.0'",
    'printenv INSTALL_DIR',
    '--site-root',
    'validate_external_environment',
    'runtime.tar.gz',
    'probe-exchange',
    'configure-site-profile.php',
    'agency-inquiry-workflow-contract.php',
    'admin-brand-locale-contract.php',
    'admin-brand-locale-render-contract.php',
    'admin-navigation-contract.php',
    'telegram-alert-readiness-contract.php',
    '/usr/sbin/runuser -u www-data --',
    'CMS recovery journal remains pending',
    'status "$STATE_ROOT"',
    'python3 "$DURABLE_HELPER" swap',
] as $needle) {
    $check(str_contains($installer, $needle), 'External runtime installer is missing: ' . $needle);
}
foreach (['hydrate_old_slot', 'runtime-prepared', 'runtime-ready', 'runtime-switch', 'runtime-bind', '-d "$SITE_ROOT/storage"'] as $forbidden) {
    $check(!str_contains($installer, $forbidden), 'External runtime installer contains legacy flow: ' . $forbidden);
}

$helper = (string) file_get_contents($root . '/deploy/atomic_release.py');
$check(str_contains($helper, 'existing external runtime bindings are not exact before code swap'), 'Existing-runtime atomic swap guard is missing.');
$check(str_contains($helper, 'elif runtime["mode"]=="existing"'), 'Existing-runtime swap branch is missing.');
$rollback = (string) file_get_contents($root . '/deploy/rollback.sh');
$check(str_contains($rollback, 'External runtime and schema remain preserved.'), 'Rollback contract is not explicit.');
$check(!str_contains($rollback, 'hydrate_old_slot'), 'Rollback must not hydrate legacy runtime.');
$check(is_file($root . '/database/migrations/10.27.0-sqlite.sql'), 'SQLite tenant-demo preflight migration marker is missing.');
$check(is_file($root . '/database/migrations/10.27.0-pgsql.sql'), 'PostgreSQL tenant-demo preflight migration marker is missing.');
$check(is_file($root . '/tests/admin-brand-locale-render-contract.php'), 'Admin brand locale render contract is missing.');
$check(is_file($root . '/tests/admin-navigation-contract.php'), 'Admin navigation contract is missing.');

echo "Managed-site external-runtime upgrade contract: OK\n";
