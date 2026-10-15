#!/usr/bin/env python3
"""Minimize Nginx -T in-memory to a conservative, redacted structural report.

nginx -T validates + prints the CURRENT DISK CONFIGURATION. It does NOT
attest the last config loaded in running workers and cannot authorize release.

Never emit raw stdout/stderr, config paths, IPs, secrets, header values or
arbitrary server names; only known fixed site labels and small enumerations.
"""
from __future__ import annotations

import json
import re
import subprocess
import sys

NGINX = "/usr/sbin/nginx"
NO_GO = 70
MAX_DUMP = 8 * 1024 * 1024
MAX_SECTIONS = 500
MAX_SERVER_BLOCKS = 1500
TIMEOUT_SECONDS = 20
TARGETS = {
    "CMS": "cms.vazin.online",
    "VISA": "visa.vazin.online",
    "STUDIO": "studio.vazin.online",
    "TRAVEL": "travel.vazin.online",
    "RUSSIAFA": "russiafa.ru",
}
BACKEND_CLASSES = {
    "php8.5-fpm.sock": "shared_php85",
    "vazincms-studio.sock": "vazincms_studio",
    "vazincms-russiafa.sock": "vazincms_russiafa",
    "vazincms-core.sock": "vazincms_core",
}
REALIP = ("real_ip_header", "set_real_ip_from", "real_ip_recursive")
CONFIG_HEADING = re.compile(r"(?m)^# configuration file [^\n]*:\s*$")


class DumpIncomplete(Exception):
    """Machine-readable diagnostics intentionally omit internal raw values."""


def strip_comments(source: str) -> str:
    """Keep quoted # but drop comments and preserve newline layout."""
    buf: list[str] = []
    quote: str | None = None
    escaped = False
    commenting = False
    for ch in source:
        if commenting:
            if ch == "\n":
                commenting = False
                buf.append("\n")
            continue
        if escaped:
            buf.append(ch)
            escaped = False
            continue
        if ch == "\\":
            buf.append(ch)
            escaped = True
        elif quote:
            buf.append(ch)
            if ch == quote:
                quote = None
        elif ch in ('"', "'"):
            quote = ch
            buf.append(ch)
        elif ch == "#":
            commenting = True
        else:
            buf.append(ch)
    return "".join(buf)


def structural_mask(source: str) -> str:
    """Same length as source; quoted and escaped characters become whitespace."""
    out: list[str] = []
    quote: str | None = None
    escaped = False
    for ch in source:
        if escaped:
            out.append("\n" if ch == "\n" else " ")
            escaped = False
        elif ch == "\\":
            out.append(" ")
            escaped = True
        elif quote:
            if ch == quote:
                quote = None
            out.append("\n" if ch == "\n" else " ")
        elif ch in ('"', "'"):
            quote = ch
            out.append(" ")
        else:
            out.append(ch)
    return "".join(out)


def find_blocks(source: str, keyword: str) -> list[str]:
    """Extract conservative balanced block bodies, using positions only."""
    structural = structural_mask(source)
    match_re = re.compile(r"(?<![\w$])" + re.escape(keyword) + r"\s*\{")
    result: list[str] = []
    for match in match_re.finditer(structural):
        start = structural.find("{", match.start(), match.end())
        if start < 0:
            raise DumpIncomplete("block_start_missing")
        depth = 0
        end: int | None = None
        for i in range(start, len(structural)):
            ch = structural[i]
            if ch == "{":
                depth += 1
            elif ch == "}":
                depth -= 1
                if depth == 0:
                    end = i
                    break
        if end is None:
            raise DumpIncomplete("unbalanced_nginx_block")
        if len(result) >= MAX_SERVER_BLOCKS:
            raise DumpIncomplete("nginx_block_limit")
        result.append(source[start+1:end])
    return result


def directive_values(source: str, key: str) -> list[str]:
    # Locate keyword in structure, then slice up to the first semicolon
    # outside quoted strings; preserve *actual* quoted values for matching.
    # Never interpret directive-like text inside quotes as executable.
    visible = structural_mask(source)
    expr = r"(?<![\w$])" + re.escape(key) + r"[ \t]"
    result: list[str] = []
    for m in re.finditer(expr, visible):
        end = visible.find(";", m.end())
        if end < 0 or end - m.end() > 2048:
            continue
        before_end = visible[m.end():end]
        if "{" in before_end or "}" in before_end:
            continue
        value = source[m.end():end].strip()
        if value:
            result.append(value)
    return result


def count_location_forms(body: str) -> dict[str, int]:
    # Modifiers are syntax tokens, not the private location path.
    locations = re.findall(r"(?<![\w$])location\s+([^{\r\n]+)\{", structural_mask(body))
    counts = {"exact": 0, "prefix_no_regex": 0, "regex": 0,
              "regex_case_insensitive": 0, "named": 0, "ordinary_prefix": 0}
    for raw in locations:
        word = raw.strip().split(None, 1)[0] if raw.strip() else ""
        if word == "=":
            counts["exact"] += 1
        elif word == "^~":
            counts["prefix_no_regex"] += 1
        elif word == "~":
            counts["regex"] += 1
        elif word == "~*":
            counts["regex_case_insensitive"] += 1
        elif word.startswith("@"):
            counts["named"] += 1
        else:
            counts["ordinary_prefix"] += 1
    return counts


def backend_class(value: str) -> str:
    stripped = value.strip().strip("'\"")
    if "$" in stripped:
        return "dynamic_backend"
    found = re.fullmatch(r"unix:/run/php/([A-Za-z0-9_.-]+\.sock)", stripped)
    if found:
        return BACKEND_CLASSES.get(found.group(1), "other_unix_backend")
    if stripped.startswith("unix:"):
        return "other_unix_backend"
    return "other_backend"


def parse_dump(raw: str) -> dict:
    if len(raw.encode("utf-8")) > MAX_DUMP:
        raise DumpIncomplete("dump_limit")
    headings = list(CONFIG_HEADING.finditer(raw))
    if not headings or len(headings) > MAX_SECTIONS:
        raise DumpIncomplete("dump_section_limit_or_missing")
    # Comments separating file sections may be included after the previous
    # section. Only parse the bodies, never expose filenames from headings.
    sources = [strip_comments(raw[m.end():(headings[i+1].start() if i+1 < len(headings) else len(raw))])
               for i, m in enumerate(headings)]
    counts = {key: sum(len(directive_values(s, key)) for s in sources) for key in REALIP}
    matches: dict[str, list[str]] = {k: [] for k in TARGETS}
    server_blocks = 0
    for section in sources:
        for block in find_blocks(section, "server"):
            server_blocks += 1
            if server_blocks > MAX_SERVER_BLOCKS:
                raise DumpIncomplete("server_block_limit")
            declared = {
                w.strip("'\"").lower()
                for v in directive_values(block, "server_name") for w in v.split()
            }
            for label, host in TARGETS.items():
                if host in declared:
                    matches[label].append(block)
    summaries: list[dict] = []
    for label in TARGETS:
        blocks = matches[label]
        location_kinds = {"exact": 0, "prefix_no_regex": 0, "regex": 0,
                          "regex_case_insensitive": 0, "named": 0, "ordinary_prefix": 0}
        for b in blocks:
            for kind, count in count_location_forms(b).items():
                location_kinds[kind] += count
        backends = sorted({backend_class(v) for b in blocks for v in directive_values(b, "fastcgi_pass")})
        summaries.append({
            "site": label,
            "matching_server_blocks": len(blocks),
            "server_block_found": bool(blocks),
            "backend_classes": backends or ["unresolved"],
            "qa_path_literal_seen": any("/__vazin_cms_health" in b for b in blocks),
            "fence_header_literal_seen": any("X-Vazin-Release-Fence" in b for b in blocks),
            "server_block_realip_counts": {
                key: sum(len(directive_values(b, key)) for b in blocks) for key in REALIP
            },
            "location_forms": location_kinds,
            "all_locations_proven_fenced": False,
        })
    by_label = {s["site"]: set(s["backend_classes"]) for s in summaries}
    shared_php85 = all(
        "shared_php85" in by_label[site] for site in ("VISA", "TRAVEL")
    )
    return {
        "type": "NGINX_DISK_CONFIG_TEST_DUMP",
        "nginx_test_succeeded": True,
        "travel_and_visa_share_php85_backend": shared_php85,
        "per_site_drain_precondition": (
            "BLOCKED_SHARED_POOL" if shared_php85 else "NOT_ATTESTED"
        ),
        "inflight_fpm_request_state_known": False,
        "background_writers_quiesced": False,
        "config_sections": len(headings),
        "server_blocks_found": server_blocks,
        "realip_directives_in_dump": counts,
        "sites": summaries,
        "source": "nginx_-T_discards_full_dump_in_memory",
        "loaded_worker_config_proven_equal_to_dump": False,
        "independent_vhost_location_review_completed": False,
        "php_fpm_drain_verified": False,
        "deployment_authorized": False,
        "production": "NO_GO",
    }


def capture_nginx_dump() -> str:
    # NO --quiet: Nginx -T itself prints the config to stdout. Captured ONLY
    # in memory; never persist or display stdout/stderr, even on errors.
    try:
        result = subprocess.run([NGINX, "-T"], capture_output=True, timeout=TIMEOUT_SECONDS, check=False)
    except (OSError, subprocess.SubprocessError):
        raise DumpIncomplete("nginx_test_not_available") from None
    if result.returncode != 0 or len(result.stdout) > MAX_DUMP:
        raise DumpIncomplete("nginx_test_failed_or_too_large")
    try:
        return result.stdout.decode("utf-8")
    except UnicodeDecodeError:
        raise DumpIncomplete("nginx_dump_non_utf8") from None


def main(argv: list[str] | None = None) -> int:
    # Fixed zero-argument command. Never echo supplied arguments, which might
    # themselves contain a secret path or credential. No caller-selected shell.
    args = sys.argv[1:] if argv is None else argv
    if args:
        print('{"type":"NGINX_DISK_CONFIG_TEST_DUMP","result":"INCOMPLETE","production":"NO_GO"}')
        return NO_GO
    try:
        result = parse_dump(capture_nginx_dump())
    except (DumpIncomplete, ValueError):
        # Never print or persist raw subprocess output, config values or path.
        print('{"type":"NGINX_DISK_CONFIG_TEST_DUMP","result":"INCOMPLETE","production":"NO_GO"}')
        return NO_GO
    print(json.dumps(result, sort_keys=True, separators=(",", ":"), ensure_ascii=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
