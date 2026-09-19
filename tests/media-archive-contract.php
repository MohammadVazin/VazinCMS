<?php
declare(strict_types=1);

if (!class_exists(ZipArchive::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) { echo "media-archive-contract: SKIP\n"; exit(0); }
$root = dirname(__DIR__); $runtime = sys_get_temp_dir() . '/vazincms-media-' . bin2hex(random_bytes(5));
mkdir($runtime . '/storage', 0700, true); mkdir($runtime . '/uploads', 0700, true);
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('VAZINCMS_STORAGE_PATH='.$runtime.'/storage'); putenv('VAZINCMS_UPLOADS_PATH='.$runtime.'/uploads'); putenv('SESSION_SECURE=false');
require $root . '/src/bootstrap.php';
use VazinCMS\{Database,MediaArchiveImporter};
$expect = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$pdo = Database::connection(); $pdo->exec((string)file_get_contents($root.'/database/schema-sqlite.sql')); $pdo->exec('CREATE TABLE cms_pages(id INTEGER PRIMARY KEY); CREATE TABLE cms_menu_items(id INTEGER PRIMARY KEY);'); $pdo->exec((string)file_get_contents($root.'/database/migrations/6.0.0-sqlite.sql')); $pdo->exec((string)file_get_contents($root.'/database/migrations/10.13.0-sqlite.sql'));
$unsafe = $runtime.'/unsafe.zip'; $zip = new ZipArchive(); $zip->open($unsafe, ZipArchive::CREATE); $zip->addFromString('../escape.jpg', 'not-image'); $zip->close();
try { MediaArchiveImporter::import($unsafe, $pdo, 1); throw new RuntimeException('Unsafe path was accepted.'); } catch (InvalidArgumentException) {}
$safe = $runtime.'/safe.zip'; $zip = new ZipArchive(); $zip->open($safe, ZipArchive::CREATE); $zip->addFromString('media/pixel.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL9xQAAAABJRU5ErkJggg==')); $zip->close();
$stats = MediaArchiveImporter::import($safe, $pdo, 1); $expect($stats['imported'] === 1, 'Safe image was not imported.'); $expect((int)$pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn() === 1, 'Media record was not created.');
echo "media-archive-contract: OK\n";
