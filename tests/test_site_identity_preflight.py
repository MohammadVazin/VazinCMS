"""Portable, offline tests of the read-only identity design gate."""
from __future__ import annotations

import contextlib
from io import StringIO
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "site_identity_preflight", ROOT / "scripts/site_identity_preflight.py"
)
assert SPEC is not None and SPEC.loader is not None
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class IdentityPreflightTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        base = Path(self.temp.name)
        self.sites = base / "sites"
        self.runtime = base / "runtime"
        self.sites.mkdir()
        self.runtime.mkdir()
        for site in audit.SITE_NAMES:
            code = self.sites / site
            code.mkdir()
            (code / ".env").write_text("DB_PASSWORD=DO_NOT_EVER_PRINT_THIS\n")
            (code / ".env").chmod(0o640)
            data = self.runtime / site / "data"
            storage = data / "storage"
            storage.mkdir(parents=True)
            (data / "uploads").mkdir()
            storage.chmod(0o700)
            (data / "uploads").chmod(0o700)
            (storage / "database.sqlite").write_bytes(b"NOT_A_REAL_DATABASE")
            (storage / "database.sqlite").chmod(0o600)
        self.installer = base / "install.sh"
        self.installer.write_text("""
SERVICE_UID="$(id -u www-data)"
SERVICE_GID="$(id -g www-data)"
runuser -u www-data
chown root:www-data
""")

    def run_audit(self):
        return audit.inspect_fleet(self.sites, self.runtime, self.installer)

    def test_current_shared_runtime_owner_is_blocked(self):
        result = self.run_audit()
        self.assertTrue(result["shared_runtime_uid_detected"])
        self.assertIn("storage_service_uid_shared_or_missing", result["global_blockers"])
        self.assertFalse(result["release_authorized"])

    def test_hardcoded_installer_identity_requires_redesign(self):
        result = self.run_audit()
        self.assertEqual(result["generic_identity_markers"], 4)
        self.assertTrue(result["requires_new_site_identity_design"])
        self.assertIn("installer_hardcodes_shared_www_data_identity", result["global_blockers"])

    def test_never_reads_or_outputs_env_content(self):
        report = json.dumps(self.run_audit())
        self.assertNotIn("DO_NOT_EVER_PRINT_THIS", report)
        self.assertNotIn("DB_PASSWORD=", report)
        self.assertNotIn(str(self.temp.name), report)

    def test_modes_and_ownership_metadata_are_reported(self):
        site = self.run_audit()["sites"][0]
        self.assertEqual(site["observed"]["environment"]["mode"], "0640")
        self.assertEqual(site["observed"]["storage"]["mode"], "0700")
        self.assertEqual(site["observed"]["database"]["mode"], "0600")
        self.assertFalse(site["per_site_identity_acceptance"])

    def test_weak_env_permissions_blocked(self):
        (self.sites / "travel-vazin-online" / ".env").chmod(0o644)
        site = self.run_audit()["sites"][0]
        self.assertIn("environment_access_mode_unreviewed", site["blockers"])

    def test_missing_database_blocked(self):
        (self.runtime / "visa-vazin-online/data/storage/database.sqlite").unlink()
        site = self.run_audit()["sites"][1]
        self.assertIn("missing_or_unsafe_database", site["blockers"])

    def test_environment_symlink_is_not_followed(self):
        env = self.sites / "travel-vazin-online/.env"
        target = self.sites / "visa-vazin-online/.env"
        env.unlink()
        env.symlink_to(target)
        site = self.run_audit()["sites"][0]
        self.assertEqual(site["observed"]["environment"]["kind"], "symlink")
        self.assertIn("missing_or_unsafe_environment", site["blockers"])

    def test_symlinked_runtime_ancestor_is_rejected(self):
        data = self.runtime / "travel-vazin-online" / "data"
        actual = self.runtime / "real-travel-data"
        data.rename(actual)
        data.symlink_to(actual, target_is_directory=True)
        observed = self.run_audit()["sites"][0]
        self.assertEqual(observed["observed"]["storage"]["kind"], "symlinked_ancestor")
        self.assertIn("missing_or_unsafe_storage", observed["blockers"])
        self.assertIn("missing_or_unsafe_database", observed["blockers"])

    def test_relative_scan_path_is_untrusted(self):
        row = audit.file_metadata(Path("relative/.env"))
        self.assertEqual(row["kind"], "unsafe_relative_path")

    def test_database_and_storage_owner_mismatch(self):
        original = audit.file_metadata
        def fake_metadata(path):
            value = original(path)
            if str(path).endswith("travel-vazin-online/data/storage/database.sqlite"):
                value["uid"] = 999
            return value
        with patch.object(audit, "file_metadata", side_effect=fake_metadata):
            site = self.run_audit()["sites"][0]
        self.assertIn("database_storage_owner_mismatch", site["blockers"])

    def test_missing_installer_fails_closed(self):
        self.installer.unlink()
        report = self.run_audit()
        self.assertIn("installer_source_unavailable", report["global_blockers"])
        self.assertEqual(report["decision"], "NO_GO")

    def test_unique_runtime_owner_still_no_go(self):
        original = audit.file_metadata
        ids = dict(zip(audit.SITE_NAMES, (10101, 10102, 10103)))
        def fake_metadata(path):
            v = original(path)
            if path.name == "storage":
                for site, uid in ids.items():
                    if site in str(path):
                        v["uid"] = uid
            return v
        with patch.object(audit, "file_metadata", side_effect=fake_metadata):
            report = self.run_audit()
        self.assertFalse(report["shared_runtime_uid_detected"])
        self.assertEqual(report["decision"], "NO_GO")
        self.assertIn("inflight_requests_and_background_jobs_not_quiesced", report["global_blockers"])

    def test_unallowlisted_site_rejected(self):
        with self.assertRaises(ValueError):
            audit.inspect_site(self.sites / "evil", self.runtime / "evil", "evil")

    def test_cli_never_returns_release_authorization(self):
        output = StringIO()
        with contextlib.redirect_stdout(output):
            result = audit.main([
                "--sites-base", str(self.sites),
                "--runtime-base", str(self.runtime),
                "--installer", str(self.installer)
            ])
        self.assertEqual(result, 70)
        obj = json.loads(output.getvalue())
        self.assertFalse(obj["release_authorized"])
        self.assertEqual(obj["decision"], "NO_GO")

    def test_schema_contains_no_mutating_actions(self):
        src = (ROOT / "scripts/site_identity_preflight.py").read_text()
        for forbidden in ("os.chown(", "os.chmod(", "shutil.rmtree(", "systemctl", "nginx -s reload", "subprocess.run(", "sqlite3.connect("):
            self.assertNotIn(forbidden, src)


if __name__ == "__main__":
    unittest.main()
