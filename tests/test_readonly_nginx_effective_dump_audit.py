"""Synthetic, privacy-minimizing contracts for the Nginx -T disk config audit.

All tests mock -T or parse public dummy config. No real Nginx invocation in
the release-check suite; no live Nginx/FPM state changes.
"""
from __future__ import annotations

import importlib.util
import json
from io import StringIO
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "scripts/readonly_nginx_effective_dump_audit.py"
SPEC = importlib.util.spec_from_file_location("readonly_nginx_effective_dump_audit", SOURCE)
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

TOKEN = "PRIVATE_FAKE_ABC_99_NOT_FOR_OUTPUT"
PRIVATE_PATH = "/private_dummy_configuration_path_777"
KNOWN_TARGETS = list(mod.TARGETS)


def synthetic_dump() -> str:
    lines = [
        "# configuration file /etc/nginx/nginx.conf:",
        "events { worker_connections 128; }",
        "http { include /etc/nginx/sites-enabled/*; }",
        "# configuration file /etc/nginx/conf.d/proxy.conf:",
        "set_real_ip_from 192.0.2.10; real_ip_header X-Forwarded-For;",
        "real_ip_recursive on;",
    ]
    for label, host in mod.TARGETS.items():
        fpm = (
            "php8.5-fpm.sock" if label in {"VISA", "TRAVEL"} else
            "vazincms-studio.sock" if label == "STUDIO" else
            "vazincms-russiafa.sock" if label == "RUSSIAFA" else
            "custom-unmapped.sock"
        )
        lines.extend([
            f"# configuration file /etc/nginx/sites-enabled/fake-{label}:",
            # Some server blocks use same-line directives; must be supported.
            f"server {{ listen 443 ssl; server_name {host};",
            f"location / {{ try_files $uri /index.php; }}",
            f"location ^~ /assets/ {{ return 503; }}",
            f"location ~ \x5c.php$ {{ fastcgi_pass unix:/run/php/{fpm}; }}",
            f"location = /__vazin_cms_health {{ return 503; }}",
            f"add_header X-Vazin-Release-Fence {TOKEN};",
            f"return 503 '{PRIVATE_PATH}'; }}",
        ])
    return "\n".join(lines) + "\n"


class DumpAuditTests(unittest.TestCase):
    def sample(self):
        return mod.parse_dump(synthetic_dump())

    def test_five_known_sites_from_inline_server_name(self):
        report = self.sample()
        self.assertEqual([s["site"] for s in report["sites"]], KNOWN_TARGETS)
        self.assertEqual([s["matching_server_blocks"] for s in report["sites"]], [1]*5)

    def test_shared_php_backend_identified_without_leaking_path(self):
        report = self.sample()
        entries = {s["site"]: s["backend_classes"] for s in report["sites"]}
        self.assertEqual(entries["VISA"], ["shared_php85"])
        self.assertEqual(entries["TRAVEL"], ["shared_php85"])
        self.assertEqual(entries["STUDIO"], ["vazincms_studio"])
        self.assertEqual(entries["CMS"], ["other_unix_backend"])

    def test_shared_php85_blocks_per_site_drain(self):
        report = self.sample()
        self.assertTrue(report["travel_and_visa_share_php85_backend"])
        self.assertEqual(report["per_site_drain_precondition"], "BLOCKED_SHARED_POOL")
        self.assertFalse(report["inflight_fpm_request_state_known"])
        self.assertFalse(report["background_writers_quiesced"])
        self.assertFalse(report["php_fpm_drain_verified"])

    def test_separate_backend_still_not_a_verified_drain(self):
        changed = synthetic_dump().replace(
            "server_name travel.vazin.online;",
            "server_name travel.vazin.online;"
        ).replace(
            "unix:/run/php/php8.5-fpm.sock;",
            "unix:/run/php/vazincms-studio.sock;", 1
        )
        report = mod.parse_dump(changed)
        # At least one of the sites no longer shares php8.5, but a read-only
        # config scan does not prove active-request drain in either case.
        self.assertFalse(report["travel_and_visa_share_php85_backend"])
        self.assertEqual(report["per_site_drain_precondition"], "NOT_ATTESTED")
        self.assertFalse(report["php_fpm_drain_verified"])

    def test_no_raw_values_or_secret_in_report(self):
        out = json.dumps(self.sample(), sort_keys=True)
        for secret in (TOKEN, PRIVATE_PATH, "192.0.2.10", "/etc/nginx/",
                       "fake-RUSSIAFA", "custom-unmapped.sock"):
            self.assertNotIn(secret, out)
        self.assertIn("NO_GO", out)

    def test_realip_counts_but_not_values(self):
        report = self.sample()
        self.assertEqual(report["realip_directives_in_dump"],
                         {"real_ip_header": 1, "set_real_ip_from": 1,
                          "real_ip_recursive": 1})
        self.assertNotIn("X-Forwarded-For", json.dumps(report))

    def test_server_name_keyword_in_quoted_string_not_a_vhost(self):
        injected = (
            "# configuration file /etc/nginx/sites-enabled/injected:\n"
            'server { return 200 "server_name visa.vazin.online;"; '
            "server_name harmless.invalid; }\n"
        )
        report = mod.parse_dump(injected)
        visa = next(s for s in report["sites"] if s["site"] == "VISA")
        self.assertEqual(visa["matching_server_blocks"], 0)

    def test_quoted_comment_inside_header_does_not_break_parser(self):
        parsed = mod.strip_comments(
            'server { add_header Example "value#still"; # secret real_ip_header x;\n'
            ' server_name "cms.vazin.online"; }\n'
        )
        self.assertIn('"value#still"', parsed)
        self.assertNotIn("secret real_ip_header", parsed)
        report = mod.parse_dump("# configuration file /example:\n" + parsed)
        self.assertEqual(report["sites"][0]["matching_server_blocks"], 1)

    def test_location_kinds_separated(self):
        report = self.sample()
        for item in report["sites"]:
            self.assertEqual(item["location_forms"]["exact"], 1)
            self.assertEqual(item["location_forms"]["ordinary_prefix"], 1)
            self.assertEqual(item["location_forms"]["prefix_no_regex"], 1)
            self.assertEqual(item["location_forms"]["regex"], 1)

    def test_false_stored_config_never_claims_live_worker_attestation(self):
        report = self.sample()
        self.assertTrue(report["nginx_test_succeeded"])
        self.assertFalse(report["loaded_worker_config_proven_equal_to_dump"])
        self.assertFalse(report["independent_vhost_location_review_completed"])
        self.assertFalse(report["php_fpm_drain_verified"])
        self.assertFalse(report["deployment_authorized"])
        self.assertEqual(report["production"], "NO_GO")
        self.assertTrue(all(s["all_locations_proven_fenced"] is False
                            for s in report["sites"]))

    def test_capture_only_fixed_nginx_t_into_memory(self):
        fake = subprocess.CompletedProcess(["/usr/sbin/nginx", "-T"], 0,
                                            b"# configuration file /test:\nserver {}\n", b"")
        with patch.object(mod.subprocess, "run", return_value=fake) as call:
            body = mod.capture_nginx_dump()
        self.assertIn("server {}", body)
        self.assertEqual(call.call_args.args[0], [mod.NGINX, "-T"])
        self.assertTrue(call.call_args.kwargs["capture_output"])
        self.assertFalse(call.call_args.kwargs["check"])
        self.assertLess(call.call_args.kwargs["timeout"], 30)

    def test_cli_does_not_leak_sensitive_failed_subprocess_output(self):
        fake = subprocess.CompletedProcess(["/usr/sbin/nginx", "-T"], 1,
                                            TOKEN.encode(), PRIVATE_PATH.encode())
        out = StringIO()
        with patch.object(mod.subprocess, "run", return_value=fake):
            with patch("sys.stdout", out):
                code = mod.main([])
        self.assertEqual(code, 70)
        self.assertNotIn(TOKEN, out.getvalue())
        self.assertNotIn(PRIVATE_PATH, out.getvalue())
        self.assertEqual(json.loads(out.getvalue())["result"], "INCOMPLETE")

    def test_cli_blocks_unsafe_extra_cli_args_without_echoing_them(self):
        output = StringIO()
        with patch("sys.stdout", output):
            exit_code = mod.main(["--nginx", TOKEN])
        self.assertEqual(exit_code, 70)
        self.assertNotIn(TOKEN, output.getvalue())
        self.assertEqual(json.loads(output.getvalue())["result"], "INCOMPLETE")

    def test_non_utf8_dump_fails_closed(self):
        fake = subprocess.CompletedProcess(["/usr/sbin/nginx", "-T"], 0,
                                            b"\xff\xff", b"")
        with patch.object(mod.subprocess, "run", return_value=fake):
            with self.assertRaises(mod.DumpIncomplete):
                mod.capture_nginx_dump()

    def test_large_dump_is_rejected_without_echo(self):
        with patch.object(mod, "MAX_DUMP", 50):
            with self.assertRaises(mod.DumpIncomplete):
                mod.parse_dump(synthetic_dump())

    def test_missing_header_is_incomplete(self):
        with self.assertRaises(mod.DumpIncomplete):
            mod.parse_dump("server { server_name visa.vazin.online; }")

    def test_unbalanced_server_block_is_incomplete(self):
        with self.assertRaises(mod.DumpIncomplete):
            mod.parse_dump("# configuration file /testing:\nserver { server_name visa.vazin.online;")

    def test_no_live_service_operations_in_script(self):
        source = SOURCE.read_text(encoding="utf-8")
        for forbidden in ("systemctl", "service nginx", "nginx -s reload",
                          "subprocess.Popen", "write_text(", "unlink(",
                          "fastcgi://", "pm.status_listen"):
            self.assertNotIn(forbidden, source)
        self.assertIn("NO_GO", source)


if __name__ == "__main__":
    unittest.main()
