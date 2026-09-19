<?php
declare(strict_types=1);

namespace VazinCMS\Controllers;

use PDO;
use RuntimeException;
use Throwable;
use VazinCMS\{Audit,Database,RuntimePaths,Security,Version,View};

final class InstallController
{
    public function handle(): void
    {
        $root = dirname(__DIR__, 2);
        $storage = RuntimePaths::storage();
        $lock = $storage . '/installed.lock';
        if (is_file($lock)) { header('Location: /login'); return; }
        $errors = []; $old = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Security::verifyCsrf();
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $old = ['name' => $name, 'email' => $email];
            if (mb_strlen($name) < 2) $errors[] = 'نام مالک حداقل دو نویسه باشد.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'ایمیل معتبر نیست.';
            if (strlen($password) < 12) $errors[] = 'رمز عبور حداقل ۱۲ کاراکتر باشد.';
            if ($errors === []) $this->install($root, $lock, $name, $email, $password);
            http_response_code(422);
        }
        View::render('install', compact('errors', 'old'));
    }

    private function install(string $root, string $lock, string $name, string $email, string $password): never
    {
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
            foreach ($files as $file) {
                $version = explode('-', basename($file))[0];
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

            $product = $pdo->prepare(
                "INSERT INTO products(slug,name,type,status,current_version) VALUES('vazincms','VazinCMS','cms','active',:version) " .
                "ON CONFLICT(slug) DO UPDATE SET current_version=:version2,status='active'"
            );
            $product->execute(['version' => Version::current(), 'version2' => Version::current()]);
            Audit::log('system.install', 'VazinCMS ' . Version::current() . ' installed', $ownerId);
            if (file_put_contents($pending, date(DATE_ATOM) . PHP_EOL, LOCK_EX) === false || !rename($pending, $lock)) {
                throw new RuntimeException('Installation lock failed');
            }
            $pdo->commit();
            header('Location: /login');
            exit;
        } catch (Throwable $error) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            @unlink($pending); @unlink($lock);
            throw $error;
        } finally {
            flock($mutex, LOCK_UN);
            fclose($mutex);
        }
    }
}
