#!/usr/bin/env python3
"""Read-only CMS per-site Unix ownership and service-identity audit.

No .env reads, no chown, no FPM configuration, no installer execution.
Release approval can never come from ownership inspection alone.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import stat
import sys

NO_GO = 70
SITE_NAMES = ("travel-vazin-online", "visa-vazin-online", "studio-vazin-online")
LABELS = ("code_root", "environment", "runtime_data", "storage", "uploads", "database")
NEEDED_HARDCODED = (
    "id -u www-data",
    "id -g www-data",
    "runuser -u www-data",
    "chown root:www-data",
)


def file_metadata(path: Path) -> dict:
    # lstat(final) alone follows a symlink in any parent component.
    # Refuse such a path without reading redirected file contents.
    if not path.is_absolute():
        return {"exists": False, "mode": None, "uid": None, "gid": None, "kind": "unsafe_relative_path"}
    for parent in path.parents:
        try:
            entry = os.lstat(parent)
        except OSError:
            return {"exists": False, "mode": None, "uid": None, "gid": None, "kind": "parent_unavailable"}
        if stat.S_ISLNK(entry.st_mode):
            return {"exists": False, "mode": None, "uid": None, "gid": None, "kind": "symlinked_ancestor"}
    try:
        info = os.lstat(path)
    except OSError:
        return {"exists": False, "mode": None, "uid": None, "gid": None, "kind": "absent"}
    if stat.S_ISLNK(info.st_mode):
        kind = "symlink"
    elif stat.S_ISDIR(info.st_mode):
        kind = "directory"
    elif stat.S_ISREG(info.st_mode):
        kind = "file"
    else:
        kind = "other"
    return {
        "exists": True,
        "mode": f"{stat.S_IMODE(info.st_mode):04o}",
        "uid": info.st_uid,
        "gid": info.st_gid,
        "kind": kind,
    }


def inspect_site(site_root: Path, runtime: Path, name: str) -> dict:
    if name not in SITE_NAMES:
        raise ValueError("site_name_not_allowlisted")
    paths = {
        "code_root": site_root,
        "environment": site_root / ".env",
        "runtime_data": runtime,
        "storage": runtime / "storage",
        "uploads": runtime / "uploads",
        "database": runtime / "storage" / "database.sqlite",
    }
    observed = {label: file_metadata(path) for label, path in paths.items()}
    blockers = []
    for label, meta in observed.items():
        expected_kind = "directory" if label in ("code_root", "runtime_data", "storage", "uploads") else "file"
        if meta["kind"] != expected_kind:
            blockers.append("missing_or_unsafe_" + label)
    env = observed["environment"]
    db = observed["database"]
    storage = observed["storage"]
    uploads = observed["uploads"]
    if env["exists"] and env["mode"] != "0640":
        blockers.append("environment_access_mode_unreviewed")
    if env["exists"] and env["uid"] != 0:
        blockers.append("environment_not_root_owned")
    if storage["exists"] and storage["mode"] != "0700":
        blockers.append("storage_access_mode_unreviewed")
    if uploads["exists"] and uploads["mode"] != "0700":
        blockers.append("uploads_access_mode_unreviewed")
    if db["exists"] and db["mode"] != "0600":
        blockers.append("database_access_mode_unreviewed")
    if db["exists"] and storage["exists"] and db["uid"] != storage["uid"]:
        blockers.append("database_storage_owner_mismatch")
    return {
        "site_key": name,
        "observed": observed,
        "blockers": sorted(set(blockers)),
        "per_site_identity_acceptance": False,
    }


def inspect_fleet(sites_base: Path, runtime_base: Path, installer: Path) -> dict:
    sites = []
    for name in SITE_NAMES:
        sites.append(inspect_site(sites_base / name, runtime_base / name / "data", name))
    owners = [site["observed"]["storage"]["uid"] for site in sites]
    blockers = [
        "php_fpm_pool_identity_not_authenticated",
        "installer_runtime_migration_not_certified",
        "inflight_requests_and_background_jobs_not_quiesced",
        "separate_fpm_masters_and_socket_acl_not_proven",
    ]
    if None in owners or len(set(owners)) != len(owners):
        blockers.append("storage_service_uid_shared_or_missing")
    try:
        text = installer.read_text(encoding="utf-8")
    except (OSError, UnicodeError):
        text = ""
        blockers.append("installer_source_unavailable")
    count = sum(text.count(pattern) for pattern in NEEDED_HARDCODED)
    if count:
        blockers.append("installer_hardcodes_shared_www_data_identity")
    elif not text:
        blockers.append("installer_identity_policy_unverified")
    # No read of environment or database contents. No full private paths in JSON.
    return {
        "schema": 1,
        "decision": "NO_GO",
        "release_authorized": False,
        "requires_new_site_identity_design": bool(count),
        "generic_identity_markers": count,
        "shared_runtime_uid_detected": None not in owners and len(set(owners)) < len(owners),
        "global_blockers": sorted(blockers),
        "sites": sites,
        "source_kind": "readonly_stat_no_env_content_or_db_connection",
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--sites-base", type=Path, default=Path("/var/www/vazin-sites"))
    parser.add_argument("--runtime-base", type=Path, default=Path("/var/lib/vazincms-runtime"))
    parser.add_argument("--installer", type=Path, default=Path(__file__).resolve().parents[1] / "deploy/install.sh")
    args = parser.parse_args(argv)
    try:
        result = inspect_fleet(args.sites_base, args.runtime_base, args.installer)
    except (OSError, ValueError):
        print('{"decision":"NO_GO","release_authorized":false,"error":"identity_metadata_unavailable"}')
        return NO_GO
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return NO_GO


if __name__ == "__main__":
    sys.exit(main())
