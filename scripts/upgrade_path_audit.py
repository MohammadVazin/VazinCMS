#!/usr/bin/env python3
"""Read-only, deliberately NON-AUTHORIZING VazinCMS upgrade-chain audit.

This tool does not run installers, write to the host, approve a release,
verify publisher signatures, or turn a historical install into a release asset.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path, PurePosixPath
from typing import Any

VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")
MANIFEST_RE = re.compile(r"^([0-9a-f]{64})  ([^\x00-\x1f\x7f]+)$")
MIGRATION_RE = re.compile(r"^([0-9]+\.[0-9]+\.[0-9]+)-(sqlite|pgsql)\.sql$")
INSTALL_OLD_RE = re.compile(r"^OLD_VERSION='([^']+)'$", re.M)
INSTALL_NEW_RE = re.compile(r"^NEW_VERSION='([^']+)'$", re.M)


def as_tuple(s: str) -> tuple[int, int, int]:
    if VERSION_RE.fullmatch(s) is None:
        raise ValueError("invalid_semver")
    return tuple(map(int, s.split(".")))  # type: ignore[return-value]


def file_hash(p: Path) -> str:
    with p.open("rb") as fp:
        return hashlib.file_digest(fp, "sha256").hexdigest()


def safe_file(root: Path, relative: str) -> Path:
    path = PurePosixPath(relative)
    if (path.is_absolute() or relative.startswith("./") or relative.endswith("/")
            or "\\" in relative or any(part in ("..", ".", "") for part in relative.split("/"))):
        raise ValueError("unsafe_path")
    here = root
    for part in path.parts:
        here = here / part
        if here.is_symlink():
            raise ValueError("symlink_path")
    if not here.is_file():
        raise ValueError("missing_manifest_file")
    if not here.resolve().is_relative_to(root):
        raise ValueError("escaped_release_root")
    return here


def manifest_audit(root: Path) -> tuple[dict[str, str], list[str]]:
    errors: list[str] = []
    entries: dict[str, str] = {}
    manifest = root / "MANIFEST.sha256"
    if not manifest.is_file() or manifest.is_symlink():
        return entries, ["manifest_missing_or_symlink"]
    try:
        lines = manifest.read_text(encoding="utf-8").splitlines()
        order: list[str] = []
        for line in lines:
            match = MANIFEST_RE.fullmatch(line)
            if match is None:
                errors.append("manifest_syntax_invalid")
                break
            expected, relative = match.groups()
            if relative in entries:
                errors.append("manifest_duplicate_path:" + relative)
                break
            entries[relative] = expected
            order.append(relative)
            try:
                actual = file_hash(safe_file(root, relative))
                if actual != expected:
                    errors.append("manifest_file_hash_mismatch:" + relative)
            except (ValueError, OSError) as exc:
                errors.append("manifest_file_invalid:" + relative + ":" + str(exc))
        if order != sorted(order):
            errors.append("manifest_not_canonical_order")
        for essential in ("VERSION", "vazin-package.json", "deploy/install.sh", "deploy/rollback.sh"):
            if essential not in entries:
                errors.append("manifest_missing_required:" + essential)
        if not entries:
            errors.append("manifest_empty")
    except (UnicodeError, OSError) as exc:
        errors.append("manifest_unreadable:" + type(exc).__name__)
    return entries, errors


def release_info(version: str, directory: Path) -> dict[str, Any]:
    errors: list[str] = []
    warnings: list[str] = []
    result: dict[str, Any] = {
        "version": version,
        "artifact_label": directory.name,
        "manifest_files": 0,
        "manifest_sha256": None,
        "errors": errors,
        "warnings": warnings,
        "migrations": {},
    }
    if not directory.is_absolute() or not directory.is_dir() or directory.is_symlink():
        errors.append("artifact_directory_missing_or_noncanonical")
        return result
    real = directory.resolve()
    entries, manifest_errors = manifest_audit(real)
    errors.extend(manifest_errors)
    result["manifest_files"] = len(entries)
    if (real / "MANIFEST.sha256").is_file():
        result["manifest_sha256"] = file_hash(real / "MANIFEST.sha256")
    version_file = real / "VERSION"
    if version_file.is_file():
        observed_version = version_file.read_text(encoding="utf-8").strip()
        if observed_version != version:
            errors.append("version_file_mismatch")
    else:
        errors.append("version_file_missing")
    manifest = real / "vazin-package.json"
    package: dict[str, Any] = {}
    try:
        loaded = json.loads(manifest.read_text(encoding="utf-8"))
        if not isinstance(loaded, dict):
            raise ValueError("package_not_object")
        package = loaded
    except (OSError, UnicodeError, ValueError) as exc:
        errors.append("package_invalid:" + type(exc).__name__)
    if package:
        if package.get("version") != version:
            errors.append("package_version_mismatch")
        if package.get("product") != "VazinCMS":
            errors.append("package_product_mismatch")
        if package.get("upgrade_only") is not True:
            errors.append("package_upgrade_only_not_true")
    result["package_minimum_current"] = package.get("minimum_current_version")
    supported = package.get("supported_current_versions")
    if not isinstance(supported, list) or any(not isinstance(x, str) for x in supported):
        supported = []
        errors.append("supported_current_versions_invalid")
    result["package_supported_current"] = supported
    installer = real / "deploy/install.sh"
    try:
        source = installer.read_text(encoding="utf-8")
        before = INSTALL_OLD_RE.findall(source)
        after = INSTALL_NEW_RE.findall(source)
        result["installer_old_version"] = before[0] if len(before) == 1 else None
        result["installer_new_version"] = after[0] if len(after) == 1 else None
        if len(before) != 1 or len(after) != 1:
            errors.append("installer_version_contract_ambiguous")
        elif after[0] != version:
            errors.append("installer_target_mismatch")
    except (OSError, UnicodeError):
        errors.append("installer_unreadable")
    # Migration list and digest to detect silent schema drift in an upgrade chain.
    migrations: dict[str, str] = {}
    mdir = real / "database/migrations"
    if not mdir.is_dir():
        errors.append("migration_directory_missing")
    else:
        for f in mdir.iterdir():
            match = MIGRATION_RE.fullmatch(f.name)
            if not match:
                continue
            relative = "database/migrations/" + f.name
            if f.is_symlink() or not f.is_file():
                errors.append("migration_symlink_or_missing:" + f.name)
                continue
            if relative not in entries:
                errors.append("migration_outside_manifest:" + f.name)
            migrations[f.name] = file_hash(f)
        sql_versions = {n.split("-", 1)[0] for n in migrations if n.endswith("-sqlite.sql")}
        pg_versions = {n.split("-", 1)[0] for n in migrations if n.endswith("-pgsql.sql")}
        if sql_versions != pg_versions:
            errors.append("sqlite_pgsql_migration_parity_mismatch")
    result["migrations"] = migrations
    return result


def audit_chain(start: str, target: str, release_paths: dict[str, Path]) -> dict[str, Any]:
    errors: list[str] = []
    warnings: list[str] = []
    try:
        as_tuple(start)
        as_tuple(target)
        versions = sorted(release_paths, key=as_tuple)
    except ValueError:
        versions = []
        errors.append("invalid_chain_semver")
    if not versions or versions[0] != start or versions[-1] != target or len(versions) < 2:
        errors.append("incomplete_or_misordered_chain")
    if len(versions) >= 2 and as_tuple(start) >= as_tuple(target):
        errors.append("non_increasing_target")
    releases = [release_info(v, release_paths[v]) for v in versions]
    steps: list[dict[str, Any]] = []
    for i in range(1, len(releases)):
        prev, now = releases[i - 1], releases[i]
        src, dst = prev["version"], now["version"]
        issues: list[str] = []
        notes: list[str] = []
        if now.get("installer_old_version") != src:
            issues.append("installer_requires_different_predecessor")
        if src not in now.get("package_supported_current", []):
            issues.append("predecessor_not_in_package_supported_list")
        minimum = now.get("package_minimum_current")
        try:
            if not isinstance(minimum, str) or as_tuple(minimum) > as_tuple(src):
                issues.append("package_minimum_excludes_predecessor")
            if minimum != src:
                notes.append("minimum_current_differs_from_installer_old_version")
        except ValueError:
            issues.append("invalid_package_minimum_current")
        previous_migrations = prev.get("migrations", {})
        current_migrations = now.get("migrations", {})
        removed = sorted(set(previous_migrations) - set(current_migrations))
        changed = sorted(n for n, digest in previous_migrations.items()
                         if n in current_migrations and current_migrations[n] != digest)
        if removed:
            issues.append("removed_migration_files:" + str(len(removed)))
        if changed:
            issues.append("changed_historical_migrations:" + str(len(changed)))
        step = {"from": src, "to": dst, "contract": "PASS" if not issues else "FAIL",
                "errors": issues, "warnings": notes,
                "added_migrations": sorted(set(current_migrations) - set(previous_migrations)),
                "removed_migrations_count": len(removed), "changed_migrations_count": len(changed)}
        steps.append(step)
        errors.extend(dst + ":" + x for x in issues)
        warnings.extend(dst + ":" + x for x in notes)
    for release in releases:
        errors.extend(release["version"] + ":" + x for x in release["errors"])
        warnings.extend(release["version"] + ":" + x for x in release["warnings"])
        release.pop("migrations", None)  # Do not print large lists; steps summarize them.
    return {
        "audit_kind": "offline_upgrade_path_contract_only",
        "start": start,
        "target": target,
        "chain_contract": "PASS" if not errors else "FAIL",
        "release_decision": "NO_GO",  # No independent signatures, staging, authorization.
        "releases": releases,
        "steps": steps,
        "errors": errors,
        "warnings": warnings,
        "missing_acceptance": [
            "independently_verified_signed_intermediate_packages",
            "owner_approved_target_versions_and_artifact_pins",
            "database_and_runtime_migration_drill_per_site",
            "restore_and_rollback_verification",
            "real_tls_and_route_acceptance",
        ],
    }


def cli(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--start", required=True)
    parser.add_argument("--target", required=True)
    parser.add_argument("--release", action="append", default=[], metavar="VERSION=ABS_DIR",
                        help="A local historical release directory; no files are modified")
    args = parser.parse_args(argv)
    try:
        release_paths: dict[str, Path] = {}
        for item in args.release:
            if "=" not in item:
                raise ValueError("release_argument_missing_equals")
            v, raw_path = item.split("=", 1)
            as_tuple(v)
            if v in release_paths:
                raise ValueError("duplicate_release_argument")
            p = Path(raw_path)
            if not p.is_absolute():
                raise ValueError("release_path_must_be_absolute")
            release_paths[v] = p
        report = audit_chain(args.start, args.target, release_paths)
    except ValueError as exc:
        report = {"audit_kind": "offline_upgrade_path_contract_only", "chain_contract": "FAIL",
                  "release_decision": "NO_GO", "errors": [str(exc)]}
    print(json.dumps(report, ensure_ascii=False, separators=(",", ":"), sort_keys=True))
    # The tool intentionally cannot become a release authorization just
    # because a caller supplies internally consistent local directories.
    return 70


if __name__ == "__main__":
    sys.exit(cli())
