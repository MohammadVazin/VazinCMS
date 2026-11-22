#!/usr/bin/env python3
"""Integration checks for the isolated VazinCMS global tenant fail-closed gate."""
import http.client
import os
import pathlib
import socket
import sqlite3
import subprocess
import tempfile
import time

root = pathlib.Path(__file__).resolve().parent.parent

with tempfile.TemporaryDirectory(prefix="vazincms-security-http-") as scratch:
    work = pathlib.Path(scratch)
    db = work / "only-synthetic-data.sqlite"
    conn = sqlite3.connect(db)
    conn.executescript("""
        CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,site_key TEXT,status TEXT);
        CREATE TABLE cms_site_domains(site_id INTEGER,domain TEXT,verified_at TEXT,is_primary INTEGER NOT NULL DEFAULT 0);
        INSERT INTO cms_sites VALUES(1,'default','active');
        INSERT INTO cms_site_domains(site_id,domain,verified_at) VALUES(1,'alpha.example.test','2026-11-01 00:00:00');
    """)
    conn.commit()
    conn.close()
    router = work / "router.php"
    entry = str(root / "public" / "index.php").replace("'", "\\'")
    router.write_text("<?php require '" + entry + "';\n", encoding="utf-8")
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    env = os.environ.copy()
    env.update({
        "DB_CONNECTION": "sqlite",
        "DB_DATABASE": str(db),
        "SESSION_SECURE": "false",
        "VAZINCMS_STORAGE_PATH": str(work),
        "APP_URL": "http://alpha.example.test",
    })
    logfile = (work / "server.log").open("w", encoding="utf-8")
    p = subprocess.Popen(
        ["php", "-d", "session.save_path=" + str(work), "-S", f"127.0.0.1:{port}",
         "-t", str(root / "public"), str(router)],
        env=env, stdout=logfile, stderr=subprocess.STDOUT,
        cwd=str(work),
    )
    def request(path, host="alpha.example.test", accept="text/html"):
        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=4)
        try:
            connection.request("GET", path, headers={"Host": host, "Accept": accept})
            response = connection.getresponse()
            return response.status, dict((key.lower(), value) for key, value in response.getheaders()), response.read().decode("utf-8", "replace")
        finally:
            connection.close()

    try:
        for i in range(50):
            try:
                status, headers, body = request("/health")
                break
            except (ConnectionError, TimeoutError, OSError):
                time.sleep(0.10)
        else:
            raise RuntimeError("PHP server not ready")
        assert status == 200, ("health", status, body[:180])
        print("HEALTH_SINGLE_SITE=PASS")

        status, headers, body = request("/", "other.example.test")
        assert status == 421 and "دسترسی سایت" in body, ("unknown host", status, body[:160])
        print("UNKNOWN_HOST_REJECTED=PASS http=421")

        status, headers, body = request("/", "alpha.example.test")
        assert status == 302 and headers.get("location") == "/install", ("canonical host", status, headers)
        print("VERIFIED_SINGLE_SITE_HOST=PASS passes gate")

        conn = sqlite3.connect(db)
        conn.execute("INSERT INTO cms_sites VALUES(2,'beta','active')")
        conn.execute("INSERT INTO cms_site_domains(site_id,domain,verified_at) VALUES(2,'beta.example.test','2026-11-01 00:00:00')")
        conn.commit()
        conn.close()

        paths = ["/", "/fa", "/fa/page/example", "/login", "/admin",
                 "/admin/pages", "/admin/media/picker", "/auth/vazin-id/start",
                 "/auth/vazin-id/callback", "/api/v3/content", "/api/v3/forms",
                 "/search", "/uploads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png"]
        checks = 0
        for host in ("alpha.example.test", "beta.example.test", "unverified.example.test"):
            for path in paths:
                is_json = path.startswith("/api/") or path == "/search"
                status, headers, body = request(path, host, "application/json" if is_json else "text/html")
                assert status == 409, ("unsafe dynamic route", host, path, status, body[:180])
                assert "no-store" in headers.get("cache-control", ""), ("not cacheable", host, path)
                assert "candidate-private-tenant-marker" not in body
                if is_json:
                    assert '"code":"multisite_unisolated"' in body, ("json reason", host, path, body[:170])
                checks += 1
        print("MULTITENANT_DYNAMIC_HTTP_BLOCKED=PASS routes=" + str(checks))
        status, headers, body = request("/health", "beta.example.test")
        assert status == 200, ("health remains reachable", status)
        print("HEALTH_MULTISITE=PASS")
    except Exception:
        logfile.flush()
        print("PHP_SERVER_LOG_TAIL:")
        print("\n".join((work / "server.log").read_text(encoding="utf-8", errors="replace").splitlines()[-12:]))
        raise
    finally:
        p.terminate()
        try:
            p.wait(timeout=3)
        except subprocess.TimeoutExpired:
            p.kill()
            p.wait(timeout=3)
        logfile.close()

print("SITE_ISOLATION_HTTP_QA=PASS (no live databases, domains, or production services used)")
