<?php
declare(strict_types=1);

namespace VazinCMS;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

final class ExtensionManager
{
    private const STATUSES = ['inactive', 'active', 'broken', 'removed'];
    private const MAX_ARCHIVE_BYTES = 25_000_000;
    private const MAX_EXPANDED_BYTES = 100_000_000;
    private const MAX_FILES = 2000;
    private const ALLOWED_FILES = [
        'php', 'json', 'css', 'js', 'mjs', 'map', 'svg', 'png', 'jpg', 'jpeg',
        'webp', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'md', 'txt', 'sql',
    ];
    private static bool $synchronized = false;
    private static bool $forceSynchronized = false;
    private static array $verifiedChecksums = [];

    public static function synchronize(bool $force = false): void
    {
        if (self::$synchronized && (!$force || self::$forceSynchronized)) return;
        if (!$force) {
            try {
                $ready = (bool) Database::connection()->query(
                    "SELECT 1 FROM cms_extensions WHERE extension_key='vazin-default' AND source='bundled' LIMIT 1"
                )->fetchColumn();
                if ($ready) { self::$synchronized = true; return; }
            } catch (Throwable) {
            }
        }
        self::ensureRuntimeLayout();
        self::synchronizeRoot(self::bundledRoot(), 'bundled');
        self::synchronizeRoot(RuntimePaths::extensions(), 'runtime');
        self::normalizeActiveTheme();
        self::$synchronized = true;
        self::$forceSynchronized = true;
    }

    public static function reset(): void
    {
        self::$synchronized = false;
        self::$forceSynchronized = false;
        self::$verifiedChecksums = [];
        ExtensionRuntime::reset();
    }

    public static function all(): array
    {
        self::synchronize(true);
        $rows = Database::connection()->query(
            "SELECT * FROM cms_extensions ORDER BY extension_type, name, extension_key"
        )->fetchAll();
        $activeVersions = self::activeVersions();
        foreach ($rows as &$row) {
            $row['manifest'] = [];
            $row['compatibility_errors'] = [];
            if (($row['status'] ?? '') === 'removed') continue;
            try {
                $manifest = self::manifestForRow($row);
        if (($row['source']??'')==='runtime') {
            $q=Database::connection()->prepare('SELECT signature_status,license_status FROM marketplace_provenance WHERE extension_key=:key AND version=:version ORDER BY id DESC LIMIT 1');$q->execute(['key'=>$key,'version'=>$manifest->version()]);$trust=$q->fetch();
            if(!$trust||!in_array((string)$trust['signature_status'],['trusted'],true)||!in_array((string)$trust['license_status'],['active','not_required'],true))throw new RuntimeException('بستهٔ runtime بدون اعتماد/لایسنس معتبر قابل فعال‌سازی نیست.');
        }
                $row['manifest'] = $manifest->data();
                $row['compatibility_errors'] = $manifest->compatibilityErrors($activeVersions);
            } catch (Throwable $error) {
                $row['compatibility_errors'] = [$error->getMessage()];
            }
        }
        unset($row);
        return $rows;
    }

    public static function activeModules(): array
    {
        self::synchronize();
        return Database::connection()->query(
            "SELECT * FROM cms_extensions WHERE extension_type='module' AND status='active' ORDER BY extension_key"
        )->fetchAll();
    }

    public static function compatibilityErrors(ExtensionManifest $manifest): array
    {
        self::synchronize();
        return $manifest->compatibilityErrors(self::activeVersions());
    }

    public static function adminNavigation(): array
    {
        $items = [];
        foreach (self::activeModules() as $row) {
            try {
                $manifest = self::manifestForRow($row);
                if ($manifest->compatibilityErrors(self::activeVersions()) !== []) continue;
                foreach ($manifest->data()['admin_navigation'] as $item) {
                    $items[] = $item + ['module_key' => $manifest->key()];
                }
            } catch (Throwable $error) {
                self::markBroken((string)$row['extension_key'], $error->getMessage());
            }
        }
        usort($items, static fn(array $left,array $right):int => [$left['order'],$left['label']] <=> [$right['order'],$right['label']]);
        return $items;
    }

    public static function isActive(string $type, string $key): bool
    {
        try {
            self::synchronize();
            $statement = Database::connection()->prepare(
                "SELECT 1 FROM cms_extensions WHERE extension_type=:type AND extension_key=:key AND status='active'"
            );
            $statement->execute(['type' => $type, 'key' => $key]);
            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function activeTheme(): ?array
    {
        self::synchronize();
        $statement = Database::connection()->query(
            "SELECT * FROM cms_extensions WHERE extension_type='theme' AND status='active' ORDER BY updated_at DESC LIMIT 1"
        );
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public static function activeExtension(string $type, string $key): ?array
    {
        if (!in_array($type, ExtensionManifest::TYPES, true)
            || preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $key) !== 1) return null;
        self::synchronize();
        $statement=Database::connection()->prepare("SELECT * FROM cms_extensions WHERE extension_type=:type AND extension_key=:key AND status='active'");
        $statement->execute(['type'=>$type,'key'=>$key]);
        $row=$statement->fetch();
        return is_array($row)?$row:null;
    }

    public static function activate(string $key): void
    {
        self::withOperationLock(static function() use ($key): void {
        self::synchronize(true);
        $row = self::row($key);
        if (($row['status'] ?? '') === 'removed') {
            throw new RuntimeException('افزونهٔ بایگانی‌شده قابل فعال‌سازی نیست.');
        }
        if (($row['source']??'')==='runtime') { try { $q=Database::connection()->prepare("SELECT 1 FROM cms_package_quarantine WHERE extension_key=:key AND status='active' LIMIT 1"); $q->execute(['key'=>$key]); if($q->fetchColumn()) throw new RuntimeException('این بسته در قرنطینه امنیتی است.'); } catch (\PDOException $e) { if(!str_contains($e->getMessage(),'cms_package_quarantine')) throw $e; } }
        $manifest = self::manifestForRow($row);
        $errors = $manifest->compatibilityErrors(self::activeVersions());
        if ($errors !== []) {
            throw new RuntimeException(implode('؛ ', $errors));
        }
        if ($manifest->type() === 'module') {
            ExtensionRuntime::validateModule($manifest);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($manifest->type() === 'theme') {
                $pdo->exec("UPDATE cms_extensions SET status='inactive',updated_at=CURRENT_TIMESTAMP WHERE extension_type='theme' AND status='active'");
                $setting = $pdo->prepare(
                    "INSERT INTO cms_settings(setting_key,setting_value) VALUES('active_theme',:key) " .
                    "ON CONFLICT(setting_key) DO UPDATE SET setting_value=:key2,updated_at=CURRENT_TIMESTAMP"
                );
                $setting->execute(['key' => $key, 'key2' => $key]);
            }
            $statement = $pdo->prepare(
                "UPDATE cms_extensions SET status='active',last_error=NULL,activated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE extension_key=:key"
            );
            $statement->execute(['key' => $key]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        ExtensionRuntime::reset();
        });
    }

    public static function deactivate(string $key): void
    {
        self::withOperationLock(static function() use ($key): void {
        self::synchronize(true);
        $row = self::row($key);
        if (($row['extension_type'] ?? '') === 'theme') {
            throw new RuntimeException('قالب فعال را با فعال‌کردن قالب دیگری جایگزین کنید.');
        }
        $statement = Database::connection()->prepare(
            "UPDATE cms_extensions SET status='inactive',updated_at=CURRENT_TIMESTAMP WHERE extension_key=:key AND status='active'"
        );
        $statement->execute(['key' => $key]);
        ExtensionRuntime::reset();
        });
    }

    public static function archive(string $key): void
    {
        self::withOperationLock(static function() use ($key): void {
        self::synchronize(true);
        $row = self::row($key);
        if (($row['source'] ?? '') !== 'runtime') {
            throw new RuntimeException('افزونهٔ همراه هسته قابل حذف نیست.');
        }
        if (($row['status'] ?? '') === 'active') {
            throw new RuntimeException('ابتدا افزونه را غیرفعال کنید.');
        }
        $path = self::validatedPackagePath($row);
        $trashRoot = RuntimePaths::extensions() . DIRECTORY_SEPARATOR . '.trash';
        self::ensureDirectory($trashRoot);
        $target = $trashRoot . DIRECTORY_SEPARATOR . $row['extension_type'] . '-' . $key . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        if (!rename($path, $target)) {
            throw new RuntimeException('انتقال افزونه به بایگانی انجام نشد.');
        }
        try {
            $statement = Database::connection()->prepare(
                "UPDATE cms_extensions SET status='removed',package_path=:path,updated_at=CURRENT_TIMESTAMP WHERE extension_key=:key"
            );
            $statement->execute(['path' => $target, 'key' => $key]);
        } catch (Throwable $error) {
            if (!rename($target, $path)) {
                error_log('[VazinCMS extension archive rollback] ' . $key);
            }
            throw $error;
        }
        ExtensionRuntime::reset();
        });
    }

    public static function installUploadedFile(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('بارگذاری بستهٔ افزونه کامل نشد.');
        }
        $path = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        if (!is_uploaded_file($path) || !str_ends_with(strtolower($name), '.zip')) {
            throw new RuntimeException('فقط بستهٔ ZIP بارگذاری‌شده پذیرفته می‌شود.');
        }
        return self::installArchive($path);
    }

    public static function installArchive(string $archive): array
    {
        return self::withOperationLock(static function() use ($archive): array {
        self::synchronize(true);
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('افزونهٔ PHP Zip روی سرور فعال نیست.');
        }
        $archive = realpath($archive) ?: '';
        $size = $archive !== '' && is_file($archive) && !is_link($archive) ? filesize($archive) : false;
        if ($size === false || $size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('حجم یا مسیر بستهٔ ZIP معتبر نیست.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('بستهٔ ZIP قابل خواندن نیست.');
        }
        $stage = RuntimePaths::extensions() . DIRECTORY_SEPARATOR . '.staging' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12));
        $package = $stage . DIRECTORY_SEPARATOR . 'package';
        self::ensureDirectory($package);
        try {
            $prefix = self::archivePrefix($zip);
            $seen = [];
            $expanded = 0;
            $files = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) throw new RuntimeException('ورودی ZIP معتبر نیست.');
                $name = (string) ($stat['name'] ?? '');
                if ($name === '' || str_contains($name, '\\')) throw new RuntimeException('نام فایل ZIP معتبر نیست.');
                if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                    throw new RuntimeException('بسته باید فقط یک پوشهٔ ریشه داشته باشد.');
                }
                $relative = $prefix === '' ? $name : substr($name, strlen($prefix));
                if ($relative === '') continue;
                $directoryEntry = str_ends_with($relative, '/');
                $relative = rtrim($relative, '/');
                self::validateArchivePath($relative);
                $folded = strtolower($relative);
                if (isset($seen[$folded])) throw new RuntimeException('مسیر تکراری در بسته وجود دارد.');
                $seen[$folded] = true;
                self::rejectZipLink($zip, $index);
                $target = $package . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if ($directoryEntry) {
                    self::ensureDirectory($target);
                    continue;
                }
                $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
                if (!in_array($extension, self::ALLOWED_FILES, true)) {
                    throw new RuntimeException('نوع فایل غیرمجاز در بسته: ' . $relative);
                }
                $entrySize = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                $expanded += $entrySize;
                $files++;
                if ($files > self::MAX_FILES || $expanded > self::MAX_EXPANDED_BYTES
                    || ($entrySize > 1_000_000 && $compressed > 0 && $entrySize / $compressed > 200)) {
                    throw new RuntimeException('بسته از محدودیت ایمنی استخراج عبور کرده است.');
                }
                self::ensureDirectory(dirname($target));
                $input = $zip->getStream($name);
                $output = @fopen($target, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new RuntimeException('استخراج امن بسته انجام نشد.');
                }
                $copied = stream_copy_to_stream($input, $output, self::MAX_EXPANDED_BYTES + 1);
                fclose($input); fclose($output);
                if ($copied !== $entrySize) throw new RuntimeException('اندازهٔ فایل استخراج‌شده ناسازگار است.');
                chmod($target, 0640);
            }

            $manifest = ExtensionManifest::fromDirectory($package);
            $errors = $manifest->compatibilityErrors(self::activeVersions());
            if ($errors !== []) throw new RuntimeException(implode('؛ ', $errors));
            if ($manifest->type() === 'module') ExtensionRuntime::validateModule($manifest);
            $trust = MarketplacePackage::verifyDirectory(Database::connection(),$package,$manifest,(string)(getenv('APP_HOST')?:($_SERVER['HTTP_HOST']??'')));


            $existing = Database::connection()->prepare('SELECT * FROM cms_extensions WHERE extension_key=:key');
            $existing->execute(['key' => $manifest->key()]);
            $existingRow = $existing->fetch();
            if (is_array($existingRow)) {
                if (($existingRow['source'] ?? '') === 'bundled') throw new RuntimeException('بستهٔ همراه هسته با بارگذاری دستی جایگزین نمی‌شود.');
                if (($existingRow['status'] ?? '') === 'active') throw new RuntimeException('برای ارتقا ابتدا افزونه را غیرفعال کنید یا قالب دیگری را فعال کنید.');
                if (($existingRow['extension_type'] ?? '') !== $manifest->type()) throw new RuntimeException('نوع نسخهٔ جدید با افزونهٔ نصب‌شده یکسان نیست.');
                if (!version_compare($manifest->version(), (string)$existingRow['version'], '>')) throw new RuntimeException('نسخهٔ جدید باید از نسخهٔ نصب‌شده بالاتر باشد.');
            }

            $typeRoot = RuntimePaths::extensions() . DIRECTORY_SEPARATOR . ($manifest->type() === 'theme' ? 'themes' : 'modules');
            self::ensureDirectory($typeRoot);
            $destination = $typeRoot . DIRECTORY_SEPARATOR . $manifest->key();
            $previous = null;
            if (is_array($existingRow) && ($existingRow['source'] ?? '') === 'runtime' && ($existingRow['status'] ?? '') !== 'removed') {
                $oldPath = self::validatedPackagePath($existingRow);
                if ($oldPath !== $destination) throw new RuntimeException('مسیر نسخهٔ نصب‌شده برای ارتقای اتمیک معتبر نیست.');
                $trashRoot = RuntimePaths::extensions() . DIRECTORY_SEPARATOR . '.trash';
                self::ensureDirectory($trashRoot);
                $previous = $trashRoot . DIRECTORY_SEPARATOR . $manifest->type() . '-' . $manifest->key() . '-' . $existingRow['version'] . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
                if (!rename($oldPath, $previous)) throw new RuntimeException('نسخهٔ قبلی برای rollback بایگانی نشد.');
            } elseif (file_exists($destination) || is_link($destination)) {
                throw new RuntimeException('پوشهٔ افزونه از قبل وجود دارد.');
            }
            if (!rename($package, $destination)) {
                if ($previous !== null) rename($previous, $destination);
                throw new RuntimeException('ثبت اتمیک بسته انجام نشد.');
            }

            try {
                $installed = ExtensionManifest::fromDirectory($destination);
                if (is_array($existingRow)) self::updateInstalled($installed);
                else self::insert($installed, 'runtime', 'inactive');
            } catch (Throwable $error) {
                self::removeTree($destination);
                if ($previous !== null && !rename($previous, $destination)) error_log('[VazinCMS extension upgrade rollback] '.$manifest->key());
                throw $error;
            }
            self::$synchronized = false;
            self::$forceSynchronized = false;
            self::$verifiedChecksums = [];
            self::synchronize(true);
            $installedRow=self::row($manifest->key());
            try{Database::connection()->prepare("INSERT INTO marketplace_provenance(extension_key,version,publisher_key,checksum,signature_status,license_status,action,metadata_json) VALUES(:key,:version,:publisher,:checksum,:signature,:license,'install',:meta)")->execute(['key'=>$manifest->key(),'version'=>$manifest->version(),'publisher'=>$trust['publisher_key'],'checksum'=>(string)($installedRow['checksum']??''),'signature'=>$trust['signature_status'],'license'=>$trust['license_status'],'meta'=>json_encode(['source'=>'runtime'],JSON_UNESCAPED_SLASHES)]);}catch(\Throwable $e){if(!str_contains($e->getMessage(),'marketplace_provenance'))throw $e;}
            return $installedRow;
        } finally {
            $zip->close();
            self::removeTree($stage);
        }
        });
    }

    public static function manifestForRow(array $row): ExtensionManifest
    {
        $path = self::validatedPackagePath($row);
        $expected = strtolower((string) ($row['checksum'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            throw new RuntimeException('اثر انگشت ثبت‌شدهٔ افزونه معتبر نیست.');
        }
        $cacheKey = $path . '|' . $expected;
        if (!isset(self::$verifiedChecksums[$cacheKey])) {
            $actual = self::checksum($path);
            if (!hash_equals($expected, $actual)) {
                throw new RuntimeException('یکپارچگی فایل‌های افزونه تغییر کرده است؛ بسته را دوباره نصب کنید.');
            }
            self::$verifiedChecksums[$cacheKey] = true;
        }
        return ExtensionManifest::fromDirectory($path);
    }

    public static function markBroken(string $key, string $message): void
    {
        try {
            $statement = Database::connection()->prepare(
                "UPDATE cms_extensions SET status='broken',last_error=:error,updated_at=CURRENT_TIMESTAMP WHERE extension_key=:key"
            );
            $statement->execute(['error' => mb_substr($message, 0, 1000), 'key' => $key]);
        } catch (Throwable) {
        }
    }

    private static function synchronizeRoot(string $root, string $source): void
    {
        foreach (['themes' => 'theme', 'modules' => 'module'] as $folder => $type) {
            $path = $root . DIRECTORY_SEPARATOR . $folder;
            if (!is_dir($path)) continue;
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                if (!$entry->isDir() || $entry->isLink()) continue;
                try {
                    $manifest = ExtensionManifest::fromDirectory($entry->getPathname());
                    if ($manifest->type() !== $type) throw new RuntimeException('نوع پوشه و manifest یکسان نیست.');
                    self::upsertDiscovered($manifest, $source);
                } catch (Throwable $error) {
                    error_log('[VazinCMS extension discovery] ' . $entry->getPathname() . ': ' . $error->getMessage());
                }
            }
        }
    }

    private static function upsertDiscovered(ExtensionManifest $manifest, string $source): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM cms_extensions WHERE extension_key=:key');
        $statement->execute(['key' => $manifest->key()]);
        $existing = $statement->fetch();
        if (!$existing) {
            $status = $source === 'bundled' ? $manifest->data()['default_status'] : 'inactive';
            self::insert($manifest, $source, $status);
            return;
        }
        if ($source === 'runtime' && ($existing['source'] ?? '') === 'bundled') return;
        $status = in_array((string) ($existing['status'] ?? ''), self::STATUSES, true)
            ? (string) $existing['status'] : 'inactive';
        if ($status === 'removed' && $source === 'runtime') return;
        $record = self::record($manifest, $source, $status);
        if ($source === 'runtime' && ($existing['source'] ?? '') === 'runtime') {
            $unchanged = hash_equals((string) ($existing['checksum'] ?? ''), $record['checksum'])
                && hash_equals((string) ($existing['manifest_json'] ?? ''), $record['manifest'])
                && (string) ($existing['package_path'] ?? '') === $record['path'];
            if (!$unchanged) {
                $broken = $pdo->prepare("UPDATE cms_extensions SET status='broken',last_error=:error,updated_at=CURRENT_TIMESTAMP WHERE extension_key=:key");
                $broken->execute(['error' => 'فایل‌های بسته خارج از چرخهٔ نصب تغییر کرده‌اند.', 'key' => $manifest->key()]);
            }
            return;
        }
        if (($existing['source'] ?? '') === 'legacy' && $source === 'runtime') $status = 'inactive';
        if ($status === 'broken') $status = 'inactive';
        $record['status'] = $status;
        $unchanged = (string)($existing['extension_type']??'')===$record['type']
            && (string)($existing['name']??'')===$record['name']
            && (string)($existing['version']??'')===$record['version']
            && (string)($existing['description']??'')===$record['description']
            && (string)($existing['source']??'')===$record['source']
            && (string)($existing['status']??'')===$record['status']
            && (string)($existing['package_path']??'')===$record['path']
            && (string)($existing['checksum']??'')===$record['checksum']
            && (string)($existing['manifest_json']??'')===$record['manifest'];
        if($unchanged)return;
        $update = $pdo->prepare(
            'UPDATE cms_extensions SET extension_type=:type,name=:name,version=:version,description=:description,' .
            'source=:source,status=:status,package_path=:path,checksum=:checksum,manifest_json=:manifest,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE extension_key=:key'
        );
        $update->execute($record);
    }

    private static function insert(ExtensionManifest $manifest, string $source, string $status): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO cms_extensions(extension_type,extension_key,name,version,description,status,source,package_path,checksum,manifest_json,activated_at) ' .
            "VALUES(:type,:key,:name,:version,:description,:status,:source,:path,:checksum,:manifest,CASE WHEN :active='active' THEN CURRENT_TIMESTAMP ELSE NULL END)"
        );
        $record = self::record($manifest, $source, $status);
        $record['active'] = $status;
        $statement->execute($record);
    }

    private static function updateInstalled(ExtensionManifest $manifest): void
    {
        $record=self::record($manifest,'runtime','inactive');
        $statement=Database::connection()->prepare(
            "UPDATE cms_extensions SET extension_type=:type,name=:name,version=:version,description=:description,status=:status,source=:source,package_path=:path,checksum=:checksum,manifest_json=:manifest,last_error=NULL,installed_at=CURRENT_TIMESTAMP,activated_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE extension_key=:key"
        );
        $statement->execute($record);
    }

    private static function record(ExtensionManifest $manifest, string $source, string $status): array
    {
        return [
            'type' => $manifest->type(), 'key' => $manifest->key(), 'name' => $manifest->name(),
            'version' => $manifest->version(), 'description' => $manifest->data()['description'],
            'source' => $source, 'status' => $status, 'path' => $manifest->directory(),
            'checksum' => self::checksum($manifest->directory()), 'manifest' => $manifest->json(),
        ];
    }

    private static function row(string $key): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $key) !== 1) {
            throw new RuntimeException('شناسهٔ افزونه معتبر نیست.');
        }
        $statement = Database::connection()->prepare('SELECT * FROM cms_extensions WHERE extension_key=:key');
        $statement->execute(['key' => $key]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new RuntimeException('افزونه پیدا نشد.');
        return $row;
    }

    private static function activeVersions(): array
    {
        $versions = [];
        foreach (Database::connection()->query("SELECT extension_key,version FROM cms_extensions WHERE status='active'")->fetchAll() as $row) {
            $versions[$row['extension_key']] = $row['version'];
        }
        return $versions;
    }

    private static function bundledRoot(): string
    {
        $root = realpath(dirname(__DIR__) . '/extensions');
        if ($root === false || !is_dir($root) || is_link($root)) {
            throw new RuntimeException('پوشهٔ افزونه‌های همراه هسته پیدا نشد.');
        }
        return $root;
    }

    private static function validatedPackagePath(array $row): string
    {
        $path = realpath((string) ($row['package_path'] ?? ''));
        $source = (string) ($row['source'] ?? '');
        $root = $source === 'bundled' ? self::bundledRoot() : RuntimePaths::extensions();
        if ($path === false || !is_dir($path) || is_link($path)
            || ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('مسیر ثبت‌شدهٔ افزونه معتبر نیست.');
        }
        return $path;
    }

    private static function ensureRuntimeLayout(): void
    {
        $root = RuntimePaths::extensions();
        foreach (['themes', 'modules', '.staging', '.trash'] as $folder) {
            self::ensureDirectory($root . DIRECTORY_SEPARATOR . $folder);
        }
    }

    private static function normalizeActiveTheme(): void
    {
        $pdo = Database::connection();
        $configured = (string) ($pdo->query("SELECT setting_value FROM cms_settings WHERE setting_key='active_theme'")->fetchColumn() ?: '');
        $rows = $pdo->query("SELECT * FROM cms_extensions WHERE extension_type='theme' AND status<>'removed' ORDER BY extension_key")->fetchAll();
        $valid = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'broken') continue;
            try {
                $manifest = self::manifestForRow($row);
                $valid[$manifest->key()] = $row;
            } catch (Throwable $error) {
                if (($row['status'] ?? '') === 'active') self::markBroken((string) $row['extension_key'], $error->getMessage());
            }
        }
        $selected = isset($valid[$configured]) ? $configured : (isset($valid['vazin-default']) ? 'vazin-default' : array_key_first($valid));
        if (!is_string($selected) || $selected === '') return;
        $needsUpdate = $configured !== $selected;
        foreach ($rows as $row) {
            if ((string)$row['extension_key'] === $selected && (string)$row['status'] !== 'active') $needsUpdate = true;
            if ((string)$row['extension_key'] !== $selected && (string)$row['status'] === 'active') $needsUpdate = true;
        }
        if (!$needsUpdate) return;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE cms_extensions SET status=CASE WHEN extension_key=:key THEN 'active' ELSE CASE WHEN status='active' THEN 'inactive' ELSE status END END,updated_at=CURRENT_TIMESTAMP WHERE extension_type='theme' AND status<>'removed'")->execute(['key' => $selected]);
            $setting = $pdo->prepare("INSERT INTO cms_settings(setting_key,setting_value) VALUES('active_theme',:key) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:key2,updated_at=CURRENT_TIMESTAMP");
            $setting->execute(['key' => $selected, 'key2' => $selected]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('ساخت پوشهٔ افزونه انجام نشد.');
        }
        if (is_link($path)) throw new RuntimeException('پیوند نمادین برای افزونه مجاز نیست.');
    }

    private static function checksum(string $directory): string
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new RuntimeException('پیوند نمادین در افزونه مجاز نیست.');
            if ($entry->isFile()) {
                $relative = substr($entry->getPathname(), strlen($directory) + 1);
                $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        $payload = '';
        foreach ($files as $path => $hash) $payload .= $hash . '  ' . $path . "\n";
        return hash('sha256', $payload);
    }

    private static function archivePrefix(ZipArchive $zip): string
    {
        $candidates = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = rtrim((string) $zip->getNameIndex($index), '/');
            if ($name === ExtensionManifest::FILE) $candidates[] = '';
            elseif (preg_match('#^([^/]+)/' . preg_quote(ExtensionManifest::FILE, '#') . '$#', $name, $match) === 1) {
                $candidates[] = $match[1] . '/';
            }
        }
        $candidates = array_values(array_unique($candidates));
        if (count($candidates) !== 1) {
            throw new RuntimeException('بسته باید دقیقاً یک manifest در ریشه داشته باشد.');
        }
        return $candidates[0];
    }

    private static function validateArchivePath(string $path): void
    {
        if ($path === '' || strlen($path) > 500 || str_contains($path, '\\')
            || preg_match('#(^/|(^|/)\.\.?(/|$)|[\x00-\x1f\x7f:]|//)#', $path) === 1) {
            throw new RuntimeException('مسیر ناامن در بستهٔ ZIP شناسایی شد.');
        }
    }

    private static function rejectZipLink(ZipArchive $zip, int $index): void
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) return;
        $operations = 0; $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $operations, $attributes)) {
            $mode = ($attributes >> 16) & 0170000;
            if ($mode === 0120000) throw new RuntimeException('پیوند نمادین در ZIP مجاز نیست.');
        }
    }

    private static function removeTree(string $path): void
    {
        $root = realpath(RuntimePaths::extensions());
        $resolved = realpath($path);
        if ($root === false || $resolved === false || $resolved === $root
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($resolved);
    }

    private static function withOperationLock(callable $operation): mixed
    {
        $path = RuntimePaths::extensions() . DIRECTORY_SEPARATOR . '.operations.lock';
        $handle = fopen($path, 'c');
        if (!is_resource($handle)) throw new RuntimeException('قفل عملیات افزونه در دسترس نیست.');
        chmod($path, 0640);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('عملیات دیگری روی افزونه‌ها در حال اجراست.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
