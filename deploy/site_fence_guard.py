#!/usr/bin/env python3
"""Non-mutating per-site Nginx fence attestation for external-runtime upgrades.

The fence must be provisioned OUTSIDE this installer by reviewed Nginx config.
The installer must never stop/reload the shared Nginx master or shared FPM.
"""
from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys

HOST = re.compile(r"^[a-z0-9](?:[a-z0-9.-]{0,250}[a-z0-9])?$")
TOKEN = re.compile(r"^[A-Za-z0-9_-]{32,96}$")
VERSION = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")
MANUAL = 70
# A public 503 on /health alone does not prove tenant-wide maintenance.
QA_PATH = "/__vazin_cms_health"
PUBLIC_PATHS = (
    "/health", "/", "/login", "/admin", "/api/v3/content",
    "/assets/app.js", QA_PATH,
)
PUBLIC_PROBES = tuple(("GET", path) for path in PUBLIC_PATHS) + (("POST", QA_PATH),)
CANARY_PREFIX = "/__vazin_fence_probe_"


def current_public_probes() -> tuple[tuple[str, str], ...]:
    # An unpredictable path detects a vhost that only fakes maintenance for
    # known fixed probe URLs. Sampling is NOT proof for every route/method.
    return PUBLIC_PROBES + (("GET", CANARY_PREFIX + secrets.token_hex(12)),)


class FenceError(Exception):
    pass


def read_token(token_file: Path, *, required_uid: int = 0) -> str:
    """O_NOFOLLOW + exact mode/ownership; never print token or file contents."""
    if not token_file.is_absolute() or ".." in token_file.parts:
        raise FenceError("fence_token_path_not_absolute_or_safe")
    if token_file.resolve(strict=True) != token_file:
        raise FenceError("fence_token_path_symlinked")
    # Reject writable ancestors unless root-owned and sticky, as is /dev/shm
    # during isolated tests.  A hostile parent could otherwise swap the path.
    for parent in (*reversed(token_file.parents),):
        info = os.lstat(parent)
        if not stat.S_ISDIR(info.st_mode):
            raise FenceError("fence_token_parent_not_directory")
        if info.st_mode & 0o022 and not (
            info.st_uid == 0 and info.st_mode & stat.S_ISVTX
        ):
            raise FenceError("fence_token_parent_writable")
    fd = os.open(token_file, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    try:
        item = os.fstat(fd)
        if not stat.S_ISREG(item.st_mode) or item.st_uid != required_uid or stat.S_IMODE(item.st_mode) != 0o600 or item.st_nlink != 1:
            raise FenceError("fence_token_ownership_or_permissions_invalid")
        data = os.read(fd, 110)
        if os.read(fd, 1):
            raise FenceError("fence_token_oversized")
    finally:
        os.close(fd)
    try:
        candidate = data.decode("ascii").strip()
    except UnicodeDecodeError as exc:
        raise FenceError("fence_token_encoding_invalid") from exc
    if TOKEN.fullmatch(candidate) is None:
        raise FenceError("fence_token_format_invalid")
    return candidate


def curl_request(
    host: str, port: int, token: str, *, qa: bool,
    ca_file: str | None = None, path: str = "/health", method: str = "GET",
) -> tuple[int, str, str]:
    if HOST.fullmatch(host) is None or not 1 <= port <= 65535:
        raise FenceError("host_or_port_invalid")
    random_canary = re.fullmatch(r"/__vazin_fence_probe_[0-9a-f]{24}", path) is not None
    if (path not in PUBLIC_PATHS and not random_canary) or method not in {"GET", "POST"}:
        raise FenceError("unsupported_fence_probe")
    if qa and (path != QA_PATH or method != "GET"):
        raise FenceError("qa_must_use_internal_health_get")
    if method == "POST" and path != QA_PATH:
        raise FenceError("post_probes_must_use_inert_internal_route")
    command = [
        "curl", "--silent", "--show-error", "--noproxy", "*",
        "--connect-timeout", "3", "--max-time", "7",
        "--proto", "=https", "--max-redirs", "0",
        "--resolve", f"{host}:{port}:127.0.0.1",
        "--dump-header", "-", "--max-filesize", "32768",
        "--write-out", "\nVAZIN_FENCE_HTTP_CODE:%{http_code}\n",
    ]
    if ca_file:
        command.extend(["--cacert", ca_file])
    if qa:
        # Header value arrives on stdin, never via argv or process listing.
        command.extend(["--config", "-"])
        config = 'header = "X-Vazin-QA-Fence-Token: ' + token + '"\n'
    else:
        config = None
    if method == "POST":
        command.extend(["--request", "POST", "--data-binary", ""])
    command.extend(["https://" + host + ":" + str(port) + path])
    result = subprocess.run(command, input=config, text=True, capture_output=True, timeout=9, check=False)
    if result.returncode != 0:
        raise FenceError("fence_https_verification_failed")
    marker = re.findall(r"VAZIN_FENCE_HTTP_CODE:([0-9]{3})", result.stdout)
    if len(marker) != 1:
        raise FenceError("fence_response_code_unparseable")
    code = int(marker[0])
    before_marker = result.stdout[:result.stdout.rfind("VAZIN_FENCE_HTTP_CODE:")]
    blocks = re.split(r"\r?\n\r?\n", before_marker, maxsplit=1)
    headers = blocks[0]
    body = blocks[1] if len(blocks) > 1 else ""
    return code, headers, body


def check_fenced(host: str, port: int, token: str, *, ca_file: str | None = None) -> None:
    # Verify all representative Nginx location classes; never regard a lone
    # /health=503 as proof the public site is closed.
    fingerprint = hashlib.sha256(token.encode("ascii")).hexdigest()
    for method, path in current_public_probes():
        code, headers, _ = curl_request(
            host, port, token, qa=False, ca_file=ca_file, path=path, method=method
        )
        if code != 503:
            raise FenceError("public_route_not_fenced")
        values = re.findall(r"(?im)^x-vazin-release-fence:[ \t]*([^\r\n]+)", headers)
        if len(values) != 1 or values[0].strip() != fingerprint:
            raise FenceError("site_fence_receipt_does_not_match")
        policies = re.findall(r"(?im)^cache-control:[ \t]*([^\r\n]+)", headers)
        if not any("no-store" in value.lower() for value in policies):
            raise FenceError("site_fence_must_not_be_cached")


def check_qa_health(host: str, port: int, token: str, expected_version: str, *, ca_file: str | None = None) -> None:
    import json
    if VERSION.fullmatch(expected_version) is None:
        raise FenceError("expected_version_not_semver")
    check_fenced(host, port, token, ca_file=ca_file)
    # The normal /health may be served by Nginx itself (e.g. Vazin Travel
    # 1.0.0); this internal FastCGI path must always reach CMS /health.
    code, _headers, body = curl_request(
        host, port, token, qa=True, ca_file=ca_file, path=QA_PATH
    )
    if code != 200:
        raise FenceError("site_qa_bypass_unavailable")
    try:
        reply = json.loads(body.strip())
    except (ValueError, TypeError) as exc:
        raise FenceError("site_qa_health_invalid_json") from exc
    if not isinstance(reply, dict) or reply.get("ok") is not True or reply.get("service") != "VazinCMS" or reply.get("version") != expected_version:
        raise FenceError("site_qa_health_not_expected_version")
    # Fence must remain active *after* the internal test.
    check_fenced(host, port, token, ca_file=ca_file)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["check", "health"])
    parser.add_argument("--host", required=True)
    parser.add_argument("--token-file", required=True)
    parser.add_argument("--version")
    # Used ONLY by independent loopback-TLS test fixtures. Production installer
    # never passes these two arguments.
    parser.add_argument("--port", type=int, default=443)
    parser.add_argument("--test-ca-file")
    args = parser.parse_args(argv)
    try:
        token = read_token(Path(args.token_file))
        if args.mode == "check":
            check_fenced(args.host, args.port, token, ca_file=args.test_ca_file)
        else:
            if not args.version:
                raise FenceError("expected_version_missing")
            check_qa_health(args.host, args.port, token, args.version, ca_file=args.test_ca_file)
    except (FenceError, OSError, subprocess.SubprocessError) as exc:
        # Return machine-readable reason only, never an absolute secret path.
        reason = str(exc) if isinstance(exc, FenceError) else "fence_system_check_failed"
        print("NO_GO: " + reason, file=sys.stderr)
        return MANUAL
    print("FENCE_OK " + args.mode + " " + args.host)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
