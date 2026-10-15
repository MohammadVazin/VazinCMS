"""Deterministic unit tests for the OP-02.13 synthetic PHP-FPM barrier.

All fixtures live in TemporaryDirectory. Real FPM integration is opt-in and
not run by release-check; there is NEVER a production drain authorization.
"""
from __future__ import annotations

import fcntl
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import threading
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tests/integration/isolated_fpm_site_write_barrier_rehearsal.py"
SPEC = importlib.util.spec_from_file_location("isolated_fpm_site_write_barrier", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
lab = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(lab)


class IsolatedBarrierStateTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="op0213-synthetic-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.file = self.root / "travel.barrier"
        self.file.write_text("OPEN:g1\n", encoding="ascii")
        self.file.chmod(0o644)

    def state(self):
        return self.file.read_text().strip()

    def test_close_current_generation(self):
        lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")
        self.assertEqual(self.state(), "CLOSED:g1")

    def test_reopen_must_advance_generation(self):
        lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")
        lab.state_transition(self.file, "CLOSED:g1", "OPEN:g2")
        self.assertEqual(self.state(), "OPEN:g2")

    def test_close_does_not_allow_direct_new_generation(self):
        with self.assertRaisesRegex(lab.RehearsalError, "unsafe_state_transition"):
            lab.state_transition(self.file, "OPEN:g1", "OPEN:g2")
        self.assertEqual(self.state(), "OPEN:g1")

    def test_reopen_same_generation_forbidden(self):
        lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")
        with self.assertRaisesRegex(lab.RehearsalError, "unsafe_state_transition"):
            lab.state_transition(self.file, "CLOSED:g1", "OPEN:g1")
        self.assertEqual(self.state(), "CLOSED:g1")

    def test_skipping_or_rolling_back_generation_forbidden(self):
        lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")
        for proposed in ("OPEN:g3", "OPEN:g0"):
            with self.assertRaises(lab.RehearsalError):
                lab.state_transition(self.file, "CLOSED:g1", proposed)
        self.assertEqual(self.state(), "CLOSED:g1")

    def test_wrong_old_state_is_rejected_without_mutation(self):
        with self.assertRaisesRegex(lab.RehearsalError, "barrier_generation_mismatch"):
            lab.state_transition(self.file, "OPEN:g9", "CLOSED:g9")
        self.assertEqual(self.state(), "OPEN:g1")

    def test_missing_state_file_fails_closed(self):
        self.file.unlink()
        with self.assertRaisesRegex(lab.RehearsalError, "barrier_missing_or_unreadable"):
            lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")

    def test_world_writable_barrier_file_rejected(self):
        self.file.chmod(0o666)
        with self.assertRaisesRegex(lab.RehearsalError, "barrier_file_identity_rejected"):
            lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")
        self.assertEqual(self.state(), "OPEN:g1")

    def test_symlinked_state_file_fails_closed(self):
        sibling = self.root / "link"
        sibling.symlink_to(self.file)
        with self.assertRaises(lab.RehearsalError):
            lab.state_transition(sibling, "OPEN:g1", "CLOSED:g1")
        self.assertEqual(self.state(), "OPEN:g1")

    def test_hardlinked_state_file_fails_closed(self):
        sibling = self.root / "other"
        os.link(self.file, sibling)
        with self.assertRaisesRegex(lab.RehearsalError, "barrier_file_identity_rejected"):
            lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")
        self.assertEqual(self.state(), "OPEN:g1")

    def test_unexpected_state_text_cannot_be_used_as_open_receipt(self):
        self.file.write_text("OPEN:g1\nOPEN:g2\n", encoding="ascii")
        with self.assertRaisesRegex(lab.RehearsalError, "barrier_generation_mismatch"):
            lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")

    def test_shared_flock_serializes_close_after_mutation(self):
        hold = os.open(self.file, os.O_RDONLY)
        self.addCleanup(os.close, hold)
        fcntl.flock(hold, fcntl.LOCK_EX)
        opened = threading.Event()
        done = threading.Event()
        failed: list[str] = []

        def closer():
            opened.set()
            try:
                lab.state_transition(self.file, "OPEN:g1", "CLOSED:g1")
            except lab.RehearsalError:
                failed.append("fail")
            finally:
                done.set()

        thread = threading.Thread(target=closer)
        thread.start()
        self.assertTrue(opened.wait(timeout=1))
        time.sleep(0.045)
        self.assertFalse(done.is_set())
        fcntl.flock(hold, fcntl.LOCK_UN)
        thread.join(timeout=2)
        self.assertTrue(done.is_set())
        self.assertFalse(failed)
        self.assertEqual(self.state(), "CLOSED:g1")

    def test_barrier_file_owned_by_current_user_for_lab(self):
        st = self.file.stat()
        self.assertEqual(st.st_uid, os.geteuid())
        self.assertEqual(stat.S_IMODE(st.st_mode), 0o644)


class SyntheticPHPProgramTests(unittest.TestCase):
    def test_script_requires_exact_generation_and_exclusive_lock(self):
        php = lab.PHP_PROGRAM
        self.assertIn('@flock($handle, LOCK_EX)', php)
        self.assertIn('if ($state !== "OPEN:" . $generation)', php)
        self.assertLess(php.index('if ($state !== "OPEN:"'), php.index('mark($marker, "PROTECTED_WRITTEN")'))
        self.assertLess(php.index('mark($marker, "PROTECTED_WRITTEN")'), php.rindex('flock($handle, LOCK_UN)'))

    def test_missing_barrier_denies_before_write(self):
        php = lab.PHP_PROGRAM
        self.assertIn('@fopen($barrier, "rb")', php)
        self.assertIn('if ($handle === false) { deny_write(); }', php)

    def test_unsafe_control_is_clearly_separate_from_protected(self):
        php = lab.PHP_PROGRAM
        self.assertIn('if ($mode === "unsafe_wait")', php)
        self.assertIn("UNSAFE_WRITTEN", php)
        self.assertIn("PROTECTED_WRITTEN", php)
        self.assertIn('exit;', php[:php.index('$handle = @fopen')])

    def test_no_inherited_environment_for_fcgi_client(self):
        with patch.dict(os.environ, {"TOP_SECRET_PRODUCTION_TEST": "test_token"}, clear=False):
            env = lab.fastcgi_env(Path("/dev/shm/mock_site/handler.php"))
        self.assertNotIn("TOP_SECRET_PRODUCTION_TEST", env)
        self.assertEqual(env["REQUEST_METHOD"], "GET")
        self.assertEqual(env["REMOTE_ADDR"], "127.0.0.1")

    def test_php_fixture_rejects_unknown_site_and_modes(self):
        with tempfile.TemporaryDirectory() as d:
            base = Path(d)
            for site, mode in (("production", "guarded_now"), ("travel", "systemctl"), ("travel", "unguarded_production")):
                with self.assertRaises(lab.RehearsalError):
                    lab.php_fixture(base/"script.php", site, "g1", mode,
                                    base/"barrier", base, "dummy")

    def test_php_fixture_embeds_only_test_paths(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            file = root/"travel.php"
            files = lab.php_fixture(file, "travel", "g1", "guarded_now",
                                    root/"travel.barrier", root, "allowed")
            body = file.read_text(encoding="utf-8")
            self.assertIn(str(root), body)
            self.assertNotIn("/etc/php/8.5/fpm/pool.d", body)
            self.assertEqual(sorted(files), ["held", "marker", "release", "started"])

    def test_release_never_claimed_by_rehearsal_source(self):
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn('"live_per_site_drain_verified": False', source)
        self.assertIn('"release_authorized": False', source)
        self.assertIn('"production": "NO_GO"', source)
        self.assertIn('"php_db_external_side_effects_covered": False', source)

    def test_no_production_nginx_or_fpm_service_control(self):
        source = SCRIPT.read_text(encoding="utf-8")
        for forbidden in ("systemctl", "service nginx", "nginx -s reload",
                          "/etc/php/8.5/fpm/pool.d", "php-fpm8.5.service",
                          "mysql -", "psql -"):
            self.assertNotIn(forbidden, source)
        self.assertIn('TemporaryDirectory(prefix="vazincms-op0213-fpm-", dir="/dev/shm")', source)
        self.assertIn('"--nodaemonize", "--fpm-config"', source)

    def test_rehearsal_only_local_unix_sockets(self):
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("site + \".sock\"", source)
        self.assertIn("[FASTCGI, \"-bind\", \"-connect\", str(socket)]", source)
        self.assertNotIn("https://", source)
        self.assertNotIn("127.0.0.1:443", source)


if __name__ == "__main__":
    unittest.main()
