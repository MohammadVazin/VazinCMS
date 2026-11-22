<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use PDO;
use RuntimeException;
use Throwable;
use VazinCMS\{Audit, BlueprintManager, Database, ExtensionManager, RuntimePaths, Security, Version, View};

final class InstallController
{
    public function handle(): void
    {
        $root = dirname(__DIR__, 2);
        $storage = RuntimePaths::storage();
        $lock = $storage . '/installed.lock';
        if (is_file($lock)) { header('Location: /login'); return; }
        $errors = [];
        $old = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            $siteName = trim((string) ($_POST['site_name'] ?? 'VazinCMS'));
            $blueprint = strtolower(trim((string) ($_POST['blueprint'] ?? 'corporate')));
            $defaultLocale = strtolower(trim((string) ($_POST['default_locale'] ?? 'fa')));
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $old = [
                'site_name' => $siteName,
                'blueprint' => $blueprint,
                'default_locale' => $defaultLocale,
                'name' => $name,
                'email' => $email,
            ];
            if (mb_strlen($siteName) < 2) $errors[] = 'عنوان وب‌سایت حداقل دو نویسه باشد.';
            if (!in_array($blueprint, BlueprintManager::validProfiles(), true)) $errors[] = 'کیت سایت انتخاب‌شده معتبر نیست.';
            if (!in_array($defaultLocale, ['fa', 'en', 'ar', 'ru'], true)) $defaultLocale = 'fa';
            if (mb_strlen($name) < 2) $errors[] = 'نام مالک حداقل دو نویسه باشد.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'ایمیل معتبر نیست.';
            if (strlen($password) < 12) $errors[] = 'رمز عبور حداقل ۱۲ کاراکتر باشد.';
            if ($errors === []) {
                $this->install($root, $lock, $name, $email, $password, $siteName, $blueprint, $defaultLocale);
            }
            http_response_code(422);
        }
        View::render('install', compact('errors', 'old'));
    }

    private function install(
        string $root,
        string $lock,
        string $name,
        string $email,
        string $password,
        string $siteName,
        string $blueprint,
        string $defaultLocale
    ): never {
        $mutex = fopen(dirname($lock) . '/install.lock', 'c');
        if ($mutex === false || !flock($mutex, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('نصب دیگری در حال اجراست.');
        }
        $pdo = null;
        $pending = dirname($lock) . '/.installed-' . bin2hex(random_bytes(8));
        try {
            $pdo = Database::connection();
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!in_array($driver, ['sqlite', 'pgsql'], true)) {
                throw new RuntimeException('درایور دیتابیس پشتیبانی نمی‌شود.');
            }
            $suffix = $driver === 'sqlite' ? 'sqlite' : 'pgsql';
            $schemaPath = $root . '/database/' . ($driver === 'sqlite' ? 'schema-sqlite.sql' : 'schema.sql');
            $schema = file_get_contents($schemaPath);
            if (!is_string($schema) || $schema === '') throw new RuntimeException('Schema unavailable');

            $pdo->beginTransaction();
            $pdo->exec($schema);
            $owner = $pdo->prepare(
                "INSERT INTO users(name,email,password_hash,role,status) VALUES(:name,:email,:hash,'owner','active')"
            );
            $owner->execute(['name' => $name, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $ownerId = (int) $pdo->lastInsertId();
            $pdo->exec($driver === 'sqlite'
                ? "INSERT OR IGNORE INTO schema_migrations(version) VALUES('0.1.0')"
                : "INSERT INTO schema_migrations(version) VALUES('0.1.0') ON CONFLICT(version) DO NOTHING");

            $files = glob($root . '/database/migrations/*-' . $suffix . '.sql') ?: [];
            usort($files, static fn(string $left, string $right): int => version_compare(
                explode('-', basename($left))[0], explode('-', basename($right))[0]
            ));

            $preTenantSynced = false;
            foreach ($files as $file) {
                $version = explode('-', basename($file))[0];
                if (!$preTenantSynced && version_compare($version, '10.31.0', '>=')) {
                    ExtensionManager::reset();
                    ExtensionManager::synchronize(true);
                    $preTenantSynced = true;
                }
                $done = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');
                $done->execute(['version' => $version]);
                if ($done->fetchColumn()) continue;
                $sql = file_get_contents($file);
                if (!is_string($sql) || $sql === '') throw new RuntimeException('Migration unavailable: ' . $version);
                $pdo->exec($sql);
                $mark = $pdo->prepare($driver === 'sqlite'
                    ? 'INSERT OR IGNORE INTO schema_migrations(version) VALUES(:version)'
                    : 'INSERT INTO schema_migrations(version) VALUES(:version) ON CONFLICT(version) DO NOTHING');
                $mark->execute(['version' => $version]);
            }
            if (!$preTenantSynced) {
                ExtensionManager::reset();
                ExtensionManager::synchronize(true);
            }

            // Provision owner membership in default site (id=1) for site-scoped admin guard
            try {
                $memSql = $driver === 'sqlite'
                    ? "INSERT OR IGNORE INTO cms_site_memberships(site_id, user_id, site_role) VALUES(1, :uid, 'owner')"
                    : "INSERT INTO cms_site_memberships(site_id, user_id, site_role) VALUES(1, :uid, 'owner') ON CONFLICT(site_id, user_id) DO NOTHING";
                $pdo->prepare($memSql)->execute(['uid' => $ownerId]);
            } catch (Throwable) {
            }

            $product = $pdo->prepare(
                "INSERT INTO products(slug,name,type,status,current_version) VALUES('vazincms','VazinCMS','cms','active',:version) " .
                "ON CONFLICT(slug) DO UPDATE SET current_version=:version2,status='active'"
            );
            $product->execute(['version' => Version::current(), 'version2' => Version::current()]);
            Audit::log('system.install', 'VazinCMS ' . Version::current() . ' installed (' . $blueprint . ')', $ownerId);
            if (file_put_contents($pending, date(DATE_ATOM) . PHP_EOL, LOCK_EX) === false || !rename($pending, $lock)) {
                throw new RuntimeException('Installation lock failed');
            }
            $pdo->commit();

            // Apply chosen Site Blueprint & initial site settings
            try {
                $setSql = $driver === 'sqlite'
                    ? 'INSERT INTO cms_settings(site_id, setting_key, setting_value) VALUES(1, :k, :v) ON CONFLICT(site_id, setting_key) DO UPDATE SET setting_value=:v2'
                    : 'INSERT INTO cms_settings(site_id, setting_key, setting_value) VALUES(1, :k, :v) ON CONFLICT(site_id, setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value';
                foreach (['site_name' => $siteName, 'default_locale' => $defaultLocale] as $sk => $sv) {
                    $args = ['k' => $sk, 'v' => $sv];
                    if ($driver === 'sqlite') $args['v2'] = $sv;
                    $pdo->prepare($setSql)->execute($args);
                }
                BlueprintManager::apply($pdo, $blueprint, $defaultLocale, $ownerId);
            } catch (Throwable) {
            }

            header('Location: /login');
            exit;
        } catch (Throwable $error) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            @unlink($pending);
            @unlink($lock);
            throw $error;
        } finally {
            flock($mutex, LOCK_UN);
            fclose($mutex);
        }
    }
}
