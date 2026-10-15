#!/usr/bin/env python3
"""Read-only, privacy-preserving *heuristic* Nginx/FPM inventory.

Never prints config lines, directive values, credentials, IPs, arbitrary paths,
server names, or raw exception details. This is NOT a deploy/readiness gate.
The source file is read in memory only and never sent to Git or output.
"""
from __future__ import annotations

import argparse
import glob
import json
from pathlib import Path
import re
import sys

MAX_FILES = 300
MAX_BYTES_PER_FILE = 2_000_000
MAX_INCLUDE_DEPTH = 10
SITES = {
    "CMS": ("cms.vazin.online", "cms.vazin.online"),
    "VISA": ("vazin-site-visa-vazin-online", "visa.vazin.online"),
    "STUDIO": ("vazin-site-studio-vazin-online", "studio.vazin.online"),
    "TRAVEL": ("vazin-site-travel-vazin-online", "travel.vazin.online"),
    "RUSSIAFA": ("russiafa.ru", "russiafa.ru"),
}
KNOWN_SOCKETS = {
    "php8.5-fpm.sock": "shared_php85",
    "vazincms-core.sock": "vazincms_core",
    "vazincms-studio.sock": "vazincms_studio",
    "vazincms-russiafa.sock": "vazincms_russiafa",
}
REALIP_DIRECTIVES = ("real_ip_header", "set_real_ip_from", "real_ip_recursive")


class ScanIncomplete(Exception):
    """Internal fault, deliberately omit raw path/argument details."""


def remove_nginx_comments(source: str) -> str:
    """Drop unquoted # comments; preserve line boundaries and quotes."""
    out: list[str] = []
    quote: str | None = None
    escaped = False
    comment = False
    for char in source:
        if comment:
            if char == "\n":
                out.append(char)
                comment = False
            continue
        if escaped:
            out.append(char)
            escaped = False
            continue
        if char == "\\":
            out.append(char)
            escaped = True
            continue
        if quote:
            if char == quote:
                quote = None
            out.append(char)
            continue
        if char in ("'", '"'):
            quote = char
            out.append(char)
        elif char == "#":
            comment = True
        else:
            out.append(char)
    return "".join(out)


def directives(source: str, name: str) -> list[str]:
    # Deliberately heuristic, not a complete Nginx grammar: fail closed.
    # Directives nested in unusual strings may generate false positives.
    pattern = r"(?<![\w$])" + re.escape(name) + r"\s+([^;{}\r\n]+);"
    return [value.strip().strip("\"'") for value in re.findall(pattern, source)]


def safe_read(path: Path, root: Path) -> str:
    """Read only ordinary files resolved *inside* the caller-selected root."""
    try:
        file = path.resolve(strict=True)
        if not file.is_relative_to(root) or not file.is_file():
            raise ScanIncomplete("file_outside_scoped_nginx_root")
        if file.stat().st_size > MAX_BYTES_PER_FILE:
            raise ScanIncomplete("config_file_too_large")
        return remove_nginx_comments(file.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, RuntimeError) as exc:
        raise ScanIncomplete("config_read_or_resolve_failed") from None


def includes_for(source: str) -> list[str]:
    return directives(source, "include")


def location_count(source: str) -> int:
    # Block-form directives end with '{', not a semicolon. Approximation only.
    return len(re.findall(r"(?<![\w$])location\s+[^{}\r\n]+\{", source))


def expand_include(raw: str, root: Path) -> tuple[list[Path], bool]:
    # No env interpolation and no external root: mark incomplete instead.
    if not raw or "$" in raw or "\n" in raw:
        return [], False
    candidate = Path(raw) if raw.startswith("/") else (root / raw)
    if not candidate.is_relative_to(root):
        return [], False
    try:
        found = [Path(p).resolve(strict=True) for p in glob.glob(str(candidate))]
    except (OSError, RuntimeError):
        return [], False
    if not found or len(found) > MAX_FILES:
        return [], False
    if any(not p.is_relative_to(root) or not p.is_file() for p in found):
        return [], False
    return found, True


def collect_site(entry: Path, root: Path) -> dict:
    sources: list[str] = []
    visited: set[Path] = set()
    pending: list[tuple[Path, int]] = [(entry, 0)]
    external_or_dynamic_includes = 0
    if not entry.exists():
        raise ScanIncomplete("expected_vhost_file_missing")
    while pending:
        path, depth = pending.pop()
        if depth > MAX_INCLUDE_DEPTH:
            raise ScanIncomplete("include_depth_limit")
        try:
            canonical = path.resolve(strict=True)
        except (OSError, RuntimeError):
            raise ScanIncomplete("invalid_nginx_path") from None
        if canonical in visited:
            continue
        if len(visited) >= MAX_FILES:
            raise ScanIncomplete("config_file_limit")
        visited.add(canonical)
        source = safe_read(path, root)
        sources.append(source)
        for raw in includes_for(source):
            matches, ok = expand_include(raw, root)
            if not ok:
                external_or_dynamic_includes += 1
            else:
                pending.extend((p, depth + 1) for p in matches)
    return {
        "sources": sources,
        "source_files": len(visited),
        "unexamined_includes": external_or_dynamic_includes,
    }


def socket_class(backend: str) -> str:
    item = re.fullmatch(r"unix:/run/php/([a-zA-Z0-9_.-]+\.sock)", backend.strip())
    if item:
        return KNOWN_SOCKETS.get(item.group(1), "other_unix_backend")
    if "$" in backend:
        return "dynamic_backend"
    if backend.startswith("unix:"):
        return "other_unix_backend"
    return "other_backend"


def relevant_pool_status(pool_root: Path, socket_classes: set[str]) -> dict[str, str]:
    # Return only enums; never output pool file paths or user/group values.
    result: dict[str, str] = {}
    if not pool_root.is_dir():
        return {name: "pool_root_missing" for name in sorted(socket_classes)}
    actual = list(pool_root.glob("*.conf"))
    if len(actual) > 100:
        raise ScanIncomplete("fpm_pool_count_exceeds_limit")
    socket_to_class = {k: v for k, v in KNOWN_SOCKETS.items()}
    listening: dict[str, list[str]] = {}
    for path in actual:
        try:
            if path.stat().st_size > MAX_BYTES_PER_FILE:
                raise ScanIncomplete("fpm_pool_file_too_large")
            # FPM pool comments begin with ';'.  A simple line parser suffices
            # for the known literal 'listen', 'pm.status_*' directives.
            source = path.read_text(encoding="utf-8")
        except (OSError, UnicodeError, RuntimeError):
            raise ScanIncomplete("fpm_pool_unreadable") from None
        text = "\n".join(line.split(";", 1)[0] for line in source.splitlines())
        matches = re.findall(r"(?m)^\s*listen\s*=\s*(\S+)", text)
        if len(matches) != 1:
            continue
        socket = matches[0].rsplit("/", 1)[-1]
        if socket not in socket_to_class:
            continue
        label = socket_to_class[socket]
        pm_status = bool(re.search(r"(?m)^\s*pm\.status_path\s*=", text))
        pm_status_listen = bool(re.search(r"(?m)^\s*pm\.status_listen\s*=", text))
        value = "status_path_and_listener" if pm_status and pm_status_listen else (
            "status_path_only" if pm_status else
            "status_listener_only" if pm_status_listen else "no_status_directives")
        listening.setdefault(label, []).append(value)
    for label in sorted(socket_classes):
        choices = listening.get(label, [])
        result[label] = choices[0] if len(choices) == 1 else (
            "duplicate_pool_listeners" if len(choices) > 1 else "no_known_pool_mapping"
        )
    return result


def audit(nginx_root: Path, fpm_root: Path) -> dict:
    root = nginx_root.resolve(strict=True)
    if not root.is_dir():
        raise ScanIncomplete("nginx_root_not_directory")
    global_names = [root / "nginx.conf", *sorted((root / "conf.d").glob("*.conf"))]
    if len(global_names) > MAX_FILES:
        raise ScanIncomplete("global_config_limit")
    global_sources = [safe_read(p, root) for p in global_names]
    globals_realip = {
        key: sum(len(directives(s, key)) for s in global_sources)
        for key in REALIP_DIRECTIVES
    }
    sites: list[dict] = []
    socket_by_site: dict[str, set[str]] = {}
    for label, (name, expected_domain) in SITES.items():
        collected = collect_site(root / "sites-enabled" / name, root)
        sources = collected["sources"]
        direct = sources[0]  # source order begins with primary vhost
        fpm_set = {socket_class(v) for s in sources for v in directives(s, "fastcgi_pass")}
        if not fpm_set:
            fpm_set.add("backend_not_identified")
        socket_by_site[label] = fpm_set
        site_directives = {
            key: sum(len(directives(s, key)) for s in sources)
            for key in REALIP_DIRECTIVES
        }
        server_name_tokens = [
            part for value in directives(direct, "server_name")
            for part in value.split()
        ]
        qa_seen = any("/__vazin_cms_health" in s for s in sources)
        fence_seen = any("X-Vazin-Release-Fence" in s for s in sources)
        site_result = {
            "site": label,
            "entry_present": True,
            "literal_server_name_seen": expected_domain in server_name_tokens,
            "files_examined": collected["source_files"],
            "unexamined_includes": collected["unexamined_includes"],
            "location_block_count_approx": sum(location_count(s) for s in sources),
            "qa_literal_found": qa_seen,
            "fence_header_literal_found": fence_seen,
            "vhost_scope_realip_directive_counts": site_directives,
            "fastcgi_backend_classes": sorted(fpm_set),
            # No pass/fail from mere probe coverage, always unproven.
            "all_routes_fenced": "NOT_PROVEN",
        }
        sites.append(site_result)
    php85_sites = sorted(label for label, backends in socket_by_site.items()
                         if "shared_php85" in backends)
    known = set().union(*socket_by_site.values())
    return {
        "type": "READ_ONLY_STATIC_INVENTORY",
        "method": "heuristic_regex_and_scoped_include_traversal",
        "scope_limits": [
            "not_an_nginx_grammar_parser",
            "external_includes_not_read",
            "root_and_conf_d_scanned_without_effective_context_proof",
            "dynamic_backends_and_internal_redirects_not_resolved",
            "no_live_FastCGI_or_worker_drain_or_TLS_or_auth_testing",
        ],
        "nginx_global_realip_directive_counts": globals_realip,
        "php85_shared_site_labels": php85_sites,
        "fpm_pool_status_configuration": relevant_pool_status(fpm_root, known),
        "sites": sites,
        "production": "NO_GO",
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--nginx-root", type=Path, default=Path("/etc/nginx"))
    parser.add_argument("--fpm-pool-root", type=Path, default=Path("/etc/php/8.5/fpm/pool.d"))
    args = parser.parse_args(argv)
    try:
        report = audit(args.nginx_root, args.fpm_pool_root)
    except (ScanIncomplete, OSError, ValueError):
        # No path, Nginx content or real exception may leak on error.
        print('{"type":"READ_ONLY_STATIC_INVENTORY","production":"NO_GO","result":"INCOMPLETE"}')
        return 70
    print(json.dumps(report, sort_keys=True, ensure_ascii=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
