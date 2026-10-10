#!/usr/bin/env python3
"""Read-only, NON-AUTHORIZING VazinCMS Nginx/FPM shared-pool inventory.

This is an observation, NOT proof of zero in-flight writes or safe rollout.
It never connects to PHP-FPM, executes Nginx, changes a service or approves release.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import sys

DOMAIN = re.compile(r"^[a-z0-9](?:[a-z0-9.-]{0,250}[a-z0-9])?$")
SERVER_NAME = re.compile(r"\bserver_name\s+([^;{}]+);")
FASTCGI_PASS = re.compile(r"\bfastcgi_pass\s+([^;{}]+);")
FPM_SETTING = re.compile(r"(?m)^\s*(listen|pm|max_children|pm\.max_children|pm\.status_path)\s*=\s*([^;\r\n]+)")
NO_GO = 70


def strip_comments(text: str) -> str:
    # Static observation only. Full Nginx grammar/includes and runtime live
    # config cannot be inferred from simple text parsing.
    return "\n".join(line.split("#", 1)[0] for line in text.splitlines())


def active_nginx_backends(config_dir: Path) -> dict[str, set[str]]:
    if not config_dir.is_dir():
        raise ValueError("nginx_directory_unavailable")
    results: dict[str, set[str]] = {}
    for f in sorted(config_dir.iterdir()):
        if not f.is_file():
            continue
        try:
            text = strip_comments(f.read_text(encoding="utf-8"))
        except (OSError, UnicodeError):
            raise ValueError("nginx_config_unreadable") from None
        hosts = {name.strip().lower() for item in SERVER_NAME.findall(text) for name in item.split()}
        endpoints = {item.strip() for item in FASTCGI_PASS.findall(text)}
        for host in hosts:
            if DOMAIN.fullmatch(host):
                results.setdefault(host, set()).update(endpoints)
    return results


def pool_inventory(pool_dir: Path) -> dict[str, dict]:
    if not pool_dir.is_dir():
        raise ValueError("fpm_pool_directory_unavailable")
    results: dict[str, dict] = {}
    for f in sorted(pool_dir.glob("*.conf")):
        try:
            content = strip_comments(f.read_text(encoding="utf-8"))
        except (OSError, UnicodeError):
            raise ValueError("fpm_pool_unreadable") from None
        settings = dict((k, v.strip()) for k, v in FPM_SETTING.findall(content))
        if not settings.get("listen"):
            continue
        key = settings["listen"].removeprefix("unix:")
        results[key] = {
            "pool": f.stem,
            "status_path_configured": bool(settings.get("pm.status_path")),
            "max_children": settings.get("pm.max_children"),
        }
    return results


def inspect(nginx_dir: Path, pool_dir: Path, hosts: list[str]) -> dict:
    if not hosts or len(set(hosts)) != len(hosts) or any(DOMAIN.fullmatch(x) is None for x in hosts):
        raise ValueError("requested_host_list_invalid")
    allhosts = active_nginx_backends(nginx_dir)
    pools = pool_inventory(pool_dir)
    socket_users: dict[str, set[str]] = {}
    for domain, connections in allhosts.items():
        for backend in connections:
            socket_users.setdefault(backend, set()).add(domain)
    sites = []
    for host in hosts:
        endpoints = sorted(allhosts.get(host, ()))
        notes = ["site_inflight_writes_not_observed", "site_background_jobs_not_observed"]
        if not endpoints:
            notes.append("no_fastcgi_upstream_visible_in_static_vhost")
        upstreams = []
        for backend in endpoints:
            if not backend.startswith("unix:"):
                notes.append("fastcgi_backend_not_unix_socket_or_unresolved")
            pool = pools.get(backend.removeprefix("unix:"))
            owners = sorted(socket_users.get(backend, set()))
            if len(owners) > 1:
                notes.append("fastcgi_backend_shared_with_other_vhosts")
            if pool is None:
                notes.append("backend_not_mapped_to_local_fpm_pool")
            elif not pool["status_path_configured"]:
                notes.append("fpm_status_path_not_configured")
            upstreams.append({
                "upstream": backend if backend.startswith("unix:/run/php/") else "nonstandard_or_unresolved",
                "observed_host_count": len(owners),
                "pool_name": pool["pool"] if pool else None,
                "status_path_configured": bool(pool and pool["status_path_configured"]),
            })
        sites.append({
            "host": host,
            "upstreams": upstreams,
            "blockers": sorted(set(notes)),
            "drain_verified": False,
        })
    return {
        "schema": 1,
        "decision": "NO_GO",
        "release_authorized": False,
        "source_quality": "read_only_static_config_observation_not_live_nginx_fpm_attestation",
        "sites": sites,
        "global_blockers": [
            "inflight_request_completion_not_proven",
            "background_job_quiescence_not_proven",
            "owner_approved_drain_evidence_not_available",
        ],
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--nginx-dir", type=Path, default=Path("/etc/nginx/sites-enabled"))
    parser.add_argument("--fpm-dir", type=Path, default=Path("/etc/php/8.5/fpm/pool.d"))
    parser.add_argument("--hosts", nargs="+", required=True)
    args = parser.parse_args(argv)
    try:
        result = inspect(args.nginx_dir, args.fpm_dir, args.hosts)
    except (OSError, ValueError):
        print('{"decision":"NO_GO","release_authorized":false,"error":"inventory_unverifiable"}')
        return NO_GO
    print(json.dumps(result, sort_keys=True, ensure_ascii=False))
    # Even a unique FPM pool does not establish zero running requests.
    return NO_GO


if __name__ == "__main__":
    raise SystemExit(main())
