#!/usr/bin/env python3
"""Manual, synthetic 2-site PHP-FPM write-barrier / epoch rehearsal.

Only isolated PHP-FPM masters and Unix sockets under a fresh /dev/shm dir.
Never load live vhosts, DB, services, customer data or production token files.
The write barrier is an *instrumented mutation-boundary lab fixture*, NOT a
drop-in production gate or a global guarantee across arbitrary PHP writes.
"""
from __future__ import annotations

import fcntl
import json
import os
from pathlib import Path
import pwd
import re
import signal
import stat
import subprocess
import sys
import tempfile
import threading
import time

FPM = "/usr/sbin/php-fpm8.5"
FASTCGI = "/usr/bin/cgi-fcgi"
NO_GO = 70
EPOCH = re.compile(r"^(OPEN|CLOSED):g([1-9][0-9]{0,3})$")
SITE_NAMES = ("travel", "visa")

# The PHP fixture has just one instrumented mutation boundary. A production
# implementation must guard EVERY side effect (DB, sessions, queue, external
# calls, cron, payment callbacks and webhook handlers).
PHP_PROGRAM = r'''
error_reporting(0);
function deny_write() {
    http_response_code(503);
    echo "DENIED";
    exit;
}
function mark($file, $word) {
    $bytes = @file_put_contents($file, $word, LOCK_EX);
    if ($bytes === false) { deny_write(); }
}
function wait_release($release) {
    $deadline = microtime(true) + 7.0;
    while (!file_exists($release) && microtime(true) < $deadline) {
        usleep(15000);
    }
    if (!file_exists($release)) { deny_write(); }
}
if ($mode === "unsafe_wait") {
    mark($started, "STARTED");
    wait_release($release);
    mark($marker, "UNSAFE_WRITTEN");
    http_response_code(200); echo "UNSAFE_COMMITTED";
    exit;
}
if ($mode === "guarded_wait") {
    mark($started, "STARTED");
    wait_release($release);
}
$handle = @fopen($barrier, "rb");
if ($handle === false) { deny_write(); }
if (!@flock($handle, LOCK_EX)) { @fclose($handle); deny_write(); }
if ($mode === "guarded_hold") {
    mark($held, "LOCK_HELD");
    wait_release($release);
}
rewind($handle);
$state = trim((string)stream_get_contents($handle));
if ($state !== "OPEN:" . $generation) {
    flock($handle, LOCK_UN); fclose($handle); deny_write();
}
// Atomic with the coordinator's CLOSE: marker mutation while LOCK_EX held.
// Not a generalized DB transaction or external-side-effect barrier.
mark($marker, "PROTECTED_WRITTEN");
flock($handle, LOCK_UN);
fclose($handle);
http_response_code(200); echo "PROTECTED_COMMITTED";
'''


class RehearsalError(Exception):
    pass


def state_transition(path: Path, expected: str, new_state: str) -> None:
    """Flock-serialized generation-fenced state transition, scratch only."""
    prior = EPOCH.fullmatch(expected)
    after = EPOCH.fullmatch(new_state)
    if prior is None or after is None:
        raise RehearsalError("invalid_state_format")
    old_gen, new_gen = int(prior.group(2)), int(after.group(2))
    if not (
        (prior.group(1) == "OPEN" and after.group(1) == "CLOSED" and old_gen == new_gen)
        or
        (prior.group(1) == "CLOSED" and after.group(1) == "OPEN" and new_gen == old_gen + 1)
    ):
        raise RehearsalError("unsafe_state_transition")
    if not path.is_absolute() or ".." in path.parts:
        raise RehearsalError("unsafe_barrier_path")
    nofollow = getattr(os, "O_NOFOLLOW", None)
    if nofollow is None:
        raise RehearsalError("nofollow_not_supported")
    try:
        fd = os.open(path, os.O_RDWR | nofollow)
    except OSError:
        raise RehearsalError("barrier_missing_or_unreadable") from None
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o644):
            raise RehearsalError("barrier_file_identity_rejected")
        with os.fdopen(fd, "r+", encoding="ascii", closefd=False) as handle:
            fcntl.flock(fd, fcntl.LOCK_EX)
            current = handle.read(64).strip()
            if current != expected:
                raise RehearsalError("barrier_generation_mismatch")
            handle.seek(0)
            handle.write(new_state + "\n")
            handle.truncate()
            handle.flush()
            os.fsync(fd)
            fcntl.flock(fd, fcntl.LOCK_UN)
    finally:
        os.close(fd)


def php_fixture(path: Path, site: str, generation: str, mode: str,
                barrier: Path, markers: Path, tag: str) -> dict[str, Path]:
    if site not in SITE_NAMES or mode not in {
        "unsafe_wait", "guarded_wait", "guarded_hold", "guarded_now"
    } or not re.fullmatch(r"g[1-9][0-9]{0,3}", generation) or not re.fullmatch(r"[a-z0-9_]{1,40}", tag):
        raise RehearsalError("invalid_fixture_input")
    files = {key: markers / (tag + "." + key) for key in ("started", "release", "held", "marker")}
    values = {
        "barrier": str(barrier),
        "generation": generation,
        "mode": mode,
        **{key: str(value) for key, value in files.items()},
    }
    definitions = "".join("$" + key + " = " + json.dumps(value) + ";\n"
                          for key, value in values.items())
    path.write_text("<?php\n" + definitions + PHP_PROGRAM, encoding="utf-8")
    path.chmod(0o644)
    return files


def fastcgi_env(script: Path) -> dict[str, str]:
    """No inherited secrets or proxy environment variables."""
    return {
        "PATH": "/usr/bin:/bin",
        "REQUEST_METHOD": "GET",
        "SCRIPT_FILENAME": str(script),
        "SCRIPT_NAME": "/" + script.name,
        "REQUEST_URI": "/" + script.name,
        "QUERY_STRING": "",
        "GATEWAY_INTERFACE": "CGI/1.1",
        "SERVER_PROTOCOL": "HTTP/1.1",
        "SERVER_SOFTWARE": "VazinCMS-OP02.13-LAB",
        "SERVER_NAME": "localhost",
        "REMOTE_ADDR": "127.0.0.1",
        "DOCUMENT_ROOT": str(script.parent),
    }


def request(socket: Path, script: Path) -> subprocess.Popen:
    return subprocess.Popen(
        [FASTCGI, "-bind", "-connect", str(socket)],
        env=fastcgi_env(script),
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        start_new_session=True,
    )


def response(process: subprocess.Popen, *, expected_status: int, expected_word: str) -> None:
    try:
        stdout, _stderr = process.communicate(timeout=12)
    except subprocess.TimeoutExpired:
        process.kill()
        process.communicate(timeout=2)
        raise RehearsalError("fastcgi_request_timeout") from None
    if process.returncode != 0:
        raise RehearsalError("fastcgi_client_failed")
    payload = stdout.decode("utf-8", errors="replace")
    status_matches = re.findall(r"(?im)^Status:\s*(\d{3})\b", payload)
    code = int(status_matches[-1]) if status_matches else 200
    if code != expected_status or expected_word not in payload:
        raise RehearsalError("fastcgi_expected_response_mismatch")


def await_file(path: Path, *, seconds: float = 6) -> None:
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if path.is_file():
            return
        time.sleep(0.02)
    raise RehearsalError("test_handshake_not_observed")


def isolate_fpm(root: Path, site: str) -> tuple[Path, subprocess.Popen]:
    """Spawn an individual test PHP-FPM master; no Nginx or live pool."""
    if site not in SITE_NAMES:
        raise RehearsalError("site_label_invalid")
    cfg = root / (site + ".conf")
    socket = root / (site + ".sock")
    cfg.write_text(
        "[global]\n"
        f"pid={root}/{site}.pid\n"
        f"error_log={root}/{site}.log\n"
        "daemonize=no\n"
        f"[{site}]\n"
        "user=www-data\n"
        "group=www-data\n"
        f"listen={socket}\n"
        "listen.owner=www-data\n"
        "listen.group=www-data\n"
        "listen.mode=0660\n"
        "pm=static\n"
        "pm.max_children=2\n"
        "clear_env=yes\n",
        encoding="ascii",
    )
    check = subprocess.run(
        [FPM, "-tt", "--fpm-config", str(cfg)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        timeout=8, check=False,
    )
    if check.returncode != 0:
        raise RehearsalError("isolated_fpm_config_rejected")
    master = subprocess.Popen(
        [FPM, "--nodaemonize", "--fpm-config", str(cfg)],
        stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL, start_new_session=True,
    )
    try:
        for _ in range(75):
            if master.poll() is not None:
                raise RehearsalError("isolated_fpm_master_failed")
            if socket.is_socket():
                return socket, master
            time.sleep(0.04)
        raise RehearsalError("isolated_fpm_socket_timeout")
    except (RehearsalError, OSError):
        # If readiness fails before registration with the experiment,
        # we still own and must reap this specific synthetic master.
        close_process(master)
        raise


def close_process(process: subprocess.Popen) -> None:
    if process.poll() is None:
        process.terminate()  # only our detached child, NOT system php8.5-fpm
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=2)


def run_experiment() -> dict:
    if os.geteuid() != 0 or not all(Path(binary).is_file() for binary in (FPM, FASTCGI)):
        raise RehearsalError("root_with_local_php_fpm_and_cgi_fcgi_required")
    pwdinfo = pwd.getpwnam("www-data")
    cases: list[str] = []
    masters: list[subprocess.Popen] = []
    children: list[subprocess.Popen] = []
    threads: list[threading.Thread] = []
    with tempfile.TemporaryDirectory(prefix="vazincms-op0213-fpm-", dir="/dev/shm") as temp:
        root = Path(temp)
        root.chmod(0o755)
        roots: dict[str, Path] = {}
        markers: dict[str, Path] = {}
        barriers: dict[str, Path] = {}
        sock: dict[str, Path] = {}
        for site in SITE_NAMES:
            site_root = root / ("source_" + site)
            site_root.mkdir(mode=0o755)
            roots[site] = site_root
            mark_dir = root / ("markers_" + site)
            mark_dir.mkdir(mode=0o700)
            os.chown(mark_dir, pwdinfo.pw_uid, pwdinfo.pw_gid)
            markers[site] = mark_dir
            barrier = root / (site + ".barrier")
            barrier.write_text("OPEN:g1\n", encoding="ascii")
            barrier.chmod(0o644)
            barriers[site] = barrier
        try:
            for site in SITE_NAMES:
                socket, master = isolate_fpm(root, site)
                sock[site] = socket
                masters.append(master)
            # The unsafe control intentionally writes AFTER CLOSE. The
            # completion after close is an expected NEGATIVE result.
            unsafe_script = roots["travel"] / "unsafe_g1.php"
            unsafe = php_fixture(unsafe_script, "travel", "g1", "unsafe_wait",
                                 barriers["travel"], markers["travel"], "unsafe_g1")
            p = request(sock["travel"], unsafe_script)
            children.append(p)
            await_file(unsafe["started"])
            state_transition(barriers["travel"], "OPEN:g1", "CLOSED:g1")
            unsafe["release"].write_text("GO")
            response(p, expected_status=200, expected_word="UNSAFE_COMMITTED")
            if not unsafe["marker"].is_file():
                raise RehearsalError("negative_control_not_reproduced")
            cases.append("unguarded_inflight_write_after_close_REPRODUCED")

            # Next generation guarded writer already entered FPM before the
            # CLOSE, but cannot commit through the lock after CLOSE or OPEN:g3.
            state_transition(barriers["travel"], "CLOSED:g1", "OPEN:g2")
            guard_script = roots["travel"] / "pending_g2.php"
            pending = php_fixture(guard_script, "travel", "g2", "guarded_wait",
                                  barriers["travel"], markers["travel"], "pending_g2")
            p = request(sock["travel"], guard_script)
            children.append(p)
            await_file(pending["started"])
            state_transition(barriers["travel"], "OPEN:g2", "CLOSED:g2")
            cases.append("travel_barrier_closed_while_request_inflight")

            immediate_script = roots["travel"] / "immediate_g2.php"
            immediate = php_fixture(immediate_script, "travel", "g2", "guarded_now",
                                    barriers["travel"], markers["travel"], "immediate_g2")
            q = request(sock["travel"], immediate_script)
            children.append(q)
            response(q, expected_status=503, expected_word="DENIED")
            if immediate["marker"].exists():
                raise RehearsalError("write_during_closed_barrier")
            cases.append("new_travel_write_during_closed_barrier_DENIED")

            # Independent Visa pool and separate barrier must continue to
            # accept its isolated mutation while Travel is CLOSED.
            visa_script = roots["visa"] / "visa_g1.php"
            visa = php_fixture(visa_script, "visa", "g1", "guarded_now",
                               barriers["visa"], markers["visa"], "visa_g1")
            q = request(sock["visa"], visa_script)
            children.append(q)
            response(q, expected_status=200, expected_word="PROTECTED_COMMITTED")
            if not visa["marker"].is_file():
                raise RehearsalError("independent_visa_write_unavailable")
            cases.append("visa_independent_pool_unaffected")

            state_transition(barriers["travel"], "CLOSED:g2", "OPEN:g3")
            pending["release"].write_text("GO")
            response(p, expected_status=503, expected_word="DENIED")
            if pending["marker"].exists():
                raise RehearsalError("stale_g2_write_after_g3_open")
            cases.append("stale_inflight_g2_after_reopen_g3_DENIED")

            fresh_script = roots["travel"] / "fresh_g3.php"
            fresh = php_fixture(fresh_script, "travel", "g3", "guarded_now",
                                barriers["travel"], markers["travel"], "fresh_g3")
            q = request(sock["travel"], fresh_script)
            children.append(q)
            response(q, expected_status=200, expected_word="PROTECTED_COMMITTED")
            if not fresh["marker"].is_file():
                raise RehearsalError("fresh_generation_write_not_accepted")
            cases.append("current_generation_g3_write_ACCEPTED")

            # A protected operation holding the mutation lock must finish
            # BEFORE CLOSE can claim completion. No fixed sleep is a drain
            # receipt; this checks explicit lock ordering in synthetic code.
            hold_script = roots["travel"] / "hold_g3.php"
            hold = php_fixture(hold_script, "travel", "g3", "guarded_hold",
                               barriers["travel"], markers["travel"], "hold_g3")
            p = request(sock["travel"], hold_script)
            children.append(p)
            await_file(hold["held"])
            started = threading.Event()
            closed = threading.Event()
            failed: list[str] = []
            def closure() -> None:
                started.set()
                try:
                    state_transition(barriers["travel"], "OPEN:g3", "CLOSED:g3")
                except RehearsalError:
                    failed.append("barrier_close_failed")
                finally:
                    closed.set()
            thread = threading.Thread(target=closure, daemon=True)
            threads.append(thread)
            thread.start()
            started.wait(timeout=2)
            time.sleep(0.15)
            if closed.is_set():
                raise RehearsalError("close_passed_while_protected_write_lock_held")
            hold["release"].write_text("GO")
            response(p, expected_status=200, expected_word="PROTECTED_COMMITTED")
            thread.join(timeout=5)
            if failed or not closed.is_set() or not hold["marker"].is_file():
                raise RehearsalError("atomic_mutation_then_close_order_invalid")
            cases.append("protected_write_finishes_BEFORE_close_acknowledged")

            late_script = roots["travel"] / "late_g3.php"
            late = php_fixture(late_script, "travel", "g3", "guarded_now",
                               barriers["travel"], markers["travel"], "late_g3")
            q = request(sock["travel"], late_script)
            children.append(q)
            response(q, expected_status=503, expected_word="DENIED")
            if late["marker"].is_file():
                raise RehearsalError("write_after_close_complete")
            cases.append("guarded_write_after_close_DENIED")

            barriers["travel"].unlink()  # only generated scratch, never production
            q = request(sock["travel"], late_script)
            children.append(q)
            response(q, expected_status=503, expected_word="DENIED")
            cases.append("missing_barrier_FAIL_CLOSED")

        finally:
            for child in reversed(children):
                close_process(child)
            for master in reversed(masters):
                close_process(master)
            for thread in threads:
                thread.join(timeout=4)
            if any(proc.poll() is None for proc in children + masters) or any(thread.is_alive() for thread in threads):
                raise RehearsalError("test_process_cleanup_unverified")
    return {
        "kind": "ISOLATED_TWO_FPM_WRITE_BARRIER_REHEARSAL",
        "cases": len(cases),
        "observed": cases,
        "production": "NO_GO",
        "release_authorized": False,
        "live_per_site_drain_verified": False,
        "live_queue_or_cron_writers_verified": False,
        "php_db_external_side_effects_covered": False,
        "scope": "synthetic_php_files_and_unix_sockets_only",
        "temporary_test_processes_cleaned": True,
    }


def main() -> int:
    try:
        result = run_experiment()
    except (RehearsalError, OSError, subprocess.SubprocessError) as exc:
        # Avoid path, command line or PHP response leak; only fixed error code.
        print('{"status":"ISOLATED_REHEARSAL_FAILED","production":"NO_GO"}')
        return NO_GO
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
