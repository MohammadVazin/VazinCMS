#!/usr/bin/env python3
"""OP-02.6 -- OFFLINE, SYNTHETIC-ONLY Unix identity generation rehearsal.

NEVER operates on a production path. Requires a root-owned temporary
/vazincms-identity-rehearsal-* directory in /dev/shm and a root-only marker.
Does NOT modify the historical source, configure FPM, swap live paths, or
grant release permission. Not designed for real customer files.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import sqlite3
import stat
import sys
import tempfile

MANUAL = 70
MARKER = b"VAZINCMS-SYNTHETIC-IDENTITY-REHEARSAL-v1\n"
TEST_PREFIX = "vazincms-identity-rehearsal-"
MAX_FILES = 5000
MAX_BYTES = 64 * 1024 * 1024


class RehearsalError(Exception):
    pass


def no_symlink_components(path: Path) -> None:
    if not path.is_absolute() or ".." in path.parts:
        raise RehearsalError("invalid_absolute_path")
    for ancestor in (path, *path.parents):
        try:
            info = os.lstat(ancestor)
        except OSError as exc:
            raise RehearsalError("missing_path_component") from exc
        if stat.S_ISLNK(info.st_mode):
            raise RehearsalError("path_component_symlinked")


def test_root(root: Path) -> None:
    """Explicit fixture marker + tmpfs device; cannot target real S17 paths."""
    no_symlink_components(root)
    if root.parent != Path("/dev/shm") or not root.name.startswith(TEST_PREFIX):
        raise RehearsalError("production_path_forbidden")
    info = os.lstat(root)
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o711:
        raise RehearsalError("test_root_ownership_invalid")
    if info.st_dev != os.stat("/dev/shm").st_dev:
        raise RehearsalError("test_root_not_tmpfs")
    marker = root / ".synthetic-only"
    no_symlink_components(marker)
    item = os.lstat(marker)
    if (not stat.S_ISREG(item.st_mode) or item.st_uid != 0
            or stat.S_IMODE(item.st_mode) != 0o600 or item.st_nlink != 1):
        raise RehearsalError("test_marker_not_root_private")
    if marker.read_bytes() != MARKER:
        raise RehearsalError("test_marker_invalid")


def account(name: str) -> tuple[int, int]:
    if not name.isascii() or not name.replace("-", "").replace("_", "").isalnum():
        raise RehearsalError("invalid_test_unix_user")
    try:
        user = pwd.getpwnam(name)
    except KeyError as exc:
        raise RehearsalError("test_account_missing") from exc
    if user.pw_uid == 0 or user.pw_gid == 0:
        raise RehearsalError("root_target_forbidden")
    return user.pw_uid, user.pw_gid


def safe_item(path: Path, *, kind: str, uid: int, gid: int, mode: int) -> os.stat_result:
    # Do not follow symbolic links, hard links, device files or sockets.
    if path.is_symlink():
        raise RehearsalError("fixture_symlink_forbidden")
    try:
        info = os.lstat(path)
    except OSError as exc:
        raise RehearsalError("fixture_item_missing") from exc
    expected = stat.S_ISDIR if kind == "dir" else stat.S_ISREG
    if not expected(info.st_mode) or (info.st_uid, info.st_gid) != (uid, gid):
        raise RehearsalError("fixture_owner_or_type_mismatch")
    if stat.S_IMODE(info.st_mode) != mode:
        raise RehearsalError("fixture_mode_mismatch")
    if kind == "file" and info.st_nlink != 1:
        raise RehearsalError("fixture_hardlink_forbidden")
    if info.st_dev != os.stat("/dev/shm").st_dev:
        raise RehearsalError("fixture_not_in_tmpfs")
    return info


def fixture_inventory(root: Path, old_uid: int, old_gid: int) -> dict[str, str]:
    """Snapshot content of an intentionally synthetic fixture, no values logged."""
    source = root / "source"
    safe_item(source, kind="dir", uid=0, gid=old_gid, mode=0o750)
    safe_item(source / ".env", kind="file", uid=0, gid=old_gid, mode=0o640)
    files: dict[str, str] = {}
    total_bytes = 0
    allowed = {"storage", "uploads", ".env"}
    unexpected = {p.name for p in source.iterdir()} - allowed
    if unexpected:
        raise RehearsalError("unexpected_fixture_entries")
    for folder in ("storage", "uploads"):
        base = source / folder
        safe_item(base, kind="dir", uid=old_uid, gid=old_gid, mode=0o700)
        for here, dirs, entries in os.walk(base, topdown=True, followlinks=False):
            directory = Path(here)
            for entry in dirs:
                safe_item(directory / entry, kind="dir", uid=old_uid, gid=old_gid, mode=0o700)
            for entry in entries:
                path = directory / entry
                info = safe_item(path, kind="file", uid=old_uid, gid=old_gid, mode=0o600)
                total_bytes += info.st_size
                if len(files) >= MAX_FILES or total_bytes > MAX_BYTES:
                    raise RehearsalError("fixture_size_limit_exceeded")
                rel = path.relative_to(source).as_posix()
                with path.open("rb") as stream:
                    files[rel] = hashlib.file_digest(stream, "sha256").hexdigest()
    if "storage/database.sqlite" not in files or "storage/installed.lock" not in files:
        raise RehearsalError("required_test_database_or_lock_missing")
    env_info = os.lstat(source / ".env")
    if env_info.st_size > 65536:
        raise RehearsalError("fixture_environment_too_large")
    with (source / ".env").open("rb") as stream:
        environment_data = stream.read()
    # Enforce fixture provenance beyond the parent marker: never copy a
    # real site environment merely moved into the /dev/shm staging root.
    if b"VAZINCMS_SYNTHETIC_REHEARSAL=1" not in environment_data.splitlines():
        raise RehearsalError("fixture_environment_not_synthetic")
    files[".env"] = hashlib.sha256(environment_data).hexdigest()
    # Database integrity is checked only on the test fixture, never live SQLite.
    db = source / "storage" / "database.sqlite"
    connection = sqlite3.connect(db.resolve().as_uri() + "?mode=ro", uri=True, timeout=2)
    try:
        if connection.execute("PRAGMA integrity_check").fetchone() != ("ok",):
            raise RehearsalError("fixture_database_integrity_failure")
        if connection.execute("PRAGMA foreign_key_check").fetchall():
            raise RehearsalError("fixture_database_foreign_key_failure")
        try:
            marker_row = connection.execute(
                "SELECT value FROM fixture_marker WHERE id = 1"
            ).fetchone()
        except sqlite3.Error as exc:
            raise RehearsalError("fixture_database_not_synthetic") from exc
        if marker_row != ("ONLY-SYNTHETIC-TEST-DATA",):
            raise RehearsalError("fixture_database_not_synthetic")
    finally:
        connection.close()
    return dict(sorted(files.items()))


def copy_tree(source: Path, destination: Path, target_uid: int, target_gid: int) -> None:
    os.mkdir(destination, 0o700)
    os.chown(destination, 0, target_gid)
    for folder in ("storage", "uploads"):
        for here, directories, files in os.walk(source / folder, topdown=True, followlinks=False):
            here_path = Path(here)
            relative = here_path.relative_to(source)
            out_dir = destination / relative
            out_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
            os.chown(out_dir, target_uid, target_gid)
            os.chmod(out_dir, 0o700)
            for name in directories:
                path = out_dir / name
                path.mkdir(mode=0o700)
                os.chown(path, target_uid, target_gid)
            for name in files:
                original = here_path / name
                safe = destination / relative / name
                # This tool only runs on marker-confirmed synthetic data.
                shutil.copyfile(original, safe, follow_symlinks=False)
                os.chown(safe, target_uid, target_gid, follow_symlinks=False)
                os.chmod(safe, 0o600)
    shutil.copyfile(source / ".env", destination / ".env", follow_symlinks=False)
    os.chown(destination / ".env", 0, target_gid, follow_symlinks=False)
    os.chmod(destination / ".env", 0o640)
    os.chmod(destination, 0o750)


def candidate_inventory(root: Path, uid: int, gid: int) -> dict[str, str]:
    destination = root / "candidate"
    safe_item(destination, kind="dir", uid=0, gid=gid, mode=0o750)
    safe_item(destination / ".env", kind="file", uid=0, gid=gid, mode=0o640)
    result = {}
    for folder in ("storage", "uploads"):
        base = destination / folder
        safe_item(base, kind="dir", uid=uid, gid=gid, mode=0o700)
        for here, dirs, names in os.walk(base, followlinks=False):
            here_path = Path(here)
            for name in dirs:
                safe_item(here_path / name, kind="dir", uid=uid, gid=gid, mode=0o700)
            for name in names:
                item = here_path / name
                safe_item(item, kind="file", uid=uid, gid=gid, mode=0o600)
                with item.open("rb") as stream:
                    result[item.relative_to(destination).as_posix()] = hashlib.file_digest(stream, "sha256").hexdigest()
    with (destination / ".env").open("rb") as stream:
        result[".env"] = hashlib.file_digest(stream, "sha256").hexdigest()
    return dict(sorted(result.items()))


def stage(root: Path, from_user: str, to_user: str, *, after_copy=None) -> dict:
    test_root(root)
    if os.geteuid() != 0:
        raise RehearsalError("root_required_in_synthetic_rehearsal")
    old_uid, old_gid = account(from_user)
    target_uid, target_gid = account(to_user)
    if old_uid == target_uid or old_gid == target_gid:
        raise RehearsalError("source_and_target_service_identity_not_distinct")
    if (root / "candidate").exists() or (root / "candidate").is_symlink():
        raise RehearsalError("candidate_already_present")
    before = fixture_inventory(root, old_uid, old_gid)
    staging = Path(tempfile.mkdtemp(prefix=".candidate-build-", dir=root))
    try:
        os.rmdir(staging)
        copy_tree(root / "source", staging, target_uid, target_gid)
        if after_copy is not None:
            after_copy()
        # Source mutation during stage is a hard NO-GO; detached copy is deleted.
        if fixture_inventory(root, old_uid, old_gid) != before:
            raise RehearsalError("source_generation_mutated_during_copy")
        # Candidate scanner expects the immutable final basename.
        staged_name = root / "candidate"
        os.rename(staging, staged_name)
        try:
            if candidate_inventory(root, target_uid, target_gid) != before:
                raise RehearsalError("candidate_content_mismatch")
            db = root / "candidate/storage/database.sqlite"
            with sqlite3.connect(db.resolve().as_uri() + "?mode=ro", uri=True) as con:
                if con.execute("PRAGMA integrity_check").fetchone() != ("ok",):
                    raise RehearsalError("candidate_database_not_integral")
        except BaseException:
            shutil.rmtree(staged_name)
            raise
        return {
            "decision": "NO_GO",
            "release_authorized": False,
            "source_unchanged": True,
            "staged_generation_verified": True,
            "files_verified": len(before),
            "from_uid": old_uid,
            "to_uid": target_uid,
            "actual_swap_performed": False,
            "job_drain_verified": False,
            "installer_updated": False,
        }
    finally:
        if staging.exists() and not staging.is_symlink():
            shutil.rmtree(staging)


def discard(root: Path, from_user: str, to_user: str) -> dict:
    test_root(root)
    old_uid, old_gid = account(from_user)
    target_uid, target_gid = account(to_user)
    source_snapshot = fixture_inventory(root, old_uid, old_gid)
    if candidate_inventory(root, target_uid, target_gid) != source_snapshot:
        raise RehearsalError("candidate_discard_content_mismatch")
    shutil.rmtree(root / "candidate")
    if fixture_inventory(root, old_uid, old_gid) != source_snapshot:
        raise RehearsalError("source_modified_during_discard")
    return {
        "decision": "NO_GO",
        "release_authorized": False,
        "staging_discarded": True,
        "historical_source_preserved": True,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Synthetic-only /dev/shm identity rehearsal")
    parser.add_argument("action", choices=("stage", "discard"))
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--from-user", required=True)
    parser.add_argument("--to-user", required=True)
    args = parser.parse_args(argv)
    try:
        result = stage(args.root, args.from_user, args.to_user) if args.action == "stage" else discard(args.root, args.from_user, args.to_user)
    except (OSError, sqlite3.Error, RehearsalError):
        print('{"decision":"NO_GO","release_authorized":false,"error":"identity_generation_rehearsal_failed"}')
        return MANUAL
    print(json.dumps(result, sort_keys=True))
    return MANUAL


if __name__ == "__main__":
    sys.exit(main())
