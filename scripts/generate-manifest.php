<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__));
if ($root === false) {
    throw new RuntimeException('Cannot resolve the release root.');
}

$manifestPath = $root . DIRECTORY_SEPARATOR . 'MANIFEST.sha256';
$checkOnly = in_array('--check', array_slice($argv, 1), true);
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $entry) {
    if ($entry->isLink()) {
        throw new RuntimeException('Symlink is not allowed in a release: ' . $entry->getPathname());
    }
    if (!$entry->isFile()) {
        continue;
    }

    $relative = str_replace(
        DIRECTORY_SEPARATOR,
        '/',
        substr($entry->getPathname(), strlen($root) + 1)
    );
    if (isExcludedReleasePath($relative)) {
        continue;
    }
    $files[] = $relative;
}

sort($files, SORT_STRING);
$lines = [];
foreach ($files as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException('Cannot hash release file: ' . $relative);
    }
    $lines[] = $hash . '  ' . $relative;
}
$content = implode("\n", $lines) . "\n";

if ($checkOnly) {
    $current = @file_get_contents($manifestPath);
    if ($current === false || !hash_equals($content, $current)) {
        fwrite(STDERR, "MANIFEST.sha256 is stale or non-canonical.\n");
        exit(1);
    }
    echo "MANIFEST.sha256 is canonical and complete.\n";
    exit(0);
}

if (file_put_contents($manifestPath, $content, LOCK_EX) === false) {
    throw new RuntimeException('Cannot write MANIFEST.sha256.');
}
echo 'Generated MANIFEST.sha256 with ' . count($files) . " files.\n";

function isExcludedReleasePath(string $relative): bool
{
    if ($relative === 'MANIFEST.sha256') {
        return true;
    }
    // Git metadata changes with every clone and must never be part of a
    // portable release manifest. The tracked .github directory remains in
    // scope so the public source retains its CI workflow.
    if ($relative === '.git' || str_starts_with($relative, '.git/')) {
        return true;
    }
    if ($relative === '.env' || (str_starts_with($relative, '.env.') && $relative !== '.env.example')) {
        return true;
    }
    if ($relative === 'storage' || str_starts_with($relative, 'storage/')) {
        return true;
    }
    if ($relative === 'public/uploads' || str_starts_with($relative, 'public/uploads/')) {
        return true;
    }

    $segments = explode('/', $relative);
    return in_array('__pycache__', $segments, true)
        || str_ends_with($relative, '.pyc')
        || str_ends_with($relative, '.bak');
}
