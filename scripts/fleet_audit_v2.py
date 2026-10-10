#!/usr/bin/env python3
"""VazinCMS fleet audit v2 — read-only staging candidate; not a production gate.

Usage: python3 scripts/fleet_audit_v2.py --policy /private/fleet-policy.json

- Does not change files, services, versions, permissions, databases or DNS.
- Checks an explicit, per-site, approved release and health contract.
- Checks HTTPS with certificate verification, VERSION, MANIFEST.sha256 and hashes.
- Fails closed (exit 70) without BOTH policy_state=approved and an independently
  supplied SHA-256 fingerprint of the reviewed policy file.
- Neither policy approval nor manifest pin alone proves artifact signatures.
- Never modifies files, deployments, database or DNS.

The example policy is an observation, NOT an authorization to treat old versions
as desired. Keep real fleet inventory private: it contains server path topology.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import ssl
import sys
from typing import Any, Callable
import urllib.parse
import urllib.request

MANIFEST_ROW = re.compile(r"^([a-f0-9]{64})  ([^\x00-\x1f\x7f]+)$")
HOSTNAME = re.compile(r"^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$", re.I)
VERSION = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9.-]+)?$")


class PolicyError(Exception):
    """Invalid or unsafe audit policy."""


def _file_sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _expected_string(site: dict[str, Any], name: str) -> str:
    value = site.get(name)
    if not isinstance(value, str) or not value.strip():
        raise PolicyError(f"Site {site.get('id', '?')} must define {name}")
    return value.strip()


def validate_policy(policy: dict[str, Any]) -> None:
    if not isinstance(policy, dict) or policy.get("schema") != 1:
        raise PolicyError("Policy schema must equal 1")
    if policy.get("policy_state") not in {"candidate", "approved"}:
        raise PolicyError("policy_state must be candidate or approved")
    sites = policy.get("sites")
    if not isinstance(sites, list) or not (1 <= len(sites) <= 25):
        raise PolicyError("sites must be a non-empty list of up to 25 entries")
    seen = set()
    for site in sites:
        if not isinstance(site, dict):
            raise PolicyError("Each site must be an object")
        ident = _expected_string(site, "id")
        host = _expected_string(site, "host").lower()
        root = _expected_string(site, "root")
        rel = _expected_string(site, "release_version")
        health_ver = _expected_string(site, "health_version")
        _expected_string(site, "health_service")
        if ident in seen:
            raise PolicyError(f"Duplicate site id: {ident}")
        seen.add(ident)
        if not HOSTNAME.fullmatch(host) or "." not in host or len(host) > 253:
            raise PolicyError(f"Invalid hostname for {ident}")
        if not Path(root).is_absolute() or "\x00" in root:
            raise PolicyError(f"Invalid absolute root for {ident}")
        if not VERSION.fullmatch(rel) or not VERSION.fullmatch(health_ver):
            raise PolicyError(f"Invalid version for {ident}")
        pin = site.get("manifest_sha256")
        if pin is not None and (not isinstance(pin, str) or re.fullmatch(r"[0-9a-f]{64}", pin) is None):
            raise PolicyError(f"Invalid manifest_sha256 for {ident}")


def check_manifest(root: Path, pin: str | None = None) -> tuple[int, str]:
    """Validate the manifest, referenced paths, and content hashes without writes."""
    realroot = root.resolve(strict=True)
    manifest = realroot / "MANIFEST.sha256"
    if not manifest.is_file():
        raise ValueError("manifest_missing")
    if manifest.is_symlink():
        raise ValueError("manifest_symlink_rejected")
    if pin and _file_sha256(manifest) != pin:
        raise ValueError("manifest_pin_mismatch")
    seen = set()
    count = 0
    with manifest.open("r", encoding="utf-8") as f:
        for rawline in f:
            line = rawline.rstrip("\r\n")
            match = MANIFEST_ROW.fullmatch(line)
            if match is None:
                raise ValueError("manifest_syntax_invalid")
            expected, rel = match.groups()
            pp = PurePosixPath(rel)
            if pp.is_absolute() or any(s in {".", ".."} for s in rel.split("/")) or "\\" in rel:
                raise ValueError("manifest_path_unsafe")
            if rel in seen:
                raise ValueError("manifest_duplicate_entry")
            seen.add(rel)
            entry = realroot / rel
            if entry.is_symlink():
                raise ValueError("manifest_entry_symlink:" + rel)
            target = entry.resolve(strict=True)
            if os.path.commonpath((str(realroot), str(target))) != str(realroot) or not target.is_file():
                raise ValueError("manifest_path_outside_release")
            if _file_sha256(target) != expected:
                raise ValueError("manifest_file_hash_mismatch:" + rel)
            count += 1
    if count == 0:
        raise ValueError("manifest_empty")
    return count, _file_sha256(manifest)


def load_health(site: dict[str, Any], timeout: float = 8.0) -> dict[str, Any]:
    # Only a plain HTTPS public health endpoint; no URL, token, or custom
    # certificate trust is supplied by a policy file.
    host = site["host"].lower()
    url = urllib.parse.urlunsplit(("https", host, "/health", "", ""))
    request = urllib.request.Request(url, headers={"Accept": "application/json"}, method="GET")
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, msg, headers, newurl):
            raise ValueError("health_redirect_not_allowed")
    opener = urllib.request.build_opener(
        urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        NoRedirect(),
    )
    with opener.open(request, timeout=timeout) as response:
        if response.status != 200:
            raise ValueError("health_http_non_200")
        raw = response.read(8193)
        if len(raw) > 8192:
            raise ValueError("health_response_too_large")
        obj = json.loads(raw)
    if not isinstance(obj, dict):
        raise ValueError("health_not_json_object")
    return obj


def audit_site(site: dict[str, Any], get_health: Callable[[dict[str, Any]], dict[str, Any]]) -> dict[str, Any]:
    errors: list[str] = []
    root = Path(site["root"])
    actual_version = None
    found_service = None
    found_version = None
    checked_files = 0
    manifest_sha = None
    try:
        actual_version = (root / "VERSION").read_text(encoding="utf-8").strip()
        if actual_version != site["release_version"]:
            errors.append("release_version_mismatch")
    except (OSError, UnicodeError) as exc:
        errors.append("version_unavailable:" + type(exc).__name__)
    try:
        health = get_health(site)
        found_service = health.get("service")
        found_version = health.get("version")
        if health.get("ok") is not True:
            errors.append("health_not_ok")
        if found_service != site["health_service"]:
            errors.append("health_service_mismatch")
        if found_version != site["health_version"]:
            errors.append("health_version_mismatch")
    except Exception as exc:
        errors.append("health_unavailable:" + type(exc).__name__)
    try:
        checked_files, manifest_sha = check_manifest(root, site.get("manifest_sha256"))
    except Exception as exc:
        errors.append("manifest_failed:" + str(exc)[:100])
    return {
        "id": site["id"],
        "host": site["host"],
        "status": "PASS" if not errors else "FAIL",
        "expected_release_version": site["release_version"],
        "actual_release_version": actual_version,
        "expected_health_service": site["health_service"],
        "actual_health_service": found_service,
        "expected_health_version": site["health_version"],
        "actual_health_version": found_version,
        "manifest_files_checked": checked_files,
        "manifest_sha256": manifest_sha,
        "manifest_pinned": bool(site.get("manifest_sha256")),
        "errors": errors,
    }


def audit_policy(
    policy: dict[str, Any],
    get_health: Callable[[dict[str, Any]], dict[str, Any]],
    approval_digest_verified: bool = False,
) -> tuple[dict[str, Any], int]:
    validate_policy(policy)
    sites = [audit_site(site, get_health) for site in policy["sites"]]
    all_valid = all(site["status"] == "PASS" for site in sites)
    all_pinned = all(site["manifest_pinned"] for site in sites)
    approved = policy["policy_state"] == "approved"
    authorized = approved and approval_digest_verified
    report = {
        "schema": 1,
        "policy_state": policy["policy_state"],
        "policy_approved": approved,
        "approval_digest_verified": approval_digest_verified,
        "all_sites_pass": all_valid,
        "all_manifests_pinned": all_pinned,
        "decision": "PASS" if authorized and all_valid and all_pinned else "NO_GO",
        "reasons": ([] if approved else ["unapproved_observations_not_authoritative"]) + ([] if approval_digest_verified else ["independent_policy_digest_missing_or_mismatched"]) + ([] if all_valid else ["one_or_more_checks_failed"]) + ([] if all_pinned else ["unanchored_manifest"]),
        "sites": sites,
    }
    return report, 0 if authorized and all_valid and all_pinned else 70


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Read-only multi-contract fleet audit (candidate)")
    ap.add_argument("--policy", required=True, help="JSON policy explicitly defining approved per-site versions and health contracts")
    ap.add_argument("--timeout", type=float, default=8.0)
    ap.add_argument("--approved-policy-sha256", help="SHA-256 from an independent owner-approved source, NOT from the same policy JSON")
    args = ap.parse_args(argv)
    if not (1 <= args.timeout <= 30):
        ap.error("timeout must be 1–30 seconds")
    try:
        raw_policy = Path(args.policy).read_bytes()
        policy = json.loads(raw_policy)
        digest = args.approved_policy_sha256
        if digest is not None and not re.fullmatch(r"[0-9a-f]{64}", digest):
            raise PolicyError("invalid_approved_policy_sha256")
        verified = bool(digest and hashlib.sha256(raw_policy).hexdigest() == digest)
        report, code = audit_policy(policy, lambda site: load_health(site, args.timeout), verified)
        print(json.dumps(report, ensure_ascii=False, indent=2))
        return code
    except (OSError, ValueError, PolicyError) as exc:
        print(json.dumps({"decision": "NO_GO", "error": type(exc).__name__ + ": " + str(exc)[:250]}, ensure_ascii=False))
        return 70


if __name__ == "__main__":
    sys.exit(main())
