#!/usr/bin/env python3
"""Non-authorizing, read-only inventory of Nginx vhosts and PHP-FPM pools.

No Nginx reload, no FPM connection, no worker manipulation, no release permit.
Static config is *never* sufficient to attest drained in-flight requests.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import sys

NO_GO = 70
DOMAIN = re.compile(r"^[a-z0-9](?:[a-z0-9.-]{0,250}[a-z0-9])?$")
SERVER = re.compile(r"\bserver\s*\{")
SERVER_NAME = re.compile(r"\bserver_name\s+([^;{}]+);")
FASTCGI = re.compile(r"\bfastcgi_pass\s+([^;{}]+);")
INCLUDE = re.compile(r"\binclude\s+([^;{}]+);")
SETTING = re.compile(r"(?m)^\s*(listen|pm\.max_children|pm\.status_path|pm\.status_listen)\s*=\s*([^;\r\n]+)")


def without_nginx_comments(text: str) -> str:
    """Remove unquoted comments while preserving line/character offsets."""
    lines: list[str] = []
    for line in text.splitlines(keepends=True):
        quote: str | None = None
        escape = False
        buf: list[str] = []
        for c in line:
            if escape:
                buf.append(c)
                escape = False
                continue
            if c == "\\":
                escape = True
                buf.append(c)
                continue
            if quote:
                if c == quote:
                    quote = None
                buf.append(c)
            elif c in ("'", '"'):
                quote = c
                buf.append(c)
            elif c == "#":
                buf.extend(" " * (len(line) - len(buf)))
                break
            else:
                buf.append(c)
        lines.append("".join(buf))
    return "".join(lines)


def ignore_quoted_braces(text: str) -> str:
    buf: list[str] = []
    quote: str | None = None
    escape = False
    for c in text:
        if escape:
            buf.append(" ")
            escape = False
        elif c == "\\" and quote:
            buf.append(" ")
            escape = True
        elif quote:
            if c == quote:
                quote = None
            buf.append(" ")
        elif c in ("'", '"'):
            quote = c
            buf.append(" ")
        else:
            buf.append(c)
    if quote:
        raise ValueError("nginx_quoted_value_not_closed")
    return "".join(buf)


def nginx_server_blocks(text: str) -> list[str]:
    """Conservative balanced server-block reader (includes not expanded)."""
    stripped = without_nginx_comments(text)
    masked = ignore_quoted_braces(stripped)
    blocks: list[str] = []
    cursor = 0
    while match := SERVER.search(masked, cursor):
        opened = match.end() - 1
        depth = 0
        closed: int | None = None
        for i in range(opened, len(masked)):
            if masked[i] == "{":
                depth += 1
            elif masked[i] == "}":
                depth -= 1
                if depth == 0:
                    closed = i
                    break
        if closed is None:
            raise ValueError("nginx_server_block_not_closed")
        blocks.append(stripped[opened + 1:closed])
        cursor = closed + 1
    return blocks


def nginx_hosts(directory: Path) -> dict[str, dict]:
    if not directory.is_dir():
        raise ValueError("nginx_dir_not_found")
    output: dict[str, dict] = {}
    for path in sorted(directory.iterdir()):
        if not path.is_file():
            continue
        try:
            sections = nginx_server_blocks(path.read_text(encoding="utf-8"))
        except (OSError, UnicodeError):
            raise ValueError("nginx_config_unreadable") from None
        for section in sections:
            hosts = {
                candidate.lower().strip()
                for raw in SERVER_NAME.findall(section) for candidate in raw.split()
            }
            endpoints = {raw.strip() for raw in FASTCGI.findall(section)}
            has_includes = bool(INCLUDE.findall(section))
            for host in hosts:
                if DOMAIN.fullmatch(host) is None:
                    continue
                row = output.setdefault(host, {"upstreams": set(), "has_unexpanded_includes": False})
                row["upstreams"].update(endpoints)
                row["has_unexpanded_includes"] |= has_includes
    return output


def fpm_pools(directory: Path) -> dict[str, dict]:
    if not directory.is_dir():
        raise ValueError("fpm_pool_dir_not_found")
    output: dict[str, dict] = {}
    for path in sorted(directory.glob("*.conf")):
        try:
            text = path.read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            raise ValueError("fpm_pool_unreadable") from None
        settings = dict((k, v.strip()) for k, v in SETTING.findall(text))
        if not settings.get("listen"):
            continue
        listen = settings["listen"].removeprefix("unix:")
        if listen in output:
            raise ValueError("duplicate_fpm_pool_listener")
        output[listen] = {
            "pool": path.stem,
            "max_children": settings.get("pm.max_children"),
            "status_path_configured": bool(settings.get("pm.status_path")),
            "status_listener_configured": bool(settings.get("pm.status_listen")),
        }
    return output


def inspect(nginx_dir: Path, fpm_dir: Path, hosts: list[str]) -> dict:
    if not hosts or len(set(hosts)) != len(hosts) or any(DOMAIN.fullmatch(x) is None for x in hosts):
        raise ValueError("host_filter_invalid")
    all_hosts = nginx_hosts(nginx_dir)
    all_pools = fpm_pools(fpm_dir)
    use_count: dict[str, set[str]] = {}
    for host, details in all_hosts.items():
        for upstream in details["upstreams"]:
            use_count.setdefault(upstream, set()).add(host)
    sites = []
    for host in hosts:
        entry = all_hosts.get(host, {"upstreams": set(), "has_unexpanded_includes": True})
        blockers = ["inflight_requests_unobserved", "job_queues_unobserved"]
        if entry["has_unexpanded_includes"]:
            blockers.append("nginx_includes_not_expanded")
        if not entry["upstreams"]:
            blockers.append("fastcgi_upstream_not_visible")
        backends = []
        for backend in sorted(entry["upstreams"]):
            mapped = all_pools.get(backend.removeprefix("unix:"))
            linked_hosts = len(use_count.get(backend, set()))
            if not backend.startswith("unix:"):
                blockers.append("fastcgi_upstream_dynamic_or_non_unix")
            if linked_hosts > 1:
                blockers.append("fpm_listener_shared_across_vhosts")
            if mapped is None:
                blockers.append("fpm_listener_not_found")
            elif not mapped["status_path_configured"] and not mapped["status_listener_configured"]:
                blockers.append("no_fpm_status_endpoint_configured")
            backends.append({
                "upstream": backend if backend.startswith("unix:/run/php/") else "unresolved_or_nonstandard",
                "observed_host_count": linked_hosts,
                "pool": mapped["pool"] if mapped else None,
                "status_path_configured": bool(mapped and mapped["status_path_configured"]),
                "status_listener_configured": bool(mapped and mapped["status_listener_configured"]),
            })
        sites.append({
            "host": host,
            "backends": backends,
            "blockers": sorted(set(blockers)),
            "drain_verified": False,
        })
    return {
        "schema": 1,
        "decision": "NO_GO",
        "release_authorized": False,
        "source_quality": "static_site_config_only_not_expanded_nginx_T_or_live_php_fpm",
        "sites": sites,
        "global_blockers": [
            "inflight_request_completion_not_attested",
            "background_job_quiescence_not_attested",
            "independent_owner_approval_missing",
        ],
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--nginx-dir", type=Path, default=Path("/etc/nginx/sites-enabled"))
    parser.add_argument("--fpm-dir", type=Path, default=Path("/etc/php/8.5/fpm/pool.d"))
    parser.add_argument("--hosts", nargs="+", required=True)
    args = parser.parse_args(argv)
    try:
        report = inspect(args.nginx_dir, args.fpm_dir, args.hosts)
    except (OSError, ValueError):
        print('{"decision":"NO_GO","release_authorized":false,"error":"inventory_inconclusive"}')
        return NO_GO
    print(json.dumps(report, ensure_ascii=False, sort_keys=True))
    return NO_GO


if __name__ == "__main__":
    sys.exit(main())
