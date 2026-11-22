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
    'schema','product','type','version','minimum_php','entrypoint','upgrade_only','supports_fresh_install',
    'managed_site_upgrade_contract','supports_preview','supports_rollback','compatible_targets',
    'minimum_vazin_online','minimum_current_version','supported_current_versions','release_channel',
    'risk_level','connector_version','requires','optional_integrations','release_notes',
];
sort($expectedKeys);
$actualKeys = array_keys($manifest);
sort($actualKeys);
$check($actualKeys === $expectedKeys, 'Package manifest keys are not exact.');
$check(($manifest['version'] ?? null) === '11.1.0', 'Manifest version must be 11.1.0.');
$check(($manifest['entrypoint'] ?? null) === 'deploy/install.sh', 'Entrypoint is not selected.');
$check(($manifest['supports_fresh_install'] ?? null) === true, 'v11 must support standalone fresh install.');
$check(($manifest['managed_site_upgrade_contract'] ?? null) === 'external-runtime-v1', 'External runtime contract is missing.');

$installer = (string) file_get_contents($root . '/deploy/install.sh');
foreach ([
    "NEW_VERSION='11.1.0'",
    "OLD_VERSION='10.30.2'",
    '--standalone',
    'printenv INSTALL_DIR',
    '--site-root',
    'validate_external_environment',
    'runtime.tar.gz',
    'probe-exchange',
    'configure-site-profile.php',
    'admin-brand-locale-contract.php',
    'admin-brand-locale-render-contract.php',
    'admin-navigation-contract.php',
    'v11-modular-suite.php',
    '/usr/sbin/runuser -u www-data --',
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

echo "Managed-site external-runtime upgrade contract: OK\n";
