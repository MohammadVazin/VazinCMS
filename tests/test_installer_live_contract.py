from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]


class InstallerLiveContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.install = (ROOT / "deploy" / "install.sh").read_text(encoding="utf-8")
        cls.rollback = (ROOT / "deploy" / "rollback.sh").read_text(encoding="utf-8")

    def test_public_health_uses_exact_https_loopback_contract(self) -> None:
        for script in (self.install, self.rollback):
            self.assertIn('--resolve "$SITE_HOST:443:127.0.0.1"', script)
            self.assertIn('"https://$SITE_HOST/health"', script)
            self.assertNotIn("'http://127.0.0.1/health'", script)
            normalized = re.sub(r"\s+", "", script.replace("'", '"'))
            self.assertIn('value.get("service")=="VazinCMS"', normalized)
            self.assertIn('value.get("version")==os.environ["EXPECTED"]', normalized)

    def test_itgala_is_not_a_cms_release_target(self) -> None:
        for script in (self.install, self.rollback):
            self.assertNotIn('/var/www/vazin-sites/itgala-ru', script)
            self.assertNotIn('SITE_KEY=itgala-ru', script)
            self.assertNotIn('SITE_HOST=itgala.ru', script)

    def test_external_runtime_is_required_and_backed_up_outside_code_root(self) -> None:
        require_external = self.install.index(
            'validate_external_environment "$SITE_ROOT/.env"'
        )
        reject_legacy = self.install.index(
            '[[ ! -e "$SITE_ROOT/storage" && ! -L "$SITE_ROOT/storage"'
        )
        backup = self.install.index(
            'tar -czf "$BACKUP_CHILD/runtime.tar.gz" -C "$RUNTIME_DATA" storage uploads'
        )
        begin = self.install.index('python3 "$DURABLE_HELPER" begin')
        self.assertLess(require_external, reject_legacy)
        self.assertLess(reject_legacy, backup)
        self.assertLess(backup, begin)
        self.assertIn(
            '--runtime-active "$RUNTIME_DATA"',
            self.install,
        )
        self.assertNotIn('ensure-service-dir "$SITE_ROOT/public/uploads"', self.install)
        self.assertNotIn('runtime-ready "$STATE_ROOT"', self.install)
        self.assertNotIn('runtime-switch "$STATE_ROOT"', self.install)
        self.assertNotIn('runtime-bind "$STATE_ROOT"', self.install)

    def test_service_test_runs_after_restrictive_source_modes_are_normalized(self) -> None:
        copy = self.install.index(
            'rsync -a --delete --no-owner --no-group --exclude=.env'
        )
        normalize_dirs = self.install.index(
            'find "$FRESH" -xdev -type d -exec chmod 0755 {} +', copy
        )
        normalize_files = self.install.index(
            'find "$FRESH" -xdev -type f -exec chmod 0644 {} +', copy
        )
        service_test = self.install.index(
            'as_www php "$FRESH/tests/identity-link-contract.php"', copy
        )
        extension_test = self.install.index(
            'as_www php "$FRESH/tests/extension-kernel.php"', copy
        )
        seal = self.install.index(
            'python3 "$DURABLE_HELPER" seal-tree "$FRESH"', copy
        )
        self.assertLess(copy, normalize_dirs)
        self.assertLess(copy, normalize_files)
        self.assertLess(normalize_dirs, service_test)
        self.assertLess(normalize_files, service_test)
        self.assertLess(service_test, extension_test)
        self.assertLess(extension_test, seal)
        self.assertLess(service_test, seal)

    def test_migration_files_never_mark_a_different_version(self) -> None:
        pattern = re.compile(
            r"schema_migrations\s*\(\s*version\s*\)\s*VALUES\s*\(\s*'([^']+)'",
            re.IGNORECASE,
        )
        migrations = sorted((ROOT / "database" / "migrations").glob("*.sql"))
        self.assertTrue(migrations)
        for migration in migrations:
            expected = migration.name.split("-", 1)[0]
            markers = pattern.findall(migration.read_text(encoding="utf-8"))
            with self.subTest(migration=migration.name):
                self.assertTrue(
                    all(marker == expected for marker in markers),
                    f"{migration.name} marks {markers} instead of {expected}",
                )


if __name__ == "__main__":
    unittest.main()
