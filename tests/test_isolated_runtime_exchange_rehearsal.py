"""Offline unit tests. Any real rename occurs only inside a temporary test dir."""
from __future__ import annotations

from contextlib import contextmanager, redirect_stdout
import importlib.util
from io import StringIO
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "isolated_runtime_exchange_rehearsal",
    ROOT / "scripts/isolated_runtime_exchange_rehearsal.py",
)
assert SPEC and SPEC.loader
import sys
sys.path.insert(0, str(ROOT / "scripts"))
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


@contextmanager
def no_lock(_root):
    yield


def journal_fixture(phase="prepared"):
    return {
        "schema": 1, "tx": "a" * 32, "phase": phase,
        "old_user": "www-data", "old_uid": 33, "old_gid": 33,
        "new_user": "nobody", "new_uid": 65534, "new_gid": 65534,
        "old_directory": {"device": 99, "inode": 111},
        "new_directory": {"device": 99, "inode": 222},
        "old_hash": "b" * 64, "new_hash": "c" * 64,
    }


class ExchangeUnitTests(unittest.TestCase):
    def test_tree_hash_order_independent(self):
        self.assertEqual(module.tree_digest({"x":"1","y":"2"}),
                         module.tree_digest({"y":"2","x":"1"}))

    def test_tree_hash_changes_when_file_changes(self):
        self.assertNotEqual(module.tree_digest({"x":"1"}),module.tree_digest({"x":"2"}))

    def test_slot_order_before_swap(self):
        item=journal_fixture()
        with patch.object(module,"directory_id",side_effect=[
            item["old_directory"],item["new_directory"]
        ]):
            self.assertEqual(module.slot_order(Path("/tmp/dummy"),item),"old_active")

    def test_slot_order_after_swap(self):
        item=journal_fixture()
        with patch.object(module,"directory_id",side_effect=[
            item["new_directory"],item["old_directory"]
        ]):
            self.assertEqual(module.slot_order(Path("/tmp/dummy"),item),"new_active")

    def test_unknown_inodes_refused(self):
        item=journal_fixture()
        with patch.object(module,"directory_id",return_value={"device":99,"inode":333}):
            with self.assertRaisesRegex(module.ExchangeError,"identity_mismatch"):
                module.slot_order(Path("/tmp/dummy"),item)

    def test_forbidden_production_path_always_refused(self):
        with self.assertRaises(Exception):
            module.require_synthetic_root(Path("/var/www/vazin-sites"))

    def test_prepared_without_swap_recovers_as_aborted(self):
        item=journal_fixture()
        recorder=Mock()
        with patch.object(module,"exclusive_lock",no_lock),\
             patch.object(module,"read_journal",return_value=item),\
             patch.object(module,"slot_order",return_value="old_active"),\
             patch.object(module,"pre_swap_verified"),\
             patch.object(module,"write_journal",side_effect=recorder),\
             patch.object(module,"atomic_exchange") as exchange:
            result=module.restore(Path("/tmp/example"))
        self.assertEqual(item["phase"],"aborted")
        self.assertFalse(result["exchange_needed"])
        exchange.assert_not_called()
        recorder.assert_called_once()

    def test_crash_after_swap_before_journal_write_is_detectable(self):
        item=journal_fixture()
        with patch.object(module,"exclusive_lock",no_lock),\
             patch.object(module,"read_journal",return_value=item),\
             patch.object(module,"slot_order",return_value="new_active"),\
             patch.object(module,"active_new_verified"):
            result=module.inspect(Path("/tmp/example"))
        self.assertTrue(result["journal_may_lag_slot"])
        self.assertEqual(result["observed_active_slot"],"new_active")

    def test_restore_after_crash_reverses_slot_exchange(self):
        item=journal_fixture("prepared")
        with patch.object(module,"exclusive_lock",no_lock),\
             patch.object(module,"read_journal",return_value=item),\
             patch.object(module,"slot_order",return_value="new_active"),\
             patch.object(module,"active_new_verified"),\
             patch.object(module,"rollback_verified"),\
             patch.object(module,"write_journal") as write,\
             patch.object(module,"atomic_exchange") as exchange:
            result=module.restore(Path("/tmp/example"))
        exchange.assert_called_once()
        self.assertEqual(item["phase"],"rolled_back")
        self.assertTrue(result["new_generation_retained"])
        write.assert_called_once()

    def test_recovered_after_rollback_before_journal_write(self):
        item=journal_fixture("swapped")
        with patch.object(module,"exclusive_lock",no_lock),\
             patch.object(module,"read_journal",return_value=item),\
             patch.object(module,"slot_order",return_value="old_active"),\
             patch.object(module,"rollback_verified"),\
             patch.object(module,"write_journal"):
            result=module.restore(Path("/tmp/example"))
        self.assertTrue(result["historical_generation_active"])
        self.assertFalse(result["exchange_needed"])
        self.assertEqual(item["phase"],"rolled_back")

    def test_swap_fails_if_journal_is_not_prepared(self):
        item=journal_fixture("swapped")
        with patch.object(module,"exclusive_lock",no_lock),patch.object(module,"read_journal",return_value=item):
            with self.assertRaisesRegex(module.ExchangeError,"not_prepared"):
                module.switch(Path("/tmp/example"))

    def test_atomic_exchange_swaps_inode_slots_and_contents(self):
        with tempfile.TemporaryDirectory(prefix="op02-atomic-directory-") as d:
            root=Path(d)
            a=root/"source";b=root/"candidate"
            a.mkdir();b.mkdir()
            (a/"old.txt").write_text("old")
            (b/"new.txt").write_text("new")
            old_inode=a.stat().st_ino
            new_inode=b.stat().st_ino
            try:
                module.atomic_exchange(root)
            except module.ExchangeError as exc:
                if "renameat2_unavailable" in str(exc):
                    self.skipTest("Platform lacks Linux renameat2")
                raise
            self.assertEqual(a.stat().st_ino,new_inode)
            self.assertEqual(b.stat().st_ino,old_inode)
            self.assertTrue((a/"new.txt").is_file())
            self.assertTrue((b/"old.txt").is_file())

    def test_atomic_exchange_fails_without_both_slots(self):
        with tempfile.TemporaryDirectory() as d:
            (Path(d)/"source").mkdir()
            with self.assertRaisesRegex(module.ExchangeError,"atomic_directory_exchange_failed"):
                module.atomic_exchange(Path(d))

    def test_journal_write_is_private_and_parseable(self):
        with tempfile.TemporaryDirectory() as d:
            module.write_journal(Path(d),journal_fixture())
            p=Path(d)/module.JOURNAL
            self.assertEqual(p.stat().st_mode & 0o777,0o600)
            self.assertEqual(json.loads(p.read_text())["schema"],1)

    def test_journal_tampered_phase_list_is_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/module.JOURNAL
            payload=journal_fixture()
            payload["phase"]=["prepared"]
            path.write_text(json.dumps(payload))
            path.chmod(0o600)
            previous=module.os.lstat(path)
            class Info:
                st_mode=previous.st_mode
                st_uid=0
                st_nlink=1
                st_size=previous.st_size
            with patch.object(module.os,"lstat",return_value=Info()):
                with self.assertRaisesRegex(module.ExchangeError,"journal_phase_invalid"):
                    module.read_journal(Path(folder))

    def test_journal_tampered_account_list_is_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/module.JOURNAL
            payload=journal_fixture()
            payload["old_user"]=["www-data"]
            path.write_text(json.dumps(payload))
            path.chmod(0o600)
            previous=module.os.lstat(path)
            class Info:
                st_mode=previous.st_mode
                st_uid=0
                st_nlink=1
                st_size=previous.st_size
            with patch.object(module.os,"lstat",return_value=Info()):
                with self.assertRaisesRegex(module.ExchangeError,"journal_account_invalid"):
                    module.read_journal(Path(folder))

    def test_cli_production_target_no_go_without_metadata(self):
        output=StringIO()
        with redirect_stdout(output):
            rc=module.main(["status","--root","/var/www/vazin-sites"])
        self.assertEqual(rc,70)
        self.assertEqual(json.loads(output.getvalue())["decision"],"NO_GO")

    def test_no_mutating_global_service_operations(self):
        content=(ROOT/"scripts/isolated_runtime_exchange_rehearsal.py").read_text()
        for bad in ("subprocess.run(", "systemctl ", "nginx -s", "chown(", "/var/lib/vazincms-runtime",
                    "os.system(", "useradd", "mount(", "pg_dump"):
            self.assertNotIn(bad,content)


if __name__ == "__main__":
    unittest.main()
