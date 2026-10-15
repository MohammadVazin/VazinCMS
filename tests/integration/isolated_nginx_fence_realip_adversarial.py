#!/usr/bin/env python3
"""Run adversarial real_ip/location precedence tests on an isolated loopback Nginx.

NO live vhost / Nginx / FPM / credentials / production ports are touched.
One synthetic token, one temporary 127.0.0.1-only listener per scenario.
A successful run PROVES only the included synthetic cases, not production safety.
"""
from __future__ import annotations

import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager

TEST_HOST = "site.example.test"
TOKEN = "Synthetic_Fence_Test_Only_" + "X" * 40
SYNTHETIC_HEALTH = '{"ok":true,"service":"VazinCMS","version":"10.30.3"}'
LOOPBACK_PEER = "127.0.0.1"
OTHER_PEER = "127.0.0.2"
EXPECTED_FINGERPRINT = hashlib.sha256(TOKEN.encode("ascii")).hexdigest()
PUBLIC_PROBES = (
    ("GET", "/health"), ("GET", "/"), ("GET", "/login"),
    ("GET", "/admin"), ("GET", "/api/v3/content"),
    ("GET", "/assets/app.js"), ("GET", "/__vazin_cms_health"),
    ("POST", "/__vazin_cms_health"),
)
CANARY_PATH = "/__vazin_fence_probe_0123456789abcdef01234567"
HIDDEN_PATH = "/unmodeled-admin-action"
PHP_EXCEPTION_PATH = "/totally-unlisted-handler.php"


def reserve_loopback_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind((LOOPBACK_PEER, 0))
        return int(s.getsockname()[1])


def render_config(port: int, *, mode: str) -> str:
    """Synthetic fixtures: weak real_ip, safe peer check, or location bypass."""
    if mode not in {"weak_realip", "strong_realip", "asset_hole",
                    "regex_hole", "unknown_hole"}:
        raise ValueError("fixture_mode_invalid")
    if not 1024 < port < 65536:
        raise ValueError("test_port_invalid")
    # A peer on 127.0.0.2 represents a proxy-adjacent request reaching the
    # 127.0.0.1-only test server; trusting XFF from that peer can rewrite
    # $remote_addr to 127.0.0.1 without changing the actual transport peer.
    source_var = "$remote_addr" if mode == "weak_realip" else "$transport_peer"
    qa_key = f"127.0.0.1|{TOKEN}"
    assets_result = 200 if mode == "asset_hole" else 503
    php_result = 200 if mode == "regex_hole" else 503
    unmodeled = (
        f"location = {HIDDEN_PATH} {{ return 200 'UNMODELED_ROUTE_IS_OPEN'; }}"
        if mode == "unknown_hole" else ""
    )
    return f"""
worker_processes 1;
error_log stderr warn;
pid nginx-test.pid;
events {{ worker_connections 128; }}
http {{
    access_log off;
    map_hash_bucket_size 256;
    default_type text/plain;
    # Trust ONLY the synthetic 127.0.0.2 peer for this proof-of-bypass.
    real_ip_header X-Forwarded-For;
    set_real_ip_from 127.0.0.2;
    # $realip_remote_addr remembers the original peer if realip rewrites.
    map $realip_remote_addr $transport_peer {{
        default $realip_remote_addr;
        "" $remote_addr;
    }}
    map "{source_var}|$http_x_vazin_qa_fence_token" $qa_allow {{
        default 0;
        "{qa_key}" 1;
    }}
    server {{
        listen 127.0.0.1:{port};
        server_name {TEST_HOST};
        add_header X-Vazin-Release-Fence "{EXPECTED_FINGERPRINT}" always;
        add_header Cache-Control "no-store" always;

        location = /__vazin_cms_health {{
            if ($request_method != GET) {{ return 503; }}
            if ($qa_allow = 0) {{ return 503; }}
            return 200 '{SYNTHETIC_HEALTH}';
        }}
        location = /health {{ return 503; }}
        location ^~ /assets/ {{ return {assets_result}; }}
        location ~ \\.php$ {{ return {php_result}; }}
        {unmodeled}
        location / {{ return 503; }}
    }}
}}
"""


@contextmanager
def isolated_nginx(config: str, port: int):
    binary = shutil.which("nginx")
    if binary is None:
        raise RuntimeError("NGINX_NOT_INSTALLED: cannot claim integration PASS")
    scratch_parent = "/dev/shm" if os.path.isdir("/dev/shm") else None
    with tempfile.TemporaryDirectory(prefix="vazin-nginx-fence-", dir=scratch_parent) as temp:
        base = Path(temp)
        (base / "conf").mkdir()
        (base / "conf" / "nginx.conf").write_text(config, encoding="ascii")
        cmd = [binary, "-p", str(base) + "/", "-c", "conf/nginx.conf"]
        check = subprocess.run(cmd + ["-t"], capture_output=True, text=True, timeout=5)
        if check.returncode:
            raise RuntimeError("ISOLATED_NGINX_CONFIG_REJECTED: " + check.stderr[-800:])
        process = subprocess.Popen(
            cmd + ["-g", "daemon off; master_process off;"],
            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
            start_new_session=True, text=True,
        )
        try:
            deadline = time.monotonic() + 3
            while time.monotonic() < deadline:
                if process.poll() is not None:
                    raise RuntimeError("ISOLATED_NGINX_FAILED_TO_START: " +
                                       (process.stderr.read() if process.stderr else "")[-800:])
                try:
                    with socket.create_connection((LOOPBACK_PEER, port), timeout=0.2):
                        break
                except OSError:
                    time.sleep(0.025)
            else:
                raise RuntimeError("ISOLATED_NGINX_READINESS_TIMEOUT")
            yield port
        finally:
            if process.poll() is None:
                process.terminate()  # ONLY this test process; never Nginx master.
            try:
                process.communicate(timeout=3)
            except subprocess.TimeoutExpired:
                process.kill()  # ONLY this test process, not the live service.
                process.communicate(timeout=3)


def request(port: int, method: str, path: str, *, source: str = LOOPBACK_PEER,
            token: str | None = None, xff: str | None = None) -> tuple[int, dict[str, str], str]:
    headers = {"Host": TEST_HOST}
    if token is not None:
        headers["X-Vazin-QA-Fence-Token"] = token
    if xff is not None:
        headers["X-Forwarded-For"] = xff
    client = http.client.HTTPConnection(
        LOOPBACK_PEER, port, timeout=3, source_address=(source, 0))
    try:
        client.request(method, path, body=b"" if method == "POST" else None,
                       headers=headers)
        response = client.getresponse()
        return response.status, {k.lower(): v for k, v in response.getheaders()}, \
            response.read(8192).decode("utf-8", errors="replace")
    finally:
        client.close()


def assert_code(port: int, method: str, path: str, expected: int, **params: str) -> None:
    got, headers, body = request(port, method, path, **params)
    if got != expected:
        raise AssertionError(f"EXPECTED_{expected}_GOT_{got}: {method} {path}, params={list(params)}")
    if expected == 503:
        if headers.get("x-vazin-release-fence") != EXPECTED_FINGERPRINT:
            raise AssertionError("FENCE_FINGERPRINT_MISSING_OR_DIFFERENT")
        if "no-store" not in headers.get("cache-control", "").lower():
            raise AssertionError("FENCE_NO_STORE_MISSING")
    if expected == 200 and path == "/__vazin_cms_health":
        if body != SYNTHETIC_HEALTH:
            raise AssertionError("SYNTHETIC_HEALTH_MISMATCH")


def exercise(mode: str, checks: list[tuple[str, int, callable]]) -> None:
    port = reserve_loopback_port()
    with isolated_nginx(render_config(port, mode=mode), port):
        for label, expected, action in checks:
            action(port, expected)
            print(f"PASS {mode} {label}: expected HTTP {expected}")


def main() -> int:
    results: list[str] = []
    try:
        exercise("weak_realip", [
            ("untrusted-peer-without-xff", 503,
             lambda p, e: assert_code(p, "GET", "/__vazin_cms_health", e, source=OTHER_PEER, token=TOKEN)),
            # Deliberately vulnerable control must return 200, proving the risk.
            ("spoofed-xff-bypasses-naive-remote-addr", 200,
             lambda p, e: assert_code(p, "GET", "/__vazin_cms_health", e, source=OTHER_PEER,
                                      token=TOKEN, xff=LOOPBACK_PEER)),
        ])
        results.append("weak_realip_vulnerability_reproduced")
        strong_checks = [
            ("real-loopback-with-valid-synthetic-token", 200,
             lambda p, e: assert_code(p, "GET", "/__vazin_cms_health", e, token=TOKEN)),
            ("real-loopback-with-wrong-token", 503,
             lambda p, e: assert_code(p, "GET", "/__vazin_cms_health", e, token="WRONG")),
            ("real-loopback-without-token", 503,
             lambda p, e: assert_code(p, "GET", "/__vazin_cms_health", e)),
            ("xff-spoof-from-other-peer", 503,
             lambda p, e: assert_code(p, "GET", "/__vazin_cms_health", e,
                                      source=OTHER_PEER, token=TOKEN, xff=LOOPBACK_PEER)),
            ("xff-chain-spoof", 503,
             lambda p, e: assert_code(p, "GET", "/__vazin_cms_health", e,
                                      source=OTHER_PEER, token=TOKEN,
                                      xff="198.51.100.9, 127.0.0.1")),
            ("non-loopback-peer-valid-token", 503,
             lambda p, e: assert_code(p, "GET", "/__vazin_cms_health", e,
                                      source=OTHER_PEER, token=TOKEN)),
            ("qa-post-never-bypasses", 503,
             lambda p, e: assert_code(p, "POST", "/__vazin_cms_health", e, token=TOKEN)),
        ]
        for method, path in PUBLIC_PROBES + (("GET", CANARY_PATH),):
            label = f"public-{method}-{path}"
            strong_checks.append((label, 503,
                                  lambda p, e, m=method, path=path: assert_code(p, m, path, e)))
        # A correct public fence must stay closed even with XFF as the sole credential.
        strong_checks.append(("public-with-spoof-xff", 503,
                              lambda p, e: assert_code(p, "GET", "/health", e, source=OTHER_PEER,
                                                       xff=LOOPBACK_PEER)))
        exercise("strong_realip", strong_checks)
        results.append("transport_peer_guard_passed")
        exercise("asset_hole", [
            ("known-fixed-asset-probe-detects-priority-bypass", 200,
             lambda p, e: assert_code(p, "GET", "/assets/app.js", e))
        ])
        results.append("known_location_priority_bypass_reproduced")
        exercise("regex_hole", [
            ("unmodeled-php-location-remains-open", 200,
             lambda p, e: assert_code(p, "GET", PHP_EXCEPTION_PATH, e))
        ])
        results.append("unmodeled_regex_bypass_reproduced")
        unknown_checks = [
            ("unmodeled-exact-location-remains-open", 200,
             lambda p, e: assert_code(p, "GET", HIDDEN_PATH, e))
        ]
        for method, path in PUBLIC_PROBES + (("GET", CANARY_PATH),):
            unknown_checks.append((f"sampled-{method}-{path}", 503,
                                   lambda p, e, m=method, path=path: assert_code(p, m, path, e)))
        exercise("unknown_hole", unknown_checks)
        results.append("fixed_plus_canary_probes_not_comprehensive")
    except (OSError, RuntimeError, AssertionError, subprocess.SubprocessError) as exc:
        print(f"NO_GO ISOLATED_REHEARSAL_FAILED {type(exc).__name__}: {exc}", file=sys.stderr)
        return 70

    print("ISOLATED_NGINX_ADVERSARIAL_PASS " + json.dumps({
        "checks": len(results), "findings": results,
        "scope": "127.0.0.1-only synthetic nginx; no real vhost or tokens",
        "production": "NO_GO",
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
