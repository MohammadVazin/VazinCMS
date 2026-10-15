"""Contract tests for the opt-in, loopback-only Nginx adversarial rehearsal.

These tests check fixture design; the real-Nginx test must additionally be run
explicitly on a suitable isolated host. They never launch/modify live services.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILE = ROOT / "tests/integration/isolated_nginx_fence_realip_adversarial.py"
SPEC = importlib.util.spec_from_file_location("isolated_nginx_fence_realip_adversarial", FILE)
assert SPEC is not None and SPEC.loader is not None
fixture = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(fixture)

GUARD_FILE = ROOT / "deploy/site_fence_guard.py"
GUARD_SPEC = importlib.util.spec_from_file_location("site_fence_guard_nginx_contract", GUARD_FILE)
assert GUARD_SPEC is not None and GUARD_SPEC.loader is not None
guard = importlib.util.module_from_spec(GUARD_SPEC)
GUARD_SPEC.loader.exec_module(guard)


class RealIpNginxRehearsalContract(unittest.TestCase):
    def test_public_samples_match_actual_guard(self):
        self.assertEqual(fixture.PUBLIC_PROBES, guard.PUBLIC_PROBES)
        self.assertRegex(fixture.CANARY_PATH, r"^/__vazin_fence_probe_[a-f0-9]{24}$")
        self.assertNotIn(("POST", fixture.CANARY_PATH), fixture.PUBLIC_PROBES)

    def test_never_listens_on_public_interface_or_production_port(self):
        for mode in ("weak_realip", "strong_realip", "asset_hole",
                     "regex_hole", "unknown_hole"):
            config = fixture.render_config(34567, mode=mode)
            self.assertIn("listen 127.0.0.1:34567;", config)
            self.assertNotIn("listen 0.0.0.0:", config)
            self.assertNotIn("listen 443;", config)
            self.assertNotIn("listen 80;", config)
        for port in (0, 80, 443, 1024, 65536):
            with self.assertRaises(ValueError):
                fixture.render_config(port, mode="strong_realip")

    def test_realip_fixture_can_reproduce_naive_remote_addr_bypass(self):
        weak = fixture.render_config(34567, mode="weak_realip")
        self.assertIn('map "$remote_addr|$http_x_vazin_qa_fence_token"', weak)
        self.assertIn("real_ip_header X-Forwarded-For;", weak)
        self.assertIn("set_real_ip_from 127.0.0.2;", weak)

    def test_hardened_fixture_checks_transport_peer_not_rewritten_ip(self):
        strong = fixture.render_config(34567, mode="strong_realip")
        self.assertIn("map $realip_remote_addr $transport_peer", strong)
        self.assertIn('map "$transport_peer|$http_x_vazin_qa_fence_token"', strong)
        self.assertIn('"" $remote_addr;', strong)

    def test_private_health_requires_get_and_synthetic_token(self):
        strong = fixture.render_config(34567, mode="strong_realip")
        self.assertIn("if ($request_method != GET) { return 503; }", strong)
        self.assertIn("if ($qa_allow = 0) { return 503; }", strong)
        self.assertIn(f'"127.0.0.1|{fixture.TOKEN}" 1;', strong)
        self.assertNotIn("VAZINCMS_SITE_FENCE_TOKEN_FILE", strong)
        self.assertTrue(fixture.TOKEN.startswith("Synthetic_Fence_Test_Only_"))

    def test_location_priority_negative_controls_are_explicit(self):
        safe = fixture.render_config(34567, mode="strong_realip")
        asset = fixture.render_config(34567, mode="asset_hole")
        regex = fixture.render_config(34567, mode="regex_hole")
        hidden = fixture.render_config(34567, mode="unknown_hole")
        self.assertIn("location ^~ /assets/ { return 503; }", safe)
        self.assertIn("location ^~ /assets/ { return 200; }", asset)
        self.assertIn(r"location ~ \.php$ { return 200; }", regex)
        self.assertIn(f"location = {fixture.HIDDEN_PATH} {{ return 200", hidden)
        self.assertNotIn(f"location = {fixture.HIDDEN_PATH} {{ return 200", safe)

    def test_fence_receipt_and_cache_policy_present_in_all_fixtures(self):
        for mode in ("weak_realip", "strong_realip", "asset_hole",
                     "regex_hole", "unknown_hole"):
            config = fixture.render_config(34567, mode=mode)
            self.assertIn("add_header X-Vazin-Release-Fence", config)
            self.assertIn(fixture.EXPECTED_FINGERPRINT, config)
            self.assertIn('add_header Cache-Control "no-store" always;', config)

    def test_runner_has_no_nginx_service_control_or_real_credentials(self):
        source = FILE.read_text(encoding="utf-8")
        for forbidden in (
            "systemctl", "nginx -s reload", "service nginx",
            "/etc/nginx/nginx.conf", "VAZINCMS_SITE_FENCE_TOKEN_FILE",
            "php-fpm", "postgresql://",
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("subprocess.Popen", source)
        self.assertIn('["-g", "daemon off; master_process off;"]', source)
        self.assertIn("TemporaryDirectory", source)
        self.assertIn("process.terminate()", source)
        self.assertIn('"production": "NO_GO"', source)


if __name__ == "__main__":
    unittest.main()
