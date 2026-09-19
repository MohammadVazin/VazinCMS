import argparse
import importlib.util
import json
import os
import shutil
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

HELPER_PATH=Path(__file__).resolve().parents[1]/"deploy"/"atomic_release.py"
SPEC=importlib.util.spec_from_file_location("cms_atomic_release",HELPER_PATH)
helper=importlib.util.module_from_spec(SPEC);assert SPEC and SPEC.loader;SPEC.loader.exec_module(helper)

def exchange(left,right):
    temporary=left.parent/".unit-exchange";os.rename(left,temporary);os.rename(right,left);os.rename(temporary,right)

def durable(path,payload):path.write_text(json.dumps(payload),encoding="utf-8")

def unit_mutable(root,storage,uploads,uid,gid):
    return {**helper.directory_identity(root),"tree_sha256":helper.content_tree_sha256(root),"storage":storage,"uploads":uploads,"uid":uid,"gid":gid}

def unit_mutable_matches(item,path):
    try:return unit_mutable(path,item["storage"],item["uploads"],item["uid"],item["gid"])==item
    except (OSError,KeyError,TypeError):return False

def unit_bound(root,storage,uploads,uid,gid):
    database=root/storage/"database.sqlite";sqlite_item=None
    if database.exists():
        connection=sqlite3.connect(database);assert connection.execute("PRAGMA quick_check").fetchone()[0]=="ok";connection.close()
        info=os.lstat(database);sqlite_item={"device":info.st_dev,"inode":info.st_ino}
    return {**helper.directory_identity(root),"storage":storage,"storage_identity":helper.directory_identity(root/storage),
        "uploads":uploads,"uploads_identity":helper.directory_identity(root/uploads),"sqlite":sqlite_item,"uid":uid,"gid":gid}

def unit_bound_matches(item,path):
    try:return unit_bound(path,item["storage"],item["uploads"],item["uid"],item["gid"])==item
    except (OSError,KeyError,TypeError,sqlite3.Error):return False

class CmsAtomicRecoveryTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name)
        self.runtime_base=self.root/"vazincms-runtime"
        self.state=self.root/"state";self.state.mkdir();self.target=self.root/"target";self.target.mkdir()
        self.fresh=self.root/".target.new";self.fresh.mkdir();self.previous=self.root/".target.previous";self.failed=self.root/".target.failed"
        (self.target/"VERSION").write_text("10.6.1\n",encoding="utf-8");(self.fresh/"VERSION").write_text("10.6.2\n",encoding="utf-8")
        self.old=helper.snapshot(self.target,"10.6.1");self.new=helper.snapshot(self.fresh,"10.6.2")
        self.payload={"schema":helper.SCHEMA,"correlation_id":"unit","phase":"prepared","machine":"machine","target":str(self.target),
            "fresh":str(self.fresh),"previous":str(self.previous),"failed":str(self.failed),"old":self.old,"new":self.new,
            "manifest_sha256":"a"*64,"backup_refs":[{"path":"/var/backups/unit","sha256":"b"*64,"size":1,"device":1,"inode":1}],"runtime":None}
        self.write();self.patches=patch.multiple(helper,RUNTIME_BASE=self.runtime_base,secure_chain=lambda *_a,**_k:None,machine_binding=lambda:"machine",
            backup_matches=lambda item:isinstance(item,dict),manifest_matches=lambda *_a:True,fsync_dir=lambda _p:None,
            fsync_file=lambda _p:None,durable_json=durable,runtime_permissions=lambda *_a:None,seal_mutable_tree=lambda *_a:None,seal_service_pair=lambda *_a:None,
            mutable_tree_snapshot=unit_mutable,mutable_tree_matches=unit_mutable_matches,bound_generation_snapshot=unit_bound,bound_generation_matches=unit_bound_matches,
            load_marker=lambda state,target,old,new:helper.validate_payload(json.loads((Path(state)/"deploy-in-progress.json").read_text()),Path(state),Path(target),old,new),
            load_completed=lambda state,target,old,new:helper.validate_payload(json.loads((Path(state)/"last-completed.json").read_text())["payload"],Path(state),Path(target),old,new))
        self.patches.start();self.rename=patch.object(helper,"rename_exchange",exchange);self.rename.start()
    def tearDown(self):self.rename.stop();self.patches.stop();self.temp.cleanup()
    def write(self):
        marker=self.state/"deploy-in-progress.json";marker.write_text(json.dumps(self.payload),encoding="utf-8");os.chmod(marker,0o600)
    def args(self):return argparse.Namespace(state=str(self.state),target=str(self.target),old_version="10.6.1",new_version="10.6.2")
    def assert_restored(self):
        self.assertEqual((self.target/"VERSION").read_text().strip(),"10.6.1");self.assertFalse((self.state/"deploy-in-progress.json").exists())

    @staticmethod
    def make_pair(root):
        storage=root/"storage";uploads=root/"uploads";storage.mkdir();uploads.mkdir()
        os.chmod(root,0o750);os.chmod(storage,0o700);os.chmod(uploads,0o700)
        return storage,uploads

    def prepare_bound_runtime(self):
        site=self.runtime_base/"unit-site";site.mkdir(parents=True)
        active=site/"data";active.mkdir();active_storage,active_uploads=self.make_pair(active)
        prepared=site/".data.new-unit";prepared.mkdir();prepared_storage,prepared_uploads=self.make_pair(prepared)
        database=prepared_storage/"database.sqlite"
        connection=sqlite3.connect(database);connection.execute("CREATE TABLE events(id INTEGER PRIMARY KEY,value TEXT)");connection.execute("INSERT INTO events(value) VALUES('legacy')");connection.commit();connection.close();os.chmod(database,0o600)
        upload=prepared_uploads/"legacy.txt";upload.write_text("legacy",encoding="utf-8");os.chmod(upload,0o600)
        legacy_storage=self.target/"storage";legacy_storage.mkdir();legacy_uploads=self.target/"public"/"uploads";legacy_uploads.mkdir(parents=True)
        shutil.copy2(database,legacy_storage/"database.sqlite");shutil.copy2(upload,legacy_uploads/"legacy.txt")
        os.chmod(legacy_storage,0o700);os.chmod(legacy_uploads.parent,0o755);os.chmod(legacy_uploads,0o700);os.chmod(legacy_storage/"database.sqlite",0o600);os.chmod(legacy_uploads/"legacy.txt",0o600)
        (self.target/".env").write_text("APP_ENV=unit\n",encoding="utf-8")
        runtime_storage=active/"storage";runtime_uploads=active/"uploads"
        (self.fresh/".env").write_text(f"VAZINCMS_STORAGE_PATH={runtime_storage}\nVAZINCMS_UPLOADS_PATH={runtime_uploads}\n",encoding="utf-8")
        runtime={"mode":"legacy","phase":"declared","active":str(active),"prepared":str(prepared),
            "active_initial":helper.directory_identity(active),"prepared_initial":helper.directory_identity(prepared),"ready":None,"bound":None,"legacy_recovery":None,
            "legacy_storage":str(legacy_storage),"legacy_uploads":str(legacy_uploads),"runtime_storage":str(runtime_storage),"runtime_uploads":str(runtime_uploads),"uid":0,"gid":0}
        self.payload["runtime"]=runtime;self.write()
        helper.command_runtime_ready(self.args());helper.command_runtime_switch(self.args());helper.command_runtime_bind(self.args())
        return runtime,runtime_storage/"database.sqlite"

    @staticmethod
    def write_sentinel(database,value):
        connection=sqlite3.connect(database);connection.execute("INSERT INTO events(value) VALUES(?)",(value,));connection.commit();connection.close()

    def hydrate(self,slot,runtime):
        storage=slot/"storage";uploads=slot/"public"/"uploads";storage.mkdir(parents=True,exist_ok=True);uploads.mkdir(parents=True,exist_ok=True)
        shutil.copy2(Path(runtime["runtime_storage"])/"database.sqlite",storage/"database.sqlite")
        shutil.copy2(Path(runtime["runtime_uploads"])/"legacy.txt",uploads/"legacy.txt")
        for directory in (storage,uploads):os.chmod(directory,0o700)
        for item in (storage/"database.sqlite",uploads/"legacy.txt"):os.chmod(item,0o600)

    @staticmethod
    def assert_sentinel(database,value):
        connection=sqlite3.connect(database);actual=connection.execute("SELECT value FROM events ORDER BY id DESC LIMIT 1").fetchone()[0];connection.close();assert actual==value

    def test_recover_before_switch_twice(self):
        helper.command_recover(self.args());helper.command_recover(self.args());self.assert_restored()
    def test_recover_after_code_exchange(self):
        exchange(self.target,self.fresh);helper.command_recover(self.args());self.assert_restored()
    def test_recover_after_old_slot_rename(self):
        exchange(self.target,self.fresh);os.rename(self.fresh,self.previous);helper.command_recover(self.args());self.assert_restored()
    def test_recover_after_rollback_exchange_then_twice(self):
        helper.command_swap(self.args());exchange(self.target,self.previous);helper.command_recover(self.args());helper.command_recover(self.args());self.assert_restored()
        self.assertEqual((self.failed/"VERSION").read_text().strip(),"10.6.2")
    def test_recover_after_failed_archive_then_twice(self):
        helper.command_swap(self.args());exchange(self.target,self.previous);os.rename(self.previous,self.failed)
        helper.command_recover(self.args());helper.command_recover(self.args());self.assert_restored()
    def test_old_slot_is_exact_snapshot(self):
        helper.command_swap(self.args());self.assertEqual(Path(os.path.abspath(self.previous)),Path(os.path.abspath(self.previous)))
        with patch("builtins.print") as output:helper.command_old_slot(self.args())
        self.assertEqual(Path(output.call_args.args[0]),self.previous)
    def test_unexpected_inode_fails_manual(self):
        detached=self.root/"detached";os.rename(self.target,detached);self.target.mkdir();(self.target/"VERSION").write_text("10.6.1\n")
        with self.assertRaises(SystemExit) as raised:helper.command_recover(self.args())
        self.assertEqual(raised.exception.code,helper.MANUAL)

    def test_write_after_code_exchange_survives_recovery_and_rerun(self):
        with patch.object(helper,"load_marker",return_value=self.payload):
            runtime,database=self.prepare_bound_runtime();helper.command_swap(self.args())
            self.write_sentinel(database,"after-exchange")
            self.hydrate(self.previous,runtime);helper.command_runtime_recovery_ready(self.args());helper.command_recover(self.args())
        self.assert_restored();self.assert_sentinel(self.target/"storage"/"database.sqlite","after-exchange");self.assert_sentinel(database,"after-exchange")
        helper.command_recover(self.args())

    def test_completed_rollback_preserves_post_success_write(self):
        with patch.object(helper,"load_marker",return_value=self.payload):
            runtime,database=self.prepare_bound_runtime();helper.command_swap(self.args());helper.command_complete(self.args())
            self.write_sentinel(database,"after-success")
            helper.command_rollback_begin(self.args());self.hydrate(self.previous,runtime);helper.command_runtime_recovery_ready(self.args());helper.command_recover(self.args())
        self.assert_restored();self.assert_sentinel(self.target/"storage"/"database.sqlite","after-success");self.assert_sentinel(database,"after-success")

    def test_stale_legacy_copy_fails_closed_before_recovery(self):
        with patch.object(helper,"load_marker",return_value=self.payload):
            _runtime,database=self.prepare_bound_runtime();helper.command_swap(self.args());self.write_sentinel(database,"not-hydrated")
            with self.assertRaises(SystemExit) as raised:helper.command_runtime_recovery_ready(self.args())
        self.assertEqual(raised.exception.code,helper.MANUAL)
        self.assertEqual((self.target/"VERSION").read_text().strip(),"10.6.2")

class CmsAtomicStaticContractTests(unittest.TestCase):
    def test_fail_closed_linux_exchange_and_exact_runtime_contract(self):
        source=HELPER_PATH.read_text(encoding="utf-8")
        self.assertIn('getattr(libc,"renameat2",None)',source);self.assertNotIn("os.rename(target,previous)",source)
        self.assertIn('Path("/var/lib/vazincms-runtime")',source)
        self.assertIn('VAZINCMS_STORAGE_PATH',source);self.assertIn('VAZINCMS_UPLOADS_PATH',source)
        self.assertIn("resumed recovery archive verification failed",source)
        self.assertIn("backup_matches(item)",source);self.assertIn("manifest_matches(target,payload",source)

if __name__=="__main__":unittest.main()
