#!/usr/bin/env python3
"""Read-only PHP-FPM status samples from a Unix status socket.

No config changes, no service control, no network requests, and NEVER a release permit.
A quiet FPM pool does not attest background jobs, FPM ownership or DB writes.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

NO_GO = 70
POOL = re.compile(r"^[A-Za-z0-9_.-]{1,80}$")
STATUS_PATH = "/__cms_fpm_status"
REQUIRED_INTEGERS = ("active processes", "idle processes", "total processes", "listen queue", "accepted conn")
MAX_OUTPUT = 16384


class EvidenceError(Exception):
    pass


def verify_unix_socket(path: Path) -> None:
    if not path.is_absolute() or ".." in path.parts:
        raise EvidenceError("status_socket_path_invalid")
    # Refuse symlink components and sockets outside approved-like absolute paths.
    for component in (path, *path.parents):
        try:
            mode = os.lstat(component).st_mode
        except OSError as exc:
            raise EvidenceError("status_socket_unavailable") from exc
        if stat.S_ISLNK(mode):
            raise EvidenceError("status_socket_symlinked")
    if not stat.S_ISSOCK(os.lstat(path).st_mode):
        raise EvidenceError("status_target_not_socket")


def parse_status(raw: str, expected_pool: str) -> dict[str, int | str]:
    if len(raw.encode("utf-8", "replace")) > MAX_OUTPUT:
        raise EvidenceError("status_response_too_large")
    separator = "\r\n\r\n" if "\r\n\r\n" in raw else "\n\n"
    headers, sep, body = raw.partition(separator)
    if not sep or "application/json" not in headers.lower():
        raise EvidenceError("status_response_not_json")
    if re.search(r"(?im)^Status:\s*(?!200\b)\d{3}", headers):
        raise EvidenceError("status_endpoint_not_ok")
    try:
        item = json.loads(body)
    except ValueError as exc:
        raise EvidenceError("status_invalid_json") from exc
    if not isinstance(item, dict) or item.get("pool") != expected_pool:
        raise EvidenceError("fpm_pool_identity_mismatch")
    for key in REQUIRED_INTEGERS:
        value = item.get(key)
        if type(value) is not int or value < 0:
            raise EvidenceError("status_counter_invalid")
    if item["active processes"] + item["idle processes"] > item["total processes"]:
        raise EvidenceError("status_process_totals_inconsistent")
    return {"pool": expected_pool,
            "active": item["active processes"],
            "idle": item["idle processes"],
            "total": item["total processes"],
            "listen_queue": item["listen queue"],
            "accepted_conn": item["accepted conn"]}


def sample_once(socket_path: Path, pool: str) -> dict:
    verify_unix_socket(socket_path)
    # No inherited cookies, proxy settings, database credentials or runtime secrets.
    env = {
        "PATH": "/usr/bin:/bin",
        "REQUEST_METHOD": "GET",
        "SCRIPT_NAME": STATUS_PATH,
        "SCRIPT_FILENAME": STATUS_PATH,
        "REQUEST_URI": STATUS_PATH + "?json",
        "QUERY_STRING": "json",
        "SERVER_PROTOCOL": "HTTP/1.1",
        "SERVER_SOFTWARE": "VazinCMS-ReadOnly-Status",
        "SERVER_NAME": "localhost",
        "REMOTE_ADDR": "127.0.0.1",
        "DOCUMENT_ROOT": "/",
    }
    try:
        result = subprocess.run(
            ["/usr/bin/cgi-fcgi", "-bind", "-connect", str(socket_path)],
            env=env, capture_output=True, text=True, timeout=4, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise EvidenceError("status_socket_request_failed") from exc
    if result.returncode != 0:
        raise EvidenceError("status_socket_request_failed")
    return parse_status(result.stdout, pool)


def sample_series(socket_path: Path, pool: str, count: int, interval_s: float) -> dict:
    if not (3 <= count <= 30 and 0.1 <= interval_s <= 3.0 and POOL.fullmatch(pool)):
        raise EvidenceError("sample_settings_invalid")
    readings = []
    for i in range(count):
        readings.append(sample_once(socket_path, pool))
        if i + 1 < count:
            time.sleep(interval_s)
    # Observed quiet is a limited evidence statement, NOT proof of completed
    # requests, queue producers or a safe cutover.
    quiet = (
        all(x["active"] == 0 and x["listen_queue"] == 0 for x in readings)
        and len({x["accepted_conn"] for x in readings}) == 1
    )
    return {
        "schema": 1,
        "pool": pool,
        "samples": count,
        "observed_quiet": quiet,
        "active_counts": [x["active"] for x in readings],
        "listen_queues": [x["listen_queue"] for x in readings],
        "accepted_connection_delta": readings[-1]["accepted_conn"] - readings[0]["accepted_conn"],
        "drain_verified": False,
        "background_jobs_verified": False,
        "release_authorized": False,
        "decision": "NO_GO",
        "limitations": [
            "instantaneous_pool_counters_not_atomic_with_release_swap",
            "socket_pool_dedication_and_vhost_routing_not_proven",
            "long_lived_writers_jobs_queues_db_sessions_not_proven_quiescent",
        ],
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--socket", type=Path, required=True)
    parser.add_argument("--pool", required=True)
    parser.add_argument("--samples", type=int, default=5)
    parser.add_argument("--interval-ms", type=int, default=500)
    args = parser.parse_args(argv)
    try:
        result = sample_series(args.socket, args.pool, args.samples, args.interval_ms / 1000)
    except (EvidenceError, OSError, ValueError):
        print('{"decision":"NO_GO","release_authorized":false,"error":"fpm_status_evidence_unavailable"}')
        return NO_GO
    print(json.dumps(result, sort_keys=True))
    return NO_GO


if __name__ == "__main__":
    sys.exit(main())
