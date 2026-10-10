"""Isolated tests for the optional read-only fleet audit candidate."""
import hashlib
import json
import io
from contextlib import redirect_stdout
from unittest.mock import patch
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from fleet_audit_v2 import audit_policy, check_manifest, main, validate_policy, PolicyError  # noqa: E402


class FleetAuditTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / "VERSION").write_text("10.30.2\n", encoding="utf-8")
        (self.root / "index.php").write_text("<?php echo 'ok';\n", encoding="utf-8")
        self.reset_manifest()
        self.site = {
            "id": "cms", "host": "cms.example.org", "root": str(self.root),
            "release_version": "10.30.2", "health_service": "VazinCMS",
            "health_version": "10.30.2",
            "manifest_sha256": hashlib.sha256((self.root / "MANIFEST.sha256").read_bytes()).hexdigest(),
        }
        self.health = lambda s: {"ok": True, "service": "VazinCMS", "version": "10.30.2"}

    def reset_manifest(self):
        lines = []
        for path in sorted([self.root / "VERSION", self.root / "index.php"]):
            lines.append(hashlib.sha256(path.read_bytes()).hexdigest() + "  " + path.name)
        (self.root / "MANIFEST.sha256").write_text("\n".join(lines) + "\n", encoding="utf-8")

    def run_policy(self, *, trusted=True, state="approved"):
        return audit_policy({"schema": 1, "policy_state": state, "sites": [self.site]}, self.health, trusted)

    def test_approved_clean_policy_with_independent_digest(self):
        report, rc = self.run_policy()
        self.assertEqual(rc, 0)
        self.assertEqual(report["decision"], "PASS")
        self.assertEqual(report["sites"][0]["manifest_files_checked"], 2)

    def test_approved_policy_without_independent_digest_stays_no_go(self):
        report, rc = self.run_policy(trusted=False)
        self.assertEqual(rc, 70)
        self.assertIn("independent_policy_digest_missing_or_mismatched", report["reasons"])

    def test_candidate_remains_no_go_even_with_digest(self):
        report, rc = self.run_policy(state="candidate")
        self.assertEqual(rc, 70)
        self.assertEqual(report["sites"][0]["status"], "PASS")

    def test_unpinned_site_stays_no_go_even_if_approved(self):
        self.site.pop("manifest_sha256", None)
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("unanchored_manifest", report["reasons"])

    def test_wrong_version_rejected(self):
        self.site["release_version"] = "10.31.0"
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("release_version_mismatch", report["sites"][0]["errors"])

    def test_different_service_explicitly_supported(self):
        self.site["health_service"] = "Vazin Travel"
        self.site["health_version"] = "1.0.0"
        self.health = lambda s: {"ok": True, "service": "Vazin Travel", "version": "1.0.0"}
        report, rc = self.run_policy()
        self.assertEqual(rc, 0)

    def test_unexpected_service_rejected(self):
        self.health = lambda s: {"ok": True, "service": "Other", "version": "10.30.2"}
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("health_service_mismatch", report["sites"][0]["errors"])

    def test_manifest_tampering_rejected(self):
        (self.root / "index.php").write_text("<?php echo 'modified';\n", encoding="utf-8")
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("manifest_failed:manifest_file_hash_mismatch:index.php", report["sites"][0]["errors"])

    def test_manifest_traversal_rejected(self):
        (self.root / "MANIFEST.sha256").write_text("0" * 64 + "  ../secret\n", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "manifest_path_unsafe"):
            check_manifest(self.root)

    def test_manifest_anchor_mismatch_rejected(self):
        self.site["manifest_sha256"] = "a" * 64
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("manifest_failed:manifest_pin_mismatch", report["sites"][0]["errors"])

    def test_manifest_symlink_rejected(self):
        other = self.root / "manifest-copy"
        other.write_bytes((self.root / "MANIFEST.sha256").read_bytes())
        (self.root / "MANIFEST.sha256").unlink()
        (self.root / "MANIFEST.sha256").symlink_to(other)
        with self.assertRaisesRegex(ValueError, "manifest_symlink_rejected"):
            check_manifest(self.root)

    def test_manifest_file_symlink_rejected(self):
        other = self.root / "another.php"
        other.write_bytes((self.root / "index.php").read_bytes())
        (self.root / "index.php").unlink()
        (self.root / "index.php").symlink_to(other)
        with self.assertRaisesRegex(ValueError, "manifest_entry_symlink"):
            check_manifest(self.root)


    def test_cli_approved_fingerprint_enforced(self):
        payload = json.dumps({"schema": 1, "policy_state": "approved", "sites": [self.site]}, sort_keys=True).encode()
        path = self.root / "policy.json"
        path.write_bytes(payload)
        digest = hashlib.sha256(payload).hexdigest()
        with patch("fleet_audit_v2.load_health", side_effect=lambda site, timeout: self.health(site)):
            out = io.StringIO()
            with redirect_stdout(out):
                rc = main(["--policy", str(path), "--approved-policy-sha256", digest])
            self.assertEqual(rc, 0)
            self.assertEqual(json.loads(out.getvalue())["decision"], "PASS")
            out = io.StringIO()
            with redirect_stdout(out):
                rc = main(["--policy", str(path)])
            self.assertEqual(rc, 70)
            self.assertEqual(json.loads(out.getvalue())["decision"], "NO_GO")
            out = io.StringIO()
            with redirect_stdout(out):
                rc = main(["--policy", str(path), "--approved-policy-sha256", "a" * 64])
            self.assertEqual(rc, 70)
            self.assertEqual(json.loads(out.getvalue())["decision"], "NO_GO")

    def test_cli_candidate_never_authorized_even_with_correct_digest(self):
        payload = json.dumps({"schema": 1, "policy_state": "candidate", "sites": [self.site]}, sort_keys=True).encode()
        path = self.root / "policy.json"
        path.write_bytes(payload)
        digest = hashlib.sha256(payload).hexdigest()
        with patch("fleet_audit_v2.load_health", side_effect=lambda site, timeout: self.health(site)):
            out = io.StringIO()
            with redirect_stdout(out):
                rc = main(["--policy", str(path), "--approved-policy-sha256", digest])
            self.assertEqual(rc, 70)
            self.assertEqual(json.loads(out.getvalue())["decision"], "NO_GO")

    def test_manifest_file_under_symlinked_parent_is_rejected(self):
        internal = self.root / "internal"
        internal.mkdir()
        (internal / "app.php").write_text("<?php return true;\n", encoding="utf-8")
        (self.root / "shortcut").symlink_to(internal, target_is_directory=True)
        row = hashlib.sha256((internal / "app.php").read_bytes()).hexdigest()
        with (self.root / "MANIFEST.sha256").open("a", encoding="utf-8") as stream:
            stream.write(row + "  shortcut/app.php\n")
        with self.assertRaisesRegex(ValueError, "manifest_entry_symlink"):
            check_manifest(self.root)

    def test_untracked_symlink_directory_rejected(self):
        internal = self.root / "internal"
        internal.mkdir()
        (self.root / "shortcut").symlink_to(internal, target_is_directory=True)
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("untracked_executable_files", report["sites"][0]["errors"])

    def test_untracked_php_code_rejects_otherwise_clean_approved_site(self):
        source = self.root / "src"
        source.mkdir()
        (source / "extra.php").write_text("<?php echo 'unexpected';\n", encoding="utf-8")
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("untracked_executable_files", report["sites"][0]["errors"])
        self.assertIn("src/extra.php", report["sites"][0]["untracked_executable_examples"])

    def test_untracked_javascript_is_not_silently_accepted(self):
        source = self.root / "public"
        source.mkdir()
        (source / "unreviewed.js").write_text("alert(1);\n", encoding="utf-8")
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("untracked_executable_files", report["sites"][0]["errors"])

    def test_untracked_mutable_data_not_conflated_with_release_code(self):
        source = self.root / "resources"
        source.mkdir()
        (source / "content.json").write_text("{}\n", encoding="utf-8")
        report, rc = self.run_policy()
        self.assertEqual(rc, 0)
        self.assertEqual(report["sites"][0]["untracked_executable_examples"], [])

    def test_unlisted_version_file_rejected_even_with_manifest_pin(self):
        line = (self.root / "MANIFEST.sha256").read_text().splitlines()
        (self.root / "MANIFEST.sha256").write_text(line[1] + "\n", encoding="utf-8")
        self.site["manifest_sha256"] = hashlib.sha256((self.root / "MANIFEST.sha256").read_bytes()).hexdigest()
        report, rc = self.run_policy()
        self.assertEqual(rc, 70)
        self.assertIn("manifest_failed:manifest_version_uncovered", report["sites"][0]["errors"])

    def test_duplicate_site_id_rejected(self):
        with self.assertRaisesRegex(PolicyError, "Duplicate site id"):
            validate_policy({"schema": 1, "policy_state": "approved", "sites": [self.site, self.site]})


if __name__ == "__main__":
    unittest.main()
