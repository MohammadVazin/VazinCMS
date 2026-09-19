<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use VazinCMS\ExtensionManager;

[$script, $command, $argument] = $argv + [null, 'list', null];
try {
    if ($command === 'sync') {
        ExtensionManager::reset();
        ExtensionManager::synchronize(true);
        echo "Extension registry synchronized.\n";
        exit(0);
    }
    if ($command === 'install' && is_string($argument) && $argument !== '') {
        $row = ExtensionManager::installArchive($argument);
        echo "Installed {$row['extension_key']} {$row['version']} (inactive).\n";
        exit(0);
    }
    if ($command === 'activate' && is_string($argument)) {
        ExtensionManager::activate($argument); echo "Activated {$argument}.\n"; exit(0);
    }
    if ($command === 'deactivate' && is_string($argument)) {
        ExtensionManager::deactivate($argument); echo "Deactivated {$argument}.\n"; exit(0);
    }
    if ($command === 'archive' && is_string($argument)) {
        ExtensionManager::archive($argument); echo "Archived {$argument}.\n"; exit(0);
    }
    if ($command === 'list') {
        foreach (ExtensionManager::all() as $row) {
            echo implode("\t", [$row['extension_type'], $row['extension_key'], $row['version'], $row['status'], $row['source']]) . "\n";
        }
        exit(0);
    }
    fwrite(STDERR, "Usage: php scripts/extensions.php list|sync|install <zip>|activate <key>|deactivate <key>|archive <key>\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
