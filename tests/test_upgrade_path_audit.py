"""Isolated, no-network contract tests for the non-authorizing upgrade auditor."""
from __future__ import annotations

import contextlib
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("upgrade_path_audit", ROOT / "scripts/upgrade_path_audit.py")
assert SPEC and SPEC.loader
AUDIT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(AUDIT)


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def manifest(root: Path) -> None:
    items = []
    for file in root.rglob("*"):
        if file.is_file() and file.name != "MANIFEST.sha256":
            rel = file.relative_to(root).as_posix()
            items.append((rel, digest(file)))
    items.sort()
    (root / "MANIFEST.sha256").write_text("".join(f"{h}  {p}\n" for p, h in items), encoding="utf-8")


def fixture(root: Path, version: str, predecessor: str, *, supported: list[str] | None = None,
            minimum: str | None = None, migrations: tuple[str, ...] = ("10.29.0",)) -> Path:
    path = root / version
    path.mkdir()
    (path / "deploy").mkdir()
    (path / "database/migrations").mkdir(parents=True)
    (path / "VERSION").write_text(version + "\n", encoding="utf-8")
    (path / "vazin-package.json").write_text(json.dumps({
        "version": version,
        "product": "VazinCMS",
        "upgrade_only": True,
        "minimum_current_version": minimum or predecessor,
        "supported_current_versions": supported if supported is not None else [predecessor],
    }), encoding="utf-8")
    (path / "deploy/install.sh").write_text(
        f"#!/bin/bash\nOLD_VERSION='{predecessor}'\nNEW_VERSION='{version}'\n", encoding="utf-8")
    for migration in migrations:
        for engine in ("sqlite", "pgsql"):
            (path / "database/migrations" / f"{migration}-{engine}.sql").write_text(
                f"-- common migration {migration}\n", encoding="utf-8")
    manifest(path)
    return path


class UpgradeChainContractTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="vazincms-op02-test-")
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.paths = {
            "10.29.0": fixture(self.folder, "10.29.0", "10.28.0"),
            "10.30.0": fixture(self.folder, "10.30.0", "10.29.0", migrations=("10.29.0", "10.30.0")),
            "10.30.1": fixture(self.folder, "10.30.1", "10.30.0", migrations=("10.29.0", "10.30.0", "10.30.1")),
            "10.30.2": fixture(self.folder, "10.30.2", "10.30.1", migrations=("10.29.0", "10.30.0", "10.30.1")),
        }

    def run_audit(self):
        return AUDIT.audit_chain("10.29.0", "10.30.2", self.paths)

    def test_complete_consistent_chain_passes_contract_but_never_authorizes_release(self):
        report = self.run_audit()
        self.assertEqual(report["chain_contract"], "PASS", report["errors"])
        self.assertEqual(report["release_decision"], "NO_GO")
        self.assertEqual([x["to"] for x in report["steps"]], ["10.30.0", "10.30.1", "10.30.2"])

    def test_installer_predecessor_mismatch_fails_closed(self):
        installer = self.paths["10.30.2"] / "deploy/install.sh"
        installer.write_text(installer.read_text().replace("10.30.1", "10.30.0"))
        manifest(self.paths["10.30.2"])
        report = self.run_audit()
        self.assertIn("10.30.2:installer_requires_different_predecessor", report["errors"])

    def test_package_supported_predecessor_exclusion_fails_closed(self):
        package = self.paths["10.30.2"] / "vazin-package.json"
        obj = json.loads(package.read_text())
        obj["supported_current_versions"] = ["10.30.0"]
        package.write_text(json.dumps(obj))
        manifest(self.paths["10.30.2"])
        report = self.run_audit()
        self.assertIn("10.30.2:predecessor_not_in_package_supported_list", report["errors"])

    def test_lower_minimum_than_installer_predecessor_warns_without_false_block(self):
        package = self.paths["10.30.2"] / "vazin-package.json"
        obj = json.loads(package.read_text())
        obj["minimum_current_version"] = "10.30.0"
        package.write_text(json.dumps(obj))
        manifest(self.paths["10.30.2"])
        report = self.run_audit()
        self.assertEqual(report["chain_contract"], "PASS")
        self.assertIn("10.30.2:minimum_current_differs_from_installer_old_version", report["warnings"])

    def test_package_minimum_excludes_predecessor(self):
        package = self.paths["10.30.2"] / "vazin-package.json"
        obj = json.loads(package.read_text()); obj["minimum_current_version"] = "10.31.0"
        package.write_text(json.dumps(obj)); manifest(self.paths["10.30.2"])
        report = self.run_audit()
        self.assertIn("10.30.2:package_minimum_excludes_predecessor", report["errors"])

    def test_missing_intermediate_stage(self):
        self.paths.pop("10.30.1")
        report = self.run_audit()
        self.assertIn("10.30.2:installer_requires_different_predecessor", report["errors"])

    def test_corrupted_manifest_file(self):
        (self.paths["10.30.0"] / "VERSION").write_text("10.31.1\n")
        report = self.run_audit()
        self.assertIn("10.30.0:version_file_mismatch", report["errors"])
        self.assertTrue(any("manifest_file_hash_mismatch" in s for s in report["errors"]))

    def test_missing_manifest_version_entry(self):
        path = self.paths["10.30.0"] / "MANIFEST.sha256"
        path.write_text("".join(x for x in path.read_text().splitlines(keepends=True) if not x.endswith("  VERSION\n")))
        report = self.run_audit()
        self.assertIn("10.30.0:manifest_missing_required:VERSION", report["errors"])

    def test_symlinked_manifest_file_rejected(self):
        root = self.paths["10.30.0"]
        p = root / "VERSION"
        contents = p.read_text(); p.unlink()
        (root / "real-version.txt").write_text(contents)
        p.symlink_to(root / "real-version.txt")
        manifest(root)
        # Add the symlink path back into the pinned manifest to try bypassing.
        line = f"{digest(root / 'real-version.txt')}  VERSION\n"
        rows = (root / "MANIFEST.sha256").read_text().splitlines(keepends=True)
        rows.append(line); rows.sort(key=lambda s: s[66:])
        (root / "MANIFEST.sha256").write_text("".join(rows))
        report = self.run_audit()
        self.assertTrue(any("manifest_file_invalid:VERSION:symlink_path" in x for x in report["errors"]))

    def test_corrupt_manifest_syntax(self):
        (self.paths["10.30.1"] / "MANIFEST.sha256").write_text("not-a-sha  VERSION\n")
        self.assertIn("10.30.1:manifest_syntax_invalid", self.run_audit()["errors"])

    def test_modified_historical_migration_blocked(self):
        path = self.paths["10.30.1"] / "database/migrations/10.30.0-sqlite.sql"
        path.write_text(path.read_text() + "ALTER TABLE example ADD COLUMN x INTEGER;\n")
        manifest(self.paths["10.30.1"])
        report = self.run_audit()
        self.assertIn("10.30.1:changed_historical_migrations:1", report["errors"])

    def test_dropped_historical_migration_blocked(self):
        (self.paths["10.30.2"] / "database/migrations/10.30.0-sqlite.sql").unlink()
        (self.paths["10.30.2"] / "database/migrations/10.30.0-pgsql.sql").unlink()
        manifest(self.paths["10.30.2"])
        report = self.run_audit()
        self.assertIn("10.30.2:removed_migration_files:2", report["errors"])

    def test_sqlite_pgsql_migration_parity_required(self):
        (self.paths["10.30.2"] / "database/migrations/10.30.1-pgsql.sql").unlink()
        manifest(self.paths["10.30.2"])
        report = self.run_audit()
        self.assertIn("10.30.2:sqlite_pgsql_migration_parity_mismatch", report["errors"])

    def test_target_not_present_fails(self):
        self.paths.pop("10.30.2")
        self.assertIn("incomplete_or_misordered_chain", self.run_audit()["errors"])

    def test_all_manifest_hashes_checked(self):
        version = self.paths["10.30.2"] / "vazin-package.json"
        version.write_text(version.read_text() + " ")
        errors = self.run_audit()["errors"]
        self.assertTrue(any(x.startswith("10.30.2:manifest_file_hash_mismatch:vazin-package.json") for x in errors))

    def test_cli_successful_contract_has_non_authorizing_exit_70(self):
        arguments = ["--start", "10.29.0", "--target", "10.30.2"]
        for version, path in self.paths.items():
            arguments.extend(["--release", version + "=" + str(path)])
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            exit_code = AUDIT.cli(arguments)
        self.assertEqual(exit_code, 70)
        summary = json.loads(out.getvalue())
        self.assertEqual(summary["chain_contract"], "PASS")
        self.assertEqual(summary["release_decision"], "NO_GO")

    def test_cli_missing_absolute_directory_rejected(self):
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            exit_code = AUDIT.cli(["--start", "10.29.0", "--target", "10.30.2", "--release", "10.29.0=relative/path"])
        self.assertEqual(exit_code, 70)
        self.assertIn("release_path_must_be_absolute", json.loads(out.getvalue())["errors"])

    def test_duplicate_release_argument_rejected(self):
        root = self.paths["10.29.0"]
        args = ["--start", "10.29.0", "--target", "10.30.2", "--release", f"10.29.0={root}", "--release", f"10.29.0={root}"]
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            exit_code = AUDIT.cli(args)
        self.assertEqual(exit_code, 70)
        self.assertIn("duplicate_release_argument", json.loads(out.getvalue())["errors"])


if __name__ == "__main__":
    unittest.main()
