"""Non-network, fail-closed tests of the per-site host traffic fence.

The acceptance test must *not* mutate or restart a real Nginx/FPM service.
"""
from __future__ import annotations

import hashlib
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("site_fence_guard", ROOT / "deploy" / "site_fence_guard.py")
assert SPEC and SPEC.loader
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class SiteFenceGuardTests(unittest.TestCase):
    TOKEN = "TestOnlyToken_20261010_" + "A" * 40
    HOST = "site.example.test"

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.file = Path(self.temp.name) / "root-token"
        self.file.write_text(self.TOKEN + "\n")
        os.chmod(self.file, 0o600)

    def test_root_style_secret_reader_accepts_exact_0600(self):
        self.assertEqual(guard.read_token(self.file, required_uid=os.getuid()), self.TOKEN)

    def test_reject_world_readable_file(self):
        self.file.chmod(0o644)
        with self.assertRaises(guard.FenceError):
            guard.read_token(self.file, required_uid=os.getuid())

    def test_reject_wrong_owner(self):
        with self.assertRaises(guard.FenceError):
            guard.read_token(self.file, required_uid=999999)

    def test_reject_symlink(self):
        link = self.file.parent / "link"
        link.symlink_to(self.file)
        with self.assertRaises(guard.FenceError):
            guard.read_token(link, required_uid=os.getuid())

    def test_reject_short_unsafe_token(self):
        self.file.write_text("demo\n")
        with self.assertRaises(guard.FenceError):
            guard.read_token(self.file, required_uid=os.getuid())

    @staticmethod
    def response(http=503, *, token=TOKEN, cache=True):
        digest = hashlib.sha256(token.encode()).hexdigest()
        return http, ("HTTP/2 " + str(http) + "\r\nx-vazin-release-fence: " +
                      digest + "\r\n" + ("cache-control: no-store\r\n" if cache else "")), ""

    def test_fenced_503_with_fingerprint_is_accepted(self):
        with patch.object(guard, "curl_request", return_value=self.response()):
            guard.check_fenced(self.HOST, 443, self.TOKEN)

    def test_unfenced_200_fails_closed(self):
        with patch.object(guard, "curl_request", return_value=self.response(http=200)):
            with self.assertRaisesRegex(guard.FenceError, "public_route_not_fenced"):
                guard.check_fenced(self.HOST, 443, self.TOKEN)

    def test_header_does_not_match_fails_closed(self):
        with patch.object(guard, "curl_request", return_value=self.response(token="B" * 48)):
            with self.assertRaisesRegex(guard.FenceError, "receipt_does_not_match"):
                guard.check_fenced(self.HOST, 443, self.TOKEN)

    def test_503_must_be_non_cacheable(self):
        with patch.object(guard, "curl_request", return_value=self.response(cache=False)):
            with self.assertRaisesRegex(guard.FenceError, "must_not_be_cached"):
                guard.check_fenced(self.HOST, 443, self.TOKEN)

    def test_qa_health_with_double_fence_observation(self):
        def reply(_host, _port, _token, *, qa, **kw):
            if qa:
                self.assertEqual(kw["path"], guard.QA_PATH)
                return 200, "HTTP/2 200\\r\\n\\r\\n", '{"ok":true,"service":"VazinCMS","version":"10.30.3"}'
            return self.response()
        with patch.object(guard, "curl_request", side_effect=reply) as observed:
            guard.check_qa_health(self.HOST, 443, self.TOKEN, "10.30.3")
            self.assertEqual(observed.call_count, len(guard.PUBLIC_PROBES) * 2 + 1)

    def test_qa_health_fails_on_old_version(self):
        def reply(_host, _port, _token, *, qa, **kw):
            if qa:
                return 200, "HTTP/2 200\\r\\n\\r\\n", '{"ok":true,"service":"VazinCMS","version":"10.29.0"}'
            return self.response()
        with patch.object(guard, "curl_request", side_effect=reply):
            with self.assertRaisesRegex(guard.FenceError, "not_expected_version"):
                guard.check_qa_health(self.HOST, 443, self.TOKEN, "10.30.3")

    def test_qa_bypass_must_not_be_public_503(self):
        with patch.object(guard, "curl_request", return_value=self.response()):
            with self.assertRaisesRegex(guard.FenceError, "bypass_unavailable"):
                guard.check_qa_health(self.HOST, 443, self.TOKEN, "10.30.3")

    def test_partial_fence_rejects_unblocked_assets(self):
        def reply(_host, _port, _token, *, qa, **kw):
            if kw.get("path") == "/assets/app.js":
                return self.response(http=200)
            return self.response()
        with patch.object(guard, "curl_request", side_effect=reply):
            with self.assertRaisesRegex(guard.FenceError, "public_route_not_fenced"):
                guard.check_fenced(self.HOST, 443, self.TOKEN)

    def test_post_internal_health_cannot_bypass_public_fence(self):
        def reply(_host, _port, _token, *, qa, **kw):
            if kw.get("method") == "POST":
                return self.response(http=200)
            return self.response()
        with patch.object(guard, "curl_request", side_effect=reply):
            with self.assertRaisesRegex(guard.FenceError, "public_route_not_fenced"):
                guard.check_fenced(self.HOST, 443, self.TOKEN)

    def test_fence_covers_multiple_location_classes(self):
        routes = set(guard.PUBLIC_PROBES)
        for path in ("/", "/health", "/login", "/admin", "/api/v3/content", "/assets/app.js", guard.QA_PATH):
            self.assertIn(("GET", path), routes)
        self.assertIn(("POST", guard.QA_PATH), routes)
        self.assertNotIn(("POST", "/api/v3/content"), routes)

    def test_private_health_endpoint_is_distinct_from_public_route(self):
        self.assertNotEqual(guard.QA_PATH, "/health")
        self.assertTrue(guard.QA_PATH.startswith("/__"))
        self.assertIn("site_qa_health_not_expected_version", (ROOT / "deploy/site_fence_guard.py").read_text())

    def test_qa_only_uses_private_internal_health_path(self):
        with self.assertRaisesRegex(guard.FenceError, "qa_must_use_internal_health_get"):
            guard.curl_request(self.HOST, 443, self.TOKEN, qa=True, path="/health")

    def test_curl_uses_verified_tls_loopback_no_follow(self):
        mock = type("Done", (), {"returncode":0, "stdout":"HTTP/2 503\r\n\r\nVAZIN_FENCE_HTTP_CODE:503\n", "stderr":""})()
        with patch.object(guard.subprocess, "run", return_value=mock) as run:
            guard.curl_request(self.HOST, 443, self.TOKEN, qa=False)
            args = run.call_args.args[0]
            self.assertIn("--resolve", args)
            self.assertIn("site.example.test:443:127.0.0.1", args)
            self.assertIn("--noproxy", args)
            self.assertIn("--max-redirs", args)
            self.assertNotIn("-k", args)
            self.assertNotIn("--insecure", args)

    def test_qa_token_is_sent_on_stdin_not_argv(self):
        mock = type("Done", (), {"returncode":0, "stdout":"HTTP/2 200\r\n\r\n{}\nVAZIN_FENCE_HTTP_CODE:200\n", "stderr":""})()
        with patch.object(guard.subprocess, "run", return_value=mock) as run:
            guard.curl_request(self.HOST, 443, self.TOKEN, qa=True, path=guard.QA_PATH)
            args = run.call_args.args[0]
            self.assertNotIn(self.TOKEN, " ".join(args))
            self.assertIn(self.TOKEN, run.call_args.kwargs["input"])
            self.assertIn("--config", args)

    def test_cli_without_root_receipt_fails_closed(self):
        with patch("sys.stderr"):
            self.assertEqual(guard.main(["check", "--host", self.HOST, "--token-file", str(self.file)]), 70) if os.getuid() != 0 else None


class ShellRolloutGuards(unittest.TestCase):
    def test_no_shared_nginx_mutation_ever(self):
        for name in ("deploy/install.sh", "deploy/rollback.sh"):
            source = (ROOT / name).read_text()
            for forbidden in ("systemctl stop nginx", "systemctl start nginx",
                              "systemctl restart nginx", "systemctl reload nginx",
                              "nginx -s reload"):
                self.assertNotIn(forbidden, source, (name, forbidden))

    def test_fence_checked_before_first_install_runtime_write(self):
        source = (ROOT / "deploy/install.sh").read_text()
        self.assertLess(source.index('python3 "$FENCE_SOURCE" check'), source.index('ensure-dir "$STATE_BASE"'))
        self.assertIn('site_fenced ||', source)
        self.assertIn('python3 "$DURABLE_FENCE" health', source)

    def test_failed_fence_cannot_trigger_unfenced_rollback(self):
        for name in ("deploy/install.sh", "deploy/rollback.sh"):
            source = (ROOT / name).read_text()
            self.assertIn('if site_fenced && python3', source)
            self.assertIn('NO_GO:', source)


if __name__ == "__main__":
    unittest.main()
