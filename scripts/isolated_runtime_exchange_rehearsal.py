#!/usr/bin/env python3
"""OP-02.7: atomic Unix runtime generation exchange, SYNTHETIC TMPFS ONLY.

Never use with customer data. In particular this is NOT an installer, an
approved recovery procedure, a production backup or a release authorization.
The journal models a crash window, but /dev/shm itself is NOT crash-durable.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import ctypes
import ctypes.util
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import uuid

import isolated_runtime_identity_generation as fixture

NO_GO = 70
JOURNAL = ".runtime-exchange-journal.json"
LOCK = ".runtime-exchange.lock"
STATES = {"prepared", "swapped", "rolled_back", "aborted"}
DIGEST = re.compile(r"^[a-f0-9]{64}$")
TX_ID = re.compile(r"^[a-f0-9]{32}$")
RENAME_EXCHANGE = 2
AT_FDCWD = -100


class ExchangeError(Exception):
    pass


def require_synthetic_root(root: Path) -> None:
    fixture.test_root(root)
    if os.geteuid() != 0:
        raise ExchangeError("root_required_inside_isolated_test")


def fsync_directory(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


@contextmanager
def exclusive_lock(root: Path):
    require_synthetic_root(root)
    path = root / LOCK
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1:
            raise ExchangeError("journal_lock_invalid")
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    except BlockingIOError as exc:
        raise ExchangeError("journal_lock_busy") from exc
    finally:
        os.close(fd)


def tree_digest(inventory: dict[str, str]) -> str:
    return hashlib.sha256(
        json.dumps(inventory, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()


def directory_id(path: Path) -> dict[str, int]:
    info = os.lstat(path)
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_dev != os.stat("/dev/shm").st_dev:
        raise ExchangeError("generation_directory_identity_invalid")
    return {"device": info.st_dev, "inode": info.st_ino}


def slot_order(root: Path, item: dict) -> str:
    first = directory_id(root / "source")
    second = directory_id(root / "candidate")
    if first == item["old_directory"] and second == item["new_directory"]:
        return "old_active"
    if first == item["new_directory"] and second == item["old_directory"]:
        return "new_active"
    raise ExchangeError("generation_slot_identity_mismatch")


def read_journal(root: Path) -> dict:
    path = root / JOURNAL
    try:
        info = os.lstat(path)
    except OSError as exc:
        raise ExchangeError("journal_missing") from exc
    if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
            or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600
            or info.st_nlink != 1 or info.st_size < 10 or info.st_size > 4096):
        raise ExchangeError("journal_security_invalid")
    with path.open("r", encoding="utf-8") as stream:
        try:
            item = json.load(stream)
        except (json.JSONDecodeError, UnicodeError) as exc:
            raise ExchangeError("journal_invalid_json") from exc
    required = {
        "schema", "tx", "phase", "old_user", "new_user", "old_uid", "old_gid",
        "new_uid", "new_gid", "old_directory", "new_directory",
        "old_hash", "new_hash",
    }
    if not isinstance(item, dict) or set(item) != required:
        raise ExchangeError("journal_schema_invalid")
    if (type(item["schema"]) is not int or item["schema"] != 1
            or not isinstance(item["phase"], str) or item["phase"] not in STATES
            or not isinstance(item["tx"], str) or not TX_ID.fullmatch(item["tx"])):
        raise ExchangeError("journal_phase_invalid")
    for key in ("old_hash", "new_hash"):
        if not isinstance(item[key], str) or not DIGEST.fullmatch(item[key]):
            raise ExchangeError("journal_digest_invalid")
    for key in ("old_directory", "new_directory"):
        value = item[key]
        if not isinstance(value, dict) or set(value) != {"device", "inode"} or not all(type(x) is int and x > 0 for x in value.values()):
            raise ExchangeError("journal_directory_identity_invalid")
    for user, uid, gid in (("old_user", "old_uid", "old_gid"), ("new_user", "new_uid", "new_gid")):
        if (type(item[uid]) is not int or type(item[gid]) is not int
                or not isinstance(item[user], str)
                or not re.fullmatch(r"[A-Za-z][A-Za-z0-9_-]{0,31}", item[user])):
            raise ExchangeError("journal_account_invalid")
        try:
            observed_account = fixture.account(item[user])
        except fixture.RehearsalError as exc:
            raise ExchangeError("journal_account_invalid") from exc
        if observed_account != (item[uid], item[gid]):
            raise ExchangeError("journal_account_mismatch")
    if item["old_uid"] == item["new_uid"] or item["old_gid"] == item["new_gid"]:
        raise ExchangeError("journal_users_not_distinct")
    return item


def write_journal(root: Path, item: dict) -> None:
    """Best effort fsync within tmpfs; NOT power-failure durability."""
    target = root / JOURNAL
    if target.is_symlink():
        raise ExchangeError("journal_symlinked")
    temporary = root / (".journal-" + uuid.uuid4().hex)
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as output:
            json.dump(item, output, sort_keys=True, separators=(",", ":"))
            output.write("\n")
            output.flush()
            os.fsync(output.fileno())
    except BaseException:
        temporary.unlink(missing_ok=True)
        raise
    os.replace(temporary, target)
    fsync_directory(root)


def atomic_exchange(root: Path) -> None:
    """Linux atomic directory-slot exchange using renameat2(RENAME_EXCHANGE)."""
    libc = ctypes.CDLL(ctypes.util.find_library("c"), use_errno=True)
    if not hasattr(libc, "renameat2"):
        raise ExchangeError("linux_renameat2_unavailable")
    swap = libc.renameat2
    swap.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
    swap.restype = ctypes.c_int
    left = os.fsencode(str(root / "source"))
    right = os.fsencode(str(root / "candidate"))
    if swap(AT_FDCWD, left, AT_FDCWD, right, RENAME_EXCHANGE) != 0:
        error = ctypes.get_errno()
        raise ExchangeError("atomic_directory_exchange_failed_errno_" + str(error))
    fsync_directory(root)


def pre_swap_verified(root: Path, item: dict) -> None:
    if slot_order(root, item) != "old_active":
        raise ExchangeError("old_generation_not_active")
    previous = fixture.fixture_inventory(root, item["old_uid"], item["old_gid"])
    proposed = fixture.candidate_inventory(root, item["new_uid"], item["new_gid"])
    if tree_digest(previous) != item["old_hash"] or tree_digest(proposed) != item["new_hash"]:
        raise ExchangeError("generation_changed_since_prepare")


def active_new_verified(root: Path, item: dict) -> None:
    if slot_order(root, item) != "new_active":
        raise ExchangeError("new_generation_not_active")
    # New-generation writes may differ from initial hashes. Its synthetic
    # provenance and owner/modes MUST still be valid before any rollback.
    fixture.fixture_inventory(root, item["new_uid"], item["new_gid"])
    preserved_old = fixture.candidate_inventory(root, item["old_uid"], item["old_gid"])
    if tree_digest(preserved_old) != item["old_hash"]:
        raise ExchangeError("preserved_old_generation_changed")


def rollback_verified(root: Path, item: dict) -> None:
    if slot_order(root, item) != "old_active":
        raise ExchangeError("rollback_slot_identity_invalid")
    unchanged_old = fixture.fixture_inventory(root, item["old_uid"], item["old_gid"])
    if tree_digest(unchanged_old) != item["old_hash"]:
        raise ExchangeError("rollback_old_generation_changed")
    # The NEW candidate may contain post-swap data; never delete or overwrite.
    fixture.candidate_inventory(root, item["new_uid"], item["new_gid"])


def prepare(root: Path, old_user: str, new_user: str) -> dict:
    with exclusive_lock(root):
        if (root / JOURNAL).exists() or (root / JOURNAL).is_symlink():
            raise ExchangeError("journal_already_exists")
        old_uid, old_gid = fixture.account(old_user)
        new_uid, new_gid = fixture.account(new_user)
        if old_uid == new_uid or old_gid == new_gid:
            raise ExchangeError("identity_not_distinct")
        before = fixture.fixture_inventory(root, old_uid, old_gid)
        after = fixture.candidate_inventory(root, new_uid, new_gid)
        if before != after:
            raise ExchangeError("candidate_content_differs_from_source")
        journal = {
            "schema": 1, "tx": uuid.uuid4().hex, "phase": "prepared",
            "old_user": old_user, "old_uid": old_uid, "old_gid": old_gid,
            "new_user": new_user, "new_uid": new_uid, "new_gid": new_gid,
            "old_directory": directory_id(root / "source"),
            "new_directory": directory_id(root / "candidate"),
            "old_hash": tree_digest(before), "new_hash": tree_digest(after),
        }
        write_journal(root, journal)
        return {"transaction_prepared": True, "source_unchanged": True}


def switch(root: Path, *, post_exchange_hook=None) -> dict:
    with exclusive_lock(root):
        item = read_journal(root)
        if item["phase"] != "prepared":
            raise ExchangeError("transaction_not_prepared")
        pre_swap_verified(root, item)
        atomic_exchange(root)
        if post_exchange_hook is not None:
            post_exchange_hook()
        # A SIGKILL before this journal write leaves the 'prepared' phase,
        # but inode mapping will correctly identify the already-swapped state.
        active_new_verified(root, item)
        item["phase"] = "swapped"
        write_journal(root, item)
        return {"switched_in_synthetic_tmpfs": True, "journal_phase": "swapped"}


def restore(root: Path) -> dict:
    with exclusive_lock(root):
        item = read_journal(root)
        slots = slot_order(root, item)
        if slots == "old_active":
            if item["phase"] not in {"prepared", "swapped", "rolled_back", "aborted"}:
                raise ExchangeError("rollback_state_ambiguous")
            # This also handles interrupted rollback after renameat2 but
            # before the 'rolled_back' journal write.
            rollback_verified(root, item) if item["phase"] in {"swapped", "rolled_back"} else pre_swap_verified(root, item)
            item["phase"] = "rolled_back" if item["phase"] in {"swapped", "rolled_back"} else "aborted"
            write_journal(root, item)
            return {"historical_generation_active": True, "exchange_needed": False,
                    "new_generation_retained": True}
        active_new_verified(root, item)
        atomic_exchange(root)
        rollback_verified(root, item)
        item["phase"] = "rolled_back"
        write_journal(root, item)
        return {"historical_generation_active": True, "exchange_needed": True,
                "new_generation_retained": True}


def inspect(root: Path) -> dict:
    with exclusive_lock(root):
        item = read_journal(root)
        slots = slot_order(root, item)
        if slots == "new_active":
            active_new_verified(root, item)
        elif item["phase"] in {"prepared", "aborted"}:
            pre_swap_verified(root, item)
        else:
            rollback_verified(root, item)
        return {"observed_active_slot": slots, "journal_phase": item["phase"],
                "journal_may_lag_slot": (item["phase"] == "prepared" and slots == "new_active")
                  or (item["phase"] == "swapped" and slots == "old_active")}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["prepare", "switch", "recover", "status"])
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--from-user")
    parser.add_argument("--to-user")
    args = parser.parse_args(argv)
    try:
        if args.action == "prepare":
            if not args.from_user or not args.to_user:
                raise ExchangeError("explicit_test_accounts_required")
            result = prepare(args.root, args.from_user, args.to_user)
        elif args.action == "switch":
            result = switch(args.root)
        elif args.action == "recover":
            result = restore(args.root)
        else:
            result = inspect(args.root)
    except (OSError, ValueError, fixture.RehearsalError, ExchangeError):
        print('{"decision":"NO_GO","release_authorized":false,"error":"synthetic_exchange_rehearsal_failed"}')
        return NO_GO
    print(json.dumps({"decision": "NO_GO", "release_authorized": False,
                      "production_durability_verified": False,
                      "worker_drain_verified": False,
                      **result}, sort_keys=True))
    return NO_GO


if __name__ == "__main__":
    sys.exit(main())
