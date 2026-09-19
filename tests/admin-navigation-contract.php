<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/platform-1010.css');
$head = (string) file_get_contents($root . '/views/partials/head.php');
if (!str_contains($css, '@media (min-width:921px){.admin-header .admin-nav{flex-wrap:wrap;justify-content:flex-start}}')) {
    throw new RuntimeException('Desktop admin navigation wrap guard is missing.');
}
if (!str_contains($head, '/assets/app-14.css?v=<?=rawurlencode($appVersion)?>')) {
    throw new RuntimeException('Responsive admin navigation stylesheet is not loaded.');
}

echo "Admin navigation overflow contract: OK\n";
