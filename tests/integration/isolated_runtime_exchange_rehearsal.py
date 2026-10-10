#!/usr/bin/env python3
"""Root-only Linux /dev/shm synthetic exchange, SIGKILL and rollback test.

Never use production data, credentials, users, site names, paths or services.
Uses existing www-data/nobody test identities, no useradd/chown on live files.
"""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import pwd
import signal
import sqlite3
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"scripts"))
import isolated_runtime_identity_generation as fixture
import isolated_runtime_exchange_rehearsal as exchange

old=pwd.getpwnam("www-data")
new=pwd.getpwnam("nobody")
assert old.pw_uid != new.pw_uid and old.pw_gid != new.pw_gid
if os.geteuid()!=0:
    raise SystemExit("PRIVATE_ROOT_NAMESPACE_REQUIRED")


def make_root():
    temp=tempfile.TemporaryDirectory(dir="/dev/shm",prefix=fixture.TEST_PREFIX)
    root=Path(temp.name)
    root.chmod(0o711)
    marker=root/".synthetic-only"
    marker.write_bytes(fixture.MARKER);marker.chmod(0o600)
    source=root/"source"
    source.mkdir(mode=0o750)
    os.chown(source,0,old.pw_gid)
    env=source/".env"
    env.write_text("VAZINCMS_SYNTHETIC_REHEARSAL=1\nDB_CONNECTION=sqlite\nDB_PASSWORD=NOT_A_REAL_SECRET\n")
    os.chown(env,0,old.pw_gid);env.chmod(0o640)
    for dirname in ("storage","uploads"):
        p=source/dirname
        p.mkdir(mode=0o700)
        os.chown(p,old.pw_uid,old.pw_gid)
    db=source/"storage/database.sqlite"
    with sqlite3.connect(db) as con:
        con.execute("CREATE TABLE fixture_marker (id INTEGER PRIMARY KEY, value TEXT NOT NULL)")
        con.execute("INSERT INTO fixture_marker(id,value) VALUES(1,'ONLY-SYNTHETIC-TEST-DATA')")
        con.execute("CREATE TABLE synthetic_events (id INTEGER PRIMARY KEY, name TEXT NOT NULL)")
        con.execute("INSERT INTO synthetic_events(name) VALUES('before-swap')")
    os.chown(db,old.pw_uid,old.pw_gid);db.chmod(0o600)
    lock=source/"storage/installed.lock"
    lock.write_bytes(b"fake-install-lock\n")
    os.chown(lock,old.pw_uid,old.pw_gid);lock.chmod(0o600)
    image=source/"uploads/example.bin"
    image.write_bytes(b"fake-pixels")
    os.chown(image,old.pw_uid,old.pw_gid);image.chmod(0o600)
    return temp,root


def run_new_user_sqlite(root,table_value):
    script=(
        "import sqlite3,sys;"
        "p=sys.argv[1];"
        "c=sqlite3.connect(p);"
        "c.execute('INSERT INTO synthetic_events(name) VALUES(?)',(sys.argv[2],));"
        "c.commit();c.close()"
    )
    r=subprocess.run(["runuser","-u","nobody","--","/usr/bin/python3","-c",script,
                      str(root/"source/storage/database.sqlite"),table_value],
                     capture_output=True,text=True,timeout=8)
    if r.returncode:
        raise RuntimeError("ISOLATED_POST_SWAP_WRITE_FAILED")


def names_in_db(path):
    with sqlite3.connect(path) as con:
        return [r[0] for r in con.execute("SELECT name FROM synthetic_events ORDER BY id")]


# Scenario 1: successful exchange + a true post-swap write by target uid
# + rollback that retains the newer generation rather than destroying data.
temp,root=make_root()
try:
    staged=fixture.stage(root,"www-data","nobody")
    assert staged["staged_generation_verified"]
    prepared=exchange.prepare(root,"www-data","nobody")
    before_source=exchange.directory_id(root/"source")
    before_candidate=exchange.directory_id(root/"candidate")
    switched=exchange.switch(root)
    status=exchange.inspect(root)
    print("SCENARIO1_ATOMIC_NEW_INODE",exchange.directory_id(root/"source")==before_candidate,flush=True)
    print("SCENARIO1_PHASE",status["journal_phase"],"SLOT",status["observed_active_slot"],flush=True)
    assert switched["switched_in_synthetic_tmpfs"] and status["observed_active_slot"]=="new_active"
    run_new_user_sqlite(root,"new-user-post-swap-write")
    rolled=exchange.restore(root)
    status=exchange.inspect(root)
    assert rolled["new_generation_retained"] and status["observed_active_slot"]=="old_active"
    old_values=names_in_db(root/"source/storage/database.sqlite")
    newer_values=names_in_db(root/"candidate/storage/database.sqlite")
    print("SCENARIO1_HISTORIC_GENERATION_RESTORED",exchange.directory_id(root/"source")==before_source,flush=True)
    print("SCENARIO1_OLD_CONTENT_UNMODIFIED",old_values==["before-swap"],flush=True)
    print("SCENARIO1_POST_SWAP_WRITE_PRESERVED",newer_values==["before-swap","new-user-post-swap-write"],flush=True)
    assert old_values==["before-swap"]
    assert newer_values==["before-swap","new-user-post-swap-write"]
    repeat=exchange.restore(root)
    print("SCENARIO1_IDEMPOTENT_RECOVERY",not repeat["exchange_needed"],flush=True)
    assert not repeat["exchange_needed"]
    try:
        fixture.discard(root,"www-data","nobody")
        raised=False
    except fixture.RehearsalError as exc:
        raised=(str(exc)=="candidate_discard_content_mismatch")
    print("SCENARIO1_DESTRUCTIVE_DISCARD_BLOCKED",raised,flush=True)
    assert raised and (root/"candidate").is_dir()
finally:
    temp.cleanup()
print("SCENARIO1_TMPFS_DESTROYED",True,flush=True)

# Scenario 2: SIGKILL between atomic exchange and journal update.
# Journal says 'prepared', inode evidence says 'new_active'.
temp,root=make_root()
try:
    fixture.stage(root,"www-data","nobody")
    exchange.prepare(root,"www-data","nobody")
    pid=os.fork()
    if pid==0:
        exchange.switch(root,post_exchange_hook=lambda: os.kill(os.getpid(),signal.SIGKILL))
        os._exit(99)
    child,exitcode=os.waitpid(pid,0)
    assert os.WIFSIGNALED(exitcode) and os.WTERMSIG(exitcode)==signal.SIGKILL
    state=exchange.inspect(root)
    print("SCENARIO2_SIGKILL_AFTER_EXCHANGE",state["journal_may_lag_slot"],flush=True)
    assert state["journal_may_lag_slot"] and state["observed_active_slot"]=="new_active"
    run_new_user_sqlite(root,"write-after-crashed-switch")
    exchange.restore(root)
    final=exchange.inspect(root)
    preserved=names_in_db(root/"candidate/storage/database.sqlite")
    print("SCENARIO2_RECOVERED_OLD_ACTIVE",final["observed_active_slot"]=="old_active",flush=True)
    print("SCENARIO2_POST_CRASH_WRITE_RETAINED","write-after-crashed-switch" in preserved,flush=True)
    assert final["observed_active_slot"]=="old_active" and "write-after-crashed-switch" in preserved
finally:
    temp.cleanup()
print("SCENARIO2_TMPFS_DESTROYED",True,flush=True)

# Scenario 3: SIGKILL AFTER rollback exchange but BEFORE journal update.
temp,root=make_root()
try:
    fixture.stage(root,"www-data","nobody")
    exchange.prepare(root,"www-data","nobody")
    exchange.switch(root)
    pid=os.fork()
    if pid==0:
        exchange.write_journal=lambda *a,**kw: os.kill(os.getpid(),signal.SIGKILL)
        exchange.restore(root)
        os._exit(98)
    child,exitcode=os.waitpid(pid,0)
    assert os.WIFSIGNALED(exitcode) and os.WTERMSIG(exitcode)==signal.SIGKILL
    state=exchange.inspect(root)
    print("SCENARIO3_JOURNAL_LAGS_ROLLBACK",state["journal_may_lag_slot"],flush=True)
    assert state["journal_may_lag_slot"] and state["observed_active_slot"]=="old_active"
    final=exchange.restore(root)
    print("SCENARIO3_RECOVERY_RECONCILED",final["historical_generation_active"] and not final["exchange_needed"],flush=True)
    assert final["historical_generation_active"] and not final["exchange_needed"]
finally:
    temp.cleanup()
print("SCENARIO3_TMPFS_DESTROYED",True,flush=True)

# Scenario 4: a source update after prepare MUST block the exchange.
temp,root=make_root()
try:
    fixture.stage(root,"www-data","nobody")
    exchange.prepare(root,"www-data","nobody")
    before=exchange.directory_id(root/"source")
    with sqlite3.connect(root/"source/storage/database.sqlite") as con:
        con.execute("INSERT INTO synthetic_events(name) VALUES('source-changed-before-swap')")
    try:
        exchange.switch(root)
        blocked=False
    except exchange.ExchangeError as exc:
        blocked=(str(exc)=="generation_changed_since_prepare")
    print("SCENARIO4_STALE_SOURCE_REJECTED",blocked,flush=True)
    assert blocked and before==exchange.directory_id(root/"source")
finally:
    temp.cleanup()
print("SCENARIO4_TMPFS_DESTROYED",True,flush=True)
print("ALL_TESTS_LIVE_SYSTEM_UNCHANGED",True,flush=True)
