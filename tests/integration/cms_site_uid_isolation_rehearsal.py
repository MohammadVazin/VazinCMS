#!/usr/bin/env python3
"""ISOLATED ONLY: actual VazinCMS code with two Unix user identities.

No production DB, .env, service, users, DNS or Nginx modifications.
Uses existing test identities www-data and nobody only inside a private
network namespace. Must be invoked as root from an explicitly isolated host.
"""
from __future__ import annotations

import grp
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
if os.geteuid() != 0:
    raise SystemExit("ISOLATED_ROOT_REQUIRED")
if not (ROOT / "VERSION").is_file():
    raise SystemExit("SOURCE_RELEASE_UNAVAILABLE")
EXPECTED_VERSION = (ROOT / "VERSION").read_text().strip()


def freeport():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def fatal(log_dir: Path, message: str):
    # Never print temporary .env or launch arguments containing secrets.
    print("ISOLATED_CHECK_FAILED", message)
    for f in ("nginx.log", "travel.log", "visa.log"):
        path = log_dir / f
        if path.is_file():
            lines = path.read_text(errors="replace").splitlines()[-5:]
            print("TEST_LOG_TYPES", f, [line.split(":")[0][-90:] for line in lines])
    raise SystemExit(70)


with tempfile.TemporaryDirectory(prefix="cms-distinct-uid-", dir="/dev/shm") as folder:
    base = Path(folder)
    base.chmod(0o755)
    work = base / "work"
    work.mkdir(mode=0o755)
    # Two *pre-existing* and different Unix identities; no useradd/usermod.
    people = {
        "travel": pwd.getpwnam("www-data"),
        "visa": pwd.getpwnam("nobody"),
    }
    assert people["travel"].pw_uid != people["visa"].pw_uid
    ports = {k: (work / (k + ".sock")) for k in people}
    sites: dict[str, dict] = {}
    fixture_php = base / "identity.php"
    fixture_php.write_text("""<?php
header('Content-Type: application/json');
echo json_encode([
  'uid' => posix_geteuid(),
  'own_env' => is_readable($_SERVER['LAB_OWN_ENV']),
  'own_db' => is_readable($_SERVER['LAB_OWN_DB']),
  'own_storage_write' => is_writable($_SERVER['LAB_OWN_STORAGE']),
  'other_env' => is_readable($_SERVER['LAB_OTHER_ENV']),
  'other_db' => is_readable($_SERVER['LAB_OTHER_DB']),
]);
""")
    fixture_php.chmod(0o644)
    for name, identity in people.items():
        other = "visa" if name == "travel" else "travel"
        group = grp.getgrgid(identity.pw_gid).gr_name
        code = base / (name + "-code")
        shutil.copytree(ROOT, code, ignore=shutil.ignore_patterns(
            ".git", ".env", "__pycache__", "*.pyc", "storage", "uploads",
        ))
        # Disposable immutable test code, not a production release installation.
        for path in code.rglob("*"):
            if path.is_dir():
                path.chmod(0o755)
            elif path.is_file():
                path.chmod(0o644)
        code.chmod(0o755)
        runtime = base / (name + "-runtime")
        runtime.mkdir(mode=0o755)
        storage = runtime / "storage"
        uploads = runtime / "uploads"
        sessions = runtime / "sessions"
        for directory in (storage, uploads, sessions):
            directory.mkdir(mode=0o700)
            os.chown(directory, identity.pw_uid, identity.pw_gid)
        db = storage / "database.sqlite"
        with sqlite3.connect(str(db)) as con:
            con.execute("CREATE TABLE sanity(id INTEGER PRIMARY KEY, name TEXT)")
            con.execute("INSERT INTO sanity(name) VALUES(?)", (name,))
        os.chown(db, identity.pw_uid, identity.pw_gid)
        db.chmod(0o600)
        lock = storage / "installed.lock"
        lock.write_text("isolated-only\n")
        os.chown(lock, identity.pw_uid, identity.pw_gid)
        lock.chmod(0o600)
        config = code / ".env"
        config.write_text(
            "APP_ENV=testing\n"
            "APP_URL=https://" + name + ".example.test\n"
            "SESSION_SECURE=true\n"
            "APP_KEY=test-isolated-" + name + "-" + ("a" * 64) + "\n"
            "DB_CONNECTION=sqlite\n"
            "DB_DATABASE=" + str(db) + "\n"
            "VAZINCMS_STORAGE_PATH=" + str(storage) + "\n"
            "VAZINCMS_UPLOADS_PATH=" + str(uploads) + "\n"
            "EXTERNAL_DELIVERY_ENABLED=false\n"
            "TELEGRAM_NETWORK_ENABLED=false\n"
            "TELEGRAM_ALERT_DELIVERY_ENABLED=false\n"
            "VAZIN_VISA_DIRECT_SALES_ENABLED=false\n"
            "TRAVEL_VISA_COMMERCIAL_ENABLED=false\n"
        )
        os.chown(config, 0, identity.pw_gid)
        config.chmod(0o640)
        conf = base / (name + "-fpm.conf")
        conf.write_text(
            "[global]\n"
            f"pid={base}/{name}.pid\n"
            f"error_log={base}/{name}.log\n"
            "daemonize=no\n"
            f"[{name}]\n"
            f"user={identity.pw_name}\n"
            f"group={group}\n"
            f"listen={ports[name]}\n"
            "listen.owner=www-data\n"
            "listen.group=www-data\n"
            "listen.mode=0660\n"
            "pm=static\n"
            "pm.max_children=1\n"
            "clear_env=yes\n"
            f"php_admin_value[session.save_path]={sessions}\n"
        )
        check = subprocess.run(["/usr/sbin/php-fpm8.5", "-tt", "--fpm-config", str(conf)],
                               capture_output=True, timeout=8)
        if check.returncode:
            fatal(base, name + "_php_fpm_config_failed")
        sites[name] = {
            "code": code, "env": config, "db": db, "storage": storage,
            "conf": conf, "uid": identity.pw_uid, "sock": ports[name],
        }
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    cert, key = base / "cert.pem", base / "key.pem"
    command = [
        "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
        "-days", "1", "-keyout", str(key), "-out", str(cert),
        "-subj", "/CN=travel.example.test",
        "-addext", "subjectAltName=DNS:travel.example.test,DNS:visa.example.test",
    ]
    subprocess.run(command, check=True, capture_output=True, timeout=16)
    blocks = []
    for name in sites:
        site, other = sites[name], sites["visa" if name == "travel" else "travel"]
        blocks.append(f"""
 server {{
  listen 127.0.0.1:{port} ssl;
  server_name {name}.example.test;
  ssl_certificate {cert};
  ssl_certificate_key {key};
  root {site['code']}/public;
  index index.php;
  location = /__lab_identity {{
    include /etc/nginx/fastcgi_params;
    fastcgi_param SCRIPT_FILENAME {fixture_php};
    fastcgi_param LAB_OWN_ENV {site['env']};
    fastcgi_param LAB_OWN_DB {site['db']};
    fastcgi_param LAB_OWN_STORAGE {site['storage']};
    fastcgi_param LAB_OTHER_ENV {other['env']};
    fastcgi_param LAB_OTHER_DB {other['db']};
    fastcgi_pass unix:{site['sock']};
  }}
  location = /index.php {{
    include /etc/nginx/fastcgi_params;
    fastcgi_param SCRIPT_FILENAME {site['code']}/public/index.php;
    fastcgi_param HTTPS on;
    fastcgi_pass unix:{site['sock']};
  }}
  location / {{ try_files $uri $uri/ /index.php?$query_string; }}
  location ~ \\.php$ {{ return 404; }}
 }}
""")
    config = base / "nginx.conf"
    config.write_text(
        "user www-data;\n"
        "worker_processes 1;\n"
        f"pid {base}/nginx.pid;\n"
        f"error_log {base}/nginx.log warn;\n"
        "events { worker_connections 128; }\n"
        "http { access_log off;\n" + "\n".join(blocks) + "\n}\n"
    )
    syntax = subprocess.run(
        ["/usr/sbin/nginx", "-t", "-c", str(config)],
        capture_output=True, timeout=9,
    )
    if syntax.returncode:
        fatal(base, "nginx_config_invalid")
    processes: dict[str, subprocess.Popen] = {}
    try:
        for name, site in sites.items():
            processes[name] = subprocess.Popen(
                ["/usr/sbin/php-fpm8.5", "--nodaemonize", "--fpm-config", str(site["conf"])],
                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
        processes["nginx"] = subprocess.Popen(
            ["/usr/sbin/nginx", "-c", str(config), "-g", "daemon off;"],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )

        def get(name, path):
            host = name + ".example.test"
            result = subprocess.run([
                "curl", "--silent", "--show-error", "--noproxy", "*",
                "--max-time", "3", "--cacert", str(cert),
                "--resolve", f"{host}:{port}:127.0.0.1",
                f"https://{host}:{port}" + path,
            ], capture_output=True, text=True, timeout=5)
            if result.returncode:
                return None
            try:
                return json.loads(result.stdout)
            except ValueError:
                return None

        ready = False
        for attempt in range(40):
            if all((get(name, "/health") or {}).get("version") == EXPECTED_VERSION for name in people):
                ready = True
                break
            time.sleep(.12)
        print("TWO_ACTUAL_CMS_HEALTH_ROUTES_PASS", ready, flush=True)
        if not ready:
            fatal(base, "actual_cms_health_unavailable")

        for name, site in sites.items():
            actual = get(name, "/__lab_identity")
            if actual is None:
                fatal(base, name + "_identity_result_missing")
            expected = (actual.get("uid") == site["uid"]
                        and actual.get("own_env") is True
                        and actual.get("own_db") is True
                        and actual.get("own_storage_write") is True
                        and actual.get("other_env") is False
                        and actual.get("other_db") is False)
            print("SITE_IDENTITY", name, "UNIX_UID", actual.get("uid"),
                  "OWN_ACCESS", actual.get("own_env"), actual.get("own_db"),
                  "OTHER_ACCESS_BLOCKED", not actual.get("other_env") and not actual.get("other_db"),
                  "PASS", expected, flush=True)
            if not expected:
                fatal(base, name + "_site_identity_isolation_failure")

        processes["travel"].terminate()
        processes["travel"].wait(timeout=4)
        visa_survival = [
            (get("visa", "/health") or {}).get("version") == EXPECTED_VERSION
            for _ in range(8)
        ]
        print("VISA_HEALTH_AFTER_TRAVEL_MASTER_TERMINATES", sum(visa_survival), "/", 8, flush=True)
        if not all(visa_survival):
            fatal(base, "visa_not_independent_of_travel_fpm")
    finally:
        for p in reversed(list(processes.values())):
            if p.poll() is None:
                p.terminate()
                try:
                    p.wait(timeout=4)
                except subprocess.TimeoutExpired:
                    p.kill()
                    p.wait(timeout=4)
        print("ALL_STAGING_MASTERS_STOPPED", all(p.poll() is not None for p in processes.values()),
              flush=True)
print("ALL_TEST_FILES_REMOVED", True, flush=True)
