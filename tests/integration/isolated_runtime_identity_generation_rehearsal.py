#!/usr/bin/env python3
"""Real Unix UID detached-copy exercise. Must run in private test namespace as root."""
from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import pwd
import shutil
import sqlite3
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "isolated_runtime_identity_generation",
    ROOT / "scripts/isolated_runtime_identity_generation.py",
)
assert SPEC and SPEC.loader
test = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(test)

if os.geteuid() != 0:
    raise SystemExit("REQUIRES_ROOT_INSIDE_PRIVATE_NAMESPACE")

old = pwd.getpwnam("www-data")
target = pwd.getpwnam("nobody")
if old.pw_uid == target.pw_uid or old.pw_gid == target.pw_gid:
    raise SystemExit("DISTINCT_TEST_IDENTITIES_REQUIRED")


def user_access(username: str, paths: list[Path]) -> list[bool]:
    # Read-only real access checks under the effective target credentials.
    script = (
        "import json,os,sys;"
        "print(json.dumps([os.access(p,os.R_OK) for p in sys.argv[1:]]))"
    )
    r = subprocess.run(
        ["runuser", "-u", username, "--", "/usr/bin/python3", "-c", script, *map(str, paths)],
        capture_output=True, text=True, timeout=7, check=True,
    )
    import json
    return json.loads(r.stdout)


with tempfile.TemporaryDirectory(dir="/dev/shm", prefix=test.TEST_PREFIX) as dirname:
    root = Path(dirname)
    root.chmod(0o711)
    marker = root / ".synthetic-only"
    marker.write_bytes(test.MARKER)
    marker.chmod(0o600)
    source = root / "source"
    source.mkdir(mode=0o750)
    os.chown(source, 0, old.pw_gid)
    env = source / ".env"
    env.write_text(
        "VAZINCMS_SYNTHETIC_REHEARSAL=1\n"
        "DB_CONNECTION=sqlite\n"
        "DB_PASSWORD=FAKE-NOT-A-REAL-SECRET\n"
        "SESSION_SECURE=true\n"
    )
    os.chown(env, 0, old.pw_gid)
    env.chmod(0o640)
    for child in ("storage", "uploads"):
        p = source / child
        p.mkdir(mode=0o700)
        os.chown(p, old.pw_uid, old.pw_gid)
    db = source / "storage" / "database.sqlite"
    with sqlite3.connect(str(db)) as con:
        con.execute("PRAGMA foreign_keys = ON")
        con.execute("CREATE TABLE item (id INTEGER PRIMARY KEY, value TEXT UNIQUE)")
        con.execute("INSERT INTO item(value) VALUES('synthetic-old-state')")
        con.execute("CREATE TABLE fixture_marker (id INTEGER PRIMARY KEY, value TEXT NOT NULL)")
        con.execute("INSERT INTO fixture_marker(id,value) VALUES(1,'ONLY-SYNTHETIC-TEST-DATA')")
    os.chown(db, old.pw_uid, old.pw_gid)
    db.chmod(0o600)
    lock = source / "storage" / "installed.lock"
    lock.write_text("synthetic-lock\n")
    os.chown(lock, old.pw_uid, old.pw_gid)
    lock.chmod(0o600)
    images = source / "uploads" / "images"
    images.mkdir(mode=0o700)
    os.chown(images, old.pw_uid, old.pw_gid)
    logo = images / "logo.bin"
    logo.write_bytes(b"synthetic-image-bytes")
    os.chown(logo, old.pw_uid, old.pw_gid)
    logo.chmod(0o600)

    before = test.fixture_inventory(root, old.pw_uid, old.pw_gid)
    state = test.stage(root, "www-data", "nobody")
    print("DETACHED_GENERATION_BUILT",state["staged_generation_verified"])
    print("FILES_HASHED",state["files_verified"])
    print("SOURCE_UNCHANGED",state["source_unchanged"])
    print("RELEASE_AUTHORIZED",state["release_authorized"])
    assert state["staged_generation_verified"] and not state["release_authorized"]
    assert test.fixture_inventory(root, old.pw_uid, old.pw_gid) == before
    candidate = root / "candidate"
    assert test.candidate_inventory(root, target.pw_uid, target.pw_gid) == before

    old_paths = [source / ".env", source / "storage/database.sqlite"]
    new_paths = [candidate / ".env", candidate / "storage/database.sqlite"]
    a = user_access("www-data", old_paths + new_paths)
    b = user_access("nobody", new_paths + old_paths)
    print("OLD_USER_OWN_DATA_READABLE",a[:2] == [True, True])
    print("OLD_USER_NEW_GENERATION_BLOCKED",a[2:] == [False, False])
    print("NEW_USER_OWN_DATA_READABLE",b[:2] == [True, True])
    print("NEW_USER_OLD_GENERATION_BLOCKED",b[2:] == [False, False])
    assert a == [True, True, False, False], a
    assert b == [True, True, False, False], b

    # Any data modified after copy must not be silently discarded.
    write = subprocess.run(
        ["runuser", "-u", "nobody", "--", "/usr/bin/python3", "-c",
         "import pathlib,sys;p=pathlib.Path(sys.argv[1]);p.write_bytes(b'new-synthetic-write');p.chmod(0o600)",
         str(candidate / "storage" / "post-stage.txt")],
        capture_output=True, text=True, timeout=7,
    )
    assert write.returncode == 0, "TARGET_OWNER_WRITE_FAILED"
    try:
        test.discard(root, "www-data", "nobody")
    except test.RehearsalError as exc:
        refused = str(exc) == "candidate_discard_content_mismatch"
    else:
        refused = False
    print("DISCARD_POST_STAGE_WRITES_FAILS_CLOSED",refused)
    print("SOURCE_STILL_INTACT_AFTER_REFUSAL",
          test.fixture_inventory(root, old.pw_uid, old.pw_gid) == before)
    assert refused and candidate.exists()
    (candidate / "storage" / "post-stage.txt").unlink()
    result = test.discard(root, "www-data", "nobody")
    print("EXACT_UNUSED_GENERATION_DISCARDED",result["staging_discarded"])
    assert not candidate.exists()
    assert test.fixture_inventory(root, old.pw_uid, old.pw_gid) == before

    # Malicious symlink, hardlink and an in-progress source mutation must block.
    symlink = source / "uploads" / "symlink"
    symlink.symlink_to("/etc/passwd")
    try:
        test.stage(root, "www-data", "nobody")
    except test.RehearsalError as exc:
        symlink_rejected = str(exc) == "fixture_symlink_forbidden"
    else:
        symlink_rejected = False
    symlink.unlink()
    print("SYMLINKED_SOURCE_REJECTED",symlink_rejected)
    assert symlink_rejected and not candidate.exists()

    hardlink = source / "uploads" / "linked-logo.bin"
    os.link(logo, hardlink)
    try:
        test.stage(root, "www-data", "nobody")
    except test.RehearsalError as exc:
        hardlink_rejected = str(exc) == "fixture_hardlink_forbidden"
    else:
        hardlink_rejected = False
    hardlink.unlink()
    print("HARDLINKED_SOURCE_REJECTED",hardlink_rejected)
    assert hardlink_rejected and not candidate.exists()

    env_original = env.read_text()
    env.write_text(env_original.replace("VAZINCMS_SYNTHETIC_REHEARSAL=1" + chr(10), ""))
    try:
        test.stage(root, "www-data", "nobody")
    except test.RehearsalError as exc:
        missing_env_rejected = str(exc) == "fixture_environment_not_synthetic"
    else:
        missing_env_rejected = False
    env.write_text(env_original)
    print("NON_SYNTHETIC_ENV_REJECTED", missing_env_rejected)
    assert missing_env_rejected and not candidate.exists()

    with sqlite3.connect(str(db)) as con:
        con.execute("UPDATE fixture_marker SET value='WRONG-MARKER' WHERE id=1")
    try:
        test.stage(root, "www-data", "nobody")
    except test.RehearsalError as exc:
        wrong_db_rejected = str(exc) == "fixture_database_not_synthetic"
    else:
        wrong_db_rejected = False
    with sqlite3.connect(str(db)) as con:
        con.execute("UPDATE fixture_marker SET value='ONLY-SYNTHETIC-TEST-DATA' WHERE id=1")
    print("NON_SYNTHETIC_DB_REJECTED", wrong_db_rejected)
    assert wrong_db_rejected and not candidate.exists()

    def simulate_writer():
        env.write_text(env.read_text() + "FAKE_NEW_CHANGE=1\n")
    try:
        test.stage(root, "www-data", "nobody", after_copy=simulate_writer)
    except test.RehearsalError as exc:
        mutation_rejected = str(exc) == "source_generation_mutated_during_copy"
    else:
        mutation_rejected = False
    print("SOURCE_WRITTEN_DURING_STAGE_REJECTED",mutation_rejected)
    print("NO_PARTIAL_CANDIDATE_LEFT",not candidate.exists())
    assert mutation_rejected and not candidate.exists()
    print("NO_PRODUCTION_PATHS_OR_SERVICE_CHANGES",True)
print("ALL_EPHEMERAL_RUNTIME_FILES_CLEANED",True)
