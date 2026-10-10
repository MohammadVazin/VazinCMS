"""Portable, offline tests for the synthetic-only detached runtime rehearsal."""
from __future__ import annotations

import contextlib
from io import StringIO
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "isolated_runtime_identity_generation",
    ROOT / "scripts/isolated_runtime_identity_generation.py",
)
assert SPEC and SPEC.loader
tool = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(tool)


class SyntheticIdentityRehearsalTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)

    def test_production_paths_rejected(self):
        for path in (Path("/var/www"), Path("/etc"), self.base):
            with self.subTest(path=path), self.assertRaises(tool.RehearsalError):
                tool.test_root(path)

    def test_marker_must_be_exact_and_root_private(self):
        with tempfile.TemporaryDirectory(prefix=tool.TEST_PREFIX, dir="/dev/shm") as name:
            path=Path(name)
            path.chmod(0o711)
            (path / ".synthetic-only").write_bytes(b"wrong marker")
            (path / ".synthetic-only").chmod(0o600)
            with self.assertRaises(tool.RehearsalError):
                tool.test_root(path)

    def test_symlink_in_parent_chain_rejected(self):
        source=self.base/"real"
        source.mkdir()
        alias=self.base/"symlink"
        alias.symlink_to(source,target_is_directory=True)
        with self.assertRaisesRegex(tool.RehearsalError,"symlinked"):
            tool.no_symlink_components(alias)

    def test_invalid_unix_names_and_root_forbidden(self):
        with self.assertRaisesRegex(tool.RehearsalError,"invalid_test_unix_user"):
            tool.account("../../root")
        class Dummy:
            pw_uid=0
            pw_gid=0
        with patch.object(tool.pwd,"getpwnam",return_value=Dummy()):
            with self.assertRaisesRegex(tool.RehearsalError,"root_target_forbidden"):
                tool.account("root")

    def test_safe_item_current_regular_file(self):
        p=self.base/"data"
        p.write_bytes(b"immutable dummy")
        p.chmod(0o600)
        info=tool.safe_item(p,kind="file",uid=os.getuid(),gid=os.getgid(),mode=0o600) if p.stat().st_dev==os.stat("/dev/shm").st_dev else None
        if info is not None:
            self.assertEqual(info.st_size,len(b"immutable dummy"))

    def test_hardlinks_are_rejected_if_on_tmpfs(self):
        with tempfile.TemporaryDirectory(dir="/dev/shm") as d:
            p=Path(d)/"data"
            p.write_bytes(b"demo")
            p.chmod(0o600)
            q=Path(d)/"alias"
            os.link(p,q)
            with self.assertRaisesRegex(tool.RehearsalError,"hardlink_forbidden"):
                tool.safe_item(p,kind="file",uid=os.getuid(),gid=os.getgid(),mode=0o600)

    def test_symlink_rejected_even_if_target_regular(self):
        with tempfile.TemporaryDirectory(dir="/dev/shm") as d:
            source=Path(d)/"real"
            source.write_bytes(b"demo")
            alias=Path(d)/"symlink"
            alias.symlink_to(source)
            with self.assertRaisesRegex(tool.RehearsalError,"fixture_symlink_forbidden"):
                tool.safe_item(alias,kind="file",uid=os.getuid(),gid=os.getgid(),mode=0o600)

    def test_wrong_mode_rejected(self):
        with tempfile.TemporaryDirectory(dir="/dev/shm") as d:
            p=Path(d)/"data"
            p.write_bytes(b"demo")
            p.chmod(0o644)
            with self.assertRaisesRegex(tool.RehearsalError,"fixture_mode_mismatch"):
                tool.safe_item(p,kind="file",uid=os.getuid(),gid=os.getgid(),mode=0o600)

    def test_stage_will_not_write_in_production_when_cli_invoked(self):
        output=StringIO()
        with contextlib.redirect_stdout(output):
            rc=tool.main(["stage","--root","/var/www/vazin-sites",
                          "--from-user","www-data","--to-user","nobody"])
        self.assertEqual(rc,70)
        self.assertIn('"decision":"NO_GO"',output.getvalue())
        self.assertNotIn("/var/www/vazin-sites",output.getvalue())

    def test_candidate_not_implicitly_authorized(self):
        self.assertEqual(tool.MANUAL,70)
        self.assertIn("actual_swap_performed",tool.stage.__code__.co_consts.__repr__())
        self.assertIn("release_authorized",tool.stage.__code__.co_consts.__repr__())

    def test_command_requires_action_and_absolute_root(self):
        with self.assertRaises(tool.RehearsalError):
            tool.no_symlink_components(Path("relative/test"))

    def test_stage_uses_no_external_deployment_commands(self):
        source=(ROOT/"scripts/isolated_runtime_identity_generation.py").read_text()
        for forbidden in ("systemctl", "service php", "nginx -s", "pg_dump", "/var/lib/vazincms-runtime",
                          "os.system(", "subprocess.run(", "chroot(", "mount("):
            self.assertNotIn(forbidden,source)

    def test_cannot_retarget_candidate_existing_symlink(self):
        with tempfile.TemporaryDirectory(dir="/dev/shm") as d:
            root=Path(d)
            (root/"candidate").symlink_to("/etc",target_is_directory=True)
            self.assertTrue((root/"candidate").is_symlink())


if __name__=="__main__":
    unittest.main()
